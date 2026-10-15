package mcpgate

import (
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// gate in front of a fake frankenphp that records the Cookie header of every call it gets.
func gate(t *testing.T) (*Gate, *[]string) {
	t.Helper()
	seen := &[]string{}
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*seen = append(*seen, r.Header.Get("Cookie"))
		w.Header().Set("Content-Type", "application/json")
		io.WriteString(w, `{"ok":true}`) //nolint:errcheck
	}))
	t.Cleanup(upstream.Close)
	g, err := New(upstream.URL)
	if err != nil {
		t.Fatal(err)
	}
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
	report(g, `{"cookie":"adminer_sid=s; adminer_key=k"}`)
	cases := map[string]func() *httptest.ResponseRecorder{
		"no token": func() *httptest.ResponseRecorder { return call(g, "POST", "/adminer.php?mcp=1", "") },
		"wrong token": func() *httptest.ResponseRecorder {
			return call(g, "POST", "/adminer.php?mcp=1", strings.Repeat("0", 64))
		},
		"token prefix":     func() *httptest.ResponseRecorder { return call(g, "POST", "/adminer.php?mcp=1", g.token[:10]) },
		"not the endpoint": func() *httptest.ResponseRecorder { return call(g, "POST", "/adminer.php?select=users", g.token) },
		"a GET":            func() *httptest.ResponseRecorder { return call(g, "GET", "/adminer.php?mcp=1", g.token) },
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

// With the token, the call reaches frankenphp carrying the reported session, and only then.
func TestLendsTheReportedSession(t *testing.T) {
	g, seen := gate(t)
	if w := call(g, "POST", "/adminer.php?pgsql=x&mcp=1", g.token); w.Code != 200 || w.Body.String() != `{"ok":true}` {
		t.Fatalf("got %d %q", w.Code, w.Body.String())
	}
	report(g, `{"cookie":"adminer_sid=s; adminer_key=k"}`)
	report(g, `not json`) // dropped, the session stays
	call(g, "POST", "/adminer.php?pgsql=x&mcp=1", g.token)
	report(g, `{"cookie":""}`) // switched off
	call(g, "POST", "/adminer.php?pgsql=x&mcp=1", g.token)
	want := []string{"", "adminer_sid=s; adminer_key=k", ""}
	if strings.Join(*seen, "|") != strings.Join(want, "|") {
		t.Errorf("frankenphp saw cookies %q, want %q", *seen, want)
	}
}

// The socket PHP actually writes to, end to end.
func TestReportsArriveOverTheSocket(t *testing.T) {
	g, _ := gate(t)
	go g.Serve()
	c, err := net.Dial("unix", g.reports.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	io.WriteString(c, `{"cookie":"adminer_sid=s"}`+"\n") //nolint:errcheck
	c.Close()                                            //nolint:errcheck
	for deadline := time.Now().Add(time.Second); time.Now().Before(deadline); time.Sleep(5 * time.Millisecond) {
		g.mu.Lock()
		got := g.cookie
		g.mu.Unlock()
		if got == "adminer_sid=s" {
			return
		}
	}
	t.Error("a report written to the socket never reached the gate")
}
