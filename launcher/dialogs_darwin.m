#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>
#include <objc/runtime.h>
#include "menu_darwin.h"
#import "webview_darwin.h"

void goFullscreenChanged(int fullscreen);
void goScrollerStyleChanged(int legacy);

/* JavaScript alert(), confirm(), prompt() and the file picker.
 *
 * WKWebView implements none of these itself: it hands each one to its UIDelegate, and
 * with no delegate nothing happens at all. webview_go declares a delegate class but
 * never assigns it -- the view's UIDelegate is nil -- so in this app alert() was silent,
 * confirm() returned false, and <input type=file> did nothing.
 *
 * That is not cosmetic. Adminer wires confirm() onto every destructive action
 * (include/html.inc.php:124) and an onclick returning false cancels the action, so
 * dropping a table, deleting rows and truncating quietly did nothing. The file picker is
 * how a .sql file gets imported. And a target=_blank link, which needs it to open anywhere.
 */

@interface AdminerDesktopUIDelegate : NSObject <WKUIDelegate>
@end

@implementation AdminerDesktopUIDelegate

- (void)webView:(WKWebView *)webView
		runJavaScriptAlertPanelWithMessage:(NSString *)message
		initiatedByFrame:(WKFrameInfo *)frame
		completionHandler:(void (^)(void))completionHandler {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:message ?: @""];
	[alert addButtonWithTitle:NSLocalizedString(@"dialog.ok", nil)];
	[alert runModal];
	completionHandler();
}

- (void)webView:(WKWebView *)webView
		runJavaScriptConfirmPanelWithMessage:(NSString *)message
		initiatedByFrame:(WKFrameInfo *)frame
		completionHandler:(void (^)(BOOL))completionHandler {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:message ?: @""];
	// First button is the default, so return activates it, as on every system sheet.
	[alert addButtonWithTitle:NSLocalizedString(@"dialog.ok", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"dialog.cancel", nil)];
	completionHandler([alert runModal] == NSAlertFirstButtonReturn);
}

- (void)webView:(WKWebView *)webView
		runJavaScriptTextInputPanelWithPrompt:(NSString *)prompt
		defaultText:(NSString *)defaultText
		initiatedByFrame:(WKFrameInfo *)frame
		completionHandler:(void (^)(NSString *))completionHandler {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:prompt ?: @""];
	[alert addButtonWithTitle:NSLocalizedString(@"dialog.ok", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"dialog.cancel", nil)];

	NSTextField *field = [[NSTextField alloc] initWithFrame:NSMakeRect(0, 0, 320, 24)];
	[field setStringValue:defaultText ?: @""];
	[alert setAccessoryView:field];
	[[alert window] setInitialFirstResponder:field];

	// nil rather than an empty string when cancelled: that is how WebKit tells a
	// dismissed prompt from one confirmed with no text.
	completionHandler([alert runModal] == NSAlertFirstButtonReturn ? [field stringValue] : nil);
}

- (void)webView:(WKWebView *)webView
		runOpenPanelWithParameters:(WKOpenPanelParameters *)parameters
		initiatedByFrame:(WKFrameInfo *)frame
		completionHandler:(void (^)(NSArray<NSURL *> *))completionHandler {
	NSOpenPanel *panel = [NSOpenPanel openPanel];
	[panel setCanChooseFiles:YES];
	[panel setCanChooseDirectories:[parameters allowsDirectories]];
	[panel setAllowsMultipleSelection:[parameters allowsMultipleSelection]];
	completionHandler([panel runModal] == NSModalResponseOK ? [panel URLs] : nil);
}

/* A link with target=_blank asks for a new window, which this app has no place for: returning nil
 * drops it, so on its own such a click did nothing. An http(s) one opens in the system browser
 * instead — the version's link to adminer's releases, a plugin's link out. Anything else, such as
 * a javascript: or a file: URL, is still dropped. */
- (WKWebView *)webView:(WKWebView *)webView
		createWebViewWithConfiguration:(WKWebViewConfiguration *)configuration
		forNavigationAction:(WKNavigationAction *)navigationAction
		windowFeatures:(WKWindowFeatures *)windowFeatures {
	NSURL *url = navigationAction.request.URL;
	NSString *scheme = [[url scheme] lowercaseString];
	if ([scheme isEqualToString:@"http"] || [scheme isEqualToString:@"https"]) {
		[[NSWorkspace sharedWorkspace] openURL:url];
	}
	return nil;
}

@end

/* WKWebView holds its UIDelegate weakly, so something else has to keep it alive. */
static AdminerDesktopUIDelegate *uiDelegate = nil;

// Declared in webview_darwin.h and shared with download_darwin.m, so not static.
WKWebView *findWebView(NSView *view) {
	// The view itself, not just its children: webview makes the WKWebView the window's
	// contentView, so walking only subviews finds nothing.
	if ([view isKindOfClass:[WKWebView class]]) {
		return (WKWebView *) view;
	}
	for (NSView *sub in [view subviews]) {
		WKWebView *found = findWebView(sub);
		if (found) {
			return found;
		}
	}
	return nil;
}

int installJSDialogs(void *nsWindow) {
	WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
	if (!webView) {
		return 0;
	}
	uiDelegate = [[AdminerDesktopUIDelegate alloc] init];
	[webView setUIDelegate:uiDelegate];
	return 1;
}

/* Back and forward on a mouse's side buttons. They arrive as otherMouseUp with
 * buttonNumber 3 (back) and 4 (forward), and WKWebView hands them to no web handler -- a
 * JS mouseup listener never sees them -- so watch for them here and drive the view's own
 * history. A local monitor sees the events app-wide; we act on those two buttons only and
 * pass everything else through. */
int installMouseNav(void *nsWindow) {
	WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
	if (!webView) {
		return 0;
	}
	// The trackpad swipe and, on most mice, the back/forward side buttons arrive as
	// navigation gestures. This is exactly what Safari turns on to act on them -- WKWebView
	// leaves it off by default, which is why nothing happened.
	[webView setAllowsBackForwardNavigationGestures:YES];
	// Belt and suspenders for mice whose side buttons come through as discrete otherMouse
	// events (buttonNumber 3 = back, 4 = forward) rather than gestures.
	[NSEvent addLocalMonitorForEventsMatchingMask:NSEventMaskOtherMouseUp
			handler:^NSEvent *(NSEvent *event) {
		if ([event buttonNumber] == 3) {
			if ([webView canGoBack]) {
				[webView goBack];
			}
			return nil;
		}
		if ([event buttonNumber] == 4) {
			if ([webView canGoForward]) {
				[webView goForward];
			}
			return nil;
		}
		return event;
	}];
	return 1;
}

/* Reload on Cmd+R and F5. Same story as the mouse buttons: WKWebView binds neither, and
 * the page's own keydown handler (shortcuts.js) never sees the keystroke here, so catch it
 * and reload the view. Consuming the event keeps it from being handled twice; off macOS,
 * where this stub does nothing, shortcuts.js is what runs instead. */
int installReloadShortcut(void *nsWindow) {
	WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
	if (!webView) {
		return 0;
	}
	[NSEvent addLocalMonitorForEventsMatchingMask:NSEventMaskKeyDown
			handler:^NSEvent *(NSEvent *event) {
		BOOL cmdR = ([event modifierFlags] & NSEventModifierFlagCommand)
			&& [[event charactersIgnoringModifiers] isEqualToString:@"r"];
		BOOL f5 = [event keyCode] == 96;
		if (cmdR || f5) {
			[webView reload];
			return nil;
		}
		return event;
	}];
	return 1;
}

/* A live resize outruns WebKit's relayout, and the strip it has not painted yet shows the
 * webview's own background: white, even on a dark page. With that background off the window's
 * shows through instead, and the page hands its colour over (setWindowBackground). Not the
 * system appearance, which the app can override, nor underPageBackgroundColor, which stays
 * white here whatever the page paints. */
int clearWebViewBackground(void *nsWindow) {
	WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
	if (!webView) {
		return 0;
	}
	[webView setValue:@NO forKey:@"drawsBackground"];
	return 1;
}

void setWindowBackground(void *nsWindow, int r, int g, int b) {
	[(NSWindow *) nsWindow setBackgroundColor:[NSColor colorWithSRGBRed:r / 255.0
		green:g / 255.0 blue:b / 255.0 alpha:1]];
}

/* Safari's Web Inspector against the app's page: Develop > <machine> > Adminer Desktop.
 * Without it there is no console and no way to see a JavaScript error, which is how a
 * confirm() that never fired stayed invisible for as long as it did.
 * Debug builds only -- an inspectable webview in a shipped app is an open door. */
int enableInspector(void *nsWindow) {
	WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
	if (!webView || ![webView respondsToSelector:@selector(setInspectable:)]) {
		return 0;
	}
	[webView setInspectable:YES];
	return 1;
}

const char *describeUIDelegate(void *nsWindow) {
	WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
	if (!webView) {
		return strdup("(no WKWebView found)");
	}
	id delegate = [webView UIDelegate];
	return strdup([[NSString stringWithFormat:@"uiDelegate=%s",
		delegate ? class_getName([delegate class]) : "(nil)"] UTF8String]);
}

/* The window has no title bar of its own: the page runs up to the top edge and the traffic
 * lights sit in a band over the sidebar, as in Mail. The empty toolbar is what puts them there --
 * a unified toolbar's title bar is 52pt tall, centring them 26pt down, and the theme's band
 * (--ad-titlebar-h) is as tall, so what shares their line is centred on it. The title stays set
 * but hidden, for Mission Control and the Window menu. The page learns it is framed this way
 * from the class installFrameless (menu_darwin.go) adds. */
int installFramelessTitlebar(void *nsWindow) {
	NSWindow *window = (NSWindow *) nsWindow;
	[window setStyleMask:[window styleMask] | NSWindowStyleMaskFullSizeContentView];
	[window setTitlebarAppearsTransparent:YES];
	[window setTitleVisibility:NSWindowTitleHidden];
	if (@available(macOS 11.0, *)) {
		[window setToolbar:[[NSToolbar alloc] initWithIdentifier:@"adminer-desktop"]];
		[window setToolbarStyle:NSWindowToolbarStyleUnified];
	}
	// The sidebar's material behind the page, which the theme leaves transparent where the sidebar
	// is (window.css): the desktop shows through it, frosted, as in Mail, and it greys out with the
	// window in the background. The webview already paints no background of its own
	// (clearWebViewBackground); the content panel paints its own, so only the sidebar is glass.
	NSView *page = [window contentView];
	NSVisualEffectView *material = [[NSVisualEffectView alloc] initWithFrame:[page frame]];
	[material setMaterial:NSVisualEffectMaterialSidebar];
	[material setBlendingMode:NSVisualEffectBlendingModeBehindWindow];
	[material setState:NSVisualEffectStateFollowsWindowActiveState];
	[window setContentView:material];
	[page setFrame:[material bounds]];
	[page setAutoresizingMask:NSViewWidthSizable | NSViewHeightSizable];
	[material addSubview:page];
	NSNotificationCenter *center = [NSNotificationCenter defaultCenter];
	[center addObserverForName:NSWindowDidEnterFullScreenNotification object:window queue:nil
		usingBlock:^(NSNotification *n) { goFullscreenChanged(1); }];
	[center addObserverForName:NSWindowDidExitFullScreenNotification object:window queue:nil
		usingBlock:^(NSNotification *n) { goFullscreenChanged(0); }];
	return 1;
}

/* Which scrollbars the system draws: legacy ones, always on screen, while a mouse is plugged in
 * or System Settings says Always; overlay ones, shown while scrolling, with a trackpad alone. The
 * theme hides WebKit's (it keeps them on screen even when the system says overlay), which left a
 * mouse user with none at all; told the style, it shows them exactly when a native app would.
 * Answered now, and again whenever it changes — a mouse plugged in while the app runs. */
int legacyScrollers(void) {
	return [NSScroller preferredScrollerStyle] == NSScrollerStyleLegacy;
}

void watchScrollerStyle(void) {
	[[NSNotificationCenter defaultCenter] addObserverForName:NSPreferredScrollerStyleDidChangeNotification
		object:nil queue:nil usingBlock:^(NSNotification *n) { goScrollerStyleChanged(legacyScrollers()); }];
}

/* With no title bar, the page's empty header is what moves the window (window-chrome.js). The
 * page's mousedown reaches here a moment after the event itself, over IPC from WebKit's content
 * process; the current event is then usually still the press or the first move of it, which
 * AppKit takes the drag from -- as Tauri's drag regions do. Only while the button is still down
 * and that event is a press or a drag: after a quick click it can already be the release, and a
 * drag started from that sticks the window to the pointer until the next click. */
void dragWindow(void *nsWindow) {
	NSEvent *event = [NSApp currentEvent];
	BOOL pressing = event && ([event type] == NSEventTypeLeftMouseDown || [event type] == NSEventTypeLeftMouseDragged);
	if (pressing && ([NSEvent pressedMouseButtons] & 1)) {
		[(NSWindow *) nsWindow performWindowDragWithEvent:event];
	}
}

/* A double-click on a title bar does what System Settings > Desktop & Dock says: zoom (Fill and
 * Maximize, the default), minimise, or nothing. */
void titlebarDoubleClick(void *nsWindow) {
	NSString *action = [[NSUserDefaults standardUserDefaults] stringForKey:@"AppleActionOnDoubleClick"];
	if ([action isEqualToString:@"Minimize"]) {
		[(NSWindow *) nsWindow performMiniaturize:nil];
	} else if (![action isEqualToString:@"None"]) {
		[(NSWindow *) nsWindow performZoom:nil];
	}
}

/* What a click at the top of the window lands on: the page, or a title bar view in front of it.
 * Logged under -debug, since a toolbar that swallowed the band would leave the breadcrumb dead. */
const char *describeTitlebarHit(void *nsWindow) {
	NSView *frame = [[(NSWindow *) nsWindow contentView] superview];
	NSView *hit = [frame hitTest:NSMakePoint(NSWidth([frame bounds]) / 2, NSHeight([frame bounds]) - 20)];
	return strdup(hit ? class_getName([hit class]) : "(nil)");
}
