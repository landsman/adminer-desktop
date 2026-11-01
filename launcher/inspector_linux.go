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

// F12 or Ctrl+Shift+I opens the inspector, and reopens it after a close -- closing its window
// only closes it, WebKit keeps the inspector itself alive and show() brings it back. This is
// the only way in on Linux: there is no menu bar to hang a Develop menu on, and right-clicking
// the page for Inspect Element is no help when the page's own context menu is what is being
// debugged.
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
	// Available, not open. -debug is also how the window is dressed as another OS and how the
	// page is checked by hand, and an inspector taking half the window on every one of those
	// runs is in the way; macOS does the same, where setInspectable only puts the page in
	// Safari's Develop menu and opening it is yours. The key below is the switch.
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

// How to open it, GTK having no Develop menu to find it in.
const inspectorHint = "press F12 or Ctrl+Shift+I for it, and console messages go to the log either way"

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
