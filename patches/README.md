# Patches to adminer

A fix to adminer itself goes upstream as a pull request first, to
[vrana/adminer](https://github.com/vrana/adminer), and lives here only until a release carries
it. `make fetch` applies every `*.patch` in this folder (`patch -p1`) to the pinned release's
sources and compiles `app/adminer.php` and `app/editor.php` from them
(`cli/compile-adminer.sh`).

- **Add one:** a diff from the root of adminer's source tree, with the upstream pull request
  on its first line. Then `make fetch` and `make checksums`, and commit the new checksums with it.
- **Drop one** once the release that carries it is pinned in the `Makefile`: delete the file,
  then `make fetch` and `make checksums`.

This README keeps the folder in git when there are no patches; the build depends on it.
