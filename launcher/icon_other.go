//go:build !linux

package main

import "unsafe"

// setWindowIcon is the Linux (GTK) path only. macOS ships the .icns in the .app bundle and the
// Windows exe carries assets/logo.ico as a resource (launcher/icon.rc), so there is nothing to set.
func setWindowIcon(window unsafe.Pointer, path string) bool { return true }
