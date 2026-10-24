// The launcher's -mcp mode as a standalone binary, for the e2e scenarios.
//
// Same reason as tests/e2e/harness/gate: the scenarios drive the bridge a process at a time, and
// CI has no WebKit to build the launcher against. It is the bridge package and nothing else, so
// what the scenarios prove is what the launcher runs.
package main

import (
	"log"
	"os"

	"github.com/landsman/adminer-desktop/launcher/mcpbridge"
)

func main() {
	if err := mcpbridge.New().Run(os.Stdin, os.Stdout); err != nil {
		log.Print(err)
		os.Exit(1)
	}
}
