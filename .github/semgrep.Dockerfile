# Never built. This file exists to hold one version number in the one syntax
# Dependabot can read, and `make security` seds the tag back out of it — so it is
# the pin, not a copy of the pin, and the two cannot drift.
#
# A Makefile variable would have been the obvious home, and was, but no Dependabot
# ecosystem parses Makefiles; the choices are a Dockerfile or a compose file, and a
# compose file here would have to be run by `docker compose` to be honest, which is
# a worse `docker run`. The name matters only in that Dependabot matches
# /dockerfile|containerfile/i as a substring.
#
# Pointed at the mirror rather than at semgrep/semgrep on purpose. Dependabot reads
# the tag list of whatever registry the image names, so pointing it here means it
# can only ever propose a version that landsman/config has actually published —
# the ordering between the two repos enforces itself instead of being remembered.
FROM ghcr.io/landsman/semgrep-mirror:1.172.0
