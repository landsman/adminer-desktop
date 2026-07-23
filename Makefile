# Every artifact below is derived from these two pins. Never "latest".
ADMINER_VERSION    = 5.5.1
FRANKENPHP_VERSION = 1.12.6

ADMINER_URL = https://github.com/vrana/adminer/releases/download/v$(ADMINER_VERSION)
FRANKEN_URL = https://github.com/php/frankenphp/releases/download/v$(FRANKENPHP_VERSION)

# ponytail: mac-arm64 only until someone needs to build elsewhere. M3 adds the matrix.
FRANKEN_ASSET = frankenphp-mac-arm64

.PHONY: fetch verify check check-app build run editor serve clean checksums

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

build: fetch
	go build -o build/adminer-desktop .

# The app itself: opens a window.
run: build
	./build/adminer-desktop

editor: build
	./build/adminer-desktop -editor

# Same startup path as `run`, minus the window — so it works over ssh and in CI.
check-app: build
	./build/adminer-desktop -headless

# Just the server, no window. Handy for poking at it with curl.
serve: fetch
	./bin/frankenphp php-server --root app --listen 127.0.0.1:18000 --no-compress

clean:
	rm -rf app bin .cache
