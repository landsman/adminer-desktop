// Package mcpbridge is the stdio half of MCP: the process an agent spawns, and the running
// window it reaches over HTTP.
//
// It is the Go side of app/src/Mcp/Stdio.php, and exists so the launcher has no PHP child of its
// own to start. MCP's stdio transport expects the client to start its server as a process; this
// app is already running, on a port picked fresh every launch, so the bridge is the piece in
// between. Per message it reads the handshake for the gate's current URL and token, forwards the
// message there, and writes the answer back. It never holds the window's session: the gate adds
// that (launcher/mcpgate).
//
// Per message, not once at startup, because the agent may have spawned the bridge before the app
// was running and will outlive a restart of it.
//
// Which answers and descriptions it owes PHP are generated from PHP at build time
// (cli/mcp-bridge.php) and embedded, so the catalogue and the translated sentences have one
// definition rather than a hand copy that drifts.
package mcpbridge

import (
	"bufio"
	"bytes"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// generated is cli/mcp-bridge.php's output: Server::PROTOCOL, Server::INSTRUCTIONS, the four
// catalogues and the plugin sentences, for the bridge and for nothing else.
//
//go:embed data.json
var generatedJSON []byte

type generated struct {
	ProtocolVersion string                       `json:"protocolVersion"`
	Instructions    string                       `json:"instructions"`
	Catalogue       map[string]json.RawMessage   `json:"catalogue"` // "00" whole, write
	Sentences       map[string]map[string]string `json:"sentences"` // locale => id => text
}

var data = mustLoad()

func mustLoad() generated {
	var g generated
	if err := json.Unmarshal(generatedJSON, &g); err != nil {
		// The file is generated and compiled in, so this is a broken build rather than anything a
		// user can cause; failing at start beats answering with an empty tool list.
		panic("mcpbridge: generated data is not valid: " + err.Error())
	}
	// The generator pretty-prints, and a raw catalogue inserted as-is would put its newlines into
	// the answer. MCP's stdio framing is one message per line, so a pretty answer is not an
	// answer at all: the client reads `{"tools":[` and gives up.
	for key, raw := range g.Catalogue {
		var compact bytes.Buffer
		if err := json.Compact(&compact, raw); err != nil {
			panic("mcpbridge: generated catalogue " + key + " is not valid: " + err.Error())
		}
		g.Catalogue[key] = compact.Bytes()
	}
	return g
}

// How long one call may wait for the window before it is reported as closed. The stdio side has
// no timeout: a client that goes quiet has not let go, and a socket stdin once dropped the server
// after a minute (the bug PR #183 fixed). This one bounds the HTTP hop only.
const callTimeout = 60 * time.Second

// Bridge reads one JSON-RPC message, answers it from PHP's own definitions when the app is
// unreachable, and forwards it to the gate otherwise.
type Bridge struct {
	dir    string // where mcp.json and settings.json live; "" when the app has no durable home
	client *http.Client
	locale string
}

// New is the bridge an agent spawns: the data dir the launcher passed in ADMINER_DESKTOP_DATA,
// or the same os.UserConfigDir() home the launcher would have used. The fallback matters because
// an agent starts this as a bare child with no environment of ours, and without it the bridge
// reports the app as not running while it is open in front of you.
func New() *Bridge {
	dir := os.Getenv("ADMINER_DESKTOP_DATA")
	if dir == "" {
		if base, err := os.UserConfigDir(); err == nil {
			dir = filepath.Join(base, "Adminer Desktop")
		}
	}
	return NewIn(dir)
}

// NewIn is for a caller that already knows where the app keeps its state.
func NewIn(dir string) *Bridge {
	return &Bridge{
		dir:    dir,
		client: &http.Client{Timeout: callTimeout},
		locale: locale(),
	}
}

// locale mirrors Desktop\I18n\Strings: Adminer's LANG where there is one, else the environment's,
// else English. The bridge runs with no Adminer, so the environment is all there is.
func locale() string {
	lang := strings.ToLower(os.Getenv("LANG"))
	if len(lang) >= 2 {
		lang = lang[:2]
	}
	if _, ok := data.Sentences[lang]; ok {
		return lang
	}
	return "en"
}

// Run pumps messages until stdin closes. Blank lines are skipped, one answer goes out per
// request, and a notification gets none — JSON-RPC answers silence with silence.
func (b *Bridge) Run(in io.Reader, out io.Writer) error {
	reader := bufio.NewReader(in)
	for {
		line, err := reader.ReadString('\n')
		if trimmed := strings.TrimSpace(line); trimmed != "" {
			if answer := b.Exchange(trimmed); answer != nil {
				if _, writeErr := fmt.Fprintln(out, *answer); writeErr != nil {
					return writeErr
				}
			}
		}
		if err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		}
	}
}

// Exchange handles one message and returns what to write back, or nil when there is nothing to
// say. Nil covers two cases that must not be confused: a JSON-RPC notification, which the
// protocol answers with silence, and a failed request that carried no id, which is also a
// notification and so also gets none.
func (b *Bridge) Exchange(line string) *string {
	message := parse(line)

	call, ok := b.handshake()
	if !ok {
		return b.unavailable(message, b.say("mcp.agent_not_running"))
	}
	body, ok := b.post(call.url, line, call.token)
	if !ok {
		return b.unavailable(message, b.say("mcp.agent_window_closed"))
	}
	if body == "" {
		return nil // the app answered 204: a notification, and nothing to forward
	}
	// Adminer answers HTML when the session behind the handshake has expired. Say that, rather
	// than handing the agent a page of markup to guess at.
	if body[0] != '{' && body[0] != '[' {
		return b.unavailable(message, b.say("mcp.agent_session_expired"))
	}
	return &body
}

// unavailable answers with the app out of reach, without failing the connection.
//
// Failing `initialize` is what a client reports as a dead server: it prints the JSON-RPC code and
// drops the message, so the sentence explaining exactly what to do never reaches anyone. The
// observed symptom was a bare "-32000" and a server listed as failed, for the entirely ordinary
// case of the feature not being switched on yet.
//
// So the handshake always succeeds — this is a working server whose backend happens to be
// unavailable — and the explanation is delivered as a tool *result* instead, which clients
// render into the conversation where somebody will read it.
//
// tools/list advertises the real tools, not a placeholder: a model seeing no database tools
// concluded the server was not connected and wrote its own bridge to the database.
func (b *Bridge) unavailable(message rpcMessage, text string) *string {
	if !message.hasID {
		return nil // a notification: answered with silence whatever the state
	}
	switch message.method {
	case "initialize":
		return message.result(fmt.Sprintf(
			`{"protocolVersion":%s,"capabilities":{"tools":{}},"serverInfo":{"name":"adminer-desktop","version":"offline"},"instructions":%s}`,
			quote(data.ProtocolVersion), quote(data.Instructions),
		))
	case "tools/list":
		return message.result(`{"tools":` + string(b.catalogue()) + `}`)
	case "ping":
		return message.result(`{}`)
	case "server/discover":
		// Not a modern answer, on purpose: this server speaks the 2025-11-25 handshake era and the
		// app behind it answers the same way. A dual-era client reads -32601 as "legacy" and falls
		// back to initialize, which is exactly what it does once the app is up. Advertising a
		// version here that the app does not implement would be the bug.
		return message.error(-32601, b.say("mcp.agent_unknown_method")+": server/discover")
	default:
		// tools/call and anything else: an error the model can read and repeat to the user.
		return message.result(fmt.Sprintf(`{"content":[{"type":"text","text":%s}],"isError":true}`, quote(text)))
	}
}

// catalogue is tools/list as the app would describe it, from settings.json beside the handshake.
//
// Not the defaults: a client keeps the list it got at connect, so the descriptions given while
// the app was closed are the ones it still trusts once the app is up — with writes on, a promise
// that everything is rolled back.
func (b *Bridge) catalogue() json.RawMessage {
	key := "00"
	if b.setting("mcp_whole_values") {
		key = "1" + key[1:]
	}
	if b.setting("mcp_write") {
		key = key[:1] + "1"
	}
	return data.Catalogue[key]
}

func (b *Bridge) setting(name string) bool {
	if b.dir == "" {
		return false
	}
	raw, err := os.ReadFile(filepath.Join(b.dir, "settings.json"))
	if err != nil {
		return false
	}
	var settings map[string]any
	if json.Unmarshal(raw, &settings) != nil {
		return false
	}
	on, _ := settings[name].(bool)
	return on
}

// say is a plugin sentence in the app's language. An untranslated id degrades to English, as it
// does in PHP; an id nobody wrote stays the id rather than an empty answer.
func (b *Bridge) say(id string) string {
	if text := data.Sentences[b.locale][id]; text != "" {
		return text
	}
	if text := data.Sentences["en"][id]; text != "" {
		return text
	}
	return id
}

type call struct {
	url   string
	token string
}

// handshake is where the window can be reached, written while the feature is on and a connected
// request is being served. Absent, unreadable or pre-token all read as "nothing to reach", which
// is what the agent is told.
func (b *Bridge) handshake() (call, bool) {
	if b.dir == "" {
		return call{}, false
	}
	raw, err := os.ReadFile(filepath.Join(b.dir, "mcp.json"))
	if err != nil {
		return call{}, false
	}
	var c struct {
		URL   string `json:"url"`
		Token string `json:"token"`
	}
	if json.Unmarshal(raw, &c) != nil || c.URL == "" || c.Token == "" {
		return call{}, false
	}
	return call{url: c.URL, token: c.Token}, true
}

// post sends one message to the launcher's gate, with its token. The gate adds the session.
func (b *Bridge) post(url, body, token string) (string, bool) {
	req, err := http.NewRequest(http.MethodPost, endpointAt(url), strings.NewReader(body))
	if err != nil {
		return "", false
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	resp, err := b.client.Do(req)
	if err != nil {
		return "", false
	}
	defer resp.Body.Close() //nolint:errcheck // read-only, closing cannot fail meaningfully
	answer, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", false
	}
	return string(answer), true
}

// endpointAt is the URL a message goes to: the handshake's, with mcp=1, which is the query the
// plugin answers on. Adminer's ME ends in "&", which a second one would leave an empty pair
// after (Desktop\Mcp\Endpoint::at).
func endpointAt(url string) string {
	if strings.Contains(url, "?") {
		return strings.TrimRight(url, "&") + "&mcp=1"
	}
	return url + "?mcp=1"
}

// rpcMessage is only as much of a message as a reply needs: the id to answer with, and the method
// to choose the answer. Everything else is forwarded untouched.
type rpcMessage struct {
	id     json.RawMessage
	hasID  bool
	method string
}

func parse(line string) rpcMessage {
	var raw struct {
		ID     json.RawMessage `json:"id"`
		Method string          `json:"method"`
	}
	if json.Unmarshal([]byte(line), &raw) != nil {
		return rpcMessage{}
	}
	// json.RawMessage is nil when the key is absent; an explicit null is "null" and JSON-RPC
	// treats a request with id null as a notification too. PHP's `?? null` cannot tell the two
	// apart either, so both are silence.
	if len(raw.ID) == 0 || string(raw.ID) == "null" {
		return rpcMessage{method: raw.Method}
	}
	return rpcMessage{id: raw.ID, hasID: true, method: raw.Method}
}

func (m rpcMessage) result(result string) *string {
	return m.answer(fmt.Sprintf(`{"jsonrpc":"2.0","id":%s,"result":%s}`, m.id, result))
}

func (m rpcMessage) error(code int, message string) *string {
	return m.answer(fmt.Sprintf(`{"jsonrpc":"2.0","id":%s,"error":{"code":%d,"message":%s}}`, m.id, code, quote(message)))
}

func (m rpcMessage) answer(encoded string) *string {
	return &encoded
}

// quote encodes one string as a JSON value. The generated text is inserted unescaped otherwise,
// and a description with a quote in it would produce a message no client can parse.
func quote(s string) string {
	b, err := json.Marshal(s)
	if err != nil {
		return `""`
	}
	return string(b)
}
