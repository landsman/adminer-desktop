# launcher

The native shell. It starts FrankenPHP on a private localhost port, points a WebView at it, and
takes the server down with the window. `main.go` is the whole flow; the rest fills gaps the
WebView leaves.

`go.mod` stays at the repo root — this is a package (`./launcher`), not a module — so the build
is `go build ./launcher`.

## What the WebView doesn't do, and where we add it

macOS uses **WKWebView** (through `github.com/webview/webview_go`), which is deliberately
minimal: it renders a page and runs JavaScript, and little else. Each thing a browser does that
Adminer relies on is added natively, one `install*` per gap, in the `_darwin.m`/`_darwin.go`
files. `menu_other.go` holds the no-op stubs for Windows and Linux, whose WebViews (WebView2,
WebKitGTK) bring most of this themselves.

| gap | added by | why |
|-----|----------|-----|
| `alert` / `confirm` / `prompt`, file picker | `installJSDialogs` | WKWebView leaves its UI delegate unset, so `confirm()` returned false and silently cancelled every "Are you sure?" — dropping a table, deleting rows. |
| mouse back/forward buttons, swipe | `installMouseNav` | WKWebView binds neither the gesture nor the side buttons. |
| Cmd+R / F5 reload | `installReloadShortcut` | WKWebView binds neither; the page never sees the keystroke (off macOS `shortcuts.js` does). |
| **downloads** | `installDownloads` | see below |
| menu bar | `installMenu` | an unbundled binary gets a bare default menu. |
| Web Inspector (`-debug` only) | `enableInspector` | no console or breakpoints otherwise. |

## Downloads

WKWebView has **no download handling of its own**: a response it can display it displays, and
one it cannot it drops — either way a `Content-Disposition: attachment` is ignored. So Adminer's
**Export → save**, which sends the dump as an attachment, rendered the SQL into the window
instead of offering to save it.

`installDownloads` (in `download_darwin.m`) sets a **navigation delegate** — webview_go leaves
that slot free, it only uses the UI delegate — that turns a download into a real one:

1. **Detect** — `decidePolicyForNavigationResponse` returns `.download` for a response whose
   `Content-Disposition` is `attachment`, or whose type the view can't render (an
   `application/octet-stream`, a `.zip`).
2. **Destination** — a save panel picks the path. The file then downloads to a sibling
   **`.part`** in that same folder — so while it grows it is plainly a still-downloading file,
   the way a browser marks one — and is renamed to the real name once it finishes. The same
   directory is deliberate: that finishing rename is atomic and never crosses a volume. Moving
   out of the temp dir *does*, and silently fails between the APFS temp and home volumes — which
   is exactly the bug an earlier "download to temp, then move" version shipped.
3. **Progress** — a small floating `NSPanel` shows a bar driven off the download's `NSProgress`,
   observed by KVO on **`completedUnitCount`** (WKDownload has no progress callback of its own —
   the `NSProgress` is the only handle there is). Adminer streams most dumps with no
   `Content-Length`, so there is no total to fill a bar against and it stays an indeterminate
   barber-pole — but the bytes written still climb, so the panel shows the size counting up
   ("casec.sql — 1.2 MB") as the real progress. When a `Content-Length` is present the bar fills
   and the label reads "written / total".

`WKDownload` is macOS **11.3+**; the app's floor is 11.0, so the whole path is behind
`@available` and does nothing on the few point-releases below — there the response displays as
it did before.

**Linux** (`download_linux.c`) has the same problem — WebKitGTK renders a displayable
attachment (Adminer's SQL is `text/plain`) into the window, and tries but fails to download an
undisplayable one because webview_go sets no destination — and the same fix, in WebKitGTK's
terms: a `decide-policy` handler calls `webkit_policy_decision_download` for an `attachment`
response or an unrenderable type; `download-started` on the web context then wires the
`decide-destination` (a native `GtkFileChooserNative` Save dialog, KDE-native through the
portal on Wayland), the same sibling-`.part`-then-rename, and a small `GtkWindow` progress bar
pulsing on `received-data`. `webkit_download_set_destination` takes a path here (not the URI
older WebKitGTK wanted). Off both, on **Windows**, the stub is empty: WebView2 downloads files
itself.
