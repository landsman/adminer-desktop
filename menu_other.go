//go:build !darwin

package main

import "unsafe"

// ponytail: no menu bar off macOS. Windows and Linux put menus inside the window rather
// than in a system bar, so there is nothing to mirror — it would be a new widget, drawn
// by us, in a webview that is already showing a full UI. The two things the mac menu
// gives you have other routes here: Adminer/Editor is the -editor flag, which is
// remembered across launches, and the log path is printed at startup.
//
// Build this out when someone actually asks; installMenu is already the seam.
func installMenu(navigate func(string), baseURL, logDir string) {}

func installJSDialogs(window unsafe.Pointer) {}

// The mouse's back/forward buttons, off macOS: Windows' WebView2 is Chromium-based and
// already navigates on them, so nothing is needed there; Linux's WebKitGTK does not, and
// will want its own handler (a GTK button-press on buttons 8/9 -> webkit_web_view_go_back).
// Built when that platform actually ships, the way the menu is — this stub is the seam.
func installMouseNav(window unsafe.Pointer) {}

// The reload shortcut is handled in the page by shortcuts.js off macOS, where the WebViews
// deliver the keystroke to it, so nothing native is needed.
func installReloadShortcut(window unsafe.Pointer) {}

func enableInspector(window unsafe.Pointer) bool { return false }

func describeUIDelegate(window unsafe.Pointer) string { return "(darwin only)" }

// No screen query off macOS yet; the caller falls back to a fixed window size.
func defaultWindowSize() (int, int) { return 0, 0 }
