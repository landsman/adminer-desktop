#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>
#include <objc/runtime.h>
#include "menu_darwin.h"
#import "webview_darwin.h"

/* Save a downloaded file instead of rendering it into the window.
 *
 * WKWebView has no download handling of its own: a response it can display it displays and one
 * it cannot it drops, so a Content-Disposition: attachment is ignored either way. That is why
 * Adminer's Export > save — which sends the dump as an attachment — printed the SQL into the
 * window instead of offering a Save panel. This adds a navigation delegate that turns an
 * attachment response into a WKDownload and writes it wherever a save panel points, showing a
 * small floating progress panel while it runs. See launcher/README.md.
 *
 * WKDownload is macOS 11.3; the app's floor is 11.0, so below that the response is left to
 * display as before — a handful of old point-releases where this simply does nothing.
 */
API_AVAILABLE(macos(11.3))
@interface AdminerDesktopNavDelegate : NSObject <WKNavigationDelegate, WKDownloadDelegate, NSWindowDelegate>
@property (nonatomic, strong) NSPanel *progressPanel;
@property (nonatomic, strong) NSProgressIndicator *progressBar;
@property (nonatomic, strong) NSTextField *progressLabel;
@property (nonatomic, strong) NSProgress *observedProgress;
@property (nonatomic, copy) NSString *downloadName;
@property (nonatomic, strong) WKDownload *activeDownload API_AVAILABLE(macos(11.3));
@end

/* Address used as the key to stash a download's [part, final] URLs on the WKDownload itself. */
static const char kDownloadDest = 0;

@implementation AdminerDesktopNavDelegate

- (void)webView:(WKWebView *)webView
		decidePolicyForNavigationResponse:(WKNavigationResponse *)navigationResponse
		decisionHandler:(void (^)(WKNavigationResponsePolicy))decisionHandler {
	if (@available(macOS 11.3, *)) {
		BOOL attachment = NO;
		NSURLResponse *response = navigationResponse.response;
		if ([response isKindOfClass:[NSHTTPURLResponse class]]) {
			NSString *disposition = [[(NSHTTPURLResponse *) response allHeaderFields]
				objectForKey:@"Content-Disposition"];
			attachment = disposition
				&& [disposition rangeOfString:@"attachment" options:NSCaseInsensitiveSearch].location != NSNotFound;
		}
		// A type the view cannot render (application/octet-stream, a .zip) would fail to display
		// anyway, so treat it as a download too — attachment header or not.
		if (attachment || !navigationResponse.canShowMIMEType) {
			decisionHandler(WKNavigationResponsePolicyDownload);
			return;
		}
	}
	decisionHandler(WKNavigationResponsePolicyAllow);
}

- (void)webView:(WKWebView *)webView
		navigationResponse:(WKNavigationResponse *)navigationResponse
		didBecomeDownload:(WKDownload *)download API_AVAILABLE(macos(11.3)) {
	download.delegate = self;
}

- (void)download:(WKDownload *)download
		decideDestinationUsingResponse:(NSURLResponse *)response
		suggestedFilename:(NSString *)suggestedFilename
		completionHandler:(void (^)(NSURL *))completionHandler API_AVAILABLE(macos(11.3)) {
	NSSavePanel *panel = [NSSavePanel savePanel];
	[panel setNameFieldStringValue:suggestedFilename ?: @"download"];
	[panel setCanCreateDirectories:YES];
	if ([panel runModal] != NSModalResponseOK) {
		completionHandler(nil); // cancelled: the download stops here
		return;
	}
	NSURL *finalURL = [panel URL];
	// Download to a sibling ".part" in the very folder the user picked, so while it grows it is
	// plainly a still-downloading file -- the way a browser marks one -- then rename it to the
	// real name once it finishes. Same directory, so that finishing rename is atomic and never
	// has to cross a volume (moving out of the temp dir does, and silently fails between the
	// APFS temp and home volumes). A leftover .part from a cancelled run would block WKDownload,
	// which will not write over a file that exists, so clear it first.
	NSURL *partURL = [finalURL URLByAppendingPathExtension:@"part"];
	[[NSFileManager defaultManager] removeItemAtURL:partURL error:nil];
	objc_setAssociatedObject(download, &kDownloadDest, @[partURL, finalURL],
		OBJC_ASSOCIATION_RETAIN_NONATOMIC);
	completionHandler(partURL);
	[self showProgressForDownload:download filename:finalURL.lastPathComponent];
}

- (void)downloadDidFinish:(WKDownload *)download API_AVAILABLE(macos(11.3)) {
	[self hideProgress];
	self.activeDownload = nil;
	NSArray<NSURL *> *urls = objc_getAssociatedObject(download, &kDownloadDest);
	if (!urls) {
		return;
	}
	NSFileManager *fm = [NSFileManager defaultManager];
	// The panel already took the overwrite confirmation, so clear any old file and rename the
	// finished .part onto the real name -- that in-place rename is what marks it done.
	[fm removeItemAtURL:urls[1] error:nil];
	[fm moveItemAtURL:urls[0] toURL:urls[1] error:nil];

	// A completion alert to dismiss, confirming where the export landed -- parity with the Linux
	// build's "Saved ..." dialog.
	NSAlert *alert = [[NSAlert alloc] init];
	alert.messageText = [NSString stringWithFormat:NSLocalizedString(@"Saved %@", nil), urls[1].lastPathComponent];
	alert.informativeText = urls[1].path;
	[alert addButtonWithTitle:NSLocalizedString(@"OK", nil)];
	[alert runModal];
}

- (void)download:(WKDownload *)download
		didFailWithError:(NSError *)error
		resumeData:(NSData *)resumeData API_AVAILABLE(macos(11.3)) {
	[self hideProgress];
	self.activeDownload = nil;
	NSArray<NSURL *> *urls = objc_getAssociatedObject(download, &kDownloadDest);
	if (urls) {
		[[NSFileManager defaultManager] removeItemAtURL:urls[0] error:nil]; // drop the .part
	}
	// A user cancel (closing the progress panel) comes through here too -- that is not a
	// failure, so do not log it as one.
	if (![error.domain isEqualToString:NSURLErrorDomain] || error.code != NSURLErrorCancelled) {
		NSLog(@"adminer-desktop: download failed: %@", error);
	}
}

// Closing the progress panel asks whether to cancel the download, and cancels it if so --
// parity with the Linux build. Returns NO either way: the panel is only ever taken down by
// hideProgress (on finish, or on the failure the cancel triggers), never by the close button.
- (BOOL)windowShouldClose:(NSWindow *)sender API_AVAILABLE(macos(11.3)) {
	if (!self.activeDownload) {
		return NO;
	}
	NSAlert *alert = [[NSAlert alloc] init];
	alert.messageText = NSLocalizedString(@"Cancel this download?", nil);
	alert.informativeText = self.downloadName ?: @"";
	[alert addButtonWithTitle:NSLocalizedString(@"Cancel Download", nil)];
	[alert addButtonWithTitle:NSLocalizedString(@"Keep Downloading", nil)];
	if ([alert runModal] == NSAlertFirstButtonReturn) {
		// Cancelling reports NSURLErrorCancelled through didFailWithError:, which drops the
		// .part and hides the panel.
		[self.activeDownload cancel:nil];
	}
	return NO;
}

/* A small floating panel with a progress bar, shown while a download runs -- WKDownload has no
 * progress UI of its own, and its NSProgress is the only thing there is to drive one. */
- (void)buildProgressPanelIfNeeded {
	if (self.progressPanel) {
		return;
	}
	// Closable so its close button is the cancel affordance; windowShouldClose: intercepts it.
	NSPanel *panel = [[NSPanel alloc]
		initWithContentRect:NSMakeRect(0, 0, 340, 72)
		styleMask:(NSWindowStyleMaskTitled | NSWindowStyleMaskClosable | NSWindowStyleMaskUtilityWindow
			| NSWindowStyleMaskNonactivatingPanel)
		backing:NSBackingStoreBuffered
		defer:NO];
	[panel setLevel:NSFloatingWindowLevel];
	[panel setFloatingPanel:YES];
	[panel setHidesOnDeactivate:NO];
	[panel setReleasedWhenClosed:NO];
	[panel setTitle:@"Adminer Desktop"];
	[panel setDelegate:self];

	NSTextField *label = [NSTextField labelWithString:@""];
	[label setFrame:NSMakeRect(16, 38, 308, 18)];
	[label setLineBreakMode:NSLineBreakByTruncatingMiddle];
	[[panel contentView] addSubview:label];

	NSProgressIndicator *bar = [[NSProgressIndicator alloc]
		initWithFrame:NSMakeRect(16, 16, 308, 16)];
	[bar setStyle:NSProgressIndicatorStyleBar];
	[bar setIndeterminate:YES];
	[[panel contentView] addSubview:bar];

	self.progressPanel = panel;
	self.progressLabel = label;
	self.progressBar = bar;
}

- (void)showProgressForDownload:(WKDownload *)download filename:(NSString *)filename
		API_AVAILABLE(macos(11.3)) {
	[self buildProgressPanelIfNeeded];
	self.activeDownload = download; // so the panel's close button can cancel it
	// One export at a time in practice; if another is somehow mid-flight, it hands over the panel.
	if (self.observedProgress) {
		[self.observedProgress removeObserver:self forKeyPath:@"completedUnitCount"];
	}
	self.downloadName = filename ?: @"";
	[self.progressLabel setStringValue:self.downloadName];
	[self.progressBar setIndeterminate:YES];
	[self.progressBar startAnimation:nil];

	// Centred near the bottom of the app's window, above everything.
	NSWindow *host = [NSApp keyWindow] ?: [NSApp mainWindow];
	if (host) {
		NSRect h = [host frame], p = [self.progressPanel frame];
		[self.progressPanel setFrameOrigin:NSMakePoint(NSMidX(h) - p.size.width / 2, h.origin.y + 48)];
	} else {
		[self.progressPanel center];
	}
	[self.progressPanel orderFrontRegardless];

	// completedUnitCount, not fractionCompleted: Adminer streams most dumps with no
	// Content-Length, so there is no total and the fraction never moves -- but the bytes
	// written still climb, and that is the real progress we show.
	self.observedProgress = download.progress;
	[self.observedProgress addObserver:self forKeyPath:@"completedUnitCount" options:0 context:NULL];
}

- (void)hideProgress {
	if (self.observedProgress) {
		[self.observedProgress removeObserver:self forKeyPath:@"completedUnitCount"];
		self.observedProgress = nil;
	}
	[self.progressBar stopAnimation:nil];
	[self.progressPanel orderOut:nil];
}

- (void)observeValueForKeyPath:(NSString *)keyPath
		ofObject:(id)object
		change:(NSDictionary<NSKeyValueChangeKey, id> *)change
		context:(void *)context {
	if (![object isKindOfClass:[NSProgress class]]) {
		return;
	}
	// KVO fires on whatever thread WebKit advances the download on; the UI has to move on main.
	NSProgress *progress = object;
	int64_t done = progress.completedUnitCount;
	int64_t total = progress.totalUnitCount;
	double fraction = progress.fractionCompleted;
	dispatch_async(dispatch_get_main_queue(), ^{
		// MB, and GB once it is that big -- never raw bytes or KB, however small it starts.
		static NSByteCountFormatter *fmt;
		static dispatch_once_t once;
		dispatch_once(&once, ^{
			fmt = [[NSByteCountFormatter alloc] init];
			fmt.allowedUnits = NSByteCountFormatterUseMB | NSByteCountFormatterUseGB;
			fmt.countStyle = NSByteCountFormatterCountStyleFile;
		});
		NSString *doneStr = [fmt stringFromByteCount:done];
		if (total > 0) {
			// A known length: fill the bar and show "written / total".
			if ([self.progressBar isIndeterminate]) {
				[self.progressBar stopAnimation:nil];
				[self.progressBar setIndeterminate:NO];
				[self.progressBar setMinValue:0];
				[self.progressBar setMaxValue:1.0];
			}
			[self.progressBar setDoubleValue:fraction];
			[self.progressLabel setStringValue:[NSString stringWithFormat:@"%@ — %@ / %@",
				self.downloadName, doneStr, [fmt stringFromByteCount:total]]];
		} else {
			// No total (a streamed dump with no Content-Length): the barber-pole keeps moving,
			// and the byte count climbing beside it is the real progress.
			[self.progressLabel setStringValue:[NSString stringWithFormat:@"%@ — %@",
				self.downloadName, doneStr]];
		}
	});
}

@end

/* Weakly held by the webview, like the UI delegate, so keep a strong reference alive. */
static id navDelegate = nil;

int installDownloads(void *nsWindow) {
	if (@available(macOS 11.3, *)) {
		WKWebView *webView = findWebView([(NSWindow *) nsWindow contentView]);
		if (!webView) {
			return 0;
		}
		navDelegate = [[AdminerDesktopNavDelegate alloc] init];
		[webView setNavigationDelegate:navDelegate];
		return 1;
	}
	return 0;
}
