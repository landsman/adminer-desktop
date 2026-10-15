// One case of downloads.sh against a real WKWebView, through launcher/download_darwin.m as the
// app installs it: load the page, click #go, print what happened as one line and exit.
//   saved <name>   a download finished, under the name its Save panel was offered, at <dest>
//   opened <url>   the click went to NSWorkspace
//   page <text>    another page replaced this one
//   nothing        none of those within 10 s
// Only the Save panel, the "Saved" alert and NSWorkspace are stubbed; the download, its .part
// and the rename run as they do in the app.
#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>
#include <objc/runtime.h>
#include "menu_darwin.h"
#import "webview_darwin.h"

WKWebView *findWebView(NSView *view) {
	return [view isKindOfClass:[WKWebView class]] ? (WKWebView *) view : nil;
}

static void say(NSString *line) {
	printf("%s\n", [line UTF8String]);
	exit(0);
}

static void stub(Class cls, SEL sel, id block) {
	method_setImplementation(class_getInstanceMethod(cls, sel), imp_implementationWithBlock(block));
}

int main(int argc, char **argv) {
	@autoreleasepool {
		if (argc != 3) {
			fprintf(stderr, "usage: %s <url> <dest>\n", argv[0]);
			return 2;
		}
		NSURL *dest = [NSURL fileURLWithPath:@(argv[2])];
		__block NSString *name = nil;
		[NSApplication sharedApplication];
		stub([NSSavePanel class], @selector(runModal), ^NSModalResponse(NSSavePanel *panel) {
			name = [[panel nameFieldStringValue] copy];
			return NSModalResponseOK;
		});
		stub([NSSavePanel class], @selector(URL), ^NSURL *(NSSavePanel *panel) {
			return dest;
		});
		stub([NSAlert class], @selector(runModal), ^NSModalResponse(NSAlert *alert) {
			say([NSString stringWithFormat:@"saved %@", name]);
			return NSAlertFirstButtonReturn;
		});
		stub([NSWorkspace class], @selector(openURL:), ^BOOL(NSWorkspace *workspace, NSURL *url) {
			say([NSString stringWithFormat:@"opened %@", url]);
			return YES;
		});

		NSWindow *window = [[NSWindow alloc] initWithContentRect:NSMakeRect(0, 0, 400, 300)
			styleMask:NSWindowStyleMaskTitled backing:NSBackingStoreBuffered defer:NO];
		WKWebView *webView = [[WKWebView alloc] initWithFrame:NSMakeRect(0, 0, 400, 300)];
		[window setContentView:webView];
		if (!installDownloads(window)) {
			say(@"not installed");
		}
		[webView loadRequest:[NSURLRequest requestWithURL:[NSURL URLWithString:@(argv[1])]]];

		__block int polls = 0;
		__block BOOL clicked = NO;
		[NSTimer scheduledTimerWithTimeInterval:0.2 repeats:YES block:^(NSTimer *timer) {
			if (++polls > 50) {
				say(@"nothing");
			}
			[webView evaluateJavaScript:@"document.body ? document.body.innerText : ''"
				completionHandler:^(id result, NSError *error) {
				if (![result isKindOfClass:[NSString class]]) {
					return; // mid-navigation
				}
				BOOL ready = [result containsString:@"ready"];
				if (!clicked && ready) {
					clicked = YES;
					[webView evaluateJavaScript:@"document.getElementById('go').click()" completionHandler:nil];
				} else if (clicked && !ready) {
					say([@"page " stringByAppendingString:[result stringByTrimmingCharactersInSet:
						[NSCharacterSet whitespaceAndNewlineCharacterSet]]]);
				}
			}];
		}];
		[[NSRunLoop mainRunLoop] run];
	}
	return 1;
}
