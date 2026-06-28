#!/usr/bin/env sh
#
# Dual-mode entrypoint for the PressReady image.
#
# Under GitHub Actions (GITHUB_ACTIONS=true), the 7 action inputs arrive as
# POSITIONAL ARGS (see action.yml) — not INPUT_* env vars, because the entrypoint
# runs under /bin/sh (dash), which drops env vars whose names contain dashes
# (INPUT_FAIL-ON, INPUT_WORKING-DIRECTORY). The arg order MUST match action.yml.
#
# Anywhere else — `docker run`, a pre-commit docker_image hook, local use — the
# args are passed straight through to the `pressready` CLI, so the same image
# doubles as a general-purpose runner:
#   docker run --rm -v "$PWD":/src -w /src ghcr.io/itzmekhokan/pressready:1 \
#     --php=8.4 --wp=6.9 --path=wp-content
set -eu

# CLI passthrough mode (not running inside GitHub Actions).
if [ "${GITHUB_ACTIONS:-}" != "true" ]; then
  exec pressready "$@"
fi

# ---- GitHub Action mode ----
# PressReady's --format=github renders annotation paths relative to the current
# directory, so we cd into the workspace first for correct repo-relative paths.
CONFIG="${1:-}"
BASELINE="${2:-}"
FAIL_ON="${3:-}"
FORMAT="${4:-}"
PHP="${5:-}"
WP="${6:-}"
WORKING_DIRECTORY="${7:-}"

# GitHub mounts the consumer checkout at $GITHUB_WORKSPACE and runs the container
# there; honour working-directory relative to it.
cd "${GITHUB_WORKSPACE:-.}/${WORKING_DIRECTORY:-.}"

set -- --format="${FORMAT:-github}"

# Only pass --config when the file is actually present. PressReady exits 2 if
# --config points at a missing file, but it already auto-discovers .pressready.json
# from the cwd, so an explicit flag is only needed (and only valid) when the file
# exists — this lets config-less consumers drive the scan with php/wp inputs alone.
if [ -n "${CONFIG}" ] && [ -f "${CONFIG}" ]; then
  set -- "$@" --config="${CONFIG}"
fi
[ -n "${BASELINE}" ] && set -- "$@" --baseline="${BASELINE}"
[ -n "${FAIL_ON}" ]  && set -- "$@" --fail-on="${FAIL_ON}"
[ -n "${PHP}" ]      && set -- "$@" --php="${PHP}"
[ -n "${WP}" ]       && set -- "$@" --wp="${WP}"

# Run the gate without aborting on a non-zero exit so we can surface it as an
# output and propagate the exact code.
set +e
pressready "$@"
code=$?
set -e

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "exit-code=${code}" >> "${GITHUB_OUTPUT}"
fi

exit "${code}"
