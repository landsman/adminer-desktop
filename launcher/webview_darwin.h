#import <WebKit/WebKit.h>

// The webview library makes the WKWebView the window's contentView, so it may be the content
// view itself or an ancestor of it. Returns nil if none is found. Defined in dialogs_darwin.m
// and shared with download_darwin.m -- an Objective-C header, imported only by the .m files,
// never by the cgo preamble (menu_darwin.h), which has to stay plain C.
WKWebView *findWebView(NSView *view);
