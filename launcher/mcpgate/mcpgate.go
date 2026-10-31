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
// The bridge reaches the gate over a second unix socket beside the reports one, and nothing listens
// on the address in its URL. A port outlives the app: after a quit, whatever binds it next would get
// every message an agent sends, token and SQL included, and could answer them. And a port would take
// the token from anyone, which on Windows may have read it from a mcp.json chmod cannot protect. A
// socket in this private directory stops both, and once the app is gone it refuses.
//
// PHP on Windows has no unix:// transport (main/streams/xp_socket.c undefines AF_UNIX there), so
// reports arrive on a loopback port instead. Any local process or web page can post to that, which
// is why every report carries a key only frankenphp's environment holds; the bridge's socket stays
// a socket, since both ends of it are Go.
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
	"runtime"
	"strings"
	"sync"
	"time"
)

// address is what the bridge's URL names and the Host a call must carry. Nothing listens on it.
const address = "127.0.0.1:1"

// Gate holds the window's session and lets a token-bearing MCP call borrow it.
type Gate struct {
	upstream string // frankenphp, http://127.0.0.1:port
	token    string
	key      string       // what a report must carry; not the token, which sits in a file agents read
	calls    net.Listener // where the bridge posts, a unix socket only this account can reach
	reports  net.Listener // where PHP says which session to lend
	dir      string       // the reports socket's private directory

	mu       sync.Mutex
	cookie   string // the Cookie header to add, empty until the window logs in
	endpoint string // the MCP endpoint's path and query, the only thing the token opens
}

// New opens the socket the bridge posts to and the one PHP reports the session on.
func New(upstream string) (*Gate, error) {
	b := make([]byte, 64)
	if _, err := rand.Read(b); err != nil {
		return nil, err
	}
	sweep(os.TempDir())
	dir, err := privateDir()
	if err != nil {
		return nil, err
	}
	reports, err := listenReports(dir)
	if err != nil {
		os.RemoveAll(dir) //nolint:errcheck // giving up anyway
		return nil, err
	}
	calls, err := net.Listen("unix", filepath.Join(dir, "call.sock"))
	if err != nil {
		return nil, errors.Join(err, reports.Close(), os.RemoveAll(dir))
	}
	return &Gate{upstream: upstream, token: hex.EncodeToString(b[:32]), key: hex.EncodeToString(b[32:]), calls: calls, reports: reports, dir: dir}, nil
}

func listenReports(dir string) (net.Listener, error) {
	if runtime.GOOS == "windows" {
		return net.Listen("tcp", "127.0.0.1:0")
	}
	return net.Listen("unix", filepath.Join(dir, "report.sock"))
}

// sweep removes the directories earlier gates left behind. Close removes its own, but a Cmd+Q
// exits through AppKit without returning to it, as a kill or a crash does, so every such start
// left one: 292 had piled up on one machine. A directory is left alone while either socket in it
// still accepts, which is another app running, and while it is younger than a minute: a gate
// that is starting has made its directory but not yet bound a socket in it, and two launchers
// started together would otherwise delete each other's. One that belongs to another account
// cannot be removed by us at all (the sticky bit on a shared /tmp), and the error is ignored.
func sweep(tmp string) {
	dirs, err := filepath.Glob(filepath.Join(tmp, "adminer-desktop-gate-*"))
	if err != nil {
		return
	}
	for _, dir := range dirs {
		info, err := os.Stat(dir)
		if err != nil || time.Since(info.ModTime()) < time.Minute {
			continue
		}
		if !listening(filepath.Join(dir, "call.sock")) && !listening(filepath.Join(dir, "report.sock")) {
			_ = os.RemoveAll(dir) // another account's, or already gone: nothing to do about either
		}
	}
}

func listening(socket string) bool {
	c, err := net.DialTimeout("unix", socket, 100*time.Millisecond)
	if err != nil {
		return false
	}
	return c.Close() == nil
}

// Env is what PHP needs to point the handshake here: the address, the socket the bridge posts
// to, the token it must show, and where to report the session, as a stream_socket_client() address
// with the key that report needs. None of it unlocks a password.
func (g *Gate) Env() []string {
	return []string{
		"ADMINER_DESKTOP_MCP_GATE=" + address,
		"ADMINER_DESKTOP_MCP_SOCKET=" + g.calls.Addr().String(),
		"ADMINER_DESKTOP_MCP_TOKEN=" + g.token,
		"ADMINER_DESKTOP_MCP_REPORT=" + g.reports.Addr().Network() + "://" + g.reports.Addr().String(),
		"ADMINER_DESKTOP_MCP_REPORT_KEY=" + g.key,
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
	_ = http.Serve(g.calls, g) // ends with Close, the only error it returns
}

// Close stops both sockets and removes the socket's directory.
func (g *Gate) Close() {
	// Shutting down: a listener that will not close has nothing left to serve either.
	_ = errors.Join(g.calls.Close(), g.reports.Close(), os.RemoveAll(g.dir))
}

// listen takes one JSON line per report, {"key": "...", "cookie": "...", "endpoint":
// "http://…?…&mcp=1"}; empty is the feature being switched off. A line that does not parse or has
// the wrong key is dropped: the next page the window loads reports again.
func (g *Gate) listen(c io.ReadCloser) {
	defer c.Close() //nolint:errcheck // one report per connection
	s := bufio.NewScanner(c)
	for s.Scan() {
		var report struct {
			Key      string  `json:"key"`
			Cookie   *string `json:"cookie"`
			Endpoint string  `json:"endpoint"`
		}
		if json.Unmarshal(s.Bytes(), &report) != nil || report.Cookie == nil ||
			subtle.ConstantTimeCompare([]byte(report.Key), []byte(g.key)) != 1 {
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
	// Whoever reaches the socket still needs the token and our own address in Host.
	got := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
	if r.Method != http.MethodPost || r.Host != address || endpoint == "" ||
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
