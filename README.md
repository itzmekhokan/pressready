# Pressready

[![CI](https://github.com/itzmekhokan/pressready/actions/workflows/ci.yml/badge.svg)](https://github.com/itzmekhokan/pressready/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/itzmekhokan/pressready.svg)](https://packagist.org/packages/itzmekhokan/pressready)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> **Is your WordPress site ready for the next PHP or WordPress version?**
> Scan the whole installed stack — every plugin, theme, and mu-plugin, not just your own code —
> and find out exactly what breaks *before* you upgrade.

`vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content` runs static analysis across every component and returns a verdict grouped by plugin/theme, with `file:line` and a clear severity for every finding.

**[→ Full documentation](https://itzmekhokan.github.io/pressready/)**

---

## Contents

- [Severity model](#severity-model)
- [Install](#install)
- [Quick start](#quick-start)
- [All options](#all-options)
- [Output formats](#output-formats)
- [Compatibility matrix](#compatibility-matrix)
- [Large stacks & performance](#large-stacks--performance)
- [Config file](#config-file)
- [WP-CLI](#wp-cli)
- [GitHub Actions / CI](#github-actions--ci)
- [Baseline (legacy sites)](#baseline-legacy-sites)
- [Exit codes](#exit-codes)
- [Per-finding suppression](#per-finding-suppression)
- [Roadmap](#roadmap)

---

## Severity model

| Level | Meaning | Engine |
|---|---|---|
| `fatal` | A symbol **removed** by the target version — PHP symbol gone, or a WordPress core API removed by the target WP version → white screen / undefined-symbol fatal on upgrade | PHPCompatibility · Pressready sniff |
| `risky` | Behavioural change that still runs but may produce wrong results | PHPCompatibility |
| `php` | PHP feature deprecated (not yet removed) | PHPCompatibility |
| `wp` | WordPress core API deprecated (but still shimmed) by the target WP version | Pressready sniff |

Under the hood Pressready runs two engines in a single PHPCS pass: [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) for the PHP axis, and a custom sniff driven by an authoritative WP deprecations dataset for the WordPress axis.

The WP dataset records the WordPress version it was generated from. If `--wp` targets a newer release than the dataset, Pressready prints a warning to stderr (WP-side results may be incomplete — regenerate the dataset) rather than imply a clean bill of health. Point a run at a custom or newer dataset with the `PRESSREADY_DATASET=/path/to/wp-deprecations.json` environment variable.

---

## Install

Pressready installs in two tiers depending on how new a PHP version you need to target.

### Default — no config (PHP ≤ 8.1)

The standard install needs **no stability config**. It pulls the stable PHPCompatibility engine, which detects everything up to **PHP 8.1** plus the full WordPress deprecations axis:

```bash
composer require --dev itzmekhokan/pressready
```

This works under a normal `"minimum-stability": "stable"` project. Most sites can stop here.

### Targeting PHP 8.2–8.4 — opt in to the pre-release engine

PHP 8.2–8.4 detection needs `PHPCompatibility 10`, which only ships as a pre-release. Allow dev stability **and** request the pre-release engine explicitly (Composer prefers stable otherwise, so you must name it):

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer config --no-plugins allow-plugins.phpcsstandards/phpcsutils true
composer require --dev itzmekhokan/pressready phpcompatibility/phpcompatibility-wp:3.0.0-alpha2
```

Equivalent `composer.json`:

```jsonc
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require-dev": {
        "itzmekhokan/pressready": "*",
        "phpcompatibility/phpcompatibility-wp": "3.0.0-alpha2"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true,
            "phpcsstandards/phpcsutils": true
        }
    }
}
```

### Global install — scan many sites from one place

Prefer one shared install for ad-hoc scans across all your local sites? Install it globally. Configure global Composer once (the dev-stability lines are only needed for PHP 8.2–8.4 — drop them if you only target PHP ≤ 8.1):

```bash
# Configure global Composer once
composer global config minimum-stability dev
composer global config prefer-stable true
composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global config --no-plugins allow-plugins.phpcsstandards/phpcsutils true

# Install globally (drop the explicit engine pin if you only target PHP ≤ 8.1)
composer global require itzmekhokan/pressready phpcompatibility/phpcompatibility-wp:3.0.0-alpha2

# Put the global bin on your PATH (zsh; use ~/.bashrc for bash)
echo 'export PATH="$PATH:$(composer global config home)/vendor/bin"' >> ~/.zshrc && source ~/.zshrc
```

Then scan any site from anywhere by pointing `--path` at its `wp-content`:

```bash
pressready --php=8.2 --path=/path/to/site/wp-content

# Sweep every project under a dev root
for proj in */ ; do
  echo "===== $proj ====="
  pressready --php=8.2 --path="$proj/wp-content" --format=summary
done
```

Global is ideal for a developer's local sweeps across many sites. For **CI and team reproducibility**, still use `composer require --dev` per project so the engine version is pinned in each `composer.lock`.

### Verify the standards registered

```bash
vendor/bin/phpcs -i   # should list "Pressready" and "PHPCompatibilityWP"
```

Requires PHP 7.4+.

---

## Quick start

```bash
# The headline question: will PHP 8.4 + WP 6.9 break this site, and where?
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content

# PHP axis only — every removal and deprecation by PHP 8.4.
vendor/bin/pressready --php=8.4 --path=wp-content

# WordPress axis only — every core API deprecated by WP 6.9.
vendor/bin/pressready --wp=6.9 --path=wp-content

# Delta: only what newly breaks upgrading FROM 6.4 TO 6.9.
vendor/bin/pressready --wp=6.9 --since=6.4 --path=wp-content

# Scan a single plugin or theme.
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content/plugins/woocommerce

# Scan just your own code (theme + brand mu-plugins), skipping third-party plugins.
vendor/bin/pressready --php=8.4 --wp=6.9 \
  --path=wp-content/themes/acme --path=wp-content/mu-plugins/acme

# PHP upgrade path — everything that breaks across 8.1 → 8.4.
vendor/bin/pressready --php=8.1-8.4 --path=wp-content
```

On a large `wp-content` the report leads with a **fix-first block** — every `fatal` then every `risky` finding with a clickable `path:line` — so upgrade blockers aren't buried under the deprecation tail. Key output behaviours:

- **Deprecations hidden by default** — the report shows fatals and risky findings inline; each component header still carries its deprecation count, and `--all` lists the full deprecation tail when you're ready to clear it
- **Duplicate collapse** — identical findings show once as `(×N)` with a line list (disable with `--no-collapse`)
- **Colour-coded severities** — red fatal, yellow risky, cyan deprecation (auto-off when piped)
- **Live progress spinner** — shown on a TTY, suppressed in CI and pipes

---

## All options

| Flag | Description |
|---|---|
| `--php=<ver>` | Target PHP version or range, e.g. `8.4` or `8.1-8.4` |
| `--wp=<ver>` | Target WordPress version, e.g. `6.9` |
| `--since=<ver>` | With `--wp`: show only what newly deprecates upgrading from this version |
| `--path=<dir>` | Path to scan (default: cwd). **Repeatable** — pass `--path` more than once, or a comma list, to scan several targets in one pass |
| `--format=<fmt>` | Output format — see [Output formats](#output-formats) |
| `--matrix` | Grade the requested target span and report the **highest fatal-free version and the first break** — see [Compatibility matrix](#compatibility-matrix) |
| `--fail-on=<lvl>` | Exit non-zero when findings at/above this level exist: `fatal` · `risky` · `deprecated` |
| `--ignore-on=<lvl>` | Hide findings at/below this level (the inverse of `--fail-on`): `fatal` · `risky` · `deprecated` |
| `--parallel[=N]` | phpcs worker count. **ON by default** (auto CPU cores). `--parallel=1` disables |
| `--cache[=<file>]` | Cache results so re-scans only reprocess changed files |
| `--no-cache` | Force a fresh scan even when `--cache` is set |
| `--ignore=<patterns>` | Extra comma-separated path patterns to skip |
| `--no-default-ignore` | Disable built-in ignores (`vendor`, `node_modules`, `build`, `dist`, `*.min.php`, `tests`, `*.blade.php`) |
| `-h`, `--help` | Print the usage / flag reference and exit |
| `--only=<needle>` | Show only components whose name matches this substring |
| `--top=N` | Show only the N worst components (most fatals first) |
| `--no-collapse` | Show one line per finding instead of collapsing duplicates |
| `--all` | List every deprecation inline. By default the deprecation tail is hidden so fatals/risky stay scannable (`--verbose` is an alias) |
| `--baseline[=<file>]` | Suppress findings present in the baseline snapshot |
| `--generate-baseline[=<file>]` | Snapshot current findings to a baseline file and exit |
| `--config=<file>` | Load defaults from a JSON config file (CLI flags always win) |

---

## Output formats

| Format | Use case |
|---|---|
| `grouped` | **Default.** Human-readable, grouped by component with fix-first block and colours |
| `table` | WP-CLI-style `+--+--+` ASCII table, one row per (collapsed) finding |
| `summary` | One line per component — counts only, no per-file detail |
| `json` | Machine-readable `{tally, components}` — pipe to `jq` or scripts |
| `sarif` | SARIF 2.1.0 for code-scanning dashboards (GitHub Advanced Security, etc.) |
| `github` | GitHub Actions workflow commands — inline PR annotations |

```bash
# Human formats
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content                  # grouped (default)
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --format=table
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --format=summary

# Machine formats
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --format=json
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --format=sarif > pressready.sarif
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --format=github
```

---

## Compatibility matrix

Instead of asking "does this one target break my site?", `--matrix` answers the question every site owner actually has — **how far can I safely upgrade, and what's the first thing that breaks?** It grades each version in the requested span from a single scan:

```bash
vendor/bin/pressready --php=7.4-8.4 --matrix --path=wp-content
```

```
  Pressready — compatibility matrix — PHP 7.4–8.4
  Scanned: wp-content

  PHP
    7.4    ✓ clear
    8.0    ✗ 1 fatal
    8.1    ✗ 1 fatal
    8.2    ✗ 1 fatal
    8.3    ✗ 1 fatal
    8.4    ✗ 1 fatal

  ✓ Safe through PHP 7.4.
  ✗ First break: PHP 8.0 (1 fatal).
```

- Pass a **range** (`--php=7.4-8.4`) to grade across it; a single `--php=8.4` grades just that version.
- `--format=json` emits the per-version grid plus `safe_through` / `first_break` for each axis, so CI can consume the ceiling.
- Honours `--fail-on`: a span containing a fatal exits non-zero.
- The WP axis is graded too; it lights up as the dataset gains removal data.

---

## Large stacks & performance

Pressready is built to scan a full enterprise `wp-content` — hundreds of plugins, tens of thousands of files:

| Feature | How it works | Measured gain |
|---|---|---|
| **Parallel** | phpcs forks workers (auto CPU cores by default) | ~5× faster on a real stack |
| **Cache** | re-scans only reprocess changed files | ~37× faster on a warm cache |
| **Default ignores** | skips `vendor/`, `node_modules/`, `build/`, `dist/`, `*.min.php`, `tests/` | removes un-fixable noise automatically |
| **Collapse** | identical findings show once as `(×N)` | up to 45% fewer output lines |

```bash
# Parallel: explicit count or disable
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --parallel=8
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --parallel=1   # serial

# Cache: warm the cache on first run, near-instant on subsequent runs
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --cache

# Focus: top 10 worst components, or a single one by name
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --top=10
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --only=woocommerce

# Add extra ignores on top of the defaults
vendor/bin/pressready --php=8.4 --path=wp-content --ignore='*/cache/*,*/languages/*'

# Hide the deprecation tail — show only fatals and risky changes
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content --ignore-on=deprecated
```

---

## Config file

Commit shared defaults to a `.pressready.json` in your project root — CLI flags always override the file.

```json
{
    "php": "8.4",
    "wp": "6.9",
    "fail-on": "fatal",
    "paths": ["wp-content/themes/acme", "wp-content/mu-plugins/acme"],
    "ignore": ["*/cache/*", "*/languages/*"],
    "top": 20
}
```

```bash
vendor/bin/pressready --path=wp-content              # picks up .pressready.json automatically
vendor/bin/pressready --config=ci/pressready.json    # or point at a specific file
```

Recognised keys mirror the flags (use `paths: []` for multiple scan targets). **Unknown keys are reported as a warning** so a typo doesn't silently change your scan scope. CLI flags always override the file — passing any `--path` on the command line replaces the config's `path`/`paths` entirely.

---

## WP-CLI

Pressready ships a `wp pressready scan` command — same engine, run from the WordPress root, defaulting to the site's `wp-content`. Runs *before* WordPress boots — no database required.

### Register the command

Add to your project's `wp-cli.yml`:

```yaml
require:
  - vendor/itzmekhokan/pressready/wp-cli.php
```

Or pass `--require` inline:

```bash
wp --require=vendor/itzmekhokan/pressready/wp-cli.php pressready scan --php=8.4 --wp=6.9
```

### Usage

```bash
wp pressready scan --php=8.4 --wp=6.9
wp pressready scan wp-content/plugins --php=8.4       # specific directory (positional)
wp pressready scan --wp=6.9 --format=json
wp pressready scan --wp=6.9 --since=6.4               # delta scan
wp pressready scan --php=8.4 --wp=6.9 --fail-on=fatal # CI gate
wp pressready scan --php=8.4 --wp=6.9 --top=10        # focus
```

`--format`: `table` (default) · `summary` · `json` · `csv` · `yaml` · `count`

All standalone CLI flags (`--ignore-on`, `--parallel`, `--cache`, `--ignore`, `--only`, `--top`, `--config`, etc.) work identically with the WP-CLI command.

---

## GitHub Actions / CI

### Recommended: the `itzmekhokan/pressready@v1` action

The published Docker action bakes Pressready **and** its PHPCS 4.x toolchain into
the image, so your repo carries no Composer boilerplate and there is no chance of
clashing with a project's own `squizlabs/php_codesniffer ^3.13` pin. A whole CI
gate reduces to:

```yaml
- uses: actions/checkout@v4
- uses: itzmekhokan/pressready@v1
  with:
    baseline: .pressready-baseline.json
    fail-on: fatal
    format: github
```

`.pressready.json` and the baseline stay repo-local — they're read from your
checkout, so per-repo scope (target PHP/WP + `paths`) is unchanged.

#### Inputs

| Input | Default | Description |
|---|---|---|
| `config` | `.pressready.json` | Path to the config file. Ignored if absent (the CLI auto-discovers `.pressready.json` from the checkout). |
| `baseline` | — | Path to a baseline file. The gate then fails only on findings **not** in the baseline. |
| `fail-on` | `fatal` | Severity threshold that fails the build: `fatal`, `risky`, or `deprecated`. |
| `format` | `github` | Output format: `github`, `grouped`, `table`, `summary`, `json`, `sarif`. |
| `php` | — | Override the target PHP version/range (else taken from config), e.g. `8.4` or `8.1-8.4`. |
| `wp` | — | Override the target WP version (else taken from config), e.g. `6.9`. |
| `working-directory` | `.` | Directory to scan from, relative to the checkout. |

#### Output

| Output | Description |
|---|---|
| `exit-code` | Pressready exit code: `0` clean, `1` findings at/above the threshold, `2` usage/config error. |

Minimal `.github/workflows/pressready.yml`:

```yaml
name: PressReady
on: [ pull_request ]
permissions:
  contents: read
jobs:
  gate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: itzmekhokan/pressready@v1
        with:
          baseline: .pressready-baseline.json
          fail-on: fatal
          format: github
```

> Pin to `@v1` for the moving major tag, or `@v1.0.0` for an immutable release.
> The image is published to `ghcr.io/itzmekhokan/pressready` and pulled pre-built,
> so runs don't rebuild it.

### Raw CLI recipes

If you'd rather run the binary yourself (e.g. you already install Pressready via
Composer in an isolated `tools/` project), these recipes still apply.

#### Inline PR annotations

```yaml
- name: Pressready scan
  run: |
    vendor/bin/pressready \
      --php=8.4 --wp=6.9 \
      --path=wp-content \
      --format=github
```

#### Fail the build on fatals

```yaml
- name: Pressready scan
  run: |
    vendor/bin/pressready \
      --php=8.4 --wp=6.9 \
      --path=wp-content \
      --fail-on=fatal \
      --cache
```

#### Upload SARIF to GitHub Advanced Security

```yaml
- name: Pressready scan
  run: |
    vendor/bin/pressready \
      --php=8.4 --wp=6.9 \
      --path=wp-content \
      --format=sarif > pressready.sarif

- name: Upload SARIF
  uses: github/codeql-action/upload-sarif@v3
  with:
    sarif_file: pressready.sarif
```

#### Baseline: fail only on new findings

```yaml
- name: Pressready scan
  run: |
    vendor/bin/pressready \
      --php=8.4 --wp=6.9 \
      --path=wp-content \
      --baseline \
      --fail-on=fatal \
      --format=github
```

---

## Baseline (legacy sites)

Adopt Pressready on a site with pre-existing issues without drowning in noise — snapshot today's findings, then fail CI only on *new* ones.

```bash
# Step 1: snapshot existing findings once.
vendor/bin/pressready --php=8.4 --wp=6.9 --generate-baseline --path=wp-content

# Step 2: from now on, fail only on new findings.
vendor/bin/pressready --php=8.4 --wp=6.9 --baseline --fail-on=fatal --path=wp-content
```

Findings are keyed by `path → signature` (sniff code + message with line numbers neutralised), so the baseline survives line shifts and reordering. Use `--baseline=<file>` / `--generate-baseline=<file>` to store the baseline at a custom path.

Paths are normalised **relative to the directory you run from** (your repo root), so a baseline generated locally matches one generated in CI regardless of whether `--path` was absolute, relative, or scoped to a subdirectory. Run `generate-baseline` and `--baseline` from the same working directory (the repo root) and the keys line up.

---

## Exit codes

CI gates can rely on a stable exit-code contract:

| Code | Meaning |
|---|---|
| `0` | Clean — no findings, or none at/above the `--fail-on` threshold |
| `1` | Findings at/above the `--fail-on` threshold (`fatal` / `risky` / `deprecated`) |
| `2` | Usage / config error — bad flag, a `--path` that doesn't exist, or **zero files scanned** |

Without `--fail-on`, the scan reports findings but exits `0`. Exit `2` is deliberately distinct from `1`: scanning zero files (a misconfigured path or over-broad `--ignore`) fails as a config error rather than passing vacuously, so a broken gate goes red instead of green.

---

## Per-finding suppression

Uses native PHPCS inline comments — no custom syntax to learn:

```php
create_function( '$x', 'return $x;' ); // phpcs:ignore
get_postdata( 1 );                      // phpcs:ignore Pressready.WordPress.Deprecated.DeprecatedFunction
```

---

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | WP-deprecations dataset generator + authoritative JSON dataset | ✅ done |
| 2 | Custom PHPCS sniff + reporter (grouped / summary / json), version gate + delta, exit codes | ✅ done |
| 3 | `--php=` via bundled PHPCompatibility; unified severity model (fatal / risky / php / wp) | ✅ done |
| 4 | Per-component attribution, baseline, `--format=github`, inline suppression | ✅ done |
| 5 | `wp pressready scan` WP-CLI command | ✅ done |
| 6 | Enterprise scale: parallel, cache, path ignores, duplicate collapsing, `--only`/`--top`, config file, SARIF, PHP ranges, docblock dataset coverage | ✅ done |
| 7 | Docker GitHub Action (`ghcr.io` image, `@v1`) — one-line CI adoption with the PHPCS toolchain baked into the image | ✅ done |
| 8+ | DataViews admin report; fix-hint links; WP.org distribution | planned |

---

## Acknowledgements

- [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) — the PHP-version compatibility engine that powers the PHP axis
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) — the static analysis foundation both engines run on

