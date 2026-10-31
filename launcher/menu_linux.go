package main

import "unsafe"

// ponytail: no menu bar on Linux. GTK puts menus inside the window, so it would be a new widget
// in a webview that is already showing a full UI. What the other menus give has other routes:
// Adminer/Editor is the -editor flag, remembered across launches, and the log path is printed at
// startup. installMenu is the seam when someone asks.
func installMenu(window unsafe.Pointer, navigate, eval func(string), baseURL, logDir string) {}
