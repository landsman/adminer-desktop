// Package mcpgate is where an agent's MCP calls reach the window's session without the session
// ever being written down.
//
// Adminer keeps the database passwords in the PHP session, encrypted with a key that lives in a
// cookie (adminer_key). Whoever holds that cookie and the session id can read them back, so
// neither may sit in a file the agent's own OS user can read — which once was mcp.json. Instead
// the PHP side reports the two cookies, and the MCP endpoint's URL, to a unix socket only this
// process reads (Desktop\Mcp\Endpoint::report()), and the bridge an agent spawns posts here with a
// token. The gate sends each call to that endpoint with the cookies added. The token opens the
// MCP endpoint and nothing else; the cookies stay in this process's memory.
//
// The bridge reaches the gate over a second unix socket beside the reports one, and the TCP
// address only names it. A port outlives the app: after a quit, whatever binds it next would get
// every message an agent sends, token and SQL included, and could answer them. A socket in this
// 0700 directory cannot be taken over by another account, and once the app is gone it refuses.
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
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// Gate holds the window's session and lets a token-bearing MCP call borrow it.
type Gate struct {
	upstream string // frankenphp, http://127.0.0.1:port
	token    string
	ln       net.Listener // the address the bridge names in the Host header, and posts to without a socket
	calls    net.Listener // where the bridge posts, a unix socket only this account can reach
	reports  net.Listener // where PHP says which session to lend
	dir      string       // the reports socket's private directory

	mu       sync.Mutex
	cookie   string // the Cookie header to add, empty until the window logs in
	endpoint string // the MCP endpoint's path and query, the only thing the token opens
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
	calls, err := net.Listen("unix", filepath.Join(dir, "call.sock"))
	if err != nil {
		return nil, errors.Join(err, reports.Close(), os.RemoveAll(dir))
	}
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, errors.Join(err, calls.Close(), reports.Close(), os.RemoveAll(dir))
	}
	return &Gate{upstream: upstream, token: hex.EncodeToString(b), ln: ln, calls: calls, reports: reports, dir: dir}, nil
}

// Env is what PHP needs to point the handshake here: the address, the socket the bridge posts
// to, the token it must show, and the socket to report the session on. None of it unlocks a
// password.
func (g *Gate) Env() []string {
	return []string{
		"ADMINER_DESKTOP_MCP_GATE=" + g.ln.Addr().String(),
		"ADMINER_DESKTOP_MCP_SOCKET=" + g.calls.Addr().String(),
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
	// Both end with Close, which is the only error either returns.
	done := make(chan error, 2)
	go func() { done <- http.Serve(g.calls, g) }()
	go func() { done <- http.Serve(g.ln, g) }()
	<-done
}

// Close stops both listeners and removes the socket's directory.
func (g *Gate) Close() {
	// Shutting down: a listener that will not close has nothing left to serve either.
	_ = errors.Join(g.ln.Close(), g.calls.Close(), g.reports.Close(), os.RemoveAll(g.dir))
}

// listen takes one JSON line per report, {"cookie": "...", "endpoint": "http://…?…&mcp=1"}; empty
// is the feature being switched off. A line that does not parse is dropped: the next page the
// window loads reports again.
func (g *Gate) listen(c io.ReadCloser) {
	defer c.Close() //nolint:errcheck // one report per connection
	s := bufio.NewScanner(c)
	for s.Scan() {
		var report struct {
			Cookie   *string `json:"cookie"`
			Endpoint string  `json:"endpoint"`
		}
		if json.Unmarshal(s.Bytes(), &report) != nil || report.Cookie == nil {
			continue
		}
		// Only its path and query: the host PHP saw is ours, and frankenphp is g.upstream.
		endpoint := ""
		if u, err := url.Parse(report.Endpoint); err == nil && report.Endpoint != "" {
			endpoint = u.RequestURI()
		}
		g.mu.Lock()
		g.cookie, g.endpoint = *report.Cookie, endpoint
		g.mu.Unlock()
	}
}

func (g *Gate) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	g.mu.Lock()
	cookie, endpoint := g.cookie, g.endpoint
	g.mu.Unlock()
	// A web page in a browser can post to loopback too. It cannot read mcp.json, so it has no
	// token; and it can only reach us under our own address, never a rebound hostname.
	got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	if r.Method != http.MethodPost || r.Host != g.ln.Addr().String() || endpoint == "" ||
		subtle.ConstantTimeCompare([]byte(got), []byte(g.token)) != 1 {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	// The endpoint PHP reported, never the path the call asked for. Adminer answers some pages
	// before the hook that answers ?mcp=1 runs (?download= streams a column and exits), so any
	// other path would be adminer with the window's session behind it.
	// Bound to the bridge's request: when it gives up, so does the call to frankenphp, rather
	// than a statement running on with nobody left to hear how it ended.
	req, err := http.NewRequestWithContext(r.Context(), http.MethodPost, g.upstream+endpoint, r.Body)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	req.Header.Set("Content-Type", "application/json")
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
