//go:build linux

package main

/*
#cgo pkg-config: gtk+-3.0 webkit2gtk-4.1
#include <gtk/gtk.h>
#include <webkit2/webkit2.h>

// The GtkWindow holds the WebKitWebView as its only child (webview_go adds it with
// gtk_container_add), so gtk_bin_get_child reaches it -- the same route installDownloads
// takes in download_linux.c.
static WebKitWebView *inspector_web_view(void *window) {
	GtkWidget *child = gtk_bin_get_child(GTK_BIN(GTK_WIDGET(window)));
	if (!child || !WEBKIT_IS_WEB_VIEW(child)) {
		return NULL;
	}
	return WEBKIT_WEB_VIEW(child);
}

static gboolean show_inspector_idle(gpointer inspector) {
	webkit_web_inspector_show(WEBKIT_WEB_INSPECTOR(inspector));
	return G_SOURCE_REMOVE;
}

// F12 or Ctrl+Shift+I reopens the inspector. Closing its window only closes it -- WebKit
// keeps the inspector itself alive and show() brings it back -- but with no menu bar on
// Linux and nothing like Safari's Develop menu, there was no way to ask for it again: the
// one route left was right-clicking the page for Inspect Element, which is no help at all
// on a page whose own context menu is the thing being debugged.
//
// Connected to the window rather than the webview: GTK offers a key press to handlers on
// the toplevel before its default handler forwards it to whatever has focus, so this fires
// with the caret in an SQL textarea too -- where a page-level shortcut would not.
static gboolean on_inspector_key(GtkWidget *widget, GdkEventKey *event, gpointer inspector) {
	(void) widget;
	gboolean ctrl_shift_i = (event->state & GDK_CONTROL_MASK) && (event->state & GDK_SHIFT_MASK)
		&& (event->keyval == GDK_KEY_i || event->keyval == GDK_KEY_I);
	if (event->keyval == GDK_KEY_F12 || ctrl_shift_i) {
		webkit_web_inspector_show(WEBKIT_WEB_INSPECTOR(inspector));
		return TRUE; // handled: the page never sees it
	}
	return FALSE;
}

static int enable_inspector(void *window) {
	WebKitWebView *view = inspector_web_view(window);
	if (!view) {
		return 0;
	}
	WebKitSettings *settings = webkit_web_view_get_settings(view);
	// Without developer extras the inspector refuses to open and the context menu has no
	// Inspect Element, which is the only way in here: GTK has no menu bar to put one in.
	webkit_settings_set_enable_developer_extras(settings, TRUE);
	// And every console.log and JavaScript error onto stdout, which the launcher already
	// tees into the log file. On macOS Safari's console is a window away; here the log is
	// the thing you have when the page dies before the inspector is up.
	webkit_settings_set_enable_write_console_messages_to_stdout(settings, TRUE);
	WebKitWebInspector *inspector = webkit_web_view_get_inspector(view);
	if (!inspector) {
		return 0;
	}
	// Shown from an idle callback, not here: this runs before the GTK main loop starts and
	// before the window itself is shown. G_SOURCE_REMOVE so it fires once -- and through a
	// wrapper rather than casting webkit_web_inspector_show to GSourceFunc, which returns
	// void: that cast leaves the return value to whatever is in the register, and a stray
	// TRUE would keep the source alive and reopen the inspector on every idle turn.
	g_idle_add(show_inspector_idle, inspector);
	g_signal_connect(GTK_WIDGET(window), "key-press-event", G_CALLBACK(on_inspector_key), inspector);
	return 1;
}

// describe_web_view names the widget the window actually holds. The macOS counterpart
// (uiDelegate=(nil)) is what found a broken confirm() in one line; the question worth
// asking here is whether the child is the WebKitWebView every install* hangs off at all.
static const char *describe_web_view(void *window) {
	GtkWidget *child = gtk_bin_get_child(GTK_BIN(GTK_WIDGET(window)));
	if (!child) {
		return "(no child widget)";
	}
	// Owned by the GObject type system and never freed, so the caller must not free it.
	return G_OBJECT_TYPE_NAME(child);
}
*/
import "C"

import "unsafe"

// Where to look once it is on. GTK has no Develop menu, so the inspector's own window is it.
const inspectorHint = "its own window — F12 or Ctrl+Shift+I reopens it after a close, and console messages also go to the log"

// enableInspector turns WebKitGTK's Web Inspector on for the app's page. Debug builds only:
// a page with developer extras in a shipped app is an open door, the same reason the macOS
// side gates setInspectable.
func enableInspector(window unsafe.Pointer) bool {
	return C.enable_inspector(window) == 1
}

// describeUIDelegate reports what the window is holding -- the WebKitWebView, or not.
func describeUIDelegate(window unsafe.Pointer) string {
	return "child=" + C.GoString(C.describe_web_view(window))
}
