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

# composer installs PHP deps into vendor/, which is also Go's vendoring directory — with
# it present Go switches to vendor mode and every go command fails ("inconsistent
# vendoring"). We do not vendor Go deps, so force module mode for all of them.
export GOFLAGS := -mod=readonly

.PHONY: fetch verify qa phpstan golangci biome security check check-app e2e build run dev editor debug bundle zip dist tarball winzip logs serve clean checksums

fetch: app/adminer.php app/editor.php app/settings/plugins/available app/settings/theme/designs bin/frankenphp$(EXE)

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

# Extracted whole, once. Selecting with a pattern like 'designs/*' is not portable:
# macOS and linux unzip let * match a slash and recurse, the windows one does not, so
# only the file directly inside designs/ came out and every design silently vanished.
.cache/adminer-src: .cache/adminer-src.zip
	rm -rf $@ .cache/src-tmp
	unzip -qo $< -d .cache/src-tmp
	mv .cache/src-tmp/adminer-$(ADMINER_VERSION) $@
	rm -rf .cache/src-tmp

# Shipped but NOT loaded. Everything in adminer-plugins/ is auto-enabled by
# adminer (include/plugins.inc.php:17-19), so "available" has to live elsewhere.
app/settings/plugins/available: .cache/adminer-src
	@mkdir -p app/settings/plugins
	rm -rf $@ && cp -R .cache/adminer-src/plugins $@
	# adminer-plugins/ stays at the document root: adminer looks for it there and
	# nowhere else (include/plugins.inc.php:18). Only the catalogue is ours to place.
	@mkdir -p app/adminer-plugins

app/settings/theme/designs: .cache/adminer-src
	@mkdir -p app/settings/theme
	rm -rf $@ && cp -R .cache/adminer-src/designs $@

bin/frankenphp$(EXE):
	@mkdir -p bin .cache
ifeq ($(suffix $(FRANKEN_ASSET)),.zip)
	curl -fsSL -o .cache/frankenphp.zip $(FRANKEN_URL)/$(FRANKEN_ASSET)
	# The whole tree, not just the exe: the windows build is a real php install, with
	# ~30 DLLs beside the binary and ext/ and lib/ next to it. Taking only frankenphp.exe
	# got 0xC0000135, STATUS_DLL_NOT_FOUND, the moment it ran.
	unzip -qo .cache/frankenphp.zip -d bin
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

# Analysis tools are pinned like everything else, so a green build stays green for a
# reason rather than because a linter happened not to ship a new rule today.
GOLANGCI_VERSION = v2.12.2
PHPSTAN_VERSION  = 2.2.5

.cache/phpstan.phar:
	@mkdir -p .cache
	curl -fsSL -o $@ https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar

# --debug is not for debugging: phpstan's parallel workers shell out to a `php` binary,
# and there deliberately is none here -- we run it through the frankenphp we download.
# 2G because adminer.php is 500 KB of minified source on very long lines.
phpstan: bin/frankenphp$(EXE) .cache/phpstan.phar app/adminer.php
	./bin/frankenphp$(EXE) php-cli .cache/phpstan.phar analyse -c phpstan.neon \
		--no-progress --debug --memory-limit=2G

golangci:
	go run github.com/golangci/golangci-lint/v2/cmd/golangci-lint@$(GOLANGCI_VERSION) run ./...

# Format-check and lint CSS and JS with Biome. Run `mise run install` once to fetch it.
# Prefer the installed binary directly (it needs only node on PATH); fall back to mise,
# which puts node on PATH, when node is not there itself; skip with a note if neither is
# set up, so `make qa` is not blocked on a machine that has not installed the JS tooling.
# Bare `mise` is not assumed to be on PATH — in a plain make shell it often is not.
biome:
	@if [ -x node_modules/.bin/biome ] && command -v node >/dev/null 2>&1; then \
		node_modules/.bin/biome check . ; \
	elif command -v mise >/dev/null 2>&1; then \
		mise run lint ; \
	else \
		echo "biome skipped (run 'mise run install', or put node on PATH)" ; \
	fi

# Security scan. Docker rather than an install, and skipped rather than failed when
# docker is not running, so `make security` is safe to chain locally.
# Pinned like everything else: on :latest a new rule turns a green build red with no
# change of ours, which is the one thing pinning exists to prevent.
SEMGREP_VERSION = 1.171.0

security:
	@docker info >/dev/null 2>&1 || { echo "semgrep skipped (docker not running)"; exit 0; }; \
	docker run --rm -v "$$PWD:/src" -w /src semgrep/semgrep:$(SEMGREP_VERSION) semgrep \
		--config=p/php --config=p/golang --config=p/secrets \
		--exclude=adminer.php --exclude=editor.php --exclude=available \
		--exclude=designs --metrics=off --error

# Static checks, every one from a tool we already have: the php is the frankenphp we
# download, the rest ship with macOS or the go toolchain. Nothing to install.
qa: bin/frankenphp$(EXE)
	./bin/frankenphp$(EXE) php-cli lint.php
	@# No database and no browser: it replays adminer's own parser over a dump.
	./bin/frankenphp$(EXE) php-cli tests/postgres/copy-import/run.php
	@gofmt -l . | grep . && { echo "gofmt: files above need formatting"; exit 1; } || echo "gofmt ok"
	go vet ./...
	@# Every darwin-only function needs a stub in menu_other.go, or the build breaks on
	@# linux and windows only -- a CI round trip away rather than a compile away.
	@for f in $$(grep -oE '^func [a-zA-Z]+' menu_darwin.go | cut -d' ' -f2); do \
		grep -q "$$f(" main.go || continue; \
		grep -q "func $$f(" menu_other.go || { echo "menu_other.go: missing stub for $$f(), used by main.go"; exit 1; }; \
	done && echo "platform stubs ok"
	@command -v shellcheck >/dev/null \
		&& { shellcheck check-stream.sh && echo "shellcheck ok"; } \
		|| { sh -n check-stream.sh && echo "sh ok (shellcheck not installed)"; }
	@command -v plutil >/dev/null && plutil -lint Info.plist.in lproj/*/Localizable.strings >/dev/null && echo "plists ok" || echo "plists skipped (macOS only)"
	@$(MAKE) --no-print-directory phpstan
	@$(MAKE) --no-print-directory golangci && echo "golangci-lint ok"
	@$(MAKE) --no-print-directory biome && echo "biome ok"

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

# Like run, but reloads the window whenever a file under app/ changes — edit PHP or CSS
# and see it without a rebuild (frankenphp serves the tree live; the window just reloads).
dev: build
	./build/adminer-desktop$(EXE) -dev

editor: build
	./build/adminer-desktop$(EXE) -editor

# Turns on Safari's Web Inspector against the app's page: Develop > this machine >
# Adminer Desktop. There is no console in the app otherwise, which is how a confirm()
# that never fired stayed invisible for as long as it did.
debug: build
	./build/adminer-desktop$(EXE) -debug

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
	cp build/adminer-desktop$(EXE) $(DIST)/
	# All of bin/, because on windows that is the php runtime's DLLs and ext/ as well as
	# the exe. cp rather than rsync: git bash on the windows runner has no rsync.
	cp -R bin/. $(DIST)/
	cp -R app $(DIST)/app
	rm -f $(DIST)/app/_stream.php   # M0 probe, not part of the product
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

# Browser end-to-end check: logs in, asserts the theme applies in light and dark, and
# writes screenshots to tests/e2e/screenshots/. Needs docker (a throwaway postgres) and
# the Playwright browser from `mise run install`. Kept out of `qa` because it is slow and
# needs docker; run it on its own.
e2e: fetch
	mise run e2e

clean:
	rm -rf app bin .cache
