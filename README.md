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
- [Large stacks & performance](#large-stacks--performance)
- [Config file](#config-file)
- [WP-CLI](#wp-cli)
- [GitHub Actions / CI](#github-actions--ci)
- [Baseline (legacy sites)](#baseline-legacy-sites)
- [Per-finding suppression](#per-finding-suppression)
- [Roadmap](#roadmap)

---

## Severity model

| Level | Meaning | Engine |
|---|---|---|
| `fatal` | PHP symbol **removed** by the target version → white screen on upgrade | PHPCompatibility |
| `risky` | Behavioural change that still runs but may produce wrong results | PHPCompatibility |
| `php` | PHP feature deprecated (not yet removed) | PHPCompatibility |
| `wp` | WordPress core API deprecated by the target WP version | Pressready sniff |

Under the hood Pressready runs two engines in a single PHPCS pass: [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) for the PHP axis, and a custom sniff driven by an authoritative WP deprecations dataset for the WordPress axis.

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
| `--path=<dir>` | Directory to scan (default: cwd) |
| `--format=<fmt>` | Output format — see [Output formats](#output-formats) |
| `--fail-on=<lvl>` | Exit non-zero when findings at/above this level exist: `fatal` · `risky` · `deprecated` |
| `--ignore-on=<lvl>` | Hide findings at/below this level (the inverse of `--fail-on`): `fatal` · `risky` · `deprecated` |
| `--parallel[=N]` | phpcs worker count. **ON by default** (auto CPU cores). `--parallel=1` disables |
| `--cache[=<file>]` | Cache results so re-scans only reprocess changed files |
| `--no-cache` | Force a fresh scan even when `--cache` is set |
| `--ignore=<patterns>` | Extra comma-separated path patterns to skip |
| `--no-default-ignore` | Disable built-in ignores (`vendor`, `node_modules`, `build`, `dist`, `*.min.php`, `tests`) |
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
    "ignore": ["*/cache/*", "*/languages/*"],
    "top": 20
}
```

```bash
vendor/bin/pressready --path=wp-content              # picks up .pressready.json automatically
vendor/bin/pressready --config=ci/pressready.json    # or point at a specific file
```

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

### Inline PR annotations

```yaml
- name: Pressready scan
  run: |
    vendor/bin/pressready \
      --php=8.4 --wp=6.9 \
      --path=wp-content \
      --format=github
```

### Fail the build on fatals

```yaml
- name: Pressready scan
  run: |
    vendor/bin/pressready \
      --php=8.4 --wp=6.9 \
      --path=wp-content \
      --fail-on=fatal \
      --cache
```

### Upload SARIF to GitHub Advanced Security

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

### Baseline: fail only on new findings

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
| 7+ | DataViews admin report; fix-hint links; WP.org distribution | planned |

---

## Acknowledgements

- [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) — the PHP-version compatibility engine that powers the PHP axis
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) — the static analysis foundation both engines run on

