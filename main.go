// adminer-desktop runs the released Adminer as a desktop app: start FrankenPHP on a
// private localhost port, point a native webview at it, and take the server down with
// the window.
package main

import (
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
// server that boots but cannot run the app still counts as not ready.
func waitReady(url string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		resp, err := http.Get(url)
		if err == nil {
			resp.Body.Close() //nolint:errcheck // readiness poll, body is never read
			if resp.StatusCode == http.StatusOK {
				return nil
			}
		}
		time.Sleep(50 * time.Millisecond)
	}
	return fmt.Errorf("server did not become ready within %s", timeout)
}

// Injected at build time by the Makefile from the same version pins the downloads use,
// so About can never disagree with what is actually bundled.
var (
	version           = "dev"
	adminerVersion    = "unknown"
	frankenphpVersion = "unknown"
)

func main() {
	editor := flag.Bool("editor", false, "open Adminer Editor instead of Adminer")
	debug := flag.Bool("debug", false, "open devtools support: Safari > Develop > Adminer Desktop")
	headless := flag.Bool("headless", false, "start the server, verify it serves, exit (used by `make check-app`)")
	dev := flag.Bool("dev", false, "reload the window whenever a file under app/ changes")
	flag.Parse()

	php, root, err := resolve()
	if err != nil {
		log.Fatal(err)
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
	srv.Stderr = io.MultiWriter(os.Stderr, logFile)
	srv.Stdout = srv.Stderr
	setProcessGroup(srv)
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
	if err := waitReady(url, 15*time.Second); err != nil {
		log.Fatal(err)
	}

	if *headless {
		fmt.Printf("OK: serving %s\n", url)
		return
	}

	w := webview.New(false)
	defer w.Destroy()
	w.SetTitle("Adminer Desktop")
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
	if *debug {
		log.Print("webview ", describeUIDelegate(w.Window()))
		if enableInspector(w.Window()) {
			log.Print("web inspector on: Safari > Develop > this machine > Adminer Desktop")
		} else {
			log.Print("web inspector unavailable")
		}
	}
	installMenu(w.Navigate, "http://"+addr, filepath.Dir(logPath))

	w.Navigate(url)
	if *dev {
		go watchAndReload(root, w)
	}
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
