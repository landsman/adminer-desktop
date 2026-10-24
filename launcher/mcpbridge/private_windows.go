package mcpbridge

import "os"

// private on Windows only asks that the socket exist: the launcher starts no gate there yet, so
// no handshake names one, and the per-user ACL of %TEMP% is what would keep other accounts out.
func private(socket string) bool {
	_, err := os.Stat(socket)
	return err == nil
}
