// adminer-desktop runs the released Adminer as a desktop app: start FrankenPHP on a
// private localhost port, point a native webview at it, and take the server down with
// the window.
package main

import (
	_ "embed"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	webview "github.com/webview/webview_go"
)

// ponytail: no go:embed. A .app bundle is a directory, so app/ ships as a folder in
// Contents/Resources and the binary just points at it. Embedding would buy nothing and
// cost a first-run extraction step plus a cache dir to invalidate.
func resolve() (php string, root string, err error) {
	exe, err := os.Executable()
	if err != nil {
		return "", "", err
	}
	dir := filepath.Dir(exe)
	bin := "frankenphp"
	if runtime.GOOS == "windows" {
		bin += ".exe"
	}
	// macOS .app bundle, then the flat folder linux and windows ship, then the dev tree
	// we run from with `go run .`.
	for _, c := range []struct{ php, root string }{
		{filepath.Join(dir, bin), filepath.Join(dir, "..", "Resources", "app")},
		{filepath.Join(dir, bin), filepath.Join(dir, "app")},
		{filepath.Join("bin", bin), "app"},
	} {
		if _, e := os.Stat(c.php); e == nil {
			if _, e := os.Stat(c.root); e == nil {
				return c.php, c.root, nil
			}
		}
	}
	return "", "", fmt.Errorf("could not find frankenphp and app/ (run `make fetch`)")
}

// serveMCP runs the stdio bridge an agent talks to, and nothing else -- no window, no server.
//
// It exists so registering the app with an agent is one path instead of two: we already know
// where frankenphp and app/ are, and the agent should not have to. It also passes the data
// directory in, which is where the bridge finds the handshake naming the running window.
func serveMCP(php, root string) error {
	cmd := exec.Command(php, "php-cli", filepath.Join(root, "mcp.php"))
	cmd.Stdin, cmd.Stdout, cmd.Stderr = os.Stdin, os.Stdout, os.Stderr
	cmd.Env = os.Environ()
	if dir, err := dataDir(); err == nil {
		cmd.Env = append(cmd.Env, "ADMINER_DESKTOP_DATA="+dir)
	}
	return cmd.Run()
}

// iconPath finds the window icon: beside the binary in the shipped layout (dist and the
// .deb put logo.png next to the executable), or assets/ when running from the dev tree.
// Empty when neither exists, which just leaves the platform default.
func iconPath() string {
	if exe, err := os.Executable(); err == nil {
		if p := filepath.Join(filepath.Dir(exe), "logo.png"); fileExists(p) {
			return p
		}
	}
	if fileExists("assets/logo.png") {
		return "assets/logo.png"
	}
	return ""
}

func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}

// openLog opens the single log file. PHP errors, adminer's own warnings and caddy's
// access log all arrive on the server's stderr, so one file is the whole logging story.
// ponytail: append forever, no rotation. A local admin tool writes a line per click,
// not per request-per-user; wire in lumberjack if a log ever gets big enough to notice.
func openLog() (*os.File, string, error) {
	dir, err := logDir()
	if err != nil {
		return nil, "", err
	}
	path := filepath.Join(dir, "adminer-desktop.log")
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	return f, path, err
}

// freePort asks the kernel for an unused port and immediately gives it back.
// ponytail: there is a race between closing this and frankenphp binding it. It is a
// desktop app on loopback; if it ever actually collides, pass the listener's fd through
// instead of the number.
func freePort() (int, error) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 0, err
	}
	defer l.Close() //nolint:errcheck // closing a probe listener; the port is the result
	return l.Addr().(*net.TCPAddr).Port, nil
}

// waitReady polls until PHP actually answers. Adminer's own page is the probe, so a
// server that boots but cannot run the app still counts as not ready. It returns how
// long that first successful request took -- the cold compile of adminer.php and its
// includes, which is the one part of startup opcache.file_cache could shorten.
func waitReady(url string, timeout time.Duration) (cold time.Duration, err error) {
	deadline := time.Now().Add(timeout)
	// Per-request timeout so the deadline below actually bounds the wait: http.Get uses
	// DefaultClient (no timeout), so a request that connects but never answers -- frankenphp
	// bound the port and logged "Caddy serving" but the first hit stalls while PHP warms up --
	// blocks forever, the deadline never fires, and the GUI never opens. A bounded request
	// times out and the loop just retries.
	client := &http.Client{Timeout: 2 * time.Second}
	for time.Now().Before(deadline) {
		start := time.Now()
		resp, err := client.Get(url)
		if err == nil {
			ok := resp.StatusCode == http.StatusOK
			if ok {
				// Drain the body so the timing spans the whole response, not just its
				// headers -- adminer streams the page as it renders.
				io.Copy(io.Discard, resp.Body) //nolint:errcheck // draining to time the cold response
			}
			resp.Body.Close() //nolint:errcheck // readiness poll
			if ok {
				return time.Since(start), nil
			}
		}
		time.Sleep(50 * time.Millisecond)
	}
	return 0, fmt.Errorf("server did not become ready within %s", timeout)
}

// timeGet issues one GET and returns how long the full response took. Used once at
// startup for a warm baseline against waitReady's cold first request; the timing, not
// the response, is the point, so a failure just reads as a near-zero sample.
func timeGet(url string) time.Duration {
	start := time.Now()
	if resp, err := http.Get(url); err == nil {
		io.Copy(io.Discard, resp.Body) //nolint:errcheck // draining to time the full response
		resp.Body.Close()              //nolint:errcheck
	}
	return time.Since(start)
}

// Injected at build time by the Makefile from the same version pins the downloads use,
// so About can never disagree with what is actually bundled.
var (
	version           = "dev"
	adminerVersion    = "unknown"
	frankenphpVersion = "unknown"
)

// The startup splash pages, shown in the native window before frankenphp is serving -- so
// they can't be fetched from it and are compiled in. This is not the app/ tree the note above
// keeps on disk: three tiny files the window needs before any server exists, with nothing to
// extract or cache, and they must never be missing since one is the first frame and one is the
// error screen. Edited as real .html/.css; go:embed bakes them in. loaderPage folds the shared
// stylesheet into a page (the templates keep a <link> so each renders standalone in an editor).
//
//go:embed loader/loading.html
var loadingHTML string

//go:embed loader/unreachable.html
var unreachableHTML string

//go:embed loader/loader.css
var loaderCSS string

func loaderPage(html string) string {
	return strings.Replace(html, `<link rel="stylesheet" href="loader.css">`, "<style>\n"+loaderCSS+"</style>", 1)
}

func main() {
	editor := flag.Bool("editor", false, "open Adminer Editor instead of Adminer")
	debug := flag.Bool("debug", false, "open devtools support: Safari > Develop > Adminer Desktop")
	headless := flag.Bool("headless", false, "start the server, verify it serves, exit (used by `make check-app`)")
	dev := flag.Bool("dev", false, "reload the window whenever a file under app/ changes")
	mcp := flag.Bool("mcp", false, "serve MCP over stdio for an agent, and exit (no window)")
	flag.Parse()

	php, root, err := resolve()
	if err != nil {
		log.Fatal(err)
	}
	// Before the banner below, and before any window: this mode owns stdout, and a stray line
	// on it corrupts the agent's JSON-RPC stream.
	if *mcp {
		if err := serveMCP(php, root); err != nil {
			os.Exit(1)
		}
		return
	}
	port, err := freePort()
	if err != nil {
		log.Fatal(err)
	}
	addr := fmt.Sprintf("127.0.0.1:%d", port)

	logFile, logPath, err := openLog()
	if err != nil {
		log.Fatal(err)
	}
	defer logFile.Close() //nolint:errcheck // process is exiting anyway
	// Also states the versions, which is the first thing worth knowing from a log, and
	// keeps the ldflags-injected values honest on every platform rather than only where
	// the About panel reads them.
	fmt.Printf("adminer-desktop %s (adminer %s, frankenphp %s)\n", version, adminerVersion, frankenphpVersion)
	fmt.Printf("logging to %s\n", logPath)

	// --no-compress: adminer/file.inc.php:14 already sets zlib.output_compression.
	// --access-log: off by default; on a single-user local app the request list is the
	// most useful thing in the log and there is no privacy cost to writing it.
	// Everything else we need is a php-server default and is asserted by check-stream.sh:
	// no request timeout, no response buffering, plaintext HTTP, localhost-only bind.
	srv := exec.Command(php, "php-server", "--root", root, "--listen", addr, "--no-compress", "--access-log")
	// Both, not either: stderr is what you read during `make run`, the file is the only
	// thing that survives being launched from Finder, where stderr goes nowhere.
	// Adminer's permanent login needs somewhere durable to keep its key. Passing the
	// path in means the per-OS logic stays in os.UserConfigDir and never gets restated
	// in PHP.
	// PHP_INI_SCAN_DIR: frankenphp logs nothing on a PHP fatal error by default -- it
	// goes to the page and no further, so the log file a user is pointed at never sees
	// the one thing they went looking for. app/php/desktop.ini turns log_errors on.
	srv.Env = append(os.Environ(), "PHP_INI_SCAN_DIR="+filepath.Join(root, "php"))
	if dir, err := dataDir(); err == nil {
		srv.Env = append(srv.Env, "ADMINER_DESKTOP_DATA="+dir)
	}
	// Our own path, so the settings dialog can print the exact `claude mcp add` line for this
	// install rather than making the user work out where the app landed. Only the launcher
	// knows it: PHP sees the app/ root, which is three different shapes across the packages.
	if exe, err := os.Executable(); err == nil {
		srv.Env = append(srv.Env, "ADMINER_DESKTOP_EXE="+exe)
	}
	// -debug reaches the page too: the plugin tags <body> with it, and the desktop scripts
	// that smooth over the WebView (the link context-menu block) stand down so the inspector's
	// own right-click menu, Inspect Element and the rest are all there.
	if *debug {
		srv.Env = append(srv.Env, "ADMINER_DESKTOP_DEBUG=1")
	}
	srv.Stderr = io.MultiWriter(os.Stderr, logFile)
	srv.Stdout = srv.Stderr
	setProcessGroup(srv)
	bootStart := time.Now()
	if err := srv.Start(); err != nil {
		log.Fatal(err)
	}
	stop := func() { stopProcessGroup(srv.Process) }
	defer stop()

	// A kill(2) from the OS must not leak the server either.
	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, os.Interrupt)
	go func() { <-sigs; stop(); os.Exit(1) }()

	// Remembered choice, unless -editor says otherwise: an explicit flag beats a
	// remembered preference.
	app := "adminer.php"
	if remembered := lastApp(); remembered == "editor.php" || remembered == "adminer.php" {
		app = remembered
	}
	if *editor {
		app = "editor.php"
	}
	// Remember it here too, not just from the mac menu: -editor should still be sticky on
	// platforms that have no menu to switch with.
	setLastApp(app)
	url := fmt.Sprintf("http://%s/%s", addr, app)

	// logReady reports the pre-window wait, splitting the cold first request from a warm one:
	// opcache is on, so the warm hit is served from compiled bytecode and cold - warm is the
	// compile cost -- the ceiling on what opcache.file_cache could save off each launch's first
	// request. The probe now runs behind the loader, so this logs from wherever it ran.
	logReady := func(cold time.Duration) {
		warm := timeGet(url)
		log.Printf("startup: server ready in %s; first request %s cold vs %s warm (~%s is PHP compile)",
			time.Since(bootStart).Round(time.Millisecond), cold.Round(time.Millisecond),
			warm.Round(time.Millisecond), (cold - warm).Round(time.Millisecond))
	}

	// headless is check-app's mode: assert the server serves, then exit. No window to fill
	// while it waits, so it keeps the plain blocking probe.
	if *headless {
		cold, err := waitReady(url, 15*time.Second)
		if err != nil {
			log.Fatal(err)
		}
		logReady(cold)
		fmt.Printf("OK: serving %s\n", url)
		return
	}

	// WebKitGTK's DMABUF renderer leaves the window black/unpainted for a second or two on
	// many Linux drivers while its GPU compositor comes up -- long enough that the loader
	// below can't paint and the user just sees an empty rectangle until the app arrives.
	// Disabling it makes WebKit paint the first frame (the loader) right away. Must be set
	// before the webview is created, since WebKit reads it at init.
	// ponytail: off for everyone on Linux, not just the affected drivers -- a black startup
	// window is worse than losing the DMABUF fast path on a local admin tool. Drop this if
	// WebKitGTK ever fixes the first-paint stall.
	if runtime.GOOS == "linux" {
		os.Setenv("WEBKIT_DISABLE_DMABUF_RENDERER", "1") //nolint:errcheck // best-effort env hint
	}

	guiStart := time.Now()
	w := webview.New(false)
	log.Printf("startup: webview created in %s", time.Since(guiStart).Round(time.Millisecond))
	defer w.Destroy()
	// webview.New already mapped the window, unpainted and unsized -- that empty frame is the
	// white flash. Hide it before the run loop starts (so it is never shown in that state) and
	// bring it back once the loader has painted, in the adLoaderShown callback below.
	hideWindow(w.Window())
	w.SetTitle("Adminer Desktop")
	// Without an icon the Linux taskbar shows a generic placeholder. macOS uses the .app's
	// .icns and Windows its own, so setWindowIcon is a no-op there; this is the GTK path,
	// reading the same logo the .icns is built from.
	if icon := iconPath(); icon == "" {
		log.Print("window icon: logo.png not found beside the binary or in assets/")
	} else if !setWindowIcon(w.Window(), icon) {
		log.Printf("window icon %q: gtk rejected it", icon)
	} else if os.Getenv("XDG_SESSION_TYPE") == "wayland" {
		// The window icon is set, but a Wayland taskbar ignores it and matches the window's
		// app_id to an installed .desktop instead — so the icon only shows for the installed
		// .deb (StartupWMClass), not for `make run`.
		log.Print("window icon: wayland shows the taskbar icon from the installed .desktop, not the window — install the .deb to see it")
	}
	// Open at 60% of the screen where a screen size is available (macOS), otherwise a fixed
	// default. HintNone leaves the window freely resizable after that.
	winW, winH := 1280, 900
	if sw, sh := defaultWindowSize(); sw > 0 && sh > 0 {
		winW, winH = sw, sh
	}
	w.SetSize(winW, winH, webview.HintNone)

	// The menu is how logs stay reachable when login fails — a link inside adminer would
	// only exist on pages you reach *after* logging in, which is exactly when you don't
	// need it.
	installJSDialogs(w.Window())
	installMouseNav(w.Window())
	installReloadShortcut(w.Window())
	installDownloads(w.Window())
	if *debug {
		log.Print("webview ", describeUIDelegate(w.Window()))
		if enableInspector(w.Window()) {
			log.Print("web inspector on: Safari > Develop > this machine > Adminer Desktop")
		} else {
			log.Print("web inspector unavailable")
		}
	}
	installMenu(w.Navigate, "http://"+addr, filepath.Dir(logPath))

	// Report from inside the webview when the loader has actually parsed and rendered -- the one
	// startup phase Go can't time, since it is WebKit's own paint. It separates "the window was
	// shown before the loader rendered" from "WebKit was slow to paint". loading.html calls this
	// on DOMContentLoaded.
	if err := w.Bind("adLoaderShown", func() {
		log.Printf("startup: loader rendered in %s", time.Since(guiStart).Round(time.Millisecond))
		// The loader has parsed and styled, so there is finally something to show: reveal the
		// window (already sized), painting straight onto the spinner instead of a blank frame.
		showWindow(w.Window())
	}); err != nil {
		log.Print("startup: loader timing bind failed: ", err)
	}

	// Show the loader now rather than blocking on the server first: a background probe swaps
	// in the app once it answers, or the error page if it never does, so the window is up and
	// painted from the first frame instead of after the whole cold start.
	w.SetHtml(loaderPage(loadingHTML))
	log.Printf("startup: loader html set in %s", time.Since(guiStart).Round(time.Millisecond))
	go func() {
		cold, err := waitReady(url, 15*time.Second)
		if err != nil {
			log.Print(err)
			w.Dispatch(func() { w.SetHtml(loaderPage(unreachableHTML)) })
			return
		}
		logReady(cold)
		w.Dispatch(func() { w.Navigate(url) })
	}()
	if *dev {
		go watchAndReload(root, w)
	}
	log.Printf("startup: entering run loop in %s", time.Since(guiStart).Round(time.Millisecond))
	w.Run()
}

// watchAndReload reloads the window whenever a file under dir changes. Dev only, and a
// coarse mtime poll rather than an OS file-watch: it needs no dependency and is plenty for
// editing PHP and CSS by hand, since frankenphp serves the tree live and a reload is all
// it takes for a change to show.
func watchAndReload(dir string, w webview.WebView) {
	newest := func() (t time.Time) {
		_ = filepath.Walk(dir, func(_ string, info os.FileInfo, err error) error {
			if err == nil && info.ModTime().After(t) {
				t = info.ModTime()
			}
			return nil
		})
		return
	}
	last := newest()
	for range time.Tick(400 * time.Millisecond) {
		if t := newest(); t.After(last) {
			last = t
			w.Dispatch(func() { w.Eval("location.reload()") })
		}
	}
}
