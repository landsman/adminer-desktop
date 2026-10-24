// Package datadir is where the app keeps its state, in one place: the launcher creates it, and
// the MCP bridge an agent spawns has to find the same one with no environment of ours.
// Desktop\Mcp\Handshake::platformDir() is the PHP copy and must stay in step.
package datadir

import (
	"os"
	"path/filepath"
)

// Path is os.UserConfigDir()/Adminer Desktop, without creating it.
func Path() (string, error) {
	base, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(base, "Adminer Desktop"), nil
}
