//go:build darwin || windows

package main

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

	// The page decides: the loader and the unreachable page have no settings, and it refuses over
	// another dialog.
	evalOpenSettings  = "window.adminer?.page?.openSettings?.()"
	evalOpenShortcuts = "window.adminer?.page?.openShortcuts?.()"
)

// openApp switches the window and remembers the choice, so the next launch reopens
// whichever of the two you were last using.
func openApp(name string) {
	menuNavigate(menuBaseURL + "/" + name)
	setLastApp(name)
}
