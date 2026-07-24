#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>
#include <objc/runtime.h>
#include "menu_darwin.h"

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
 * how a .sql file gets imported.
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
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
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
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"Cancel", nil)];
	completionHandler([alert runModal] == NSAlertFirstButtonReturn);
}

- (void)webView:(WKWebView *)webView
		runJavaScriptTextInputPanelWithPrompt:(NSString *)prompt
		defaultText:(NSString *)defaultText
		initiatedByFrame:(WKFrameInfo *)frame
		completionHandler:(void (^)(NSString *))completionHandler {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:prompt ?: @""];
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"Cancel", nil)];

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

@end

/* WKWebView holds its UIDelegate weakly, so something else has to keep it alive. */
static AdminerDesktopUIDelegate *uiDelegate = nil;

static WKWebView *findWebView(NSView *view) {
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
