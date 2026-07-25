// Save a downloaded file instead of rendering it into the window -- the Linux (WebKitGTK)
// half of what download_darwin.m does for WKWebView. See launcher/README.md.
//
// webview_go wires no download handling, so WebKitGTK's defaults apply: a response it can
// display (Adminer's SQL export is text/plain) it renders into the window, and one it cannot
// it tries to download but, with no decide-destination handler, fails with nowhere to write.
// Either way Export > save was broken. This forces the download and writes it wherever a
// native Save dialog points, with a small progress window while it runs.

#include <string.h>
#include <gtk/gtk.h>
#include <webkit2/webkit2.h>
#include <libsoup/soup.h>
#include <glib/gstdio.h>
#include "i18n_gen.h" // adTr(): the native-shell strings, generated from app/src/I18n/Locale/*.php

// The app's main GtkWindow, kept to parent the Save dialog and the progress window.
static GtkWidget *g_main_window = NULL;

typedef struct {
	WebKitDownload *download; // so closing the progress window can cancel it
	GtkWidget *win;
	GtkWidget *bar;
	GtkWidget *label;
	gchar *name;        // shown in the progress label
	gchar *final_path;  // the .part is renamed onto this once the download finishes
} DlCtx;

static void dl_ctx_free(gpointer data) {
	DlCtx *c = data;
	if (c->win) {
		gtk_widget_destroy(c->win);
	}
	g_free(c->name);
	g_free(c->final_path);
	g_free(c);
}

// Closing the progress window asks whether to cancel the download, and cancels it if so.
// Returns TRUE unconditionally so the window manager never destroys the window itself -- the
// window is only ever torn down by end_progress (which nulls c->win, the guard on_received_data
// checks). Letting the WM destroy it out from under a still-running download left dangling
// widget pointers and a stream of gtk_progress_bar_pulse GTK-CRITICAL warnings.
static gboolean on_progress_delete(GtkWidget *widget, GdkEvent *event, gpointer user_data) {
	(void) widget;
	(void) event;
	DlCtx *c = user_data;
	GtkWidget *ask = gtk_message_dialog_new(GTK_WINDOW(c->win),
		GTK_DIALOG_MODAL | GTK_DIALOG_DESTROY_WITH_PARENT,
		GTK_MESSAGE_QUESTION, GTK_BUTTONS_YES_NO, "%s", adTr("Cancel this download?"));
	gtk_window_set_title(GTK_WINDOW(ask), "Adminer Desktop");
	gtk_message_dialog_format_secondary_text(GTK_MESSAGE_DIALOG(ask), "%s", c->name);
	gint res = gtk_dialog_run(GTK_DIALOG(ask));
	gtk_widget_destroy(ask);
	if (res == GTK_RESPONSE_YES) {
		// Cancelling emits "failed" with a cancelled-by-user error, which drops the .part and
		// closes this window (on_failed -> end_progress).
		webkit_download_cancel(c->download);
	}
	return TRUE;
}

// A small window with a label and a progress bar, shown while a download runs. WebKitGTK has
// no download UI of its own, and Adminer streams most dumps with no Content-Length, so the bar
// pulses and the byte count climbing beside it ("casec.sql - 1.2 MB") is the real progress;
// when a length is known the bar fills and the label reads "written / total".
static void show_progress(DlCtx *c) {
	GtkWidget *win = gtk_window_new(GTK_WINDOW_TOPLEVEL);
	gtk_window_set_title(GTK_WINDOW(win), "Adminer Desktop");
	gtk_window_set_type_hint(GTK_WINDOW(win), GDK_WINDOW_TYPE_HINT_UTILITY);
	gtk_window_set_resizable(GTK_WINDOW(win), FALSE);
	gtk_window_set_default_size(GTK_WINDOW(win), 340, -1);
	if (g_main_window) {
		gtk_window_set_transient_for(GTK_WINDOW(win), GTK_WINDOW(g_main_window));
		gtk_window_set_position(GTK_WINDOW(win), GTK_WIN_POS_CENTER_ON_PARENT);
	} else {
		gtk_window_set_position(GTK_WINDOW(win), GTK_WIN_POS_CENTER);
	}

	GtkWidget *box = gtk_box_new(GTK_ORIENTATION_VERTICAL, 8);
	gtk_container_set_border_width(GTK_CONTAINER(box), 16);
	GtkWidget *label = gtk_label_new(c->name);
	gtk_label_set_ellipsize(GTK_LABEL(label), PANGO_ELLIPSIZE_MIDDLE);
	gtk_widget_set_halign(label, GTK_ALIGN_START);
	GtkWidget *bar = gtk_progress_bar_new();
	gtk_box_pack_start(GTK_BOX(box), label, FALSE, FALSE, 0);
	gtk_box_pack_start(GTK_BOX(box), bar, FALSE, FALSE, 0);
	gtk_container_add(GTK_CONTAINER(win), box);

	c->win = win;
	c->bar = bar;
	c->label = label;
	g_signal_connect(win, "delete-event", G_CALLBACK(on_progress_delete), c);
	gtk_widget_show_all(win);
}

static void end_progress(DlCtx *c) {
	if (c->win) {
		gtk_widget_destroy(c->win);
		c->win = NULL;
	}
}

static void on_received_data(WebKitDownload *download, guint64 length, gpointer user_data) {
	(void) length;
	DlCtx *c = user_data;
	if (!c->win) {
		return;
	}
	gchar *got = g_format_size(webkit_download_get_received_data_length(download));

	WebKitURIResponse *resp = webkit_download_get_response(download);
	gint64 total = resp ? webkit_uri_response_get_content_length(resp) : 0;
	gchar *text;
	if (total > 0) {
		gtk_progress_bar_set_fraction(GTK_PROGRESS_BAR(c->bar),
			webkit_download_get_estimated_progress(download));
		gchar *totalStr = g_format_size((guint64) total);
		text = g_strdup_printf("%s \xE2\x80\x94 %s / %s", c->name, got, totalStr);
		g_free(totalStr);
	} else {
		gtk_progress_bar_pulse(GTK_PROGRESS_BAR(c->bar));
		text = g_strdup_printf("%s \xE2\x80\x94 %s", c->name, got);
	}
	gtk_label_set_text(GTK_LABEL(c->label), text);
	g_free(text);
	g_free(got);
}

static void on_finished(WebKitDownload *download, gpointer user_data) {
	DlCtx *c = user_data;
	const gchar *part = webkit_download_get_destination(download);
	// Rename the finished .part onto the real name -- that in-place rename is what marks it
	// done, and same-directory keeps it atomic. The Save dialog already took the overwrite
	// decision, so overwrite is allowed.
	if (part && c->final_path) {
		g_rename(part, c->final_path);
	}
	end_progress(c);

	// A completion dialog to dismiss, confirming where the export landed. Modal, and safe to
	// run here: download signals fire on the GTK main thread, so gtk_dialog_run's nested loop
	// is fine.
	GtkWidget *dialog = gtk_message_dialog_new(
		g_main_window ? GTK_WINDOW(g_main_window) : NULL,
		GTK_DIALOG_MODAL | GTK_DIALOG_DESTROY_WITH_PARENT,
		GTK_MESSAGE_INFO, GTK_BUTTONS_OK, adTr("Saved %s"), c->name);
	gtk_window_set_title(GTK_WINDOW(dialog), "Adminer Desktop");
	gtk_message_dialog_format_secondary_text(GTK_MESSAGE_DIALOG(dialog), "%s", c->final_path);
	gtk_dialog_run(GTK_DIALOG(dialog));
	gtk_widget_destroy(dialog);
}

static void on_failed(WebKitDownload *download, GError *error, gpointer user_data) {
	DlCtx *c = user_data;
	const gchar *part = webkit_download_get_destination(download);
	if (part) {
		g_unlink(part); // drop the partial .part
	}
	// A user cancel comes through here too -- that is not a failure, so do not log it as one.
	if (!g_error_matches(error, WEBKIT_DOWNLOAD_ERROR, WEBKIT_DOWNLOAD_ERROR_CANCELLED_BY_USER)) {
		g_warning("adminer-desktop: download failed: %s", error ? error->message : "unknown");
	}
	end_progress(c);
}

static gboolean on_decide_destination(WebKitDownload *download, gchar *suggested_filename,
		gpointer user_data) {
	(void) user_data;
	GtkWindow *parent = g_main_window ? GTK_WINDOW(g_main_window) : NULL;
	GtkFileChooserNative *dialog = gtk_file_chooser_native_new(
		adTr("Save Export"), parent, GTK_FILE_CHOOSER_ACTION_SAVE, "_Save", "_Cancel");
	GtkFileChooser *chooser = GTK_FILE_CHOOSER(dialog);
	gtk_file_chooser_set_do_overwrite_confirmation(chooser, TRUE);
	gtk_file_chooser_set_current_name(chooser,
		(suggested_filename && *suggested_filename) ? suggested_filename : "download");

	gint res = gtk_native_dialog_run(GTK_NATIVE_DIALOG(dialog));
	if (res != GTK_RESPONSE_ACCEPT) {
		g_object_unref(dialog);
		webkit_download_cancel(download); // cancelled: the download stops here
		return TRUE;
	}
	gchar *final_path = gtk_file_chooser_get_filename(chooser);
	g_object_unref(dialog);

	// Download to a sibling ".part" in the very folder the user picked -- while it grows it is
	// plainly a still-downloading file, the way a browser marks one -- then rename it onto the
	// real name once it finishes (see on_finished). Same directory keeps that rename atomic and
	// off any cross-volume move. A leftover .part from a cancelled run is allowed to be
	// overwritten rather than blocking the download.
	gchar *part_path = g_strconcat(final_path, ".part", NULL);

	DlCtx *c = g_new0(DlCtx, 1);
	c->download = download;
	c->name = g_path_get_basename(final_path);
	c->final_path = final_path; // takes ownership
	g_object_set_data_full(G_OBJECT(download), "dlctx", c, dl_ctx_free);

	webkit_download_set_allow_overwrite(download, TRUE);
	webkit_download_set_destination(download, part_path);
	g_free(part_path);

	g_signal_connect(download, "received-data", G_CALLBACK(on_received_data), c);
	g_signal_connect(download, "finished", G_CALLBACK(on_finished), c);
	g_signal_connect(download, "failed", G_CALLBACK(on_failed), c);

	show_progress(c);
	return TRUE;
}

static void on_download_started(WebKitWebContext *context, WebKitDownload *download,
		gpointer user_data) {
	(void) context;
	(void) user_data;
	g_signal_connect(download, "decide-destination", G_CALLBACK(on_decide_destination), NULL);
}

static gboolean on_decide_policy(WebKitWebView *web_view, WebKitPolicyDecision *decision,
		WebKitPolicyDecisionType type, gpointer user_data) {
	(void) web_view;
	(void) user_data;
	if (type != WEBKIT_POLICY_DECISION_TYPE_RESPONSE) {
		return FALSE; // navigation and new-window decisions: leave to the default handler
	}
	WebKitResponsePolicyDecision *rd = WEBKIT_RESPONSE_POLICY_DECISION(decision);
	WebKitURIResponse *resp = webkit_response_policy_decision_get_response(rd);

	gboolean attachment = FALSE;
	SoupMessageHeaders *headers = webkit_uri_response_get_http_headers(resp);
	if (headers) {
		const char *cd = soup_message_headers_get_one(headers, "Content-Disposition");
		if (cd) {
			gchar *lc = g_ascii_strdown(cd, -1);
			attachment = strstr(lc, "attachment") != NULL;
			g_free(lc);
		}
	}
	// A type the view cannot render (application/octet-stream, a .zip) would fail to display
	// anyway, so treat it as a download too -- attachment header or not.
	if (attachment || !webkit_response_policy_decision_is_mime_type_supported(rd)) {
		webkit_policy_decision_download(decision);
		return TRUE;
	}
	return FALSE;
}

// installDownloads attaches the download handling to webview_go's WebKitWebView. The GtkWindow
// holds the WebKitWebView as its only child (gtk_container_add), so gtk_bin_get_child reaches
// it. Returns 0 if the child is not a WebKitWebView -- nothing to attach to.
int installDownloads(void *window) {
	GtkWidget *win = GTK_WIDGET(window);
	GtkWidget *child = gtk_bin_get_child(GTK_BIN(win));
	if (!child || !WEBKIT_IS_WEB_VIEW(child)) {
		return 0;
	}
	g_main_window = win;
	WebKitWebView *web_view = WEBKIT_WEB_VIEW(child);
	g_signal_connect(web_view, "decide-policy", G_CALLBACK(on_decide_policy), NULL);

	WebKitWebContext *ctx = webkit_web_view_get_context(web_view);
	g_signal_connect(ctx, "download-started", G_CALLBACK(on_download_started), NULL);
	return 1;
}
