# Pressready

[![CI](https://github.com/itzmekhokan/pressready/actions/workflows/ci.yml/badge.svg)](https://github.com/itzmekhokan/pressready/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

> **Is your WordPress site ready for the next PHP or WordPress version?**
> Scan the whole installed stack — every plugin, theme, and mu-plugin, not just your own code —
> and find out exactly what breaks *before* you upgrade.

`pressready --php=8.4 --wp=6.9` points static analysis at a live site's `wp-content` and returns a verdict grouped by component, with `file:line` and a clear severity for every finding.

**[→ Full documentation](https://itzmekhokan.github.io/pressready/)**

---

## Severity model

| Level | Meaning |
|---|---|
| `fatal` | PHP symbol removed by the target version → white screen |
| `risky` | Behavioural change that still runs but may produce wrong results |
| `php` | PHP feature deprecated (not yet removed) |
| `wp` | WordPress core API deprecated by the target WP version |

Under the hood it runs two engines in one PHPCS pass: [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) for the PHP axis and a custom sniff driven by an authoritative WP deprecations dataset for the WordPress axis.

---

## Install

Detecting breakages on **PHP 8.2–8.4** requires the `PHPCompatibility 10` engine, which the upstream project only ships as a dev release. So your project needs to allow dev stability (it still prefers stable for everything else) and allow the PHPCS installer plugin to register the standards.

Add this to your project's `composer.json`:

```jsonc
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true,
            "phpcsstandards/phpcsutils": true
        }
    }
}
```

Then:

```bash
composer require --dev itzmekhokan/pressready
```

Or set the same config from the command line, without editing the file by hand:

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer config --no-plugins allow-plugins.phpcsstandards/phpcsutils true
composer require --dev itzmekhokan/pressready
```

Verify the standards registered:

```bash
vendor/bin/phpcs -i   # should list "Pressready" and "PHPCompatibilityWP"
```

Requires PHP 7.4+.

---

## Quick start

```bash
# Both axes — the headline question.
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content

# One axis at a time.
vendor/bin/pressready --php=8.4 --path=.
vendor/bin/pressready --wp=6.9  --path=.

# Delta: only what newly breaks upgrading FROM 6.4 TO 6.9.
vendor/bin/pressready --wp=6.9 --since=6.4 --path=.

# CI gate: fail the build only on real fatals.
vendor/bin/pressready --php=8.4 --wp=6.9 --fail-on=fatal --path=.

# Machine-readable output.
vendor/bin/pressready --php=8.4 --wp=6.9 --format=json --path=.

# GitHub PR inline annotations.
vendor/bin/pressready --php=8.4 --wp=6.9 --format=github --path=.
```

`--format`: `grouped` (default) · `summary` · `json` · `github`
`--fail-on`: `fatal` · `risky` · `deprecated`

On a large `wp-content`, the grouped/summary output leads with a **fix-first block** — every `fatal` then every `risky` finding with a clickable `path:line` — so the issues that actually block an upgrade aren't buried under the deprecation tail. In an interactive terminal you also get a live progress meter while it scans (suppressed automatically when piped or in CI).

---

## WP-CLI

Pressready ships a `wp pressready` command — same engine, run from the WordPress root, defaulting to the site's `wp-content`. Runs *before* WordPress boots — no database required.

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
# Scan with both axes.
wp pressready scan --php=8.4 --wp=6.9

# Scan a specific directory (positional — WP-CLI reserves --path for the install dir).
wp pressready scan wp-content/plugins --php=8.4

# WordPress axis only, JSON output.
wp pressready scan --wp=6.9 --format=json

# Delta: only what newly deprecates upgrading from 6.4.
wp pressready scan --wp=6.9 --since=6.4

# CI gate: exit non-zero when fatals are present.
wp pressready scan --php=8.4 --wp=6.9 --fail-on=fatal
```

`--format`: `table` (default) · `summary` · `json` · `csv` · `yaml` · `count`
`--fail-on`: `fatal` · `risky` · `deprecated`

---

## Baseline (legacy sites)

Adopt on a site with pre-existing issues without drowning in noise — snapshot today's findings, then fail CI only on *new* ones.

```bash
# Snapshot existing findings.
vendor/bin/pressready --php=8.4 --wp=6.9 --generate-baseline

# From now on, fail only on new findings.
vendor/bin/pressready --php=8.4 --wp=6.9 --baseline --fail-on=fatal
```

Findings are keyed by `path → signature` so the baseline survives line shifts and reordering.

---

## Per-finding suppression

Uses native PHPCS inline comments — no custom syntax:

```php
create_function( '$x', 'return $x;' ); // phpcs:ignore
get_postdata( 1 );                      // phpcs:ignore Pressready.WordPress.Deprecated.DeprecatedFunction
```

---

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | WP-deprecations dataset generator + dataset | ✅ done |
| 2 | Scan sniff + reporter (grouped / summary / json), version gate + delta, exit codes | ✅ done |
| 3 | `--php=` via bundled PHPCompatibility; unified severity model | ✅ done |
| 4 | Per-component attribution, baseline, `--format=github`, inline suppression | ✅ done |
| 5 | `wp pressready scan` WP-CLI command | ✅ done |
| 6+ | DataViews admin report; fix-hint links; WP.org distribution | planned |

---

Sibling to **[Crate](https://itzmekhokan.github.io/crate/)** in a "safe change" family — Crate ships content between environments; Pressready tells you it's safe to change the environment itself.
