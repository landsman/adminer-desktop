//go:build linux

package main

/*
#cgo pkg-config: gtk+-3.0
#include <stdlib.h>
#include <gtk/gtk.h>

static int set_window_icon(void *window, const char *path) {
	return gtk_window_set_icon_from_file(GTK_WINDOW(window), path, NULL) ? 1 : 0;
}
*/
import "C"

import "unsafe"

// setWindowIcon sets the GTK window icon from a PNG on disk, returning false if GTK rejects
// it. webview_go makes a bare GtkWindow with no icon. On X11 this is the taskbar icon
// (_NET_WM_ICON); on Wayland the taskbar takes its icon from the .desktop the window's
// app_id matches instead, so there it only dresses the window — see the deb StartupWMClass.
func setWindowIcon(window unsafe.Pointer, path string) bool {
	cpath := C.CString(path)
	defer C.free(unsafe.Pointer(cpath))
	return C.set_window_icon(window, cpath) != 0
}
