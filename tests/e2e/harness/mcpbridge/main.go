// The launcher's -mcp bridge as a standalone binary, for the e2e scenarios.
//
// Same reason as tests/e2e/harness/gate: the scenarios drive the bridge a process at a time, and
// CI has no WebKit to build the launcher against. It runs the bridge package the launcher runs,
// so what the scenarios prove about messages holds there. What it leaves out is serveMCP's own
// part — signals, SIGPIPE and the bridge-exit log line — which no scenario reaches.
//
// ADMINER_DESKTOP_DATA points it at the scenario's directory. The launcher reads no such
// variable; with it unset this is mcpbridge.New(), the launcher's own lookup.
package main

import (
	"log"
	"os"

	"github.com/landsman/adminer-desktop/launcher/mcpbridge"
)

func main() {
	bridge := mcpbridge.New()
	if dir := os.Getenv("ADMINER_DESKTOP_DATA"); dir != "" {
		bridge = mcpbridge.NewIn(dir)
	}
	if err := bridge.Run(os.Stdin, os.Stdout); err != nil {
		log.Print(err)
		os.Exit(1)
	}
}
