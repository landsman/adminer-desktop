package main

import (
	"os"
	"os/exec"
)

// Windows has neither Setpgid nor kill(2), so the unix approach does not translate.
// ponytail: kill the child directly rather than reaching for job objects. frankenphp is
// a single process that spawns no workers of its own, so there is no tree to orphan —
// revisit only if a stray frankenphp.exe is ever left holding the port.
func setProcessGroup(cmd *exec.Cmd) {}

func stopProcessGroup(p *os.Process) {
	if p != nil {
		_ = p.Kill()
	}
}
