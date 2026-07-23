package main

/*
#cgo CFLAGS: -x objective-c
#cgo LDFLAGS: -framework Cocoa -framework WebKit
#include <stdlib.h>
#include "menu_darwin.h"
*/
import "C"

import (
	"os/exec"
	"unsafe"
)

// Set by installMenu before the menu can be clicked. The menu only exists while the
// webview is running, so these are always populated by the time a callback fires.
var (
	menuNavigate func(string)
	menuBaseURL  string
	menuLogDir   string
)

const (
	adminerSiteURL = "https://www.adminer.org"
	repoURL        = "https://github.com/landsman/adminer-desktop"
	issuesURL      = repoURL + "/issues"
)

func openURL(url string) { _ = exec.Command("open", url).Start() }

//export goMenuAdminer
func goMenuAdminer() { openApp("adminer.php") }

//export goMenuEditor
func goMenuEditor() { openApp("editor.php") }

// openApp switches the window and remembers the choice, so the next launch reopens
// whichever of the two you were last using.
func openApp(name string) {
	menuNavigate(menuBaseURL + "/" + name)
	setLastApp(name)
}

//export goMenuLogs
func goMenuLogs() {
	// `open` on the directory, not the file: a .log has no reliable default handler, but
	// Finder always knows what to do with a folder.
	_ = exec.Command("open", menuLogDir).Start()
}

//export goMenuAdminerSite
func goMenuAdminerSite() { openURL(adminerSiteURL) }

//export goMenuRepo
func goMenuRepo() { openURL(repoURL) }

//export goMenuIssues
func goMenuIssues() { openURL(issuesURL) }

// installMenu replaces the default menu webview gives an unbundled binary. Must run on
// the main thread, after webview has created NSApp and before Run().
// installJSDialogs teaches webview's WKUIDelegate to show alert, confirm and prompt.
// Without it confirm() returns false, which silently cancels every adminer action that
// asks "Are you sure?" -- dropping a table, deleting rows, truncating.
func installJSDialogs() { C.installJSDialogs() }

func installMenu(navigate func(string), baseURL, logDir string) {
	menuNavigate, menuBaseURL, menuLogDir = navigate, baseURL, logDir
	v, a, f := C.CString(version), C.CString(adminerVersion), C.CString(frankenphpVersion)
	defer C.free(unsafe.Pointer(v))
	defer C.free(unsafe.Pointer(a))
	defer C.free(unsafe.Pointer(f))
	C.installMenu(v, a, f)
}
