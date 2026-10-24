//go:build !windows

package mcpbridge

import (
	"os"
	"path/filepath"
	"syscall"
)

// private is whether a socket and the directory holding it are this account's and nobody
// else's: not a link, owned by us, and a 0700 directory, as mcpgate makes it.
func private(socket string) bool {
	dir, err := os.Lstat(filepath.Dir(socket))
	if err != nil || !dir.IsDir() || dir.Mode().Perm()&0o077 != 0 || !ours(dir) {
		return false
	}
	file, err := os.Lstat(socket)
	return err == nil && file.Mode()&os.ModeSocket != 0 && ours(file)
}

func ours(info os.FileInfo) bool {
	stat, ok := info.Sys().(*syscall.Stat_t)
	return ok && int(stat.Uid) == os.Geteuid()
}
