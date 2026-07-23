package main

/*
#cgo CFLAGS: -x objective-c
#cgo LDFLAGS: -framework Cocoa
#include "menu_darwin.h"
*/
import "C"

import (
	"os/exec"
)

// Set by main before the menu can be clicked. The menu only exists while the webview is
// running, so these are always populated by the time a callback fires.
var (
	menuNavigate func(string)
	menuBaseURL  string
	menuLogDir   string
)

//export goMenuAdminer
func goMenuAdminer() { menuNavigate(menuBaseURL + "/adminer.php") }

//export goMenuEditor
func goMenuEditor() { menuNavigate(menuBaseURL + "/editor.php") }

//export goMenuLogs
func goMenuLogs() {
	// `open` on the directory, not the file: a .log has no reliable default handler, but
	// Finder always knows what to do with a folder.
	_ = exec.Command("open", menuLogDir).Start()
}

// installMenu replaces the default menu webview gives an unbundled binary. Must run on
// the main thread, after webview has created NSApp and before Run().
func installMenu() { C.installMenu() }
