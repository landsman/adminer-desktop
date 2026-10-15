// Command gate runs frankenphp behind launcher/mcpgate, the way the launcher does, for the e2e
// fixture: it has no window, and CI has no WebKit to build the launcher against.
//
//	gate <upstream url> <command> [args...]
package main

import (
	"log"
	"os"
	"os/exec"
	"os/signal"
	"syscall"

	"github.com/landsman/adminer-desktop/launcher/mcpgate"
)

func main() {
	if len(os.Args) < 3 {
		log.Fatal("usage: gate <upstream url> <command> [args...]")
	}
	g, err := mcpgate.New(os.Args[1])
	if err != nil {
		log.Fatal(err)
	}
	defer g.Close()
	cmd := exec.Command(os.Args[2], os.Args[3:]...)
	cmd.Env = append(os.Environ(), g.Env()...)
	cmd.Stdout, cmd.Stderr = os.Stdout, os.Stderr
	if err := cmd.Start(); err != nil {
		log.Fatal(err)
	}
	// The fixture stops us with SIGTERM; frankenphp must go with us, or it keeps the port.
	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, syscall.SIGTERM, os.Interrupt)
	go func() {
		s := <-sigs
		cmd.Process.Signal(s) //nolint:errcheck // exiting either way
	}()
	go g.Serve()
	if err := cmd.Wait(); err != nil {
		g.Close()
		os.Exit(1)
	}
}
