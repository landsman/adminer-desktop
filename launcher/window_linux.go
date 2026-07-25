//go:build linux

package main

/*
#cgo pkg-config: gtk+-3.0
#include <gtk/gtk.h>

static void hide_window(void *window) { gtk_widget_hide(GTK_WIDGET(window)); }
static void show_window(void *window) { gtk_widget_show_all(GTK_WIDGET(window)); }
*/
import "C"

import "unsafe"

// hideWindow / showWindow keep the window off-screen until the loader has actually painted.
// webview_go shows the window inside webview.New() -- before it is resized and before WebKit
// has drawn a frame -- so without this the user sees a mis-sized, unpainted rectangle for as
// long as WebKit's first paint takes (a second or two on some Linux drivers). Hiding before the
// run loop starts means it is never shown in that state; the loader's own DOMContentLoaded shows
// it again, so the window appears already sized and painted.
func hideWindow(window unsafe.Pointer) { C.hide_window(window) }

func showWindow(window unsafe.Pointer) { C.show_window(window) }
