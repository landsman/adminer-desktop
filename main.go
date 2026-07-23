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
	"syscall"
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
	// Bundle layout first, then the dev tree we run from with `go run .`.
	for _, c := range []struct{ php, root string }{
		{filepath.Join(dir, "frankenphp"), filepath.Join(dir, "..", "Resources", "app")},
		{"bin/frankenphp", "app"},
	} {
		if _, e := os.Stat(c.php); e == nil {
			if _, e := os.Stat(c.root); e == nil {
				return c.php, c.root, nil
			}
		}
	}
	return "", "", fmt.Errorf("could not find frankenphp and app/ (run `make fetch`)")
}

// openLog puts the log where macOS users and Console.app already look for one.
// PHP errors, adminer's own warnings and caddy's access log all arrive on the server's
// stderr, so one file is the whole logging story.
// ponytail: append forever, no rotation. A local admin tool writes a line per click,
// not per request-per-user; wire in lumberjack if a log ever gets big enough to notice.
func openLog() (*os.File, string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return nil, "", err
	}
	dir := filepath.Join(home, "Library", "Logs", "Adminer")
	if err := os.MkdirAll(dir, 0o755); err != nil {
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
	defer l.Close()
	return l.Addr().(*net.TCPAddr).Port, nil
}

// waitReady polls until PHP actually answers. Adminer's own page is the probe, so a
// server that boots but cannot run the app still counts as not ready.
func waitReady(url string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		resp, err := http.Get(url)
		if err == nil {
			resp.Body.Close()
			if resp.StatusCode == http.StatusOK {
				return nil
			}
		}
		time.Sleep(50 * time.Millisecond)
	}
	return fmt.Errorf("server did not become ready within %s", timeout)
}

func main() {
	editor := flag.Bool("editor", false, "open Adminer Editor instead of Adminer")
	headless := flag.Bool("headless", false, "start the server, verify it serves, exit (used by `make check-app`)")
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
	defer logFile.Close()
	fmt.Printf("logging to %s\n", logPath)

	// --no-compress: adminer/file.inc.php:14 already sets zlib.output_compression.
	// --access-log: off by default; on a single-user local app the request list is the
	// most useful thing in the log and there is no privacy cost to writing it.
	// Everything else we need is a php-server default and is asserted by check-stream.sh:
	// no request timeout, no response buffering, plaintext HTTP, localhost-only bind.
	srv := exec.Command(php, "php-server", "--root", root, "--listen", addr, "--no-compress", "--access-log")
	// Both, not either: stderr is what you read during `make run`, the file is the only
	// thing that survives being launched from Finder, where stderr goes nowhere.
	srv.Stderr = io.MultiWriter(os.Stderr, logFile)
	srv.Stdout = srv.Stderr
	// Own process group, so the whole tree dies with us rather than orphaning a server
	// that still holds the port.
	srv.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
	if err := srv.Start(); err != nil {
		log.Fatal(err)
	}
	stop := func() { syscall.Kill(-srv.Process.Pid, syscall.SIGTERM) }
	defer stop()

	// A kill(2) from the OS must not leak the server either.
	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, os.Interrupt, syscall.SIGTERM)
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
	w.SetTitle("Adminer")
	w.SetSize(1280, 900, webview.HintNone)

	// The menu is how logs stay reachable when login fails — a link inside adminer would
	// only exist on pages you reach *after* logging in, which is exactly when you don't
	// need it.
	menuNavigate = w.Navigate
	menuBaseURL = "http://" + addr
	menuLogDir = filepath.Dir(logPath)
	installMenu()

	w.Navigate(url)
	w.Run()
}
