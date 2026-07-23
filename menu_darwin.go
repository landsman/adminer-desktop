package main

/*
#cgo CFLAGS: -x objective-c
#cgo LDFLAGS: -framework Cocoa -framework WebKit
#include <stdlib.h>
#include "menu_darwin.h"
*/
import "C"

import (
	"log"
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
// describeUIDelegate reports which class is serving as the webview's UI delegate.
func describeUIDelegate(window unsafe.Pointer) string {
	c := C.describeUIDelegate(window)
	defer C.free(unsafe.Pointer(c))
	return C.GoString(c)
}

// enableInspector turns on Safari's Web Inspector for the app's page.
func enableInspector(window unsafe.Pointer) bool {
	return C.enableInspector(window) == 1
}

// defaultWindowSize is 60% of the main screen's usable area, from AppKit. It returns
// 0, 0 when no screen is available, so the caller falls back to a fixed size.
func defaultWindowSize() (int, int) {
	var w, h C.int
	C.defaultWindowSize(&w, &h)
	return int(w), int(h)
}

func installJSDialogs(window unsafe.Pointer) {
	if C.installJSDialogs(window) != 1 {
		log.Print("js dialogs: could not attach a UI delegate - alert, confirm, prompt and file upload will not work")
	}
}

// installMouseNav routes the mouse's back/forward side buttons to the webview's history,
// which WKWebView otherwise ignores. Best-effort: the back button just stays dead if the
// monitor cannot attach.
func installMouseNav(window unsafe.Pointer) {
	C.installMouseNav(window)
}

func installMenu(navigate func(string), baseURL, logDir string) {
	menuNavigate, menuBaseURL, menuLogDir = navigate, baseURL, logDir
	v, a, f := C.CString(version), C.CString(adminerVersion), C.CString(frankenphpVersion)
	defer C.free(unsafe.Pointer(v))
	defer C.free(unsafe.Pointer(a))
	defer C.free(unsafe.Pointer(f))
	C.installMenu(v, a, f)
}
