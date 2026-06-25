#!/usr/bin/env sh
#
# Map GitHub Action inputs to PressReady CLI flags and run the scan from the
# consumer's checkout. PressReady's --format=github renders annotation paths
# relative to the current directory, so we cd into the workspace first to get
# correct repo-relative annotations.
#
# Inputs arrive as POSITIONAL ARGS (see action.yml), not INPUT_* env vars: the
# entrypoint runs under /bin/sh (dash), which drops env vars whose names contain
# dashes (INPUT_FAIL-ON, INPUT_WORKING-DIRECTORY). The arg order here MUST match
# the `args:` list in action.yml.
set -eu

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
