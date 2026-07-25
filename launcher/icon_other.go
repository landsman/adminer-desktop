//go:build !linux

package main

import "unsafe"

// setWindowIcon is the Linux (GTK) path only. macOS ships the .icns in the .app bundle and
// Windows carries its own icon, so there is nothing to set here.
func setWindowIcon(window unsafe.Pointer, path string) bool { return true }
