// Declarations only. The implementation lives in menu_darwin.m, because cgo compiles
// its preamble into several translation units and an @implementation there ends up
// duplicated at link time.
void installMenu(const char *version, const char *adminerVersion, const char *frankenphpVersion);


// JavaScript alert/confirm/prompt, which webview's own delegate leaves unimplemented.
int installJSDialogs(void *nsWindow); // 1 when the UI delegate was attached

// Route the mouse's back/forward side buttons to the webview's history.
int installMouseNav(void *nsWindow); // 1 when the monitor was attached

const char *describeUIDelegate(void *nsWindow);
int enableInspector(void *nsWindow); // 1 if the web inspector was turned on

// The main screen's usable area at 60% — a sensible default window that never exceeds the
// display. width/height are set to 0 when no screen is available.
void defaultWindowSize(int *width, int *height);
