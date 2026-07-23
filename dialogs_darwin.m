#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>
#include <objc/runtime.h>
#include "menu_darwin.h"

/* JavaScript alert(), confirm() and prompt().
 *
 * WKWebView does not implement these itself: it hands each one to its WKUIDelegate, and
 * if the delegate does not implement the method, nothing happens at all. webview_go's
 * delegate implements only the file picker, so in this app every alert() was silent and
 * every confirm() returned false.
 *
 * That is not cosmetic. Adminer wires confirm() onto every destructive action
 * (include/html.inc.php:124), and an onclick returning false cancels the action -- so
 * dropping a table, deleting rows and truncating quietly did nothing at all.
 *
 * The methods are added to webview's own delegate class at runtime rather than replacing
 * the delegate, so the file picker it already provides keeps working.
 */

static void runAlert(id self, SEL cmd, id webView, NSString *message, id frame,
		void (^completionHandler)(void)) {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:message ?: @""];
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
	[alert runModal];
	completionHandler();
}

static void runConfirm(id self, SEL cmd, id webView, NSString *message, id frame,
		void (^completionHandler)(BOOL)) {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:message ?: @""];
	// First button is the default one, so return activates it -- the same as every other
	// confirmation sheet on the system.
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"Cancel", nil)];
	completionHandler([alert runModal] == NSAlertFirstButtonReturn);
}

static void runPrompt(id self, SEL cmd, id webView, NSString *prompt, NSString *defaultText,
		id frame, void (^completionHandler)(NSString *)) {
	NSAlert *alert = [[NSAlert alloc] init];
	[alert setMessageText:prompt ?: @""];
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"Cancel", nil)];

	NSTextField *field = [[NSTextField alloc] initWithFrame:NSMakeRect(0, 0, 320, 24)];
	[field setStringValue:defaultText ?: @""];
	[alert setAccessoryView:field];
	[[alert window] setInitialFirstResponder:field];

	// nil, not an empty string, when cancelled: that is how WebKit distinguishes a
	// dismissed prompt from one confirmed with no text.
	completionHandler([alert runModal] == NSAlertFirstButtonReturn ? [field stringValue] : nil);
}

void installJSDialogs(void) {
	// Created lazily by webview when the view is built, so this has to run after that.
	Class cls = objc_getClass("WebviewWKUIDelegate");
	if (!cls) {
		return;
	}
	class_addMethod(cls,
		@selector(webView:runJavaScriptAlertPanelWithMessage:initiatedByFrame:completionHandler:),
		(IMP) runAlert, "v@:@@@@");
	class_addMethod(cls,
		@selector(webView:runJavaScriptConfirmPanelWithMessage:initiatedByFrame:completionHandler:),
		(IMP) runConfirm, "v@:@@@@");
	class_addMethod(cls,
		@selector(webView:runJavaScriptTextInputPanelWithPrompt:defaultText:initiatedByFrame:completionHandler:),
		(IMP) runPrompt, "v@:@@@@@");
}
