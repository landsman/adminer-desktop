# Every artifact below is derived from these two pins. Never "latest".
ADMINER_VERSION    = 5.5.1
FRANKENPHP_VERSION = 1.12.6

ADMINER_URL = https://github.com/vrana/adminer/releases/download/v$(ADMINER_VERSION)
FRANKEN_URL = https://github.com/php/frankenphp/releases/download/v$(FRANKENPHP_VERSION)

# Which frankenphp build to fetch. Defaults to this machine; CI overrides it per runner.
# Windows is the odd one: it is the only asset shipped as a zip rather than a bare binary.
UNAME_S := $(shell uname -s)
UNAME_M := $(shell uname -m)
ifeq ($(UNAME_S),Darwin)
	FRANKEN_ASSET ?= frankenphp-mac-$(if $(filter arm64,$(UNAME_M)),arm64,x86_64)
	EXE =
else ifeq ($(UNAME_S),Linux)
	FRANKEN_ASSET ?= frankenphp-linux-$(if $(filter aarch64 arm64,$(UNAME_M)),aarch64,x86_64)
	EXE =
else
	FRANKEN_ASSET ?= frankenphp-windows-x86_64.zip
	EXE = .exe
endif

.PHONY: fetch verify qa check check-app build run editor bundle zip dist tarball winzip logs serve clean checksums

fetch: app/adminer.php app/editor.php app/plugins-available app/designs bin/frankenphp$(EXE)

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

bin/frankenphp$(EXE):
	@mkdir -p bin .cache
ifeq ($(suffix $(FRANKEN_ASSET)),.zip)
	curl -fsSL -o .cache/frankenphp.zip $(FRANKEN_URL)/$(FRANKEN_ASSET)
	unzip -qojd bin .cache/frankenphp.zip '*frankenphp.exe'
else
	curl -fsSL -o $@ $(FRANKEN_URL)/$(FRANKEN_ASSET)
endif
	chmod +x $@

# macOS ships shasum and no sha256sum; the windows runner's git bash ships sha256sum and
# no shasum. Both write the same "hash  file" format, so one checksums.txt serves all.
SHA256 := $(shell command -v sha256sum >/dev/null 2>&1 && echo sha256sum || echo "shasum -a 256")

# Hard fail on mismatch: means the release was re-uploaded or the download was tampered
# with. Only the adminer artifacts are listed — frankenphp differs per platform, and a
# per-OS checksum file would be four files to keep in step instead of one.
verify: fetch
	$(SHA256) -c checksums.txt

# Regenerate after a deliberate version bump. Review the diff.
checksums:
	$(SHA256) app/adminer.php app/editor.php > checksums.txt

# Static checks, every one from a tool we already have: the php is the frankenphp we
# download, the rest ship with macOS or the go toolchain. Nothing to install.
qa: bin/frankenphp$(EXE)
	./bin/frankenphp$(EXE) php-cli lint.php
	@gofmt -l . | grep . && { echo "gofmt: files above need formatting"; exit 1; } || echo "gofmt ok"
	go vet ./...
	@sh -n check-stream.sh && echo "sh ok"
	@command -v plutil >/dev/null && plutil -lint Info.plist.in lproj/*/Localizable.strings >/dev/null && echo "plists ok" || echo "plists skipped (macOS only)"

# M0: does FrankenPHP survive a 120s progressively-flushed response?
check: fetch
	./check-stream.sh

# About reads these, so it can never disagree with what is actually bundled.
VERSION = $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS = -X main.version=$(VERSION) \
	-X main.adminerVersion=$(ADMINER_VERSION) \
	-X main.frankenphpVersion=$(FRANKENPHP_VERSION)

build: fetch
	go build -ldflags "$(LDFLAGS)" -o build/adminer-desktop$(EXE) .

# The app itself: opens a window.
run: build
	./build/adminer-desktop$(EXE)

editor: build
	./build/adminer-desktop$(EXE) -editor

# Same startup path as `run`, minus the window — so it works over ssh and in CI.
check-app: build
	./build/adminer-desktop$(EXE) -headless

APP = build/Adminer Desktop.app
ICON = build/AdminerDesktop.icns

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
	rm -rf "$(APP)"
	mkdir -p "$(APP)"/Contents/MacOS "$(APP)"/Contents/Resources
	sed 's|@ADMINER_VERSION@|$(ADMINER_VERSION)|g' Info.plist.in > "$(APP)"/Contents/Info.plist
	cp build/adminer-desktop "$(APP)"/Contents/MacOS/
	cp bin/frankenphp "$(APP)"/Contents/MacOS/
	# Everything except the M0 probe and the plugins the user has not enabled.
	rsync -a --exclude '_stream.php' app/ "$(APP)"/Contents/Resources/app/
	# NSLocalizedString resolves against the main bundle, so the .lproj folders have to
	# sit directly in Resources. macOS then picks the language itself.
	cp -R lproj/*.lproj "$(APP)"/Contents/Resources/
	cp $(ICON) "$(APP)"/Contents/Resources/
	@echo "built "$(APP)" -- $$(du -sh "$(APP)" | cut -f1)"

# Unsigned, so a first launch elsewhere needs right-click > Open. Signing is M4.
zip: bundle
	cd build && rm -f "Adminer Desktop.zip" && zip -qry "Adminer Desktop.zip" "Adminer Desktop.app"
	@echo "built build/Adminer Desktop.zip -- $$(du -sh "build/Adminer Desktop.zip" | cut -f1)"

# Linux and Windows get a plain directory rather than a bundle or an installer: the
# layout resolve() looks for is "runtime and app/ next to the binary", which a folder
# already satisfies. AppImage, .deb and an MSI are all packaging opinions we do not need
# before anyone has asked to install this.
# Staged under build/pkg/ so the folder name inside the archive can still be
# adminer-desktop without colliding with the binary of that name in build/.
DIST = build/pkg/adminer-desktop

dist: build
	rm -rf $(DIST) && mkdir -p $(DIST)
	cp build/adminer-desktop$(EXE) bin/frankenphp$(EXE) $(DIST)/
	rsync -a --exclude '_stream.php' app/ $(DIST)/app/
	@echo "built $(DIST) -- $$(du -sh $(DIST) | cut -f1)"

# tar preserves the executable bit; zip on windows does not need it.
tarball: dist
	cd build/pkg && tar czf ../adminer-desktop-linux.tar.gz adminer-desktop
	@echo "built build/adminer-desktop-linux.tar.gz -- $$(du -sh build/adminer-desktop-linux.tar.gz | cut -f1)"

winzip: dist
	rm -f build/adminer-desktop-windows.zip && cd build/pkg && zip -qry ../adminer-desktop-windows.zip adminer-desktop
	@echo "built build/adminer-desktop-windows.zip -- $$(du -sh build/adminer-desktop-windows.zip | cut -f1)"

# PHP errors, adminer warnings and caddy's access log all land in one file, in the
# place macOS users and Console.app already look.
logs:
	open ~/Library/Logs/"Adminer Desktop"

# Just the server, no window. Handy for poking at it with curl.
serve: fetch
	./bin/frankenphp$(EXE) php-server --root app --listen 127.0.0.1:18000 --no-compress

clean:
	rm -rf app bin .cache
