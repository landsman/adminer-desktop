// Declarations only. The implementation lives in menu_darwin.m, because cgo compiles
// its preamble into several translation units and an @implementation there ends up
// duplicated at link time.
void installMenu(const char *version, const char *adminerVersion, const char *frankenphpVersion);

// Remembering which of the two apps was last used. NSUserDefaults rather than a file of
// our own: no path to build, no directory to create, and it is inspectable with
// `defaults read org.adminer.desktop`.
void setLastApp(const char *name);
char *lastApp(void); // strdup'd, caller frees; empty string when never set
