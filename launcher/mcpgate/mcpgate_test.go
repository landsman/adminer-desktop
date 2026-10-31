package mcpgate

import (
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

// What PHP reports for a window logged in to demo, and where that sends a call.
const (
	endpoint = "/adminer.php?pgsql=x&username=u&db=demo&mcp=1"
	session  = `{"key":"k","cookie":"adminer_sid=s; adminer_key=k","endpoint":"http://127.0.0.1:1` + endpoint + `"}`
)

// gate in front of a fake frankenphp that records what every call it gets asks for, and as whom.
func gate(t *testing.T) (*Gate, *[]string) {
	t.Helper()
	seen := &[]string{}
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*seen = append(*seen, r.URL.RequestURI()+" "+r.Header.Get("Cookie"))
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"ok":true}`) //nolint:errcheck
	}))
	t.Cleanup(upstream.Close)
	g, err := New(upstream.URL)
	if err != nil {
		t.Fatal(err)
	}
	g.key = "k"
	t.Cleanup(g.Close)
	return g, seen
}

func call(g *Gate, method, target, token string) *httptest.ResponseRecorder {
	r := httptest.NewRequest(method, target, strings.NewReader(`{"jsonrpc":"2.0","id":1,"method":"ping"}`))
	r.Host = g.ln.Addr().String()
	if token != "" {
		r.Header.Set("Authorization", "Bearer "+token)
	}
	w := httptest.NewRecorder()
	g.ServeHTTP(w, r)
	return w
}

func report(g *Gate, line string) {
	g.listen(io.NopCloser(strings.NewReader(line + "\n")))
}

// Everything the token is not for is refused before frankenphp sees it: no session goes out.
func TestRefuses(t *testing.T) {
	g, seen := gate(t)
	unreported, _ := gate(t)
	report(g, session)
	cases := map[string]func() *httptest.ResponseRecorder{
		"nothing reported yet": func() *httptest.ResponseRecorder {
			return call(unreported, "POST", "/adminer.php?mcp=1", unreported.token)
		},
		"no token": func() *httptest.ResponseRecorder { return call(g, "POST", "/adminer.php?mcp=1", "") },
		"wrong token": func() *httptest.ResponseRecorder {
			return call(g, "POST", "/adminer.php?mcp=1", strings.Repeat("0", 64))
		},
		"token prefix": func() *httptest.ResponseRecorder { return call(g, "POST", "/adminer.php?mcp=1", g.token[:10]) },
		"a GET":        func() *httptest.ResponseRecorder { return call(g, "GET", "/adminer.php?mcp=1", g.token) },
		"a rebound host": func() *httptest.ResponseRecorder {
			r := httptest.NewRequest("POST", "/adminer.php?mcp=1", nil)
			r.Host = "evil.example:80"
			r.Header.Set("Authorization", "Bearer "+g.token)
			w := httptest.NewRecorder()
			g.ServeHTTP(w, r)
			return w
		},
	}
	for name, do := range cases {
		if w := do(); w.Code != http.StatusForbidden {
			t.Errorf("%s: got %d, want 403", name, w.Code)
		}
	}
	if len(*seen) != 0 {
		t.Errorf("frankenphp was called %d times through refused requests", len(*seen))
	}
}

// With the token, the call reaches frankenphp carrying the reported session, and only while one
// is reported.
func TestLendsTheReportedSession(t *testing.T) {
	g, seen := gate(t)
	report(g, session)
	report(g, `not json`) // dropped, the session stays
	if w := call(g, "POST", "/adminer.php?pgsql=x&mcp=1", g.token); w.Code != 200 || w.Body.String() != `{"ok":true}` {
		t.Fatalf("got %d %q", w.Code, w.Body.String())
	}
	report(g, `{"key":"k","cookie":"","endpoint":""}`) // switched off
	if w := call(g, "POST", "/adminer.php?pgsql=x&mcp=1", g.token); w.Code != http.StatusForbidden {
		t.Errorf("switched off: got %d, want 403", w.Code)
	}
	want := []string{endpoint + " adminer_sid=s; adminer_key=k"}
	if strings.Join(*seen, "|") != strings.Join(want, "|") {
		t.Errorf("frankenphp saw %q, want %q", *seen, want)
	}
}

// Whatever the call asks for, frankenphp is asked for the endpoint PHP reported. Adminer answers
// ?download= before the hook that answers ?mcp=1 runs, so passing the path on handed any column,
// with the window's session, to a token meant to open the MCP endpoint only.
func TestOpensOnlyTheReportedEndpoint(t *testing.T) {
	g, seen := gate(t)
	report(g, session)
	for _, target := range []string{
		"/adminer.php?pgsql=x&username=u&db=demo&download=users&field=email&where%5Bid%5D=1&mcp=1",
		"/adminer.php?select=users",
		"/api.php?action=resize",
	} {
		call(g, "POST", target, g.token)
	}
	if len(*seen) != 3 {
		t.Fatalf("frankenphp was called %d times, want 3", len(*seen))
	}
	for _, s := range *seen {
		if !strings.HasPrefix(s, endpoint+" ") {
			t.Errorf("frankenphp was asked for %q, want only %q", s, endpoint)
		}
	}
}

// The socket PHP actually writes to, end to end.
func TestReportsArriveOverTheSocket(t *testing.T) {
	g, _ := gate(t)
	go g.Serve()
	c, err := net.Dial(g.reports.Addr().Network(), g.reports.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	io.WriteString(c, session+"\n") //nolint:errcheck
	c.Close()                       //nolint:errcheck
	for deadline := time.Now().Add(time.Second); time.Now().Before(deadline); time.Sleep(5 * time.Millisecond) {
		g.mu.Lock()
		got := g.cookie
		g.mu.Unlock()
		if got == "adminer_sid=s; adminer_key=k" {
			return
		}
	}
	t.Error("a report written to the socket never reached the gate")
}

// Anything local can reach a loopback port, and a page's post arrives as lines too.
func TestAReportWithoutTheKeyIsDropped(t *testing.T) {
	g, _ := gate(t)
	report(g, session)
	report(g, `{"cookie":"forged=1","endpoint":"http://127.0.0.1:1/adminer.php?download=1"}`)
	report(g, `{"key":"wrong","cookie":"forged=1","endpoint":"http://127.0.0.1:1/adminer.php?download=1"}`)
	if g.cookie != "adminer_sid=s; adminer_key=k" || g.endpoint != endpoint {
		t.Errorf("a forged report replaced the session: %q %q", g.cookie, g.endpoint)
	}
}

// The address the launcher hands out has to open in the PHP that ships: a unix:// one does not on
// Windows, and nothing but the bundled binary can say so.
func TestBundledPHPReaches(t *testing.T) {
	php := filepath.Join("..", "..", "bin", "frankenphp")
	if runtime.GOOS == "windows" {
		php += ".exe"
	}
	if _, err := os.Stat(php); err != nil {
		t.Skip("no bundled frankenphp: make fetch")
	}
	g, _ := gate(t)
	go g.Serve()
	script := filepath.Join(t.TempDir(), "report.php")
	code := `<?php $g = stream_socket_client(getenv("ADMINER_DESKTOP_MCP_REPORT"), $n, $e, 1) or exit(1);
fwrite($g, json_encode(["key" => getenv("ADMINER_DESKTOP_MCP_REPORT_KEY"), "cookie" => "from=php", "endpoint" => ""]) . "\n");`
	if err := os.WriteFile(script, []byte(code), 0o600); err != nil {
		t.Fatal(err)
	}
	cmd := exec.Command(php, "php-cli", script)
	cmd.Env = append(os.Environ(), g.Env()...)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("php could not connect: %v\n%s", err, out)
	}
	for deadline := time.Now().Add(time.Second); time.Now().Before(deadline); time.Sleep(5 * time.Millisecond) {
		g.mu.Lock()
		got := g.cookie
		g.mu.Unlock()
		if got == "from=php" {
			return
		}
	}
	t.Error("php's report never reached the gate")
}

// The bridge's own way in: the socket in the gate's private directory, under the TCP address's
// name, which a quit app's port cannot be taken over to fake.
func TestAnswersOverTheCallSocket(t *testing.T) {
	g, seen := gate(t)
	report(g, session)
	go g.Serve()
	client := &http.Client{Transport: &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", g.calls.Addr().String())
		},
	}}
	req, err := http.NewRequest("POST", "http://"+g.ln.Addr().String()+"/adminer.php?mcp=1", strings.NewReader(`{}`))
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+g.token)
	resp, err := client.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	body, err := io.ReadAll(resp.Body)
	if err = errors.Join(err, resp.Body.Close()); err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 || string(body) != `{"ok":true}` || len(*seen) != 1 {
		t.Errorf("over the socket: %d %q, frankenphp saw %q", resp.StatusCode, body, *seen)
	}
}

// When the bridge gives up, so does the call to frankenphp, rather than a statement running on
// with nobody left to hear how it ended.
func TestGivingUpReachesFrankenphp(t *testing.T) {
	cancelled := make(chan struct{})
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// A Go server notices a closed connection only once the body is read, as PHP has by then.
		if _, err := io.ReadAll(r.Body); err != nil {
			t.Error(err)
		}
		select {
		case <-r.Context().Done():
			close(cancelled)
		case <-time.After(3 * time.Second):
		}
	}))
	t.Cleanup(upstream.Close)
	g, err := New(upstream.URL)
	if err != nil {
		t.Fatal(err)
	}
	g.key = "k"
	t.Cleanup(g.Close)
	report(g, session)
	ctx, cancel := context.WithCancel(context.Background())
	r := httptest.NewRequest("POST", "/adminer.php?mcp=1", strings.NewReader(`{}`)).WithContext(ctx)
	r.Host = g.ln.Addr().String()
	r.Header.Set("Authorization", "Bearer "+g.token)
	go g.ServeHTTP(httptest.NewRecorder(), r)
	time.Sleep(50 * time.Millisecond)
	cancel()
	select {
	case <-cancelled:
	case <-time.After(2 * time.Second):
		t.Error("frankenphp kept the call after the bridge gave up")
	}
}

// The same, through the socket the bridge really uses: a client that hangs up is what cancels the
// request, not a context a test hands in.
func TestHangingUpOnTheSocketReachesFrankenphp(t *testing.T) {
	cancelled := make(chan struct{})
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if _, err := io.ReadAll(r.Body); err != nil {
			t.Error(err)
		}
		select {
		case <-r.Context().Done():
			close(cancelled)
		case <-time.After(3 * time.Second):
		}
	}))
	t.Cleanup(upstream.Close)
	g, err := New(upstream.URL)
	if err != nil {
		t.Fatal(err)
	}
	g.key = "k"
	t.Cleanup(g.Close)
	report(g, session)
	go g.Serve()
	ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer cancel()
	client := &http.Client{Transport: &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", g.calls.Addr().String())
		},
	}}
	req, err := http.NewRequestWithContext(ctx, "POST", "http://"+g.ln.Addr().String()+"/adminer.php?mcp=1", strings.NewReader(`{}`))
	if err != nil {
		t.Fatal(err)
	}
	req.Header.Set("Authorization", "Bearer "+g.token)
	if resp, err := client.Do(req); err == nil {
		closeBody(t, resp)
		t.Fatal("the call returned before the client gave up")
	}
	select {
	case <-cancelled:
	case <-time.After(2 * time.Second):
		t.Error("frankenphp kept the call after the client hung up on the socket")
	}
}

func closeBody(t *testing.T, resp *http.Response) {
	t.Helper()
	if err := resp.Body.Close(); err != nil {
		t.Error(err)
	}
}

// A directory a quit app left behind goes at the next start; one whose gate still answers stays.
func TestSweepsWhatAQuitAppLeft(t *testing.T) {
	tmp, err := os.MkdirTemp("", "gs") // short: a unix socket path has to stay under ~100 bytes
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if err := os.RemoveAll(tmp); err != nil {
			t.Error(err)
		}
	})
	stale := filepath.Join(tmp, "adminer-desktop-gate-1")
	live := filepath.Join(tmp, "adminer-desktop-gate-2")
	starting := filepath.Join(tmp, "adminer-desktop-gate-3") // made, its sockets not bound yet
	other := filepath.Join(tmp, "something-else")
	for _, dir := range []string{stale, live, starting, other} {
		if err := os.Mkdir(dir, 0o700); err != nil {
			t.Fatal(err)
		}
	}
	dead, err := net.Listen("unix", filepath.Join(stale, "call.sock"))
	if err != nil {
		t.Fatal(err)
	}
	dead.(*net.UnixListener).SetUnlinkOnClose(false) // a quit app leaves its socket file behind
	closeOrFail(t, dead)
	ln, err := net.Listen("unix", filepath.Join(live, "report.sock"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { closeOrFail(t, ln) })
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			closeOrFail(t, c)
		}
	}()

	old := time.Now().Add(-time.Hour)
	for _, dir := range []string{stale, live, other} {
		if err := os.Chtimes(dir, old, old); err != nil {
			t.Fatal(err)
		}
	}
	sweep(tmp)
	for dir, want := range map[string]bool{stale: false, live: true, starting: true, other: true} {
		if _, err := os.Stat(dir); (err == nil) != want {
			t.Errorf("%s: present %v, want %v", filepath.Base(dir), err == nil, want)
		}
	}
}

func closeOrFail(t *testing.T, c io.Closer) {
	t.Helper()
	if err := c.Close(); err != nil {
		t.Error(err)
	}
}
