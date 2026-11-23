//go:build !darwin

package main

import (
	"unsafe"

	webview "github.com/webview/webview_go"
)

func installJSDialogs(window unsafe.Pointer) {}

// The mouse's back/forward buttons, off macOS: Windows' WebView2 is Chromium-based and
// already navigates on them, so nothing is needed there; Linux's WebKitGTK does not, and
// will want its own handler (a GTK button-press on buttons 8/9 -> webkit_web_view_go_back).
// Built when that platform actually ships, the way the menu is — this stub is the seam.
func installMouseNav(window unsafe.Pointer) {}

// The reload shortcut is handled in the page by shortcuts.js off macOS, where the WebViews
// deliver the keystroke to it, so nothing native is needed.
func installReloadShortcut(window unsafe.Pointer) {}

// The resize flash is WKWebView's; nothing to clear elsewhere yet.
func clearWebViewBackground(window unsafe.Pointer) {}

func setWindowBackground(window unsafe.Pointer, r, g, b int) {}

func setWindowAppearance(window unsafe.Pointer, dark int) {}

// installDownloads is per-platform: real on Linux (download_linux.c), a no-op on Windows
// (download_other.go), and the macOS build has its own in menu_darwin.go.

// enableInspector and describeUIDelegate are per-platform: real on macOS and on Linux
// (inspector_linux.go, where WebKitGTK has an inspector of its own to turn on), stubbed for
// Windows in inspector_other.go.

// The title bar stays: GTK and Windows draw their own, and the page lays out under it.
func installFrameless(w webview.WebView) {}

func describeTitlebarHit(window unsafe.Pointer) string { return "(darwin only)" }

// Windows and Linux keep their scrollbars on screen natively; the theme only hides them on macOS.
func installScrollerStyle(w webview.WebView) {}

// Closing the window returns from the run loop off macOS, so the defers already clean up.
func onTerminate(cleanup func()) {}

// The window manager places the window; -dev leaves it to it.
func moveToBuiltinScreen(window unsafe.Pointer) bool { return false }

// No screen query off macOS yet; the caller falls back to a fixed window size.
func defaultWindowSize() (int, int) { return 0, 0 }
