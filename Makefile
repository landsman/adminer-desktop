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

.PHONY: help install linux-deps fetch verify qa phpstan phpcs golangci biome security check check-app e2e build run dev editor debug demo down bundle zip dist tarball winzip deb logs serve clean checksums

.DEFAULT_GOAL := help

# Self-documenting: every target with a `## ` comment lists itself here, so this
# stays in step with the Makefile instead of being a second list to maintain.
help:  ## Show this help
	@grep -hE '^[a-z][a-zA-Z-]*:.*## ' $(MAKEFILE_LIST) \
		| sort | awk -F':.*## ' '{printf "  \033[1m%-10s\033[0m %s\n", $$1, $$2}'

# mise owns the dev toolchain (node, go, composer + npm deps, the Chromium e2e browser) —
# one entry point so its version pins stay in one place. On Linux the webview also needs
# GTK/WebKit dev headers, which are a distro package rather than a mise tool, so install
# runs linux-deps there too. Toolchain first, then `fetch` for the downloads (adminer +
# designs), so one command sets a fresh checkout up completely.
install:  ## Install everything: dev toolchain (mise) + Linux webview deps + downloads
ifeq ($(UNAME_S),Linux)
	$(MAKE) linux-deps
endif
	mise run install
	$(MAKE) fetch

# The one build dependency mise cannot pin: webview is cgo, and on Linux that means the
# system GTK-3 and WebKit2GTK dev headers — distro packages, not a toolchain. webview_go
# also hardcodes `pkg-config webkit2gtk-4.0`, which Ubuntu 24.04 dropped for 4.1 (same API
# over libsoup3), so a shim .pc that just requires 4.1 keeps the build on a current distro.
# Debian/Ubuntu (apt) is the only Linux supported right now — and the path CI and the .deb
# build on — so gate on apt up front and fail clearly on Arch/Fedora/others rather than
# half-installing. The pkg-config guard then makes a re-run a no-op that never re-sudos.
linux-deps:  ## Install the Linux webview build deps (GTK/WebKit dev headers, Debian/Ubuntu only)
	@command -v apt-get >/dev/null 2>&1 || { echo "linux-deps: Debian/Ubuntu (apt) is the only supported Linux distribution right now. On Arch, Fedora and others, install the GTK-3 and WebKit2GTK 4.1 dev headers with your own package manager."; exit 1; }
	@pkg-config --exists gtk+-3.0 webkit2gtk-4.1 2>/dev/null && echo "gtk/webkit dev headers present" || { \
		sudo apt-get update && sudo apt-get install -y libgtk-3-dev libwebkit2gtk-4.1-dev; }
	@pkg-config --exists webkit2gtk-4.0 2>/dev/null || printf 'Name: webkit2gtk-4.0\nDescription: Shim onto webkit2gtk-4.1\nVersion: 2.44.0\nRequires: webkit2gtk-4.1\n' \
		| sudo tee /usr/lib/$(UNAME_M)-linux-gnu/pkgconfig/webkit2gtk-4.0.pc >/dev/null

fetch: app/adminer.php app/editor.php app/src/Settings/Plugins/available app/src/Settings/Theme/designs bin/frankenphp$(EXE)  ## Download adminer + frankenphp (pinned, checksum-verified)

app/adminer.php:
	@mkdir -p app
	curl -fsSL --retry 3 --retry-delay 2 -o $@ $(ADMINER_URL)/adminer-$(ADMINER_VERSION).php

app/editor.php:
	@mkdir -p app
	curl -fsSL --retry 3 --retry-delay 2 -o $@ $(ADMINER_URL)/editor-$(ADMINER_VERSION).php

# The release zip is the full source tree; we want plugins/ and designs/ out of it.
# Same zip, same pinned tag as adminer.php — plugins can never drift from the core.
.cache/adminer-src.zip:
	@mkdir -p .cache
	curl -fsSL --retry 3 --retry-delay 2 -o $@ $(ADMINER_URL)/adminer-$(ADMINER_VERSION).zip

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
app/src/Settings/Plugins/available: .cache/adminer-src
	@mkdir -p app/src/Settings/Plugins
	rm -rf $@ && cp -R .cache/adminer-src/plugins $@
	# adminer-plugins/ stays at the document root: adminer looks for it there and
	# nowhere else (include/plugins.inc.php:18). Only the catalogue is ours to place.
	@mkdir -p app/adminer-plugins

app/src/Settings/Theme/designs: .cache/adminer-src
	@mkdir -p app/src/Settings/Theme
	rm -rf $@ && cp -R .cache/adminer-src/designs $@

bin/frankenphp$(EXE):
	@mkdir -p bin .cache
ifeq ($(suffix $(FRANKEN_ASSET)),.zip)
	curl -fsSL --retry 3 --retry-delay 2 -o .cache/frankenphp.zip $(FRANKEN_URL)/$(FRANKEN_ASSET)
	# The whole tree, not just the exe: the windows build is a real php install, with
	# ~30 DLLs beside the binary and ext/ and lib/ next to it. Taking only frankenphp.exe
	# got 0xC0000135, STATUS_DLL_NOT_FOUND, the moment it ran.
	unzip -qo .cache/frankenphp.zip -d bin
else
	curl -fsSL --retry 3 --retry-delay 2 -o $@ $(FRANKEN_URL)/$(FRANKEN_ASSET)
endif
	chmod +x $@

# macOS ships shasum and no sha256sum; the windows runner's git bash ships sha256sum and
# no shasum. Both write the same "hash  file" format, so one checksums.txt serves all.
SHA256 := $(shell command -v sha256sum >/dev/null 2>&1 && echo sha256sum || echo "shasum -a 256")

# Hard fail on mismatch: means the release was re-uploaded or the download was tampered
# with. Only the adminer artifacts are listed — frankenphp differs per platform, and a
# per-OS checksum file would be four files to keep in step instead of one.
verify: fetch  ## Checksum-verify the downloaded adminer files
	$(SHA256) -c checksums.txt

# Regenerate after a deliberate version bump. Review the diff.
checksums:
	$(SHA256) app/adminer.php app/editor.php > checksums.txt

# Analysis tools are pinned like everything else, so a green build stays green for a
# reason rather than because a linter happened not to ship a new rule today.
GOLANGCI_VERSION = v2.12.2
PHPSTAN_VERSION  = 2.2.5
COMPOSER_VERSION = 2.10.2

.cache/phpstan.phar:
	@mkdir -p .cache
	curl -fsSL --retry 3 --retry-delay 2 -o $@ https://github.com/phpstan/phpstan/releases/download/$(PHPSTAN_VERSION)/phpstan.phar

.cache/composer.phar:
	@mkdir -p .cache
	curl -fsSL --retry 3 --retry-delay 2 -o $@ https://getcomposer.org/download/$(COMPOSER_VERSION)/composer.phar

# The app's own PHP deps: latte renders our markup and tracy stands behind -debug. qa
# needs them as much as the app does -- phpstan resolves those classes through vendor/,
# and the template linter is one of the packages. composer.json lives in app/ so vendor/
# lands beside the code it autoloads (and out of Go's way at the module root), hence
# --working-dir.
app/vendor: app/composer.json app/composer.lock bin/frankenphp$(EXE) .cache/composer.phar
	./bin/frankenphp$(EXE) php-cli .cache/composer.phar install --no-interaction --working-dir=app
	@touch app/vendor

# --debug is not for debugging: phpstan's parallel workers shell out to a `php` binary,
# and there deliberately is none here -- we run it through the frankenphp we download.
# 2G because adminer.php is 500 KB of minified source on very long lines.
phpstan: bin/frankenphp$(EXE) .cache/phpstan.phar app/adminer.php app/vendor
	./bin/frankenphp$(EXE) php-cli .cache/phpstan.phar analyse -c phpstan.neon \
		--no-progress --debug --memory-limit=2G

# The conventions phpstan does not judge: every property a native type (a bare @var is not
# enough) and [] over array(). PHPCS with slevomat, run through the frankenphp we already
# download — no separate PHP install. The ruleset (phpcs.xml) holds the rules and excludes
# adminer's own downloaded files, which keep their conventions.
phpcs: app/vendor
	./bin/frankenphp$(EXE) php-cli app/vendor/bin/phpcs --standard=phpcs.xml

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
qa: bin/frankenphp$(EXE) app/vendor  ## Run every static check (php, go, js lint + formatting)
	./bin/frankenphp$(EXE) php-cli cli/lint.php
	@# No database and no browser: it replays adminer's own parser over a dump.
	./bin/frankenphp$(EXE) php-cli tests/postgres/copy-import/run.php
	@gofmt -l . | grep . && { echo "gofmt: files above need formatting"; exit 1; } || echo "gofmt ok"
	go vet ./...
	@# Every darwin-only function needs a non-darwin definition, or the build breaks on
	@# linux and windows only -- a CI round trip away rather than a compile away. That is
	@# either a shared stub in menu_other.go, or a platform split (a real *_linux.go and a
	@# *_other.go stub for windows), the way installDownloads is done.
	@for f in $$(grep -oE '^func [a-zA-Z]+' launcher/menu_darwin.go | cut -d' ' -f2); do \
		grep -q "$$f(" launcher/main.go || continue; \
		grep -q "func $$f(" launcher/menu_other.go && continue; \
		{ grep -lq "func $$f(" launcher/*_linux.go && grep -lq "func $$f(" launcher/*_other.go; } \
			|| { echo "launcher: no non-darwin definition of $$f() (menu_other.go stub, or *_linux.go + *_other.go split)"; exit 1; }; \
	done && echo "platform stubs ok"
	@command -v shellcheck >/dev/null \
		&& { shellcheck check.sh && echo "shellcheck ok"; } \
		|| { sh -n check.sh && echo "sh ok (shellcheck not installed)"; }
	@command -v plutil >/dev/null && plutil -lint Info.plist.in lproj/*/Localizable.strings >/dev/null && echo "plists ok" || echo "plists skipped (macOS only)"
	@$(MAKE) --no-print-directory phpstan
	@$(MAKE) --no-print-directory phpcs && echo "phpcs ok"
	@$(MAKE) --no-print-directory golangci && echo "golangci-lint ok"
	@$(MAKE) --no-print-directory biome && echo "biome ok"

# Boot the app and assert the desktop plugin's before-login behaviour — prefill, refresh
# shortcut, design switch, plugin toggle — against the real login page.
check: fetch  ## Boot the app, assert before-login behaviour (prefill, design, plugins)
	./check.sh

# About reads these, so it can never disagree with what is actually bundled.
VERSION = $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS = -X main.version=$(VERSION) \
	-X main.adminerVersion=$(ADMINER_VERSION) \
	-X main.frankenphpVersion=$(FRANKENPHP_VERSION)

build: fetch  ## Build the launcher binary
	go build -ldflags "$(LDFLAGS)" -o build/adminer-desktop$(EXE) ./launcher

# The app itself: opens a window.
run: build  ## Build and open the app window
	./build/adminer-desktop$(EXE)

# Like run, but reloads the window whenever a file under app/ changes — edit PHP or CSS
# and see it without a rebuild (frankenphp serves the tree live; the window just reloads).
dev: build  ## Run, reloading the window on any change under app/
	./build/adminer-desktop$(EXE) -dev

editor: build  ## Run in editor mode
	./build/adminer-desktop$(EXE) -editor

# Turns on Safari's Web Inspector against the app's page: Develop > this machine >
# Adminer Desktop. There is no console in the app otherwise, which is how a confirm()
# that never fired stayed invisible for as long as it did.
debug: build  ## Run with Safari's Web Inspector attached
	./build/adminer-desktop$(EXE) -debug

# The app in dev mode against seeded demo data, for clicking around by hand. Brings up
# (or reuses) the same throwaway postgres the e2e uses, reseeds it, and opens the app
# logged straight into it — ADMINER_DESKTOP_DEMO carries the throwaway connection, which
# desktop.php hands to demo-login.js to fill and submit. Only `make demo` ever sets it, so
# a shipped build never auto-logs-in. `make down` kills the container when you are done.
# vendor/ because dev serves app/ and Latte renders from it. The seed drops and recreates,
# so re-running just refreshes the data.
DEMO_PG = adminer-demo-pg

demo: build app/vendor  ## Run against seeded demo data, opened logged in (needs docker)
	@docker start $(DEMO_PG) >/dev/null 2>&1 || docker run -d --name $(DEMO_PG) \
		-e POSTGRES_PASSWORD=demo -e POSTGRES_DB=demo -p 55432:5432 postgres:18-alpine >/dev/null
	@echo "waiting for postgres ..." && until docker exec $(DEMO_PG) pg_isready -U postgres >/dev/null 2>&1; do sleep 1; done
	@docker exec -i $(DEMO_PG) psql -U postgres -d demo -v ON_ERROR_STOP=1 < tests/e2e/seed.sql >/dev/null
	@echo "demo data ready on 127.0.0.1:55432 (postgres / demo / demo)"
	ADMINER_DESKTOP_DEMO='pgsql 127.0.0.1:55432 postgres demo demo' ./build/adminer-desktop$(EXE) -dev

# Kill the demo database container `make demo` left running.
down:  ## Stop the demo database container
	-docker rm -f $(DEMO_PG)

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
bundle: build app/vendor $(ICON)  ## Build the macOS .app bundle
	rm -rf "$(APP)"
	mkdir -p "$(APP)"/Contents/MacOS "$(APP)"/Contents/Resources
	sed 's|@ADMINER_VERSION@|$(ADMINER_VERSION)|g' Info.plist.in > "$(APP)"/Contents/Info.plist
	cp build/adminer-desktop "$(APP)"/Contents/MacOS/
	cp bin/frankenphp "$(APP)"/Contents/MacOS/
	# The whole app/ tree in one copy — vendor/ lives in app/ now, so it (and the autoloader
	# Latte needs) travels along too.
	rsync -a app/ "$(APP)"/Contents/Resources/app/
	# Strip the dev tooling the shared app/vendor target installs for qa (phpcs, slevomat,
	# playwright — ~9 MB the shipped app never runs). composer.json travels with app/, so this
	# reconciles the copied tree down to the production deps in place.
	./bin/frankenphp php-cli .cache/composer.phar install --no-dev --no-interaction \
		--working-dir="$(APP)"/Contents/Resources/app
	# NSLocalizedString resolves against the main bundle, so the .lproj folders have to
	# sit directly in Resources. macOS then picks the language itself.
	cp -R lproj/*.lproj "$(APP)"/Contents/Resources/
	cp $(ICON) "$(APP)"/Contents/Resources/
	@echo "built "$(APP)" -- $$(du -sh "$(APP)" | cut -f1)"

# Unsigned, so a first launch elsewhere needs right-click > Open. Signing is M4.
zip: bundle  ## Zip the macOS .app bundle
	cd build && rm -f "Adminer Desktop.zip" && zip -qry "Adminer Desktop.zip" "Adminer Desktop.app"
	@echo "built build/Adminer Desktop.zip -- $$(du -sh "build/Adminer Desktop.zip" | cut -f1)"

# Linux and Windows get a plain directory rather than a bundle or an installer: the
# layout resolve() looks for is "runtime and app/ next to the binary", which a folder
# already satisfies. AppImage, .deb and an MSI are all packaging opinions we do not need
# before anyone has asked to install this.
# Staged under build/pkg/ so the folder name inside the archive can still be
# adminer-desktop without colliding with the binary of that name in build/.
DIST = build/pkg/adminer-desktop

dist: build app/vendor  ## Stage the Linux/Windows folder layout
	rm -rf $(DIST) && mkdir -p $(DIST)
	cp build/adminer-desktop$(EXE) $(DIST)/
	# The window icon, beside the binary so iconPath() finds it at runtime (the launcher
	# sets the GTK window icon from it; the .deb also points its .desktop at a copy).
	cp assets/logo.png $(DIST)/
	# All of bin/, because on windows that is the php runtime's DLLs and ext/ as well as
	# the exe. cp rather than rsync: git bash on the windows runner has no rsync.
	cp -R bin/. $(DIST)/
	cp -R app $(DIST)/app   # includes app/vendor, the autoloader Latte renders through
	# Strip the dev tooling app/vendor carries for qa (phpcs, slevomat, playwright — ~9 MB the
	# shipped app never runs), reconciling the copied tree down to production deps in place.
	./bin/frankenphp$(EXE) php-cli .cache/composer.phar install --no-dev --no-interaction \
		--working-dir=$(DIST)/app
	@echo "built $(DIST) -- $$(du -sh $(DIST) | cut -f1)"

# tar preserves the executable bit; zip on windows does not need it.
tarball: dist  ## Package the Linux tarball
	cd build/pkg && tar czf ../adminer-desktop-linux.tar.gz adminer-desktop
	@echo "built build/adminer-desktop-linux.tar.gz -- $$(du -sh build/adminer-desktop-linux.tar.gz | cut -f1)"

winzip: dist  ## Package the Windows zip
	rm -f build/adminer-desktop-windows.zip && cd build/pkg && zip -qry ../adminer-desktop-windows.zip adminer-desktop
	@echo "built build/adminer-desktop-windows.zip -- $$(du -sh build/adminer-desktop-windows.zip | cut -f1)"

# A .deb is the same flat layout dist stages, just rooted at /usr/lib instead of a folder:
# resolve() reads os.Executable(), which follows the /usr/bin symlink back to the real
# binary, so frankenphp and app/ beside it are found exactly as in the tarball. Arch comes
# from dpkg since the go build is never cross-compiled here. Debian versions must begin
# with a digit and reserve '-' as the upstream/revision separator, so git's description is
# trimmed to its first digit and its dashes turned to '~'. --root-owner-group ships the
# files as root:root without needing fakeroot.
DEB         = build/deb
DEB_ARCH    = $(shell dpkg --print-architecture 2>/dev/null || echo amd64)
DEB_VERSION = $(shell printf '%s' '$(VERSION)' | sed -E 's/^[^0-9]*//; s/-/~/g')
DEB_FILE    = build/adminer-desktop_$(DEB_VERSION)_$(DEB_ARCH).deb

deb: dist  ## Package a Debian .deb (Linux)
	rm -rf $(DEB)
	mkdir -p $(DEB)/DEBIAN $(DEB)/usr/lib $(DEB)/usr/bin $(DEB)/usr/share/applications $(DEB)/usr/share/pixmaps
	cp -R build/pkg/adminer-desktop $(DEB)/usr/lib/adminer-desktop
	ln -sf ../lib/adminer-desktop/adminer-desktop $(DEB)/usr/bin/adminer-desktop
	cp assets/logo.png $(DEB)/usr/share/pixmaps/adminer-desktop.png
	# StartupWMClass = the GtkWindow's app_id (webview's prgname, the binary basename), so
	# Plasma matches the running window to this entry and shows its icon in the taskbar —
	# the way a Wayland session gets the icon, where the runtime GtkWindow icon does not reach.
	printf '%s\n' \
		'[Desktop Entry]' 'Type=Application' 'Name=Adminer Desktop' \
		'Comment=Adminer as a desktop app' 'Exec=adminer-desktop' 'Icon=adminer-desktop' \
		'Terminal=false' 'Categories=Development;Database;' 'StartupWMClass=adminer-desktop' \
		> $(DEB)/usr/share/applications/adminer-desktop.desktop
	printf '%s\n' \
		'Package: adminer-desktop' 'Version: $(DEB_VERSION)' 'Architecture: $(DEB_ARCH)' \
		'Maintainer: Michal Landsman <landsman@insuit.cz>' \
		'Section: database' 'Priority: optional' \
		'Depends: libgtk-3-0, libwebkit2gtk-4.1-0' \
		'Homepage: https://github.com/landsman/adminer-desktop' \
		'Description: Adminer as a desktop app' \
		' No PHP install, no web server, no browser tab.' \
		> $(DEB)/DEBIAN/control
	dpkg-deb --build --root-owner-group $(DEB) $(DEB_FILE)
	@echo "built $(DEB_FILE) -- $$(du -sh $(DEB_FILE) | cut -f1)"

# PHP errors, adminer warnings and caddy's access log all land in one file, in the
# place macOS users and Console.app already look.
logs:  ## Open the log folder (macOS)
	open ~/Library/Logs/"Adminer Desktop"

# Just the server, no window. Handy for poking at it with curl.
serve: fetch  ## Serve app/ with no window, for poking at with curl
	./bin/frankenphp$(EXE) php-server --root app --listen 127.0.0.1:18000 --no-compress

# Browser end-to-end check: logs in, asserts the theme applies in light and dark, and
# writes screenshots to tests/e2e/screenshots/. Needs docker (a throwaway postgres) and
# the Playwright browser from `mise run install`. Kept out of `qa` because it is slow and
# needs docker; run it on its own.
e2e: fetch  ## Browser check: login + theme in light and dark (needs docker)
	mise run e2e

clean:  ## Remove downloaded and built files (app/, bin/, .cache/)
	rm -rf app bin .cache
