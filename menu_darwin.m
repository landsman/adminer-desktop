#import <Cocoa/Cocoa.h>
#include "menu_darwin.h"

// Implemented in Go, exported via //export.
void goMenuAdminer(void);
void goMenuEditor(void);
void goMenuLogs(void);
void goMenuAdminerSite(void);
void goMenuRepo(void);
void goMenuIssues(void);

static NSString *aboutVersion = nil;
static NSString *aboutCredits = nil;

// NSMenuItem is target-action, not blocks, so reaching Go needs an object with real
// selectors on it. This class is the whole bridge.
@interface AdminerMenuTarget : NSObject
- (void)openAdminer:(id)sender;
- (void)openEditor:(id)sender;
- (void)openLogs:(id)sender;
- (void)openAbout:(id)sender;
- (void)openAdminerSite:(id)sender;
- (void)openRepo:(id)sender;
- (void)openIssues:(id)sender;
@end

@implementation AdminerMenuTarget
- (void)openAdminer:(id)sender { goMenuAdminer(); }
- (void)openEditor:(id)sender { goMenuEditor(); }
- (void)openLogs:(id)sender { goMenuLogs(); }
- (void)openAdminerSite:(id)sender { goMenuAdminerSite(); }
- (void)openRepo:(id)sender { goMenuRepo(); }
- (void)openIssues:(id)sender { goMenuIssues(); }

// The standard About panel, not a window of our own: it already renders a version, a
// scrollable credits field and the app icon, and it looks like every other mac app.
- (void)openAbout:(id)sender {
	[NSApp activateIgnoringOtherApps:YES];
	[NSApp orderFrontStandardAboutPanelWithOptions:@{
		@"ApplicationName": @"Adminer",
		@"ApplicationVersion": aboutVersion,
		// Blank, or the panel appends its own build number after ours.
		@"Version": @"",
		@"Credits": [[NSAttributedString alloc] initWithString:aboutCredits],
	}];
}
@end

// Retained for the process lifetime: NSMenu does not own its items' targets, and a
// collected target turns every menu click into a silent no-op.
static AdminerMenuTarget *menuTarget = nil;

static void addItem(NSMenu *menu, NSString *title, SEL action, NSString *key, id target) {
	NSMenuItem *item = [[NSMenuItem alloc] initWithTitle:title action:action keyEquivalent:key];
	[item setTarget:target];
	[menu addItem:item];
}

static NSString *const kLastApp = @"lastApp";

void setLastApp(const char *name) {
	[[NSUserDefaults standardUserDefaults] setObject:[NSString stringWithUTF8String:name] forKey:kLastApp];
}

char *lastApp(void) {
	NSString *v = [[NSUserDefaults standardUserDefaults] stringForKey:kLastApp];
	return strdup(v ? [v UTF8String] : "");
}

void installMenu(const char *version, const char *adminerVersion, const char *frankenphpVersion) {
	aboutVersion = [NSString stringWithUTF8String:version];
	// Assembled here rather than in Go so that every user-visible string in the app lives
	// in Localizable.strings and a translator has exactly one file to work with.
	// Short key, unlike the menu items: a multi-line format string makes a poor key, and
	// en.lproj always ships so there is nothing to fall back to.
	aboutCredits = [NSString stringWithFormat:NSLocalizedString(@"credits.format", nil),
		[NSString stringWithUTF8String:adminerVersion],
		[NSString stringWithUTF8String:frankenphpVersion],
		[NSString stringWithUTF8String:version]];

	// macOS titles the application menu from the process name, not from any NSMenu title
	// we set. Unbundled that name is the executable, so the menu would read
	// "adminer-desktop". Setting it here fixes the bundle and `make run` alike.
	[[NSProcessInfo processInfo] setProcessName:@"Adminer"];

	menuTarget = [[AdminerMenuTarget alloc] init];

	NSMenu *bar = [[NSMenu alloc] init];

	NSMenuItem *appItem = [[NSMenuItem alloc] init];
	[bar addItem:appItem];
	NSMenu *appMenu = [[NSMenu alloc] initWithTitle:@"Adminer"];
	// Also in the app menu, because that is where mac users reflexively look for it.
	addItem(appMenu, NSLocalizedString(@"About Adminer", nil), @selector(openAbout:), @"", menuTarget);
	[appMenu addItem:[NSMenuItem separatorItem]];
	addItem(appMenu, @"Adminer", @selector(openAdminer:), @"1", menuTarget);
	addItem(appMenu, @"Editor", @selector(openEditor:), @"2", menuTarget);
	[appMenu addItem:[NSMenuItem separatorItem]];
	addItem(appMenu, NSLocalizedString(@"Open Logs", nil), @selector(openLogs:), @"l", menuTarget);
	[appMenu addItem:[NSMenuItem separatorItem]];
	// nil target: Quit travels the responder chain to NSApp, which is what makes Cmd-Q
	// behave like every other mac app.
	addItem(appMenu, NSLocalizedString(@"Quit Adminer", nil), @selector(terminate:), @"q", nil);
	[appItem setSubmenu:appMenu];

	NSMenuItem *helpItem = [[NSMenuItem alloc] init];
	[bar addItem:helpItem];
	NSMenu *helpMenu = [[NSMenu alloc] initWithTitle:NSLocalizedString(@"Help", nil)];
	addItem(helpMenu, NSLocalizedString(@"About Adminer", nil), @selector(openAbout:), @"", menuTarget);
	[helpMenu addItem:[NSMenuItem separatorItem]];
	addItem(helpMenu, NSLocalizedString(@"Adminer Website", nil), @selector(openAdminerSite:), @"", menuTarget);
	addItem(helpMenu, NSLocalizedString(@"adminer-desktop on GitHub", nil), @selector(openRepo:), @"", menuTarget);
	addItem(helpMenu, NSLocalizedString(@"Report an Issue", nil), @selector(openIssues:), @"", menuTarget);
	[helpItem setSubmenu:helpMenu];

	[NSApp setMainMenu:bar];
}
