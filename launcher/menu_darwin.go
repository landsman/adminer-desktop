package main

/*
#cgo CFLAGS: -x objective-c
#cgo LDFLAGS: -framework Cocoa -framework WebKit
#include <stdlib.h>
#include "menu_darwin.h"
*/
import "C"

import (
	"fmt"
	"log"
	"os/exec"
	"unsafe"

	webview "github.com/webview/webview_go"
)

// Set by installFrameless; the notification that calls it only fires once the window is up.
var onFullscreen func(bool)

//export goFullscreenChanged
func goFullscreenChanged(on C.int) {
	if onFullscreen != nil {
		onFullscreen(on == 1)
	}
}

// installFrameless takes the title bar away: the page runs to the window's top edge and the
// traffic lights sit in a band over the sidebar (the theme's window.css). The page is told by the
// ad-frameless class on <html>, set before it paints, and moves the window from that band
// (window-chrome.js) through the bindings here. ad-fullscreen marks a full-screen window, which
// shows no lights: set the same way, by an init script added again on each change — it runs
// after the earlier ones and wins — so a page loaded in full screen paints that way from the start.
func installFrameless(w webview.WebView) {
	win := w.Window()
	if C.installFramelessTitlebar(win) != 1 {
		return
	}
	bind := func(name string, f any) {
		if err := bindNative(w, name, f); err != nil {
			log.Printf("frameless: %s bind failed: %v", name, err)
		}
	}
	bind("dragWindow", func() { C.dragWindow(win) })
	bind("titlebarDoubleClick", func() { C.titlebarDoubleClick(win) })
	mark := func(on bool) string {
		return fmt.Sprintf(`document.documentElement.classList.toggle("ad-fullscreen", %t);`, on)
	}
	onFullscreen = func(on bool) {
		w.Init(mark(on))
		w.Eval(mark(on))
	}
	w.Init(`document.documentElement.classList.add("ad-frameless");`)
}

// Set by onTerminate; AppKit calls it from inside terminate:, then exits the process.
var onQuit func()

//export goAppWillTerminate
func goAppWillTerminate() {
	if onQuit != nil {
		onQuit()
	}
}

// onTerminate runs cleanup when the app quits from the menu, the Dock or a logout. Those exit
// the process without returning from the run loop, so a defer never sees them.
func onTerminate(cleanup func()) {
	onQuit = cleanup
	C.watchTermination()
}

// moveToBuiltinScreen puts the window on the laptop's own display, for -dev.
func moveToBuiltinScreen(window unsafe.Pointer) bool {
	return C.moveToBuiltinScreen(window) == 1
}

// Set by installScrollerStyle; the notification that calls it only fires once the window is up.
var onScrollerStyle func(bool)

//export goScrollerStyleChanged
func goScrollerStyleChanged(legacy C.int) {
	if onScrollerStyle != nil {
		onScrollerStyle(legacy == 1)
	}
}

// installScrollerStyle tells every page which scrollbars the system draws, as the class
// ad-legacy-scrollers on <html> (base.css shows WebKit's own then). An init script, so the class
// is there before the page paints; on a change another one is added, which runs after and wins,
// and the page on screen is told directly.
func installScrollerStyle(w webview.WebView) {
	mark := func(legacy bool) string {
		return fmt.Sprintf(`document.documentElement.classList.toggle("ad-legacy-scrollers", %t);`, legacy)
	}
	w.Init(mark(C.legacyScrollers() == 1))
	onScrollerStyle = func(legacy bool) {
		w.Init(mark(legacy))
		w.Eval(mark(legacy))
	}
	C.watchScrollerStyle()
}

// describeTitlebarHit names the view a click near the window's top lands on.
func describeTitlebarHit(window unsafe.Pointer) string {
	c := C.describeTitlebarHit(window)
	defer C.free(unsafe.Pointer(c))
	return C.GoString(c)
}

// Set by installMenu before the menu can be clicked. The menu only exists while the
// webview is running, so these are always populated by the time a callback fires.
var (
	menuNavigate func(string)
	menuEval     func(string)
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

// The page decides: the loader and the unreachable page have no settings, and it refuses over
// another dialog.
//
//export goMenuSettings
func goMenuSettings() { menuEval("window.adminer?.page?.openSettings?.()") }

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

// installReloadShortcut reloads the page on Cmd+R and F5. WKWebView leaves both unbound on
// macOS and the page never sees the keystroke, so it is caught natively; shortcuts.js
// covers the other platforms, where the WebViews do deliver it to the page.
func installReloadShortcut(window unsafe.Pointer) {
	C.installReloadShortcut(window)
}

// clearWebViewBackground stops the white strip a fast resize shows in dark mode.
func clearWebViewBackground(window unsafe.Pointer) {
	C.clearWebViewBackground(window)
}

// setWindowBackground paints the window in the page's colour, which is what shows in that strip.
func setWindowBackground(window unsafe.Pointer, r, g, b int) {
	C.setWindowBackground(window, C.int(r), C.int(g), C.int(b))
}

// setWindowAppearance pins the window light (0) or dark (1), or lets it follow the system (-1).
func setWindowAppearance(window unsafe.Pointer, dark int) {
	C.setWindowAppearance(window, C.int(dark))
}

// installDownloads makes the webview save an attachment response (Adminer's Export > save)
// through a native Save panel instead of rendering the file into the window. macOS 11.3+;
// below that it is a no-op and the old behaviour stands.
func installDownloads(window unsafe.Pointer) {
	if C.installDownloads(window) != 1 {
		log.Print("downloads: native save handling not attached (needs macOS 11.3+); Export > save will show the file in the window")
	}
}

func installMenu(navigate, eval func(string), baseURL, logDir string) {
	menuNavigate, menuEval, menuBaseURL, menuLogDir = navigate, eval, baseURL, logDir
	v, a, f := C.CString(version), C.CString(adminerVersion), C.CString(frankenphpVersion)
	defer C.free(unsafe.Pointer(v))
	defer C.free(unsafe.Pointer(a))
	defer C.free(unsafe.Pointer(f))
	C.installMenu(v, a, f)
}
