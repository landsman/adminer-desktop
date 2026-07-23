// Declarations only. The implementation lives in menu_darwin.m, because cgo compiles
// its preamble into several translation units and an @implementation there ends up
// duplicated at link time.
void installMenu(const char *version, const char *adminerVersion, const char *frankenphpVersion);


// JavaScript alert/confirm/prompt, which webview's own delegate leaves unimplemented.
void installJSDialogs(void);
