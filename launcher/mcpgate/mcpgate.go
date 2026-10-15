// Package mcpgate is where an agent's MCP calls reach the window's session without the session
// ever being written down.
//
// Adminer keeps the database passwords in the PHP session, encrypted with a key that lives in a
// cookie (adminer_key). Whoever holds that cookie and the session id can read them back, so
// neither may sit in a file the agent's own OS user can read — which once was mcp.json. Instead
// the PHP side reports the two cookies to a unix socket only this process reads
// (Desktop\Mcp\Endpoint::report()), and the bridge an agent spawns posts here with a token. The
// gate adds the cookies on the way to frankenphp. The token opens the MCP endpoint and nothing
// else; the cookies stay in this process's memory.
//
// A socket and not an inherited descriptor: frankenphp is a thread-safe PHP build, which resolves
// symlinks in every path it opens, so on Linux /dev/fd/3 (a link into /proc) never opens, and
// php://fd is CLI-only. Anyone who can connect can send a report but never read one back, and a
// web page cannot reach a unix socket at all.
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
	"path/filepath"
	"strings"
	"sync"
)

// Gate holds the window's session and lets a token-bearing MCP call borrow it.
type Gate struct {
	upstream string // frankenphp, http://127.0.0.1:port
	token    string
	ln       net.Listener // where the bridge posts
	reports  net.Listener // where PHP says which session to lend
	dir      string       // the reports socket's private directory

	mu     sync.Mutex
	cookie string // the Cookie header to add, empty until the window logs in
}

// New opens the listener the bridge posts to and the socket PHP reports the session on.
func New(upstream string) (*Gate, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return nil, err
	}
	// MkdirTemp makes it 0700, so no other account can even connect.
	dir, err := os.MkdirTemp("", "adminer-desktop-gate-")
	if err != nil {
		return nil, err
	}
	reports, err := net.Listen("unix", filepath.Join(dir, "report.sock"))
	if err != nil {
		os.RemoveAll(dir) //nolint:errcheck // giving up anyway
		return nil, err
	}
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		reports.Close()   //nolint:errcheck
		os.RemoveAll(dir) //nolint:errcheck
		return nil, err
	}
	return &Gate{upstream: upstream, token: hex.EncodeToString(b), ln: ln, reports: reports, dir: dir}, nil
}

// Env is what PHP needs to point the handshake here: the address, the token the bridge must
// show, and the socket to report the session on. None of it unlocks a password.
func (g *Gate) Env() []string {
	return []string{
		"ADMINER_DESKTOP_MCP_GATE=" + g.ln.Addr().String(),
		"ADMINER_DESKTOP_MCP_TOKEN=" + g.token,
		"ADMINER_DESKTOP_MCP_REPORT=" + g.reports.Addr().String(),
	}
}

// Serve takes session reports and answers the bridge until Close.
func (g *Gate) Serve() {
	go func() {
		for {
			c, err := g.reports.Accept()
			if err != nil {
				return
			}
			go g.listen(c)
		}
	}()
	http.Serve(g.ln, g) //nolint:errcheck // ends with Close
}

// Close stops both listeners and removes the socket's directory.
func (g *Gate) Close() {
	g.ln.Close()        //nolint:errcheck // shutting down
	g.reports.Close()   //nolint:errcheck
	os.RemoveAll(g.dir) //nolint:errcheck
}

// listen takes one JSON line per report, {"cookie": "..."}; an empty cookie is the feature being
// switched off. A line that does not parse is dropped: the next page the window loads reports
// again.
func (g *Gate) listen(c io.ReadCloser) {
	defer c.Close() //nolint:errcheck // one report per connection
	s := bufio.NewScanner(c)
	for s.Scan() {
		var report struct {
			Cookie *string `json:"cookie"`
		}
		if json.Unmarshal(s.Bytes(), &report) == nil && report.Cookie != nil {
			g.mu.Lock()
			g.cookie = *report.Cookie
			g.mu.Unlock()
		}
	}
}

func (g *Gate) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// A web page in a browser can post to loopback too. It cannot read mcp.json, so it has no
	// token; and it can only reach us under our own address, never a rebound hostname. Only the
	// MCP endpoint: anything else would be a page of adminer with the window's session behind
	// it, which is exactly what the token must not open.
	got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	if r.Method != http.MethodPost || r.Host != g.ln.Addr().String() || r.URL.Query().Get("mcp") != "1" ||
		subtle.ConstantTimeCompare([]byte(got), []byte(g.token)) != 1 {
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
