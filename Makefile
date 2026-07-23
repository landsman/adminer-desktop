# Every artifact below is derived from these two pins. Never "latest".
ADMINER_VERSION    = 5.5.1
FRANKENPHP_VERSION = 1.12.6

ADMINER_URL = https://github.com/vrana/adminer/releases/download/v$(ADMINER_VERSION)
FRANKEN_URL = https://github.com/php/frankenphp/releases/download/v$(FRANKENPHP_VERSION)

# ponytail: mac-arm64 only until someone needs to build elsewhere. M3 adds the matrix.
FRANKEN_ASSET = frankenphp-mac-arm64

.PHONY: fetch verify check check-app build run editor bundle zip logs serve clean checksums

fetch: app/adminer.php app/editor.php app/plugins-available app/designs bin/frankenphp

app/adminer.php:
	@mkdir -p app
	curl -fsSL -o $@ $(ADMINER_URL)/adminer-$(ADMINER_VERSION).php

app/editor.php:
	@mkdir -p app
	curl -fsSL -o $@ $(ADMINER_URL)/editor-$(ADMINER_VERSION).php

# The release zip is the full source tree; we want plugins/ and designs/ out of it.
# Same zip, same pinned tag as adminer.php — plugins can never drift from the core.
.cache/adminer-src.zip:
	@mkdir -p .cache
	curl -fsSL -o $@ $(ADMINER_URL)/adminer-$(ADMINER_VERSION).zip

# Shipped but NOT loaded. Everything in adminer-plugins/ is auto-enabled by
# adminer (include/plugins.inc.php:17-19), so "available" has to live elsewhere.
app/plugins-available: .cache/adminer-src.zip
	unzip -qo $< 'adminer-$(ADMINER_VERSION)/plugins/*' -d .cache
	@mkdir -p app && rm -rf $@ && mv .cache/adminer-$(ADMINER_VERSION)/plugins $@
	@mkdir -p app/adminer-plugins   # user's drop folder; empty by default

app/designs: .cache/adminer-src.zip
	unzip -qo $< 'adminer-$(ADMINER_VERSION)/designs/*' -d .cache
	@mkdir -p app && rm -rf $@ && mv .cache/adminer-$(ADMINER_VERSION)/designs $@

bin/frankenphp:
	@mkdir -p bin
	curl -fsSL -o $@ $(FRANKEN_URL)/$(FRANKEN_ASSET)
	chmod +x $@

# Hard fail on mismatch: means the release was re-uploaded or the download was tampered with.
verify: fetch
	shasum -a 256 -c checksums.txt

# Regenerate after a deliberate version bump. Review the diff.
checksums:
	shasum -a 256 app/adminer.php app/editor.php bin/frankenphp > checksums.txt

# M0: does FrankenPHP survive a 120s progressively-flushed response?
check: fetch
	./check-stream.sh

# About reads these, so it can never disagree with what is actually bundled.
VERSION = $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS = -X main.version=$(VERSION) \
	-X main.adminerVersion=$(ADMINER_VERSION) \
	-X main.frankenphpVersion=$(FRANKENPHP_VERSION)

build: fetch
	go build -ldflags "$(LDFLAGS)" -o build/adminer-desktop .

# The app itself: opens a window.
run: build
	./build/adminer-desktop

editor: build
	./build/adminer-desktop -editor

# Same startup path as `run`, minus the window — so it works over ssh and in CI.
check-app: build
	./build/adminer-desktop -headless

APP = build/Adminer.app
ICON = build/Adminer.icns

# sips and iconutil ship with macOS, so the icon needs no image tooling installed.
# ponytail: the source is adminer's own 57px pictogram, the largest that exists —
# so the big sizes are upscaled and soft. Swap in a vector if upstream ever has one.
$(ICON): assets/logo.png
	@mkdir -p build/icon.iconset
	@for s in 16 32 64 128 256 512 1024; do \
		sips -z $$s $$s $< --out build/icon.iconset/icon_$${s}x$${s}.png >/dev/null; \
	done
	@cd build/icon.iconset && for s in 16 32 128 256 512; do \
		d=$$((s * 2)); cp icon_$${d}x$${d}.png icon_$${s}x$${s}@2x.png; \
	done && rm -f icon_64x64.png icon_1024x1024.png
	iconutil -c icns build/icon.iconset -o $@
	@rm -rf build/icon.iconset

# A .app is just a directory, which is why none of this needs go:embed or a static
# single-binary build: the runtime and app/ are simply files inside it.
bundle: build $(ICON)
	rm -rf $(APP)
	mkdir -p $(APP)/Contents/MacOS $(APP)/Contents/Resources
	sed 's|@ADMINER_VERSION@|$(ADMINER_VERSION)|g' Info.plist.in > $(APP)/Contents/Info.plist
	cp build/adminer-desktop $(APP)/Contents/MacOS/
	cp bin/frankenphp $(APP)/Contents/MacOS/
	# Everything except the M0 probe and the plugins the user has not enabled.
	rsync -a --exclude '_stream.php' app/ $(APP)/Contents/Resources/app/
	# NSLocalizedString resolves against the main bundle, so the .lproj folders have to
	# sit directly in Resources. macOS then picks the language itself.
	cp -R lproj/*.lproj $(APP)/Contents/Resources/
	cp $(ICON) $(APP)/Contents/Resources/
	@echo "built $(APP) -- $$(du -sh $(APP) | cut -f1)"

# Unsigned, so a first launch elsewhere needs right-click > Open. Signing is M4.
zip: bundle
	cd build && rm -f Adminer.zip && zip -qry Adminer.zip Adminer.app
	@echo "built build/Adminer.zip -- $$(du -sh build/Adminer.zip | cut -f1)"

# PHP errors, adminer warnings and caddy's access log all land in one file, in the
# place macOS users and Console.app already look.
logs:
	open ~/Library/Logs/Adminer

# Just the server, no window. Handy for poking at it with curl.
serve: fetch
	./bin/frankenphp php-server --root app --listen 127.0.0.1:18000 --no-compress

clean:
	rm -rf app bin .cache
