//go:build !linux

package main

import "unsafe"

// setWindowIcon is the Linux (GTK) path only. macOS ships the .icns in the .app bundle and the
// Windows exe carries one built from assets/logo.png (launcher/icon.rc), so there is nothing to set.
func setWindowIcon(window unsafe.Pointer, path string) bool { return true }
