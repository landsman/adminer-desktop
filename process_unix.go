//go:build !windows

package main

import (
	"os"
	"os/exec"
	"syscall"
)

// Own process group, so the whole tree dies with us rather than orphaning a server that
// still holds the port.
func setProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

// Negative pid signals the group, which is the point of Setpgid above.
func stopProcessGroup(p *os.Process) {
	if p != nil {
		_ = syscall.Kill(-p.Pid, syscall.SIGTERM)
	}
}
