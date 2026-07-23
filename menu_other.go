//go:build !darwin

package main

// ponytail: no menu bar off macOS. Windows and Linux put menus inside the window rather
// than in a system bar, so there is nothing to mirror — it would be a new widget, drawn
// by us, in a webview that is already showing a full UI. The two things the mac menu
// gives you have other routes here: Adminer/Editor is the -editor flag, which is
// remembered across launches, and the log path is printed at startup.
//
// Build this out when someone actually asks; installMenu is already the seam.
func installMenu(navigate func(string), baseURL, logDir string) {}
