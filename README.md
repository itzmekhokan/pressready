# Pressready

> **Is your WordPress press-ready for the next PHP/WordPress version?**
> Scan a site's whole installed stack — every third-party plugin and theme, not just
> your own code — and find out exactly what breaks *before* you upgrade.

`pressready --php=8.4 --wp=6.9` points static analysis at a live site's `wp-content` and
returns a verdict grouped by plugin, with `file:line` and a clear severity for each finding:

- **`fatal`** — a PHP symbol removed by the target version: a call to undefined → white screen.
- **`risky`** — a behavioural/correctness change that still runs but may misbehave.
- **`php`** — a PHP feature deprecated (not yet removed) by the target version.
- **`wp`** — a WordPress core API deprecated by the target WP version.

Under the hood it runs two engines in a single pass:

- **PHP side:** [PHPCompatibility](https://github.com/PHPCompatibility/PHPCompatibility) (the
  proven, community-maintained ruleset) for the PHP-version axis.
- **WP side (the moat):** a custom PHPCS sniff driven by an authoritative, regenerable dataset
  of *every* WordPress core deprecation — generated straight from core's own `_deprecated_*`
  annotations (see [Phase 1](#phase-1--wordpress-deprecations-dataset-)).

Sibling to **Crate** in a "safe change" family (Crate ships content between environments;
Pressready tells you it's safe to change the environment itself).

## Install

```bash
composer require --dev itzmekhokan/pressready
```

That pulls PHP_CodeSniffer + PHPCompatibilityWP and registers the `Pressready` standard
automatically. Then:

```bash
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content/plugins
```

> Requires PHP 7.4+. Uses pre-release PHPCompatibility 10 (the only line with PHP 8.x removal
> data); these are pinned and will be unpinned when it ships stable.

## Quick start

```bash
# Both axes — the headline question.
vendor/bin/pressready --php=8.4 --wp=6.9 --path=wp-content

# One axis at a time.
vendor/bin/pressready --php=8.4 --path=.
vendor/bin/pressready --wp=6.9  --path=.

# Only what newly breaks upgrading FROM 6.4 TO 6.9.
vendor/bin/pressready --wp=6.9 --since=6.4 --path=.

# CI gate: fail the build only on real fatals.
vendor/bin/pressready --php=8.4 --wp=6.9 --fail-on=fatal --path=.

# Machine-readable, or inline GitHub PR annotations.
vendor/bin/pressready --php=8.4 --wp=6.9 --format=json --path=.
vendor/bin/pressready --php=8.4 --wp=6.9 --format=github --path=.

# Adopt on a legacy site: snapshot existing findings, then fail only on NEW ones.
vendor/bin/pressready --php=8.4 --wp=6.9 --generate-baseline
vendor/bin/pressready --php=8.4 --wp=6.9 --baseline --fail-on=fatal
```

`--format`: `grouped` (default) · `summary` · `json` · `github`.
`--fail-on`: `fatal` · `risky` · `deprecated` (any finding).
`--baseline[=file]` suppresses known findings · `--generate-baseline[=file]` writes one.

---

## How it works

The rest of this README documents how Pressready is built, phase by phase.

## Phase 1 — WordPress deprecations dataset  ✅

Core annotates every deprecation inline with the version it was deprecated in
(`_deprecated_function( __FUNCTION__, '6.9.0' )`, `apply_filters_deprecated( 'hook', …, '4.3.0' )`,
etc.). The generator token-parses `wp-includes` + `wp-admin` from a `wordpress-develop`
checkout and extracts all of it into one versioned JSON file.

### Regenerate

```bash
php bin/gen-wp-deprecations.php \
  --src=/path/to/wordpress-develop/src \
  --out=data/wp-deprecations.json

# DEBUG=1 prints every call that couldn't be resolved (dynamic version/identifier).
```

Re-run against a newer core checkout each WP release to refresh the dataset for free.

### Dataset shape (`data/wp-deprecations.json`)

```json
{
  "schema": 1,
  "generated_at": "2026-05-31T…Z",
  "source": { "wp_version": "7.1-alpha-…" },
  "counts": { "function": 384, "method": 69, "class": 3, "file": 33, "hook": 51, "argument": 62 },
  "deprecations": {
    "function": { "get_postdata": { "deprecated": "1.5.1", "replacement": "get_post()" }, … },
    "method":   { "Services_JSON::decode": { "deprecated": "5.3.0", "replacement": "…" }, … },
    "class":    { "WP_User_Search": { "deprecated": "3.1.0", "replacement": "WP_User_Query" }, … },
    "file":     { "rss.php": { "deprecated": "3.0.0", "replacement": "/class-simplepie.php" }, … },
    "hook":     { "query_string": { "deprecated": "2.1.0", "replacement": "query_vars, request", "type": "filter" }, … },
    "argument": { "WP_Query": { "deprecated": "3.1.0", "note": "deprecated argument" }, … }
  }
}
```

### What the generator handles

- Scope resolution — `__FUNCTION__` / `__METHOD__` / `__CLASS__` resolve against the
  enclosing class/function (token-walked brace stack), so a `__FUNCTION__` inside a class
  is correctly recorded as a **method**.
- Every core deprecation API: `_deprecated_function`, `_deprecated_constructor` (→ class),
  `_deprecated_class`, `_deprecated_file`, `_deprecated_argument`, `_deprecated_hook`,
  `apply_filters_deprecated` (filter), `do_action_deprecated` (action).
- Multiline calls (token-based, not regex); context-sensitive keyword names
  (the deprecated `readonly()` function tokenizes as `T_READONLY` on PHP 8.1+).
- Excludes: `_doing_it_wrong` (misuse, not deprecation); `wp-content` (default themes /
  bundled plugins use non-WP version strings like `'Gutenberg 16.3.0'`); the API function
  *declarations* themselves; dynamic identifiers/versions (`$version`, `'MU'`,
  `__CLASS__ . '::' . $name`, interpolated `"auth_{$type}_…"` hook names).

Current run: **641 deprecation calls → 602 unique entries; 8 unresolved**, all of which are
genuinely dynamic and unmatchable by static analysis.

### Notes for Phase 2 (the sniff)

- PHP function/class/method names are **case-insensitive** → match case-insensitively.
- Hook names are **case-sensitive** → match exactly.
- Severity model: removed-in-target = fatal; deprecated-in-target = deprecated; argument =
  risky/info. (PHP-side severities come from PHPCompatibility.)

## Phase 2 — the scan engine (PHPCS sniff + reporter)  ✅

A custom PHP_CodeSniffer standard (`Pressready/`) whose `DeprecatedSniff` consumes the Phase 1
dataset and flags usage of deprecated core APIs against a target WP version (`--wp=`), plus
the `bin/pressready` driver that groups findings by the owning plugin/theme. See
[Quick start](#quick-start) for usage. The raw sniff also runs directly:
`PRESSREADY_WP=6.9 vendor/bin/phpcs --standard=Pressready <path>`.

### What it detects (and deliberately doesn't)

| Detected | How |
|---|---|
| Deprecated **function** calls | `foo()` — case-insensitive; skips `->foo()`, `Ns\foo()`, declarations |
| Deprecated static **methods** | `Class::method()` — case-insensitive |
| Deprecated **classes** | `new`, `extends`, `implements`, `instanceof`, `Class::` — unqualified, case-insensitive |
| Deprecated **hooks** | first literal arg of `add_filter`/`add_action`/`do_action`/`apply_filters`/… — case-sensitive |
| Deprecated **files** | `include`/`require` of a deprecated core basename |

Deliberately **not** flagged (avoids false positives): instance method calls on dynamic
objects (`$obj->method()`), dynamic/interpolated hook names, and classes whose *constructor*
is deprecated but the class is not (e.g. `WP_Widget`, `POMO_FileReader`). Deprecated
*arguments* are in the dataset but not yet surfaced (can't be detected statically without
arg analysis).

Findings are **warnings** (WP-deprecated APIs still run; they signal tech debt / notices) —
the real upgrade *fatals* come from the PHP side in Phase 3.

Verified: fixture (positive + negative controls), bbPress (5 real findings, 0 false
positives), WooCommerce `includes/` at scale.

## Phase 3 — the PHP side + unified severity model  ✅

Adds the PHP-version axis (`--php=`) by bundling **PHPCompatibility** (the proven,
community-maintained ruleset) and running it in the **same PHPCS pass** as the Pressready WP
sniff, so one scan answers *"will PHP 8.4 **and** WP 6.9 break my site, and where?"*. See
[Quick start](#quick-start). `--php=<ver>` is the PHP version the site will run on; it maps to
PHPCompatibility's `testVersion`. At least one of `--php` / `--wp` is required.

### Unified severity model

Every finding is classified into one tier, because **not every PHPCompatibility error is a
runtime fatal** — only *removals* are. (PHPCompatibility flags behavioural changes like the
PHP 7.0 `func_get_args()` semantics as errors too; those run fine, they just behave
differently.)

| Tier | Meaning | Source |
|---|---|---|
| `fatal` | Symbol **removed** by the target PHP version → call to undefined → white screen | PHPCompatibility, message says "removed since PHP" |
| `risky` | Behavioural / correctness change that still runs but may misbehave | other PHPCompatibility errors |
| `php` | PHP feature **deprecated** (not yet removed) by the target version | PHPCompatibility warnings |
| `wp` | WordPress core API deprecated by the target WP version | Pressready sniff |

The verdict and `--fail-on=fatal` key on **true fatals only**, so the report never
cries wolf. `--fail-on` accepts `fatal` · `risky` · `deprecated` (any finding).

Verified end-to-end: combined fixture (`tests/fixtures/upgrade.php` → 1 fatal, 1 risky,
3 deprecations) and **bbPress at PHP 8.4 + WP 6.9 → 0 fatals, 7 risky, 5 deprecations** —
i.e. an honest "runs on 8.4, with behaviour to review", not a false alarm.

### Engine stack

The PHP 8.x *removal* data only lives in the PHPCompatibility `10.x` line (the stable
`9.3.5` predates PHP 8.0 and can't see 8.x removals), which requires **PHP_CodeSniffer 4**.
So the project runs on **PHPCS 4 + `phpcompatibility/phpcompatibility-wp` 3.0.0-alpha2**
(the WP-aware wrapper that excludes functions WordPress polyfills, avoiding false positives).
The custom Pressready sniff runs unchanged on PHPCS 4. These are pre-release deps pinned in
`composer.json` (`minimum-stability: dev`); they get unpinned when PHPCompatibility 10 ships
stable.

## Phase 4 — attribution, baseline & CI  ✅

Makes Pressready usable against a *real* `wp-content` and inside CI.

**Per-component attribution.** Findings group by the plugin/theme that owns them, with name +
version read from the header — including the cases a naïve scan gets wrong:

- multi-file plugins/themes (slug dir; themes via `style.css`),
- **single-file plugins** (`plugins/hello.php`) and **mu-plugins** — the header is read from
  the file itself, so `hello.php` reports as *Hello Dolly 1.7.2*, not `hello.php`,
- **WordPress core** (`wp-admin`/`wp-includes`) collapses into one "WordPress core" bucket.

Components sort fatals-first so the things that actually break an upgrade are at the top.

**Baseline** — adopt the tool on a legacy site without drowning in pre-existing findings
(PHPStan-style):

```bash
# Snapshot today's findings into .pressready-baseline.json
vendor/bin/pressready --php=8.4 --wp=6.9 --generate-baseline

# From now on, only NEW findings show (and fail CI). Known ones are suppressed.
vendor/bin/pressready --php=8.4 --wp=6.9 --baseline --fail-on=fatal
```

Findings are keyed by `path → signature → count` (signature = sniff code + message with line
numbers neutralised), so the baseline survives line shifts and reordering. `--baseline=<file>`
/ `--generate-baseline=<file>` accept a custom path.

**CI output.** `--format=github` emits GitHub Actions workflow commands so each finding
annotates the PR inline (fatals as `::error`, everything else as `::warning`):

```bash
vendor/bin/pressready --php=8.4 --wp=6.9 --format=github
```

**Per-finding suppression** uses native PHPCS comments — no custom syntax:

```php
create_function( '$x', 'return $x;' ); // phpcs:ignore
get_postdata( 1 ); // phpcs:ignore Pressready.WordPress.Deprecated.DeprecatedFunction
```

Verified end-to-end against a real multi-component `wp-content` (Akismet, Classic Editor,
Hello Dolly, a theme, an mu-plugin): correct attribution; baseline suppresses all known
findings and surfaces only newly-introduced ones; `--fail-on=fatal` over a baseline gates CI.

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | WP-deprecations dataset generator + dataset | ✅ done |
| 2 | `scan` sniff + reporter (grouped/summary/json), version gate + delta, exit codes | ✅ done |
| 3 | `--php=` via bundled PHPCompatibility; unified severity model (fatal/risky/php/wp) | ✅ done |
| 4 | Per-component attribution, baseline, `--format=github`, ignore annotations | ✅ done |
| 5 | Wrap as `wp … pressready`; distribution: Composer + WP.org, Plugin Check clean | planned |
| 6+ | DataViews admin report; fix-hint links; Update Safety Net (sequel) | planned |
