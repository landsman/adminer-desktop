//go:build !darwin && !linux

package main

import "unsafe"

// WebView2 brings its own devtools (F12), so there is nothing to turn on -- and nothing
// that would be gated on -debug if there were.
func enableInspector(window unsafe.Pointer) bool { return false }

const inspectorHint = "F12 opens WebView2's own devtools"

func describeUIDelegate(window unsafe.Pointer) string { return "(darwin and linux only)" }
