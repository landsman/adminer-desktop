//go:build !windows

package mcpgate

import "os"

// privateDir is a new 0700 directory, so no other account can even connect.
func privateDir() (string, error) {
	return os.MkdirTemp("", "adminer-desktop-gate-")
}
