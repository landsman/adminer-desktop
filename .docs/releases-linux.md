# Automated Linux releases — implementation plan

Issue [#27](https://github.com/landsman/adminer-desktop/issues/27): "automated release
process for Debian. Flatpack?"

## Where we already are

`make deb` builds a correct package today — `/usr/lib/adminer-desktop/` with the binary,
frankenphp and `app/` beside each other, a `/usr/bin` symlink, a `.desktop` entry with
`StartupWMClass`, an icon in `/usr/share/pixmaps`, and `Depends: libgtk-3-0,
libwebkit2gtk-4.1-0`. Version comes from `git describe`, arch from `dpkg`.

Nothing publishes it. `build.yml` builds on manual dispatch and uploads artifacts that
expire, `deb` is not even in the matrix, and a tag does nothing. **The gap is delivery,
not packaging.**

Two facts that shape everything below: the repo is public (Actions minutes on Linux are
free — `CLAUDE.md` still says otherwise), and it has **no LICENSE file**, so legally
nothing here is redistributable yet.

## Stage 1 — build a tag, attach the packages to its release — **done**

Manual on purpose while the app is in beta: the release, its notes and its prerelease flag
are written by hand on GitHub, and the pipeline only fills in the files. That keeps a typo
in a tag from publishing something, and keeps the macOS runner off every `git push --tags`.

`build.yml`, no Makefile change:

- A `tag` input on `workflow_dispatch`. Naming an existing tag runs the build (the
  `build` checkbox stays, for artifacts with no release attached) and checks that tag out
  — an empty ref is the default branch, so the old behaviour is untouched.
- The Linux matrix row packages `tarball deb` — `deb` only re-roots what `dist` already
  staged, so it costs a `dpkg-deb` call rather than a second compile — and uploads
  `build/*.deb` alongside the tarball.
- A `release` job downloads all three artifacts and runs `gh release upload <tag>
  --clobber`, then `gh release edit <tag> --prerelease`. Upload, never create: `gh` finds
  a draft or a published release by tag, and `--clobber` makes a re-run replace an asset
  instead of failing on the name. The prerelease flag is the "beta mode" — held by the
  pipeline rather than by a checkbox someone has to remember, since an unticked one puts a
  beta on the repo front page as "Latest". One line to delete at the first stable tag.
  `gh` is on the runner, so no new action dependency; `contents: write` on that job alone.
- `fetch-depth: 0` on the build checkout. `VERSION` is `git describe`, and without tags
  that is `dev`, which `DEB_VERSION` strips to nothing and `dpkg-deb` then refuses.

`DEB_VERSION` strips everything before the first digit and turns `-` into `~`, so
`beta-v0.4` → `adminer-desktop_0.4_amd64.deb` and a later `v1.0.0` → `1.0.0`.

The release job runs on `!cancelled()` rather than success, because Windows is knowingly
red and uploading the two platforms that do build beats uploading nothing. It fails
outright if no artifact arrived at all.

Users run `sudo apt install ./adminer-desktop_0.4_amd64.deb`; apt resolves the two
dependencies. No PPA, no key, no repo.

Skipped: signing, arm64, and a tag-push trigger — that last one is four lines
(`tags: [v*]` plus `gh release create`) the day a tag should publish on its own.

## Stage 2 — Flatpak

### Why, given the .deb exists

Not for Debian. Debian is served by stage 1. Flatpak buys three things:

1. **Every other distro.** `make linux-deps` refuses anything without apt, and says so.
   Fedora, Arch, openSUSE and Silverblue users currently have a tarball and their own
   luck with GTK/WebKit versions.
2. **The webkit2gtk version chase ends.** The runtime pins GTK3 and WebKitGTK, so the
   `webkit2gtk-4.0` → `4.1` shim `linux-deps` installs stops being a per-distro problem.
3. **Auto-updates**, which a downloaded `.deb` will never have.

### Repackage, do not rebuild

`org.gnome.Platform` ships GTK3 and webkit2gtk-4.1, which is exactly what the launcher
links against. So the manifest's only source is **the release tarball `make tarball`
already produces** — no golang SDK extension, no vendored Go modules, no offline composer
install (flatpak-builder builds with no network; `app/vendor` is already inside the
tarball, so the problem never arises).

frankenphp is a prebuilt binary, and stays one — nobody is compiling a static PHP inside
flatpak-builder. It is MIT, so it is redistributable and needs a plain `archive` source,
not `extra-data`. Flathub accepts prebuilt binaries; roughly 6% of what it hosts is not
built from source.

```yaml
app-id: io.github.landsman.AdminerDesktop
runtime: org.gnome.Platform
runtime-version: '48'
sdk: org.gnome.Sdk
command: adminer-desktop
finish-args:
  - --share=network              # DB hosts, and our own 127.0.0.1 server
  - --socket=wayland
  - --socket=fallback-x11
  - --device=dri
  - --filesystem=home            # SQLite files, ~/.my.cnf, import sources
  - --filesystem=xdg-download    # Export > save
  - --filesystem=/run/mysqld     # local unix sockets live outside the sandbox
  - --filesystem=/var/run/postgresql
modules:
  - name: adminer-desktop
    buildsystem: simple
    build-commands:
      - install -d /app/lib/adminer-desktop && cp -R . /app/lib/adminer-desktop
      - install -Dm755 ... /app/bin/adminer-desktop      # symlink, as the .deb does
      - install -Dm644 ... /app/share/applications/io.github.landsman.AdminerDesktop.desktop
      - install -Dm644 ... /app/share/metainfo/io.github.landsman.AdminerDesktop.metainfo.xml
    sources:
      - type: archive
        url: https://github.com/landsman/adminer-desktop/releases/download/beta-vX.Y/adminer-desktop_X.Y_linux-amd64.tar.gz
        sha256: ...
```

The data dir needs no code change: `os.UserConfigDir` becomes
`~/.var/app/io.github.landsman.AdminerDesktop/config/` inside the sandbox, which is the
right answer.

### What to verify in a test build, not assume

- The runtime's webkit2gtk soname matches what the ubuntu-24.04 binary was linked
  against. If it does not, the module builds the Go binary instead, using
  `org.freedesktop.Sdk.Extension.golang` and `go mod vendor` — the only reason to.
- `resolve()` finds `app/` from `/app/lib/adminer-desktop/`. It reads `os.Executable()`
  and follows symlinks, which is exactly why the `.deb` layout works, so this should hold
  for free — confirm it rather than trust it.
- `StartupWMClass` must equal the app id under Wayland or the taskbar icon goes missing.
  It is the binary basename today; the flatpak `.desktop` needs the reverse-DNS id.
- A local socket connection (`/run/mysqld/mysqld.sock`) actually reaches the host daemon.

### Two files stage 2 forces into existence

The `.desktop` file is `printf`'d inside the `deb` target. Once a second package needs
it, it moves to `packaging/` and both read it. Along with it comes an AppStream
`metainfo.xml` — required by Flathub, and the source of the store description,
screenshots and per-release notes. `make qa` can validate it with
`flatpak run org.freedesktop.appstream.cli validate`.

### Delivery, cheapest first

**(a) A `.flatpak` bundle on the release.** `flatpak-builder` + `flatpak build-bundle` in
the tag workflow, uploaded beside the `.deb`. Install with `flatpak install ./file`.
No repo, no key, no review — and no auto-updates.

**(b) A flatpak repo on GitHub Pages.** `build-export` + `build-update-repo` writing to
`gh-pages`; users `flatpak remote-add` once and get updates. Costs a GPG key held as a
secret and a repo that grows with every release.

**(c) Flathub.** Discoverability, hosting and updates handled for us. Costs a PR to
`flathub/flathub`, a review round, and a manifest repo whose sha256 bumps on every
release (their bot can do it). **Blocked until the repo has a LICENSE** — Flathub
requires the content be redistributable and the license be declared correctly in the
appdata.

## Recommendation

Stage 1 now; it is the issue, and it is an afternoon.

Then (a) — a bundle attached to the same release proves the manifest, the sandbox
permissions and the metainfo with no key to hold and no review to wait on. Go to (c) if
the manifest survives real use, and skip (b) entirely: a self-signed flatpak repo is a
key to babysit forever for the same result Flathub gives away.

An apt repo (`apt upgrade` for the `.deb`) is deliberately not in this plan. It is a
second signing key and a second update channel to keep alive, for users a Flatpak already
covers. Add it only if someone asks for a native package that self-updates.
