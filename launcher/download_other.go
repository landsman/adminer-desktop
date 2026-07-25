//go:build !darwin && !linux

package main

import "unsafe"

// Downloads off macOS and Linux: Windows' WebView2 brings its own download UI, so nothing is
// wired here. macOS is download_darwin.m, Linux is download_linux.c.
func installDownloads(window unsafe.Pointer) {}
