// Declarations only. The implementation lives in menu_darwin.m, because cgo compiles
// its preamble into several translation units and an @implementation there ends up
// duplicated at link time.
void installMenu(const char *version, const char *adminerVersion, const char *frankenphpVersion);
// Calls goAppWillTerminate when the app quits through terminate:, which never returns to Go.
void watchTermination(void);


// JavaScript alert/confirm/prompt, which webview's own delegate leaves unimplemented.
int installJSDialogs(void *nsWindow); // 1 when the UI delegate was attached

// Route the mouse's back/forward side buttons to the webview's history.
int installMouseNav(void *nsWindow); // 1 when the monitor was attached

// Reload the page on Cmd+R and F5, which WKWebView otherwise ignores.
int installReloadShortcut(void *nsWindow); // 1 when the monitor was attached

// Save an attachment response (Adminer's Export > save) through a native Save panel instead
// of rendering the file into the window. macOS 11.3+.
int installDownloads(void *nsWindow); // 1 when the download handler was attached

// Let the window's appearance-following background show where WebKit has not painted yet.
int clearWebViewBackground(void *nsWindow); // 1 when the webview was found
void setWindowBackground(void *nsWindow, int r, int g, int b); // sRGB, 0-255
// The sidebar's material is drawn in the window's appearance, so it follows the app's Light/Dark override.
void setWindowAppearance(void *nsWindow, int dark); // -1 follows the system, 0 light, 1 dark

// No title bar: the page runs to the top and the traffic lights sit in the sidebar.
int installFramelessTitlebar(void *nsWindow); // 1 when applied
int legacyScrollers(void);    // 1 while the system draws always-visible scrollbars
void watchScrollerStyle(void); // calls goScrollerStyleChanged when that changes
void dragWindow(void *nsWindow);          // from a mousedown on the page's empty header
void titlebarDoubleClick(void *nsWindow); // zoom, minimise or nothing, as the system says
const char *describeTitlebarHit(void *nsWindow);

const char *describeUIDelegate(void *nsWindow);
int enableInspector(void *nsWindow); // 1 if the web inspector was turned on

// The main screen's usable area at 60% — a sensible default window that never exceeds the
// display. width/height are set to 0 when no screen is available.
void defaultWindowSize(int *width, int *height);

// -dev: fill the laptop's built-in display. 1 when there is one.
int moveToBuiltinScreen(void *nsWindow);
