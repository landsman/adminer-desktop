// One case of downloads.sh against a real WebKitGTK, through launcher/download_linux.c as the
// app installs it: load the page, click #go, print what happened as one line and exit.
//   saved <name>   a download finished, under the name its Save dialog was offered, at <dest>
//   page <text>    another page replaced this one
//   nothing        neither within 10 s
// Only the dialogs are stubbed; the download, its .part and the rename run as they do in the app.
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <gtk/gtk.h>
#include <webkit2/webkit2.h>

int installDownloads(void *window);

static const char *dest;
static gchar *name;
static WebKitWebView *view;
static int polls;
static gboolean clicked;

// Defined in this executable, these take the place of libgtk's for download_linux.c, so no
// dialog opens: the Save dialog is accepted with <dest>, and the "Saved" one reports.
void gtk_file_chooser_set_current_name(GtkFileChooser *chooser, const gchar *suggested) {
	(void) chooser;
	name = g_strdup(suggested);
}

gint gtk_native_dialog_run(GtkNativeDialog *dialog) {
	(void) dialog;
	return GTK_RESPONSE_ACCEPT;
}

gchar *gtk_file_chooser_get_filename(GtkFileChooser *chooser) {
	(void) chooser;
	return g_strdup(dest);
}

gint gtk_dialog_run(GtkDialog *dialog) {
	(void) dialog;
	printf("saved %s\n", name);
	exit(0);
}

static void on_text(GObject *source, GAsyncResult *res, gpointer user_data) {
	(void) source;
	(void) user_data;
	JSCValue *value = webkit_web_view_evaluate_javascript_finish(view, res, NULL);
	if (!value) {
		return; // mid-navigation
	}
	gchar *text = jsc_value_to_string(value);
	gboolean ready = strstr(text, "ready") != NULL;
	if (!clicked && ready) {
		clicked = TRUE;
		webkit_web_view_evaluate_javascript(view, "document.getElementById('go').click()", -1,
			NULL, NULL, NULL, NULL, NULL);
	} else if (clicked && !ready) {
		printf("page %s\n", g_strstrip(text));
		exit(0);
	}
	g_free(text);
	g_object_unref(value);
}

static gboolean poll(gpointer user_data) {
	(void) user_data;
	if (++polls > 50) {
		puts("nothing");
		exit(0);
	}
	webkit_web_view_evaluate_javascript(view, "document.body ? document.body.innerText : ''", -1,
		NULL, NULL, NULL, on_text, NULL);
	return G_SOURCE_CONTINUE;
}

int main(int argc, char **argv) {
	if (argc != 3) {
		fprintf(stderr, "usage: %s <url> <dest>\n", argv[0]);
		return 2;
	}
	dest = argv[2];
	gtk_init(&argc, &argv);
	GtkWidget *window = gtk_window_new(GTK_WINDOW_TOPLEVEL);
	view = WEBKIT_WEB_VIEW(webkit_web_view_new());
	gtk_container_add(GTK_CONTAINER(window), GTK_WIDGET(view));
	if (!installDownloads(window)) {
		puts("not installed");
		return 0;
	}
	webkit_web_view_load_uri(view, argv[1]);
	g_timeout_add(200, poll, NULL);
	gtk_main();
	return 1;
}
