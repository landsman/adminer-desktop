package mcpbridge

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/landsman/adminer-desktop/launcher/datadir"
)

// The scenarios tests/e2e/features/shell/mcp-bridge.feature describes, against this package with
// no PHP: the app is a test server, the handshake is a temporary directory, and the transport is
// what the feature cannot provoke on demand — a closed window, an expired session, no app at all.

func bridge(t *testing.T, dir string) *Bridge {
	t.Helper()
	return NewIn(dir)
}

// handshake points the bridge at a server, in the shape Desktop\Mcp\Handshake writes.
func handshake(t *testing.T, dir, url string) {
	t.Helper()
	body := fmt.Sprintf(`{"url":%q,"token":"tok","target":""}`, url)
	if err := os.WriteFile(filepath.Join(dir, "mcp.json"), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
}

// reply is a test server's answer; a write that fails would leave the bridge with a short body
// and the test asserting on something the server never meant to say.
func reply(t *testing.T, w io.Writer, body string) {
	t.Helper()
	if _, err := io.WriteString(w, body); err != nil {
		t.Error(err)
	}
}

func readBody(t *testing.T, r *http.Request) string {
	t.Helper()
	body, err := io.ReadAll(r.Body)
	if err != nil {
		t.Error(err)
	}
	return string(body)
}

func closeOrFail(t *testing.T, c io.Closer) {
	t.Helper()
	if err := c.Close(); err != nil {
		t.Error(err)
	}
}

func settings(t *testing.T, dir string, values map[string]any) {
	t.Helper()
	body, err := json.Marshal(values)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "settings.json"), body, 0o600); err != nil {
		t.Fatal(err)
	}
}

func initialize() string {
	return `{"jsonrpc":"2.0","id":1,"method":"initialize"}`
}

func listTools() string {
	return `{"jsonrpc":"2.0","id":7,"method":"tools/list"}`
}

func callTool() string {
	return `{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_tables"}}`
}

func notification() string {
	return `{"jsonrpc":"2.0","method":"notifications/initialized"}`
}

func decode(t *testing.T, answer *string) map[string]any {
	t.Helper()
	if answer == nil {
		t.Fatal("the bridge said nothing")
	}
	var decoded map[string]any
	if err := json.Unmarshal([]byte(*answer), &decoded); err != nil {
		t.Fatalf("not JSON: %q: %v", *answer, err)
	}
	return decoded
}

// With no handshake the connection still comes up, carrying the reason.
func TestUnavailableInitializeSucceeds(t *testing.T) {
	b := bridge(t, t.TempDir())
	answer := decode(t, b.Exchange(initialize()))
	if answer["result"] == nil {
		t.Fatalf("initialize failed while the app was unavailable: %v", answer)
	}
	result := answer["result"].(map[string]any)
	if result["protocolVersion"] != data.ProtocolVersion {
		t.Errorf("protocolVersion = %v, want %s", result["protocolVersion"], data.ProtocolVersion)
	}
	if !strings.Contains(result["instructions"].(string), "write a bridge of your own") {
		t.Errorf("initialize does not say to stay on these tools: %v", result["instructions"])
	}
	info := result["serverInfo"].(map[string]any)
	if info["version"] != "offline" {
		t.Errorf("serverInfo.version = %v, want offline", info["version"])
	}
}

// Every answer is one line: MCP's stdio framing is newline-delimited, and a pretty-printed
// catalogue once put the newlines of the generated JSON into the tools/list answer. A client read
// `{"tools":[` and saw an empty list.
func TestAnswersAreOneLine(t *testing.T) {
	b := bridge(t, t.TempDir())
	for _, message := range []string{initialize(), listTools(), callTool(), `{"jsonrpc":"2.0","id":9,"method":"server/discover"}`} {
		answer := b.Exchange(message)
		if answer == nil {
			t.Fatalf("%s got no answer", message)
		}
		if strings.Contains(*answer, "\n") {
			t.Errorf("%s was answered over %d lines", message, strings.Count(*answer, "\n")+1)
		}
	}
}

// The list is the real one, in the order PHP advertises it.
func TestUnavailableCatalogue(t *testing.T) {
	b := bridge(t, t.TempDir())
	result := decode(t, b.Exchange(listTools()))["result"].(map[string]any)
	var names []string
	for _, raw := range result["tools"].([]any) {
		names = append(names, raw.(map[string]any)["name"].(string))
	}
	want := "list_connections,current_connection,list_databases,list_tables,describe_table,preview_table_data,execute_query"
	if strings.Join(names, ",") != want {
		t.Errorf("tools = %v, want %s", names, want)
	}
}

// Described offline as the settings say, since a client keeps the list it got at connect.
func TestUnavailableCatalogueFollowsSettings(t *testing.T) {
	dir := t.TempDir()
	settings(t, dir, map[string]any{"mcp": true, "mcp_write": true})
	b := bridge(t, dir)
	result := decode(t, b.Exchange(listTools()))["result"].(map[string]any)
	query := findTool(t, result, "execute_query")
	if !strings.Contains(query["description"].(string), "is committed") {
		t.Errorf("execute_query offline does not mention committed writes: %v", query["description"])
	}
}

// The app reads these with PHP's (bool), so a hand-edited 1 or "1" means on there and must here.
func TestSettingsReadAsPHPDoes(t *testing.T) {
	for _, tc := range []struct {
		value any
		on    bool
	}{
		{true, true}, {false, false}, {nil, false},
		{1, true}, {0, false}, {0.5, true},
		{"1", true}, {"0", false}, {"", false}, {"false", true}, {" 0", true},
		{[]any{}, false}, {[]any{0}, true}, {map[string]any{}, false},
	} {
		dir := t.TempDir()
		settings(t, dir, map[string]any{"mcp_write": tc.value})
		if write, _ := bridge(t, dir).settings(); write != tc.on {
			t.Errorf("mcp_write %#v read as %v, PHP reads %v", tc.value, write, tc.on)
		}
	}
}

// A handshake with no token cannot get past the gate, so it reads as none, as it does in PHP.
func TestEmptyTokenIsNoHandshake(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "mcp.json"), []byte(`{"url":"http://127.0.0.1:1/","token":""}`), 0o600); err != nil {
		t.Fatal(err)
	}
	result := decode(t, bridge(t, dir).Exchange(callTool()))["result"].(map[string]any)
	said := result["content"].([]any)[0].(map[string]any)["text"].(string)
	if !strings.Contains(said, "AI access") {
		t.Errorf("an empty token was not read as no handshake: %s", said)
	}
}

func findTool(t *testing.T, result map[string]any, name string) map[string]any {
	t.Helper()
	for _, raw := range result["tools"].([]any) {
		tool := raw.(map[string]any)
		if tool["name"] == name {
			return tool
		}
	}
	t.Fatalf("no %s in the catalogue", name)
	return nil
}

// The explanation belongs where a client renders it: a tool result, not a transport error.
func TestUnavailableCallIsAToolError(t *testing.T) {
	b := bridge(t, t.TempDir())
	result := decode(t, b.Exchange(callTool()))["result"].(map[string]any)
	if result["isError"] != true {
		t.Fatalf("a call while unavailable was not a tool error: %v", result)
	}
	said := result["content"].([]any)[0].(map[string]any)["text"].(string)
	if !strings.Contains(said, "AI access") {
		t.Errorf("the tool error does not say where to switch it on: %s", said)
	}
}

// JSON-RPC answers a notification with silence; an error here is a protocol violation.
func TestNotificationIsSilent(t *testing.T) {
	b := bridge(t, t.TempDir())
	if answer := b.Exchange(notification()); answer != nil {
		t.Errorf("a notification was answered with %q", *answer)
	}
	if answer := b.Exchange(``); answer != nil {
		t.Errorf("a blank line was answered with %q", *answer)
	}
}

// A legacy server answers a modern probe with an implementation-defined error, and the client
// then falls back to initialize. A DiscoverResult here would promise an era the app does not speak.
func TestDiscoverAnswersLegacy(t *testing.T) {
	b := bridge(t, t.TempDir())
	answer := decode(t, b.Exchange(`{"jsonrpc":"2.0","id":1,"method":"server/discover"}`))
	err, ok := answer["error"].(map[string]any)
	if !ok || err["code"] != float64(-32601) {
		t.Fatalf("server/discover was not answered -32601: %v", answer)
	}
}

// The window closed between the handshake and the call.
func TestWindowClosed(t *testing.T) {
	dir := t.TempDir()
	dead := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	url := dead.URL + "/adminer.php?pgsql=x"
	dead.Close()
	handshake(t, dir, url)
	result := decode(t, bridge(t, dir).Exchange(callTool()))["result"].(map[string]any)
	said := result["content"].([]any)[0].(map[string]any)["text"].(string)
	if !strings.Contains(said, "stopped answering") {
		t.Errorf("a closed window does not say so: %s", said)
	}
}

// said is the text of a tool-error answer.
func said(t *testing.T, answer *string) string {
	t.Helper()
	result := decode(t, answer)["result"].(map[string]any)
	return result["content"].([]any)[0].(map[string]any)["text"].(string)
}

// app answers every call with one status and body.
func app(t *testing.T, status int, body string) string {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(status)
		reply(t, w, body)
	}))
	t.Cleanup(server.Close)
	return server.URL + "/adminer.php?pgsql=x"
}

// A request owed an answer that got an empty one — PHP died, the gate had nothing to say — is
// told so, or the agent waits on its id for good. A notification still gets silence.
func TestEmptyAnswerToARequestIsReported(t *testing.T) {
	dir := t.TempDir()
	handshake(t, dir, app(t, http.StatusOK, ""))
	if got := said(t, bridge(t, dir).Exchange(callTool())); !strings.Contains(got, "stopped answering") {
		t.Errorf("an empty answer to a request: %s", got)
	}
	if got := bridge(t, dir).Exchange(notification()); got != nil {
		t.Errorf("a notification was answered with %s", *got)
	}
}

// An error status is not passed off as the app's answer, whatever its body looks like.
func TestErrorStatusIsNotAnAnswer(t *testing.T) {
	dir := t.TempDir()
	handshake(t, dir, app(t, http.StatusBadGateway, `{"jsonrpc":"2.0","id":3,"result":{"forged":true}}`))
	if got := said(t, bridge(t, dir).Exchange(callTool())); !strings.Contains(got, "stopped answering") {
		t.Errorf("a 502 with JSON: %s", got)
	}
	handshake(t, dir, app(t, http.StatusForbidden, `{}`))
	if got := said(t, bridge(t, dir).Exchange(callTool())); !strings.Contains(got, "expired") {
		t.Errorf("the gate's 403: %s", got)
	}
}

// A BOM or whitespace around an answer does not make it a login page, nor a second line.
func TestBOMIsNotALoginPage(t *testing.T) {
	const answer = `{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}`
	dir := t.TempDir()
	handshake(t, dir, app(t, http.StatusOK, "\uFEFF \n"+answer+"\n"))
	if got := bridge(t, dir).Exchange(listTools()); got == nil || *got != answer {
		t.Errorf("a padded answer came back as %v", got)
	}
}

// Only ever the gate, on this machine: anywhere else would get the token and the SQL.
func TestOffMachineHandshakeIsNotFollowed(t *testing.T) {
	for _, url := range []string{"http://example.com/adminer.php?pgsql=x", "https://127.0.0.1:1/", "http://localhost.evil:1/"} {
		dir := t.TempDir()
		handshake(t, dir, url)
		if got := said(t, bridge(t, dir).Exchange(callTool())); !strings.Contains(got, "AI access") {
			t.Errorf("%s was followed: %s", url, got)
		}
	}
}

// With a socket in the handshake the call goes there and nowhere else: a quit app's port can be
// bound by anyone, its socket cannot.
func TestPostsOverTheSocket(t *testing.T) {
	short, err := os.MkdirTemp("", "mb") // a unix socket path has to stay under ~100 bytes
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { closeOrFail(t, closerFunc(func() error { return os.RemoveAll(short) })) })
	socket := filepath.Join(short, "call.sock")
	ln, err := net.Listen("unix", socket)
	if err != nil {
		t.Fatal(err)
	}
	server := &httptest.Server{Listener: ln, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reply(t, w, `{"jsonrpc":"2.0","id":7,"result":{"via":"socket"}}`)
	})}}
	server.Start()
	stranger := app(t, http.StatusOK, `{"jsonrpc":"2.0","id":7,"result":{"via":"port"}}`)

	dir := t.TempDir()
	write := func(socket string) {
		body := fmt.Sprintf(`{"url":%q,"token":"tok","socket":%q}`, stranger, socket)
		if err := os.WriteFile(filepath.Join(dir, "mcp.json"), []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	write(socket)
	if got := bridge(t, dir).Exchange(listTools()); got == nil || !strings.Contains(*got, "socket") {
		t.Errorf("the call did not go over the socket: %v", got)
	}
	server.Close() // the app quit: its socket is gone, and the port is someone else's now
	if got := bridge(t, dir).Exchange(callTool()); got == nil || strings.Contains(*got, "port") || !strings.Contains(*got, `"isError":true`) {
		t.Errorf("a gone socket fell back to the port: %v", got)
	}
}

// A socket in a directory another account could have made is not dialled: on Linux the gate's
// directory is in the shared /tmp, and after the app has gone anyone can recreate the path.
func TestSocketInAnOpenDirectoryIsRefused(t *testing.T) {
	short, err := os.MkdirTemp("", "mb")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { closeOrFail(t, closerFunc(func() error { return os.RemoveAll(short) })) })
	if err := os.Chmod(short, 0o755); err != nil { // what a directory made by someone else looks like to us, short of a second account
		t.Fatal(err)
	}
	socket := filepath.Join(short, "call.sock")
	ln, err := net.Listen("unix", socket)
	if err != nil {
		t.Fatal(err)
	}
	reached := false
	server := &httptest.Server{Listener: ln, Config: &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reached = true
		reply(t, w, `{"jsonrpc":"2.0","id":3,"result":{}}`)
	})}}
	server.Start()
	defer server.Close()
	dir := t.TempDir()
	body := fmt.Sprintf(`{"url":"http://127.0.0.1:1/adminer.php?pgsql=x","token":"tok","socket":%q}`, socket)
	if err := os.WriteFile(filepath.Join(dir, "mcp.json"), []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	if got := said(t, bridge(t, dir).Exchange(callTool())); !strings.Contains(got, "AI access") || reached {
		t.Errorf("a socket in an open directory was dialled (reached %v): %s", reached, got)
	}
}

// A cancelled call gets no answer, and the window is hung up on rather than left working on it.
func TestCancelledCallIsDropped(t *testing.T) {
	asked, released := make(chan struct{}), make(chan struct{})
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(readBody(t, r), "tools/call") {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		close(asked)
		select {
		case <-r.Context().Done():
			close(released)
		case <-time.After(5 * time.Second):
		}
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	pr, pw := io.Pipe()
	out := new(syncBuffer)
	done := make(chan error, 1)
	go func() { done <- bridge(t, dir).Run(pr, out) }()
	if _, err := io.WriteString(pw, callTool()+"\n"); err != nil {
		t.Fatal(err)
	}
	<-asked // the window is working on it now
	if _, err := io.WriteString(pw, `{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":3}}`+"\n"); err != nil {
		t.Fatal(err)
	}
	select {
	case <-released:
	case <-time.After(2 * time.Second):
		t.Error("the window was left working on a call the client cancelled")
	}
	closeOrFail(t, pw)
	if err := <-done; err != nil {
		t.Fatal(err)
	}
	if out.String() != "" {
		t.Errorf("a cancelled call was answered: %q", out.String())
	}
}

// More calls than run at once still leave a ping free to answer.
func TestPingIsNotQueuedBehindCalls(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := readBody(t, r)
		if strings.Contains(body, "tools/call") {
			time.Sleep(400 * time.Millisecond)
		}
		reply(t, w, `{"jsonrpc":"2.0","id":1,"result":{}}`)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	pr, pw := io.Pipe()
	out := new(syncBuffer)
	done := make(chan error, 1)
	go func() { done <- bridge(t, dir).Run(pr, out) }()
	for i := 0; i < maxCalls*3; i++ {
		if _, err := io.WriteString(pw, callTool()+"\n"); err != nil {
			t.Fatal(err)
		}
	}
	start := time.Now()
	if _, err := io.WriteString(pw, `{"jsonrpc":"2.0","id":99,"method":"ping"}`+"\n"); err != nil {
		t.Fatal(err)
	}
	waitFor(t, out, 1)
	if took := time.Since(start); took > 300*time.Millisecond {
		t.Errorf("the ping waited %v behind %d calls", took, maxCalls*3)
	}
	closeOrFail(t, pw)
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

type closerFunc func() error

func (f closerFunc) Close() error { return f() }

// A redirect is not followed: Go would carry the token to another port on the same host.
func TestRedirectIsNotFollowed(t *testing.T) {
	reached := false
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reached = true
		reply(t, w, `{}`)
	}))
	defer elsewhere.Close()
	redirect := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, elsewhere.URL, http.StatusTemporaryRedirect)
	}))
	defer redirect.Close()
	dir := t.TempDir()
	handshake(t, dir, redirect.URL+"/adminer.php?pgsql=x")
	if got := said(t, bridge(t, dir).Exchange(callTool())); !strings.Contains(got, "expired") {
		t.Errorf("a redirect: %s", got)
	}
	if reached {
		t.Error("the redirect was followed, token and all")
	}
}

// A ping is answered while a slow call is still running, not after it.
func TestPingIsNotHeldBehindASlowCall(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body := readBody(t, r)
		if strings.Contains(body, "tools/call") {
			time.Sleep(300 * time.Millisecond)
			reply(t, w, `{"jsonrpc":"2.0","id":3,"result":{}}`)
			return
		}
		reply(t, w, `{"jsonrpc":"2.0","id":4,"result":{}}`)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	out := new(bytes.Buffer)
	input := callTool() + "\n" + `{"jsonrpc":"2.0","id":4,"method":"ping"}` + "\n"
	if err := bridge(t, dir).Run(strings.NewReader(input), out); err != nil {
		t.Fatal(err)
	}
	lines := strings.Split(strings.TrimSpace(out.String()), "\n")
	if len(lines) != 2 || !strings.Contains(lines[0], `"id":4`) {
		t.Errorf("the ping waited behind the call: %q", lines)
	}
}

// HTML back means the session expired, and the agent is told rather than handed markup to guess at.
func TestHTMLMeansExpiredSession(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reply(t, w, `<!doctype html><title>Login</title>`)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	result := decode(t, bridge(t, dir).Exchange(callTool()))["result"].(map[string]any)
	said := result["content"].([]any)[0].(map[string]any)["text"].(string)
	if !strings.Contains(said, "expired") {
		t.Errorf("a login page does not read as an expired session: %s", said)
	}
}

// An empty answer is a notification the app answered with 204: nothing to forward.
func TestEmptyAnswerForwardsNothing(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	if answer := bridge(t, dir).Exchange(notification()); answer != nil {
		t.Errorf("an empty answer was forwarded as %q", *answer)
	}
}

// A JSON answer is forwarded exactly as the server said it.
func TestJSONAnswerIsUnchanged(t *testing.T) {
	const answer = `{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}`
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reply(t, w, answer)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	got := bridge(t, dir).Exchange(listTools())
	if got == nil || *got != answer {
		t.Errorf("the bridge reshaped what the server said: %v", got)
	}
}

// It posts where the handshake says, with mcp=1 and the token, and the message unchanged.
func TestPostsToTheHandshake(t *testing.T) {
	var uri, auth, body string
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		uri, auth = r.URL.RequestURI(), r.Header.Get("Authorization")
		body = readBody(t, r)
		reply(t, w, `{}`)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x&db=demo&")
	bridge(t, dir).Exchange(listTools())
	for _, needle := range []string{"mcp=1", "db=demo"} {
		if !strings.Contains(uri, needle) {
			t.Errorf("posted to %q, with no %s", uri, needle)
		}
	}
	if strings.Contains(uri, "&&") {
		t.Errorf("posted to %q, with an empty query pair", uri)
	}
	if body != listTools() {
		t.Errorf("the bridge changed the message on the way through: %q", body)
	}
	if auth != "Bearer tok" {
		t.Errorf("the gate was shown %q, not its token", auth)
	}
}

// run() pumps a stream: blank lines skipped, one answer per request, silence stays silent.
func TestStreamPumping(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.Contains(readBody(t, r), `"id"`) {
			reply(t, w, `{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}`)
		}
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	input := listTools() + "\n\n" + notification() + "\n" + listTools() + "\n"
	out := new(bytes.Buffer)
	if err := bridge(t, dir).Run(strings.NewReader(input), out); err != nil {
		t.Fatal(err)
	}
	if strings.Count(out.String(), `"result"`) != 2 || strings.Count(out.String(), "\n") != 2 {
		t.Errorf("the stream was answered as: %q", out.String())
	}
}

// A quiet spell on a socket stdin is not the client letting go. The launcher's bridge reads a
// socket as readily as a pipe, and nothing in this package sets a read deadline — the PHP one
// dropped after default_socket_timeout until https://github.com/landsman/adminer-desktop/pull/183.
//
// Seconds from MCP_BRIDGE_QUIET_SECONDS (default 1); the issue asks for 90 s once by hand.
func TestQuietStdinSurvives(t *testing.T) {
	quiet := time.Second
	if seconds, err := strconv.Atoi(os.Getenv("MCP_BRIDGE_QUIET_SECONDS")); err == nil && seconds > 0 {
		quiet = time.Duration(seconds) * time.Second
	}
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reply(t, w, `{"jsonrpc":"2.0","id":7,"result":{}}`)
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")

	agent, bridgeSide := net.Pipe()
	t.Cleanup(func() { closeOrFail(t, agent) }) // a second Close of a net.Pipe end is a no-op
	out := new(syncBuffer)
	done := make(chan error, 1)
	go func() { done <- bridge(t, dir).Run(bridgeSide, out) }()

	if _, err := agent.Write([]byte(listTools() + "\n")); err != nil {
		t.Fatal(err)
	}
	waitFor(t, out, 1)
	time.Sleep(quiet)
	if _, err := agent.Write([]byte(listTools() + "\n")); err != nil {
		t.Fatal(err) // the bridge closed its end: it gave up during the quiet spell
	}
	waitFor(t, out, 2)
	closeOrFail(t, agent)
	if err := <-done; err != nil {
		t.Fatalf("the bridge ended with %v after a quiet spell", err)
	}
}

// syncBuffer is what Run writes to from its own goroutine; the test reads it while the bridge
// works, which is a plain bytes.Buffer only if the race detector is not looking.
type syncBuffer struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (s *syncBuffer) Write(p []byte) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.Write(p)
}

func (s *syncBuffer) String() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.b.String()
}

func waitFor(t *testing.T, out *syncBuffer, answers int) {
	t.Helper()
	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); time.Sleep(10 * time.Millisecond) {
		if strings.Count(out.String(), `"result"`) >= answers {
			return
		}
	}
	t.Fatalf("waited for %d answers, got %q", answers, out.String())
}

// The offline sentences are translated as the app is, not English whatever the locale.
func TestLocale(t *testing.T) {
	t.Setenv("LANG", "cs_CZ.UTF-8")
	b := NewIn(t.TempDir())
	result := decode(t, b.Exchange(callTool()))["result"].(map[string]any)
	said := result["content"].([]any)[0].(map[string]any)["text"].(string)
	if !strings.Contains(said, "neběží") {
		t.Errorf("LANG=cs_CZ.UTF-8 did not answer in Czech: %s", said)
	}
}

// The data dir is the launcher's whatever the environment says: a shell with
// ADMINER_DESKTOP_DATA left over from `make serve` must not point an agent somewhere else.
func TestFindsTheLaunchersDataDir(t *testing.T) {
	home := t.TempDir()
	t.Setenv("HOME", home)
	t.Setenv("XDG_CONFIG_HOME", filepath.Join(home, ".config"))
	t.Setenv("ADMINER_DESKTOP_DATA", t.TempDir()) // empty: reading it would say "not running"
	dir, err := datadir.Path()
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		reply(t, w, `{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}`)
	}))
	defer app.Close()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	if got := New().Exchange(listTools()); got == nil || *got != `{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}` {
		t.Errorf("the bridge did not use the launcher's data dir: %v", got)
	}
}

// Offline initialize meets a client where Server::negotiate() would: an older version back, a
// newer one or none told ours.
func TestOfflineInitializeNegotiates(t *testing.T) {
	for asked, want := range map[string]string{
		`"2025-06-18"`: "2025-06-18",
		`"2099-01-01"`: data.ProtocolVersion,
		`""`:           data.ProtocolVersion,
		`5`:            data.ProtocolVersion,
	} {
		line := `{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":` + asked + `}}`
		got := decode(t, bridge(t, t.TempDir()).Exchange(line))["result"].(map[string]any)["protocolVersion"]
		if got != want {
			t.Errorf("asked %s, answered %v, want %s", asked, got, want)
		}
	}
}

// A method that is not a string still has an id owed an answer; PHP reads it as no method.
func TestNonStringMethodIsAnswered(t *testing.T) {
	answer := bridge(t, t.TempDir()).Exchange(`{"jsonrpc":"2.0","id":1,"method":5}`)
	if answer == nil {
		t.Fatal("a request with a numeric method got no answer, so the client waits on id 1")
	}
	if decode(t, answer)["result"].(map[string]any)["isError"] != true {
		t.Errorf("not the tool error PHP gives: %s", *answer)
	}
}

// The timeout is idle, as PHP's is: a body still arriving past it is not a closed window.
func TestSlowBodyIsNotAClosedWindow(t *testing.T) {
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		flusher := w.(http.Flusher)
		for _, part := range []string{`{"jsonrpc":"2.0",`, `"id":7,`, `"result":{}}`} {
			reply(t, w, part)
			flusher.Flush()
			time.Sleep(60 * time.Millisecond)
		}
	}))
	defer app.Close()
	dir := t.TempDir()
	handshake(t, dir, app.URL+"/adminer.php?pgsql=x")
	b := bridge(t, dir)
	b.idle = 100 * time.Millisecond // under the whole 180 ms, over each 60 ms gap
	if got := b.Exchange(listTools()); got == nil || *got != `{"jsonrpc":"2.0","id":7,"result":{}}` {
		t.Errorf("a slow body was cut off: %v", got)
	}

	silent := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(300 * time.Millisecond)
	}))
	defer silent.Close()
	handshake(t, dir, silent.URL+"/adminer.php?pgsql=x")
	result := decode(t, b.Exchange(callTool()))["result"].(map[string]any)
	if said := result["content"].([]any)[0].(map[string]any)["text"].(string); !strings.Contains(said, "may still have been applied") {
		t.Errorf("a silent window was not reported as timed out, with a write possibly applied: %s", said)
	}
}

// Every sentence the bridge can say exists in English, and the catalogue has all four settings
// combinations: a generator that missed one would otherwise fail only at runtime.
func TestGeneratedDataIsComplete(t *testing.T) {
	for _, key := range []string{"00", "01", "10", "11"} {
		if _, ok := data.Catalogue[key]; !ok {
			t.Errorf("no catalogue for %s", key)
		}
	}
	for _, id := range []string{"mcp.agent_not_running", "mcp.agent_window_closed", "mcp.agent_session_expired", "mcp.agent_unknown_method", "mcp.agent_timed_out"} {
		if data.Sentences["en"][id] == "" {
			t.Errorf("no English text for %s", id)
		}
	}
}
