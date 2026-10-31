package mcpbridge

import (
	"net"
	"os/exec"
	"path/filepath"
	"testing"
)

// Everyone on the directory: what one somebody else prepared looks like, short of a second account.
func TestSocketInAnOpenDirectoryIsRefusedOnWindows(t *testing.T) {
	dir := t.TempDir()
	socket := filepath.Join(dir, "call.sock")
	ln, err := net.Listen("unix", socket)
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close() //nolint:errcheck // the test is over
	if out, err := exec.Command("icacls", dir, "/grant", "*S-1-1-0:(OI)(CI)F").CombinedOutput(); err != nil {
		t.Fatalf("icacls: %v\n%s", err, out)
	}
	if private(socket) {
		t.Error("a socket in a directory open to Everyone counts as private")
	}
}
