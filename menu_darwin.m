#import <Cocoa/Cocoa.h>
#include "menu_darwin.h"

// Implemented in Go, exported via //export.
void goMenuAdminer(void);
void goMenuEditor(void);
void goMenuLogs(void);

// NSMenuItem is target-action, not blocks, so reaching Go needs an object with real
// selectors on it. This class is the whole bridge.
@interface AdminerMenuTarget : NSObject
- (void)openAdminer:(id)sender;
- (void)openEditor:(id)sender;
- (void)openLogs:(id)sender;
@end

@implementation AdminerMenuTarget
- (void)openAdminer:(id)sender { goMenuAdminer(); }
- (void)openEditor:(id)sender { goMenuEditor(); }
- (void)openLogs:(id)sender { goMenuLogs(); }
@end

// Retained for the process lifetime: NSMenu does not own its items' targets, and a
// collected target turns every menu click into a silent no-op.
static AdminerMenuTarget *menuTarget = nil;

static void addItem(NSMenu *menu, NSString *title, SEL action, NSString *key, id target) {
	NSMenuItem *item = [[NSMenuItem alloc] initWithTitle:title action:action keyEquivalent:key];
	[item setTarget:target];
	[menu addItem:item];
}

void installMenu(void) {
	menuTarget = [[AdminerMenuTarget alloc] init];

	NSMenu *bar = [[NSMenu alloc] init];
	NSMenuItem *appItem = [[NSMenuItem alloc] init];
	[bar addItem:appItem];

	NSMenu *appMenu = [[NSMenu alloc] initWithTitle:@"Adminer"];
	addItem(appMenu, @"Adminer", @selector(openAdminer:), @"1", menuTarget);
	addItem(appMenu, @"Editor", @selector(openEditor:), @"2", menuTarget);
	[appMenu addItem:[NSMenuItem separatorItem]];
	addItem(appMenu, @"Open Logs", @selector(openLogs:), @"l", menuTarget);
	[appMenu addItem:[NSMenuItem separatorItem]];
	// nil target: Quit travels the responder chain to NSApp, which is what makes Cmd-Q
	// behave like every other mac app.
	addItem(appMenu, @"Quit Adminer", @selector(terminate:), @"q", nil);

	[appItem setSubmenu:appMenu];
	[NSApp setMainMenu:bar];
}
