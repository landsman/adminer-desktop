// Package mcpgate is where an agent's MCP calls reach the window's session without the session
// ever being written down.
//
// Adminer keeps the database passwords in the PHP session, encrypted with a key that lives in a
// cookie (adminer_key). Whoever holds that cookie and the session id can read them back, so
// neither may sit in a file the agent's own OS user can read — which once was mcp.json. Instead
// the PHP side reports the two cookies over a pipe only this process holds (fd 3 in frankenphp,
// see Desktop\Mcp\Endpoint::report()), and the bridge an agent spawns posts here with a token.
// The gate adds the cookies on the way to frankenphp. The token opens the MCP endpoint and
// nothing else; the cookies stay in this process's memory.
//
// Pure Go and no cgo, so the e2e harness runs the same gate (tests/e2e/harness/gate) on a CI
// machine that has no WebKit to build the launcher against.
package mcpgate

import (
	"bufio"
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"os"
	"strings"
	"sync"
)

// Gate holds the window's session and lets a token-bearing MCP call borrow it.
type Gate struct {
	upstream string // frankenphp, http://127.0.0.1:port
	token    string
	ln       net.Listener
	session  *os.File // the read end PHP reports on

	mu     sync.Mutex
	cookie string // the Cookie header to replay, empty until the window logs in
}

// New opens the listener the bridge posts to and the pipe PHP reports the session on. The pipe's
// write end goes to frankenphp as its first extra file (fd 3); Env says so to PHP.
func New(upstream string) (*Gate, *os.File, error) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, nil, err
	}
	r, w, err := os.Pipe()
	if err != nil {
		ln.Close() //nolint:errcheck // giving up anyway
		return nil, nil, err
	}
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		ln.Close() //nolint:errcheck
		return nil, nil, err
	}
	return &Gate{upstream: upstream, token: hex.EncodeToString(b), ln: ln, session: r}, w, nil
}

// Env is what PHP needs to point the handshake here: the address, the token the bridge must
// show, and which descriptor to report the session on. None of it unlocks a password.
func (g *Gate) Env() []string {
	return []string{
		"ADMINER_DESKTOP_MCP_GATE=" + g.ln.Addr().String(),
		"ADMINER_DESKTOP_MCP_TOKEN=" + g.token,
		"ADMINER_DESKTOP_SESSION_FD=3",
	}
}

// Serve reads session reports and answers the bridge until the process ends.
func (g *Gate) Serve() {
	go g.listen(g.session)
	http.Serve(g.ln, g) //nolint:errcheck // ends with the process
}

// listen takes one JSON line per report, {"cookie": "..."}; an empty cookie is the feature being
// switched off, or the window logging out. A line that does not parse is dropped: the next page
// the window loads reports again.
func (g *Gate) listen(r io.Reader) {
	s := bufio.NewScanner(r)
	for s.Scan() {
		var report struct {
			Cookie string `json:"cookie"`
		}
		if json.Unmarshal(s.Bytes(), &report) == nil {
			g.mu.Lock()
			g.cookie = report.Cookie
			g.mu.Unlock()
		}
	}
}

func (g *Gate) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// A web page in a browser can post to loopback too. It cannot read mcp.json, so it has no
	// token; and it can only reach us under our own address, never a rebound hostname.
	got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	if r.Method != http.MethodPost || r.Host != g.ln.Addr().String() ||
		subtle.ConstantTimeCompare([]byte(got), []byte(g.token)) != 1 {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	// Only the MCP endpoint: a call that is not one would be a page of adminer with the window's
	// session behind it, which is exactly what the token must not open.
	if r.URL.Query().Get("mcp") != "1" {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	req, err := http.NewRequest(http.MethodPost, g.upstream+r.URL.RequestURI(), r.Body)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	req.Header.Set("Content-Type", "application/json")
	g.mu.Lock()
	cookie := g.cookie
	g.mu.Unlock()
	if cookie != "" {
		req.Header.Set("Cookie", cookie)
	}
	// RoundTrip, not a client: a redirect is the bridge's to follow, through us again.
	resp, err := http.DefaultTransport.RoundTrip(req)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close() //nolint:errcheck // relaying
	for _, h := range []string{"Content-Type", "Location"} {
		if v := resp.Header.Get(h); v != "" {
			w.Header().Set(h, v)
		}
	}
	w.WriteHeader(resp.StatusCode)
	io.Copy(w, resp.Body) //nolint:errcheck // the bridge sees a short body as a failed call
}
