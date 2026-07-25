# Manual testing

Some behaviour lives in the native shell — the Save dialog, the download, the progress
window — not in the page. `make e2e` drives Chromium through Playwright, so it never touches
WKWebView / WebKitGTK / WebView2 and cannot see any of it; `curl` sees only the HTTP response,
which was never the part that broke. So these get checked by hand, per platform, from a real
build (`make run`).

## Downloads

Adminer's **Export → Save** sends the dump as `Content-Disposition: attachment`. The native
WebViews would otherwise render a displayable dump (SQL is `text/plain`) straight into the
window, or drop an undisplayable one — so the shell intercepts the response, turns it into a
real download, and writes it wherever a native Save dialog points, with a progress window
while it runs. This is what to confirm.

### Setup

You need a database to export from. A throwaway one:

```sh
docker run --rm -e POSTGRES_PASSWORD=pass -e POSTGRES_DB=test -p 5432:5432 postgres
```

Then, in a second terminal:

```sh
make run
```

Log in (host `127.0.0.1`, user `postgres`, password `pass`, database `test`), or connect to
any database you already have. Seed at least one **large** table (tens of MB) so the download
runs long enough to actually watch the progress window — a two-row table finishes before the
window is visible.

### What to check — both platforms

Run **Export** from the top of a database (or a table), and for each row below confirm the
result. Use the format/output selectors on the Export page to produce each response type.

| # | Do this | Expect |
|---|---------|--------|
| 1 | Export → **Save**, format **SQL** | A **native Save dialog** opens — the dump is *not* printed into the window. |
| 2 | Pick a name, Save | File appears at that path with the dump in it. |
| 3 | Watch the folder during a large export | A sibling **`name.sql.part`** grows while downloading, then is renamed to `name.sql` when done — no `.part` left behind. |
| 4 | Watch the **progress window** during a large export | A small window titled *Adminer Desktop* shows the filename and a bar. Adminer streams with no `Content-Length`, so the bar animates (indeterminate) while the **byte count climbs** beside the name (e.g. `dump.sql — 12.4 MB`). It closes when the download finishes. |
| 5 | Export → **Save**, output **gzip** | Same as above; file is the `.gz`. |
| 6 | Export → **Save**, format **CSV** | Same; CSV is `text/csv`, still an attachment, still saved not rendered. |
| 7 | On a table with a blob/text column, use the **↓ download** link on a single field | Saved as a file (`application/octet-stream`) via the same dialog. |
| 8 | Open the Save dialog and **Cancel** | Nothing is written; no `.part` is left in the target folder. |
| 9 | Click around normally — open tables, run a SELECT, page through results | Regular pages still render in the window. Nothing tries to download a page. (Guards against the detector being too greedy.) |

Row **9** is the important negative case: if ordinary navigation ever pops a Save dialog, the
download detection is firing on responses it shouldn't.

### Linux (WebKitGTK, Wayland/X11)

The completion dialog and cancel-on-close now exist on **both platforms** (macOS equivalents in
its section). The checks here are written against Linux; **L3** is Linux-specific:

| # | Do this | Expect |
|---|---------|--------|
| L1 | Let a download **finish** | A **`Saved <name>`** dialog appears with the full path; dismiss it with **OK**. |
| L2 | During a large export, **close the progress window** (the WM close button) | A **`Cancel this download?`** confirm appears. **No** keeps it downloading; **Yes** stops the download, removes the `.part`, and closes the progress window. |
| L3 | After closing/cancelling as in L2 | **No** `Gtk-CRITICAL … gtk_progress_bar_pulse` **spam** in the terminal — the window is never destroyed out from under the running download. |

Notes:

- The Save dialog is a **`GtkFileChooserNative`**. On Wayland it comes from the desktop portal,
  so on KDE/Kubuntu it is the **KDE-native** dialog, on GNOME the GTK one — either is correct.
- The progress window is a small GTK top-level. On Wayland it may open centred on its own
  rather than over the main window; that is fine as long as it appears and updates.
- Cancelling (L2) must **not** log `download failed:` — a user cancel is not a failure.
- If **Export → Save renders the SQL into the window** instead of opening a dialog, the handler
  did not attach — check the startup log (`make logs`) for
  `downloads: could not reach the webview`.
- Debugging note: the macOS Safari Web Inspector is not available here; watch the terminal from
  `make run` for `download failed:` warnings, and the target folder for the `.part`.

### macOS (WKWebView)

- The Save dialog is a native **`NSSavePanel`**; the progress window is a floating **`NSPanel`**
  that sits near the bottom of the app window, above everything.
- Same indeterminate-bar-with-byte-count behaviour for a streamed dump; when a response *does*
  carry a `Content-Length`, the bar fills and the label reads `written / total`.
- Download handling needs **macOS 11.3+** (`WKDownload`). On 11.0–11.2 the whole path is a no-op
  and the response displays as it did before — expected, not a bug. The floor is worth a note if
  testing on an old point release.
- **Completion & cancel** (parity with Linux **L1**/**L2**): a finished download shows a
  **`Saved <name>`** alert to dismiss with **OK**; the progress panel carries a **close button**,
  and clicking it asks **`Cancel this download?`** — **Cancel Download** stops it (drops the
  `.part`, hides the panel), **Keep Downloading** leaves it running. A cancel must **not** log
  `download failed:`.
- Use the Web Inspector (`make debug`, then Safari → Develop → this machine → Adminer Desktop)
  if a response renders when it should download.

### Windows (WebView2)

Nothing custom to test — WebView2 has its own download handling and UI. Confirm once that
**Export → Save** offers to save the file (WebView2's own download bar), rather than showing it.
