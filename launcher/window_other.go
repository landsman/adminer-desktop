//go:build !linux

package main

import "unsafe"

// hideWindow / showWindow are the Linux (GTK) path only. macOS's WKWebView holds the previous
// frame until the new page paints rather than flashing an empty window, and Windows' WebView2
// behaves the same, so there is nothing to hide there.
func hideWindow(window unsafe.Pointer) {}

func showWindow(window unsafe.Pointer) {}
