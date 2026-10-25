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
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/landsman/adminer-desktop/launcher/datadir"
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

// How long the window may stay silent on one call before it is reported as closed: to connect,
// to answer, and between two reads of a body still arriving. Idle, as PHP's http `timeout` is,
// not a cap on the whole call — a large result still streaming at a minute is not a closed window.
// The stdio side has no timeout: a client that goes quiet has not let go, and a socket stdin once
// dropped the server after a minute (the bug https://github.com/landsman/adminer-desktop/pull/183
// fixed). This one bounds the HTTP hop only.
const callTimeout = 60 * time.Second

// Bridge reads one JSON-RPC message, answers it from PHP's own definitions when the app is
// unreachable, and forwards it to the gate otherwise.
type Bridge struct {
	dir    string        // where mcp.json and settings.json live; "" when the app has no durable home
	logs   string        // where the day's mcp-YYYY-MM-DD.log goes; "" writes none
	pruned atomic.Value  // the UTC day prune() last ran for, so it runs once a day, not per call
	idle   time.Duration // callTimeout, shorter in a test
	locale string
}

// New is the bridge an agent spawns, on the launcher's own data dir whatever the environment
// says: an agent started from a shell that has ADMINER_DESKTOP_DATA set for `make serve` or the
// e2e suite would otherwise read another directory's handshake and report the app as not running
// while it is open in front of you. logs is the launcher's log directory, where Open Logs looks.
func New(logs string) *Bridge {
	dir, err := datadir.Path()
	if err != nil {
		dir = "" // no config dir at all: nothing to reach, which is what the agent is told
	}
	b := NewIn(dir)
	b.logs = logs
	return b
}

// NewIn is for a caller that already knows where the app keeps its state.
func NewIn(dir string) *Bridge {
	return &Bridge{
		dir:    dir,
		idle:   callTimeout,
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
	return b.RunUntil(context.Background(), in, out)
}

// RunUntil is Run, ended early by ctx: the calls still in flight are cancelled and recorded as
// such before it returns, so a signal does not take them out of the log with the process.
func (b *Bridge) RunUntil(ctx context.Context, in io.Reader, out io.Writer) error {
	// Messages are answered as they finish, not in turn, and stdin is read on whatever is in
	// flight: a ping, or the notification that cancels a call, must never wait in the pipe behind
	// a slow query, or a client checking the server is alive gives up on it. JSON-RPC matches
	// answers by id, so the order is free.
	var (
		writing  sync.Mutex
		inFlight sync.WaitGroup
		failed   error
		running  sync.Map // a request's id → what cancels it, for notifications/cancelled
	)
	// The first write that fails closes this: nobody is reading the answers any more, which a
	// read blocked on a quiet stdin would otherwise never notice.
	gone := make(chan struct{})
	slots := make(chan struct{}, maxCalls)
	lines := make(chan string)
	ended := make(chan error, 1)
	go func() {
		reader := bufio.NewReader(in)
		for {
			line, err := reader.ReadString('\n')
			if line != "" {
				select {
				case lines <- line:
				case <-gone:
					return
				}
			}
			if err != nil {
				ended <- err
				return
			}
		}
	}()
	stop := func() error {
		running.Range(func(_, cancel any) bool {
			cancel.(context.CancelCauseFunc)(errCancelled)
			return true
		})
		inFlight.Wait()
		writing.Lock()
		defer writing.Unlock()
		return failed
	}
	for {
		select {
		case <-ctx.Done():
			return stop()
		case <-gone:
			return stop() // stdout is closed: nobody is left to answer
		case err := <-ended:
			inFlight.Wait() // stdin closing is the client letting go, not a reason to drop answers
			writing.Lock()
			defer writing.Unlock()
			if failed != nil {
				return failed
			}
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		case line := <-lines:
			trimmed := strings.TrimSpace(line)
			if trimmed == "" {
				continue
			}
			message := parse(trimmed)
			if message.method == "notifications/cancelled" {
				if cancel, ok := running.Load(message.cancels); ok {
					cancel.(context.CancelCauseFunc)(errCancelled)
				}
			}
			// Registered here, before the goroutine starts, so a cancel that arrives right behind
			// its request still finds it.
			ctx, cancel := context.WithCancelCause(context.Background())
			if message.hasID {
				running.Store(string(message.id), cancel)
			}
			inFlight.Add(1)
			go func() {
				defer inFlight.Done()
				defer cancel(nil)
				defer running.Delete(string(message.id))
				start := time.Now()
				answer, result := (*string)(nil), outcome{status: statusCancelled}
				waited := true
				if message.method == "tools/call" {
					select {
					case slots <- struct{}{}:
						defer func() { <-slots }()
					case <-ctx.Done():
						waited = false // cancelled while it waited its turn
					}
				}
				if waited {
					answer, result = b.exchange(ctx, trimmed)
				}
				// MCP: a request the client cancelled gets no answer at all.
				if errors.Is(context.Cause(ctx), errCancelled) {
					answer, result = nil, outcome{status: statusCancelled}
				}
				b.record(message, trimmed, start, result)
				if answer == nil {
					return
				}
				writing.Lock()
				defer writing.Unlock()
				if failed != nil {
					return
				}
				if _, err := fmt.Fprintln(out, *answer); err != nil {
					failed = err
					close(gone)
				}
			}()
		}
	}
}

// How many tool calls one bridge runs at once; the rest wait their turn, and nothing else waits
// at all. Each call holds a frankenphp thread, two when it names another connection, and
// frankenphp has twice as many as there are cores: an agent that sends twenty queries must not
// take every thread the window needs. A guess at "well under half of a small machine's", not a
// measurement.
const maxCalls = 4

var errCancelled = errors.New("the client cancelled the request")

// Exchange handles one message and returns what to write back, or nil when there is nothing to
// say. Nil covers two cases that must not be confused: a JSON-RPC notification, which the
// protocol answers with silence, and a failed request that carried no id, which is also a
// notification and so also gets none.
func (b *Bridge) Exchange(line string) *string {
	answer, _ := b.exchange(context.Background(), line)
	return answer
}

// exchange is Exchange, and how it went, for the record.
func (b *Bridge) exchange(ctx context.Context, line string) (*string, outcome) {
	message := parse(line)
	failed := func(status, id string) (*string, outcome) {
		text := b.say(id)
		return b.unavailable(message, text), outcome{status: status, error: text}
	}

	call, ok := b.handshake()
	if !ok {
		return failed(statusNotRunning, "mcp.agent_not_running")
	}
	status, body, err := b.post(ctx, call, line)
	if errors.Is(err, errTimedOut) {
		return failed(statusTimeout, "mcp.agent_timed_out")
	}
	if err != nil {
		return failed(statusWindowClosed, "mcp.agent_window_closed")
	}
	// Adminer redirects to its login page when the session behind the handshake has expired, and
	// the gate answers 403 once it has no session to lend: say that, whatever the body. Any other
	// error is not the app's answer either, however much it looks like JSON.
	if (status >= 300 && status < 400) || status == http.StatusUnauthorized || status == http.StatusForbidden {
		return failed(statusSessionExpired, "mcp.agent_session_expired")
	}
	if status < 200 || status >= 300 {
		return failed(statusWindowClosed, "mcp.agent_window_closed")
	}
	// A BOM or a stray newline is not a different answer, and a newline would end the line stdio
	// frames each message by.
	body = strings.TrimSpace(strings.TrimPrefix(body, "\uFEFF"))
	if body == "" {
		// Silence answers a notification (the app's 204), and unavailable() keeps it silent. A
		// request owed an answer that got none — PHP died, the gate had nothing to say — is told
		// so, or the agent waits on its id for good.
		if !message.hasID {
			return nil, outcome{status: statusOK}
		}
		return failed(statusWindowClosed, "mcp.agent_window_closed")
	}
	// A login page served as 200 is an expired session too, rather than markup to guess at.
	if body[0] != '{' && body[0] != '[' {
		return failed(statusSessionExpired, "mcp.agent_session_expired")
	}
	return &body, answered(body)
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
			quote(negotiate(message.asked)), quote(data.Instructions),
		))
	case "tools/list":
		return message.result(`{"tools":` + string(b.catalogue()) + `}`)
	case "ping":
		return message.result(`{}`)
	case "server/discover":
		// Not a modern answer, on purpose: this server speaks the 2025-11-25 handshake era and the
		// app behind it answers the same way (Desktop\Mcp\Server). A dual-era client reads -32601
		// as "legacy" and falls back to initialize, which is exactly what it does once the app is
		// up. Advertising a version here that the app does not implement would be the bug.
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
	write, whole := b.settings()
	key := "00"
	if whole {
		key = "1" + key[1:]
	}
	if write {
		key = key[:1] + "1"
	}
	return data.Catalogue[key]
}

// settings reads settings.json once, so both values come from the same version of the file.
func (b *Bridge) settings() (write, whole bool) {
	if b.dir == "" {
		return false, false
	}
	raw, err := os.ReadFile(filepath.Join(b.dir, "settings.json"))
	if err != nil {
		return false, false
	}
	var settings map[string]any
	if json.Unmarshal(raw, &settings) != nil {
		return false, false
	}
	return phpBool(settings["mcp_write"]), phpBool(settings["mcp_whole_values"])
}

// phpBool is PHP's (bool) over a decoded JSON value, which is how the app itself reads these
// (Desktop\Mcp\Endpoint). Anything else and a hand-edited `"mcp_write": 1` would be described
// offline as rolled back while the app commits it.
func phpBool(v any) bool {
	switch v := v.(type) {
	case bool:
		return v
	case float64:
		return v != 0
	case string:
		return v != "" && v != "0"
	case []any:
		return len(v) > 0
	case map[string]any:
		return len(v) > 0 // json_decode(..., true) makes an object an array, and {} an empty one
	default:
		return false // null
	}
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
	url    string
	token  string
	socket string // the gate's unix socket, the only place a call goes
}

// handshake is where the window can be reached, written while the feature is on and a connected
// request is being served. Absent, unreadable, pre-token or pointing anywhere but this machine all
// read as "nothing to reach", which is what the agent is told.
func (b *Bridge) handshake() (call, bool) {
	if b.dir == "" {
		return call{}, false
	}
	raw, err := os.ReadFile(filepath.Join(b.dir, "mcp.json"))
	if err != nil {
		return call{}, false
	}
	var c struct {
		URL    string `json:"url"`
		Token  string `json:"token"`
		Socket string `json:"socket"`
	}
	if json.Unmarshal(raw, &c) != nil || c.URL == "" || c.Token == "" || !loopback(c.URL) {
		return call{}, false
	}
	// The socket, and only the socket: the url's port outlives the app and anyone can bind it.
	// A handshake with none is one an older version wrote, and the next page the window loads
	// writes a new one. And not a socket someone else could have made: on Linux the gate's
	// directory is in the shared /tmp, and once the app has gone another account can recreate it
	// at the path mcp.json still names.
	if c.Socket == "" || !private(c.Socket) {
		return call{}, false
	}
	return call{url: c.URL, token: c.Token, socket: c.Socket}, true
}

// loopback is Desktop\Mcp\Handshake::isLoopback(): http on 127.0.0.1, all the gate listens on.
// A url anywhere else would hand the token and the agent's SQL to whoever is there.
func loopback(raw string) bool {
	u, err := url.Parse(raw)
	return err == nil && u.Scheme == "http" && u.Hostname() == "127.0.0.1"
}

var errTimedOut = errors.New("the window was silent for the whole timeout")

// post sends one message to the launcher's gate, with its token. The gate adds the session.
//
// Over the gate's unix socket, never the url's port: a quit app leaves its port behind
// for anyone to bind, and whoever did would get the token and the SQL, and could answer. The
// socket sits in the gate's 0700 directory, and once the app is gone it refuses. No proxy and no
// redirect followed: the gate is the only place this goes, and Go would carry the token along to
// a redirect on the same host.
func (b *Bridge) post(parent context.Context, c call, body string) (int, string, error) {
	ctx, cancel := context.WithCancelCause(parent)
	defer cancel(nil)
	idle := time.AfterFunc(b.idle, func() { cancel(errTimedOut) })
	defer idle.Stop()
	transport := &http.Transport{
		DisableKeepAlives: true,
		// Whatever host the url names: it is only the Host header the gate checks.
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", c.socket)
		},
	}
	client := &http.Client{
		Transport:     transport,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpointAt(c.url), strings.NewReader(body))
	if err != nil {
		return 0, "", err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.token)
	resp, err := client.Do(req)
	if err != nil {
		return 0, "", timedOutOr(ctx, err)
	}
	answer, err := io.ReadAll(idleReader{resp.Body, idle, b.idle})
	if err = errors.Join(err, resp.Body.Close()); err != nil {
		return 0, "", timedOutOr(ctx, err)
	}
	return resp.StatusCode, string(answer), nil
}

// timedOutOr says which failure it was: the idle timer giving up, or the window not there.
func timedOutOr(ctx context.Context, err error) error {
	if errors.Is(context.Cause(ctx), errTimedOut) {
		return errTimedOut
	}
	return err
}

// idleReader pushes the deadline back on every read that brought something.
type idleReader struct {
	r     io.Reader
	timer *time.Timer
	idle  time.Duration
}

func (r idleReader) Read(p []byte) (int, error) {
	n, err := r.r.Read(p)
	if n > 0 {
		r.timer.Reset(r.idle)
	}
	return n, err
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

// rpcMessage is only as much of a message as a reply needs: the id to answer with, the method
// to choose the answer, and the version an initialize asked for. Everything else is forwarded
// untouched.
type rpcMessage struct {
	id      json.RawMessage
	hasID   bool
	method  string
	asked   string
	tool    string // tools/call: the tool's name
	cancels string // notifications/cancelled: the id of the request it cancels, as raw JSON
}

// parse reads each field on its own, as PHP's Stdio does: a method or a version of the wrong
// type reads as none, rather than failing the whole decode and losing an id that is owed an
// answer.
func parse(line string) rpcMessage {
	var raw struct {
		ID     json.RawMessage `json:"id"`
		Method json.RawMessage `json:"method"`
		Params json.RawMessage `json:"params"`
	}
	if json.Unmarshal([]byte(line), &raw) != nil {
		return rpcMessage{}
	}
	m := rpcMessage{method: str(raw.Method)}
	var params struct {
		ProtocolVersion json.RawMessage `json:"protocolVersion"`
		RequestID       json.RawMessage `json:"requestId"`
		Name            json.RawMessage `json:"name"`
	}
	if json.Unmarshal(raw.Params, &params) == nil {
		m.asked = str(params.ProtocolVersion)
		m.tool = str(params.Name)
		m.cancels = string(params.RequestID)
	}
	// json.RawMessage is nil when the key is absent; an explicit null is "null" and JSON-RPC
	// treats a request with id null as a notification too. PHP's `?? null` cannot tell the two
	// apart either, so both are silence.
	if len(raw.ID) != 0 && string(raw.ID) != "null" {
		m.id, m.hasID = raw.ID, true
	}
	return m
}

// str is a JSON string's value, or "" for anything else — absent, null, a number.
func str(raw json.RawMessage) string {
	var s string
	if json.Unmarshal(raw, &s) != nil {
		return ""
	}
	return s
}

// negotiate is Desktop\Mcp\Server::negotiate(): the client's version when it is older than
// ours, else ours. Versions are ISO dates, so older is a string comparison.
func negotiate(asked string) string {
	if asked != "" && asked < data.ProtocolVersion {
		return asked
	}
	return data.ProtocolVersion
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
