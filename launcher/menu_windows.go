package main

/*
#include <stdlib.h>
#include "menu_windows.h"
*/
import "C"

import (
	"log"
	"os/exec"
	"strings"
	"unsafe"
)

var menuWindow unsafe.Pointer

// installMenu attaches the native menu bar. Its strings and the window procedure are in
// menu_windows.c. Best-effort: without it the window still works, and the page's own gear and
// shortcuts remain.
func installMenu(window unsafe.Pointer, navigate, eval func(string), baseURL, logDir string) {
	menuWindow, menuNavigate, menuEval, menuBaseURL, menuLogDir = window, navigate, eval, baseURL, logDir
	if C.adMenuInstall(window) != 1 {
		log.Print("menu: could not attach the menu bar")
	}
}

//export goMenuCommand
func goMenuCommand(id C.int) {
	switch id {
	case C.AD_MENU_ADMINER:
		openApp("adminer.php")
	case C.AD_MENU_EDITOR:
		openApp("editor.php")
	case C.AD_MENU_SETTINGS:
		menuEval(evalOpenSettings)
	case C.AD_MENU_SHORTCUTS:
		menuEval(evalOpenShortcuts)
	case C.AD_MENU_LOGS:
		_ = exec.Command("explorer", menuLogDir).Start()
	case C.AD_MENU_QUIT:
		C.adMenuClose(menuWindow)
	case C.AD_MENU_SITE:
		openURL(adminerSiteURL)
	case C.AD_MENU_REPO:
		openURL(repoURL)
	case C.AD_MENU_ISSUES:
		openURL(issuesURL)
	case C.AD_MENU_ABOUT:
		showAbout()
	}
}

func openURL(url string) {
	_ = exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
}

func showAbout() {
	id := C.CString("about.credits")
	defer C.free(unsafe.Pointer(id))
	// In Go, because the positions are not something the C runtime's printf reads.
	text := strings.NewReplacer("%1$s", adminerVersion, "%2$s", frankenphpVersion, "%3$s", version).
		Replace(C.GoString(C.adMenuText(id)))
	c := C.CString(text)
	defer C.free(unsafe.Pointer(c))
	C.adMenuAbout(menuWindow, c)
}
