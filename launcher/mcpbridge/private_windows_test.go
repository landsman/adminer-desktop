package mcpbridge

import (
	"os/exec"
	"path/filepath"
	"testing"
)

// Everyone on the directory: what one somebody else prepared looks like, short of a second account.
// Private first, so the refusal can only be down to that grant.
func TestSocketInAnOpenDirectoryIsRefusedOnWindows(t *testing.T) {
	socket := gateSocket(t)
	if !private(socket) {
		t.Fatal("the gate's own socket does not count as private")
	}
	if out, err := exec.Command("icacls", filepath.Dir(socket), "/grant", "*S-1-1-0:(OI)(CI)F").CombinedOutput(); err != nil {
		t.Fatalf("icacls: %v\n%s", err, out)
	}
	if private(socket) {
		t.Error("a socket in a directory open to Everyone counts as private")
	}
}
