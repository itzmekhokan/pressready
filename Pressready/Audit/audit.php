<?php
/**
 * Pressready — WordPress upgrade-readiness scanner.
 *
 * @copyright 2026 Khokan Sardar
 * @license   GPL-2.0-or-later
 * @link      https://github.com/itzmekhokan/pressready
 *
 * Security audit — `pressready audit` and `pressready update-db`.
 *
 * Answers "is this site running something with a known security hole?" for the
 * platform layers every WordPress site sits on: WordPress core, PHP and Node.
 *
 *   1. Inventory — find the versions the repo declares or ships:
 *      wp-includes/version.php, composer.lock (roots/wordpress …), pantheon.yml,
 *      .lando.yml, .ddev/config.yaml, Dockerfile / compose images, .nvmrc,
 *      .node-version, .tool-versions, package.json volta, composer.json
 *      config.platform.php, and setup-php / setup-node in GitHub workflows.
 *      `--runtime` adds the PHP (and Node) actually running the audit — on a
 *      server via `wp pressready audit`, that is the real production patch level.
 *   2. Advisory data — `update-db` fetches public, keyless feeds into one local
 *      JSON snapshot; `audit` itself never touches the network (unless --update):
 *        WordPress  api.wordpress.org/core/stable-check (latest|outdated|insecure)
 *        PHP        php.net releases JSON (each release tagged "security" or not)
 *        Node       nodejs.org/dist/index.json (each release has security: bool)
 *        EOL dates  endoflife.date (php, nodejs)
 *   3. Verdict per detected version:
 *        fatal  WordPress version marked insecure, or a PHP/Node version behind a
 *               security release on its own branch — known-vulnerable now.
 *        risky  PHP/Node branch past end of life — no future security fixes.
 *        info   Not the newest WordPress, or a version the snapshot doesn't know.
 *
 * Loaded by bin/pressready only for the audit subcommands, so it shares that
 * script's helpers (ansi(), exit_code(), baseline_relpath(), gh_escape_*()).
 *
 * @package Pressready
 */

const PRESSREADY_AUDIT_SCHEMA     = 1;
const PRESSREADY_AUDIT_STALE_DAYS = 3;

/**
 * Advisory feeds. `php_releases` is per major (%d); the snapshot fetches every
 * major that still has a supported branch.
 */
const PRESSREADY_AUDIT_FEEDS = array(
	'wordpress'     => 'https://api.wordpress.org/core/stable-check/1.0/',
	'php_releases'  => 'https://www.php.net/releases/index.php?json&max=1000&version=%d',
	'php_eol'       => 'https://endoflife.date/api/php.json',
	'node_releases' => 'https://nodejs.org/dist/index.json',
	'node_eol'      => 'https://endoflife.date/api/nodejs.json',
);

/**
 * Package names that carry WordPress core in a Composer-managed site.
 */
const PRESSREADY_AUDIT_CORE_PACKAGES = array(
	'roots/wordpress',
	'roots/wordpress-no-content',
	'roots/wordpress-full',
	'johnpbloch/wordpress',
	'johnpbloch/wordpress-core',
);

/**
 * `pressready update-db` entry point.
 *
 * @param string[] $argv CLI arguments (argv[1] is the subcommand).
 * @return void
 */
function audit_update_db_main( array $argv ): void {
	$opts = parse_opts( array_slice( $argv, 1 ) );
	if ( isset( $opts['help'] ) ) {
		audit_print_usage();
		exit( 0 );
	}
	$path = audit_db_path( $opts );
	try {
		$db = audit_update_db( $path );
	} catch ( \RuntimeException $e ) {
		fwrite( STDERR, 'error: ' . $e->getMessage() . "\n" );
		exit( 2 );
	}
	fwrite(
		STDOUT,
		sprintf(
			"Wrote advisory snapshot to %s (%d WordPress versions, %d PHP branches, %d Node branches)\n",
			$path,
			count( $db['wordpress']['versions'] ),
			count( $db['php']['branches'] ),
			count( $db['node']['branches'] )
		)
	);
	exit( 0 );
}

/**
 * `pressready audit` entry point.
 *
 * @param string[] $argv CLI arguments (argv[1] is the subcommand).
 * @return void
 */
function audit_main( array $argv ): void {
	$opts = parse_opts( array_slice( $argv, 1 ) );
	if ( isset( $opts['help'] ) ) {
		audit_print_usage();
		exit( 0 );
	}
	$format  = $opts['format'] ?? 'grouped';
	$formats = array( 'grouped', 'table', 'summary', 'json', 'sarif', 'github', 'gitlab' );
	if ( ! in_array( $format, $formats, true ) ) {
		fwrite( STDERR, "error: --format must be one of: " . implode( ', ', $formats ) . "\n" );
		exit( 2 );
	}

	$db_path = audit_db_path( $opts );
	if ( isset( $opts['update'] ) ) {
		try {
			audit_update_db( $db_path );
		} catch ( \RuntimeException $e ) {
			fwrite( STDERR, 'error: ' . $e->getMessage() . "\n" );
			exit( 2 );
		}
	}
	if ( ! is_file( $db_path ) ) {
		fwrite( STDERR, "error: no advisory data at $db_path — run `pressready update-db` first (or pass --update)\n" );
		exit( 2 );
	}
	$db = json_decode( (string) file_get_contents( $db_path ), true );
	if ( ! is_array( $db ) || ( $db['schema'] ?? 0 ) !== PRESSREADY_AUDIT_SCHEMA ) {
		fwrite( STDERR, "error: advisory data at $db_path is unreadable or from another version — run `pressready update-db`\n" );
		exit( 2 );
	}
	$age_days = audit_warn_if_db_stale( $db );

	$inventory = array();
	foreach ( resolve_scan_paths( $opts ) as $p ) {
		$root = realpath( $p );
		if ( false === $root || ! is_dir( $root ) ) {
			fwrite( STDERR, "error: --path is not a directory: $p\n" );
			exit( 2 );
		}
		$inventory = array_merge( $inventory, audit_inventory( $root ) );
	}
	$inventory = array_merge( $inventory, audit_runtime_inventory( $opts ) );

	// Detecting nothing is a misconfiguration (wrong --path), never a clean pass.
	if ( empty( $inventory ) ) {
		fwrite( STDERR, "error: no WordPress, PHP or Node versions found — check --path\n" );
		exit( 2 );
	}

	$today    = gmdate( 'Y-m-d' );
	$findings = array();
	foreach ( $inventory as $i => $item ) {
		$item_findings             = audit_check( $item, $db, $today );
		$inventory[ $i ]['status'] = audit_item_status( $item_findings );
		$findings                  = array_merge( $findings, $item_findings );
	}
	usort(
		$findings,
		static fn( $a, $b ) => audit_severity_rank( $a['severity'] ) <=> audit_severity_rank( $b['severity'] )
	);

	$tally = array( 'fatal' => 0, 'risky' => 0, 'info' => 0, 'total' => 0 );
	foreach ( $findings as $f ) {
		++$tally[ $f['severity'] ];
		++$tally['total'];
	}

	// --fail-on=info means "any finding"; exit_code() spells that 'any'.
	if ( 'info' === ( $opts['fail-on'] ?? '' ) ) {
		$opts['fail-on'] = 'any';
	}

	switch ( $format ) {
		case 'json':
			echo json_encode(
				array(
					'tally'     => $tally,
					'advisory'  => array(
						'generated_at' => $db['generated_at'] ?? null,
						'age_days'     => $age_days,
					),
					'inventory' => $inventory,
					'findings'  => $findings,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n";
			break;
		case 'github':
			audit_render_github( $findings );
			break;
		case 'sarif':
			audit_render_sarif( $findings );
			break;
		case 'gitlab':
			audit_render_gitlab( $findings );
			break;
		default:
			audit_render( $inventory, $findings, $tally, $db, $age_days, 'summary' !== $format );
	}
	exit( exit_code( $tally, $opts ) );
}

/**
 * Print the audit usage for `pressready audit --help`.
 *
 * @return void
 */
function audit_print_usage(): void {
	echo <<<USAGE
Pressready audit — flag known-vulnerable WordPress, PHP and Node versions.

Usage:
  pressready audit [--path=<dir>]... [options]
  pressready update-db [--db=<file>]

Finds the WordPress core version and the PHP / Node versions a site declares
(version.php, composer.lock, pantheon.yml, .lando.yml, .ddev, Dockerfile,
compose files, .nvmrc, .node-version, .tool-versions, package.json volta,
GitHub workflows) and checks them against a local advisory snapshot.

Severity:
  fatal   WordPress marked insecure, or PHP/Node behind a security release
  risky   PHP/Node branch past end of life (no more security fixes)
  info    Not the newest release, or a version the snapshot doesn't know

Options:
  --path=<dir>           Directory to audit (repeatable; default: cwd)
  --update               Refresh the advisory snapshot before auditing
  --db=<file>            Advisory snapshot path (default: ~/.cache/pressready/advisories.json)
  --runtime              Also audit the PHP (and Node) running this command
  --runtime-php=<ver>    Audit this PHP version as the runtime
  --runtime-node=<ver>   Audit this Node version as the runtime
  --format=<fmt>         grouped (default) | summary | json | sarif | github | gitlab
  --fail-on=<lvl>        Exit non-zero at/above: fatal | risky | info

The snapshot comes from public, keyless feeds: api.wordpress.org stable-check,
php.net releases, nodejs.org/dist and endoflife.date. Audits warn when it is
older than 3 days.

USAGE;
}

/**
 * Resolve the advisory snapshot path: --db, then $PRESSREADY_AUDIT_DB, then the
 * user cache dir.
 *
 * @param array $opts Parsed options.
 * @return string
 */
function audit_db_path( array $opts ): string {
	if ( isset( $opts['db'] ) && '1' !== $opts['db'] && '' !== $opts['db'] ) {
		return $opts['db'];
	}
	$env = getenv( 'PRESSREADY_AUDIT_DB' );
	if ( false !== $env && '' !== $env ) {
		return $env;
	}
	$cache = getenv( 'XDG_CACHE_HOME' );
	if ( false === $cache || '' === $cache ) {
		$home  = getenv( 'HOME' );
		$cache = ( false !== $home && '' !== $home ) ? $home . '/.cache' : sys_get_temp_dir();
	}
	return rtrim( $cache, '/' ) . '/pressready/advisories.json';
}

/**
 * Fetch one advisory feed and decode it. PRESSREADY_AUDIT_FEEDS_DIR points at a
 * directory of local copies instead (tests, air-gapped mirrors): the file is
 * named after the feed key, e.g. php_releases-8.json, node_eol.json.
 *
 * @param string   $key   Feed key in PRESSREADY_AUDIT_FEEDS.
 * @param int|null $major PHP major for the per-major releases feed.
 * @return array
 * @throws \RuntimeException When the feed can't be fetched or isn't JSON.
 */
function audit_fetch_feed( string $key, ?int $major = null ): array {
	$dir = getenv( 'PRESSREADY_AUDIT_FEEDS_DIR' );
	if ( false !== $dir && '' !== $dir ) {
		$src  = rtrim( $dir, '/' ) . '/' . $key . ( null !== $major ? '-' . $major : '' ) . '.json';
		$body = is_file( $src ) ? file_get_contents( $src ) : false;
	} else {
		$src  = null !== $major ? sprintf( PRESSREADY_AUDIT_FEEDS[ $key ], $major ) : PRESSREADY_AUDIT_FEEDS[ $key ];
		$body = audit_http_get( $src );
	}
	if ( false === $body ) {
		throw new \RuntimeException( "could not fetch advisory feed: $src" );
	}
	$data = json_decode( (string) $body, true );
	if ( ! is_array( $data ) ) {
		throw new \RuntimeException( "advisory feed is not valid JSON: $src" );
	}
	return $data;
}

/**
 * HTTP GET with a timeout and an identifying User-Agent. Uses ext-curl when
 * present, else the http:// stream wrapper.
 *
 * @param string $url URL.
 * @return string|false Body, or false on any failure (including non-2xx).
 */
function audit_http_get( string $url ) {
	$ua = 'pressready/' . pressready_version() . ' (+https://github.com/itzmekhokan/pressready)';
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_USERAGENT      => $ua,
				CURLOPT_FAILONERROR    => true,
			)
		);
		$body = curl_exec( $ch );
		curl_close( $ch );
		return is_string( $body ) ? $body : false;
	}
	$ctx = stream_context_create(
		array(
			'http' => array(
				'timeout'    => 30,
				'user_agent' => $ua,
			),
		)
	);
	return @file_get_contents( $url, false, $ctx );
}

/**
 * Fetch every feed and write the normalised advisory snapshot.
 *
 * Snapshot shape (schema 1):
 *   wordpress.versions  { "7.1.1": "insecure", "7.1.4": "latest", … }
 *   php.branches        { "8.3": { eol, latest, security_releases: [{version,date}] } }
 *   node.branches       { "22":  { eol, latest, security_releases: [{version,date}] } }
 *
 * The file is written atomically, so a failed fetch never leaves a half
 * snapshot behind (the previous one survives).
 *
 * @param string $path Destination file.
 * @return array The snapshot.
 * @throws \RuntimeException When a feed fails or the file can't be written.
 */
function audit_update_db( string $path ): array {
	$wp = audit_fetch_feed( 'wordpress' );
	if ( empty( $wp ) ) {
		throw new \RuntimeException( 'WordPress stable-check feed returned no versions' );
	}

	// PHP: EOL data names the branches; fetch release history for every major
	// that still has a supported branch (older majors are wholly end of life).
	$today    = gmdate( 'Y-m-d' );
	$php_eol  = audit_fetch_feed( 'php_eol' );
	$php      = audit_branches_from_eol( $php_eol );
	$majors   = array();
	foreach ( $php as $branch => $b ) {
		if ( ! audit_is_eol( $b['eol'], $today ) ) {
			$majors[ (int) $branch ] = true;
		}
	}
	foreach ( array_keys( $majors ) as $major ) {
		foreach ( audit_fetch_feed( 'php_releases', $major ) as $version => $rel ) {
			if ( ! in_array( 'security', (array) ( $rel['tags'] ?? array() ), true ) ) {
				continue;
			}
			$date = isset( $rel['date'] ) ? gmdate( 'Y-m-d', (int) strtotime( $rel['date'] . ' UTC' ) ) : null;
			audit_add_security_release( $php, audit_branch_of( 'php', (string) $version ), (string) $version, $date );
		}
	}

	// Node: every release in one index, each with a security flag.
	$node = audit_branches_from_eol( audit_fetch_feed( 'node_eol' ) );
	foreach ( audit_fetch_feed( 'node_releases' ) as $rel ) {
		$version = ltrim( (string) ( $rel['version'] ?? '' ), 'v' );
		if ( '' === $version || false !== strpos( $version, '-' ) || empty( $rel['security'] ) ) {
			continue; // Pre-releases and non-security releases don't matter here.
		}
		audit_add_security_release( $node, audit_branch_of( 'node', $version ), $version, $rel['date'] ?? null );
	}

	$db = array(
		'schema'       => PRESSREADY_AUDIT_SCHEMA,
		'generated_at' => gmdate( 'c' ),
		'sources'      => PRESSREADY_AUDIT_FEEDS,
		'wordpress'    => array( 'versions' => $wp ),
		'php'          => array( 'branches' => audit_sort_branches( $php ) ),
		'node'         => array( 'branches' => audit_sort_branches( $node ) ),
	);

	$dir = dirname( $path );
	if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new \RuntimeException( "cannot create directory for advisory data: $dir" );
	}
	$tmp = $path . '.tmp-' . getmypid();
	if ( false === file_put_contents( $tmp, json_encode( $db, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) || ! rename( $tmp, $path ) ) {
		@unlink( $tmp );
		throw new \RuntimeException( "cannot write advisory data: $path" );
	}
	return $db;
}

/**
 * Seed a branch map from an endoflife.date product feed.
 *
 * @param array $cycles endoflife.date cycles.
 * @return array<string,array{eol:string|bool|null,latest:?string,security_releases:array}>
 */
function audit_branches_from_eol( array $cycles ): array {
	$branches = array();
	foreach ( $cycles as $c ) {
		if ( ! isset( $c['cycle'] ) ) {
			continue;
		}
		$branches[ (string) $c['cycle'] ] = array(
			'eol'               => $c['eol'] ?? null,
			'latest'            => isset( $c['latest'] ) ? (string) $c['latest'] : null,
			'security_releases' => array(),
		);
	}
	return $branches;
}

/**
 * Order branches newest first, and each branch's security releases newest first.
 *
 * @param array $branches Branch map.
 * @return array
 */
function audit_sort_branches( array $branches ): array {
	foreach ( $branches as &$b ) {
		usort( $b['security_releases'], static fn( $x, $y ) => version_compare( $y['version'], $x['version'] ) );
	}
	unset( $b );
	uksort( $branches, static fn( $x, $y ) => version_compare( (string) $y, (string) $x ) );
	return $branches;
}

/**
 * Record a security release on its branch (creating the branch if the EOL feed
 * didn't list it).
 *
 * @param array       $branches Branch map (by reference).
 * @param string      $branch   Branch key.
 * @param string      $version  Release version.
 * @param string|null $date     Release date (Y-m-d).
 * @return void
 */
function audit_add_security_release( array &$branches, string $branch, string $version, ?string $date ): void {
	if ( ! isset( $branches[ $branch ] ) ) {
		$branches[ $branch ] = array( 'eol' => null, 'latest' => null, 'security_releases' => array() );
	}
	$branches[ $branch ]['security_releases'][] = array( 'version' => $version, 'date' => $date );
	if ( null === $branches[ $branch ]['latest'] || version_compare( $version, $branches[ $branch ]['latest'], '>' ) ) {
		$branches[ $branch ]['latest'] = $version;
	}
}

/**
 * Branch key a version belongs to: PHP branches are major.minor, Node branches
 * are the major.
 *
 * @param string $layer   'php' | 'node'.
 * @param string $version Version string.
 * @return string
 */
function audit_branch_of( string $layer, string $version ): string {
	$parts = explode( '.', $version );
	return 'node' === $layer ? $parts[0] : $parts[0] . '.' . ( $parts[1] ?? '0' );
}

/**
 * Whether an endoflife.date `eol` value means the branch is past end of life.
 *
 * @param mixed  $eol   Date string, true (EOL, date unknown), false/null (supported).
 * @param string $today Y-m-d.
 * @return bool
 */
function audit_is_eol( $eol, string $today ): bool {
	if ( true === $eol ) {
		return true;
	}
	return is_string( $eol ) && '' !== $eol && $eol <= $today;
}

/**
 * Warn (to stderr) when the snapshot is older than PRESSREADY_AUDIT_STALE_DAYS:
 * new security releases land without any code change, so an old snapshot can
 * show a clean audit for a version that has since become vulnerable.
 *
 * @param array $db Snapshot.
 * @return int|null Age in whole days, or null when unknown.
 */
function audit_warn_if_db_stale( array $db ): ?int {
	$ts = isset( $db['generated_at'] ) ? strtotime( (string) $db['generated_at'] ) : false;
	if ( false === $ts ) {
		return null;
	}
	$age = (int) floor( ( time() - $ts ) / 86400 );
	if ( $age > PRESSREADY_AUDIT_STALE_DAYS ) {
		fwrite(
			STDERR,
			sprintf( "warning: advisory data is %d days old; newer security releases may be missing — run `pressready update-db`.\n", $age )
		);
	}
	return $age;
}

/**
 * Parse a declared version into its numeric form and precision.
 *
 * Accepts "8.3", "8.3.12", "v22.11.0", "20.x", "8.3.12-fpm". Returns null for
 * non-numeric values ("lts/*", "latest", "${{ matrix.php }}").
 *
 * @param string $raw Declared value.
 * @return array{0:string,1:int}|null [version, number of numeric parts]
 */
function audit_parse_version( string $raw ): ?array {
	if ( ! preg_match( '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim( $raw, " \t\"'" ), $m ) ) {
		return null;
	}
	$parts = array_values( array_filter( array_slice( $m, 1 ), static fn( $p ) => '' !== $p ) );
	return array( implode( '.', $parts ), count( $parts ) );
}

/**
 * Find the versions a site declares or ships, under one root.
 *
 * @param string $root Absolute directory.
 * @return array<int,array{layer:string,version:string,exact:bool,file:string,line:int,source:string}>
 */
function audit_inventory( string $root ): array {
	$items = array();

	$wp_version_regex = '/^\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/m';

	// Auditing from inside wp-content: core sits one level up, outside the walk.
	$parent_core = dirname( $root ) . '/wp-includes/version.php';
	if ( 'wp-content' === basename( $root ) && is_file( $parent_core ) ) {
		audit_match( $items, $parent_core, $root, 'wordpress', $wp_version_regex, 'wp-includes/version.php' );
	}

	foreach ( audit_walk( $root ) as $file ) {
		$name = basename( $file );
		$rel  = substr( $file, strlen( $root ) + 1 );

		if ( 'version.php' === $name ) {
			// The walker only yields version.php from a wp-includes directory —
			// WordPress core wherever it sits (root, wp/, web/wp/, app/public/ …).
			audit_match( $items, $file, $root, 'wordpress', $wp_version_regex, 'wp-includes/version.php' );
		} elseif ( 'composer.lock' === $name ) {
			audit_scan_composer_lock( $items, $file, $root );
		} elseif ( 'composer.json' === $name ) {
			$json = json_decode( (string) file_get_contents( $file ), true );
			$php  = $json['config']['platform']['php'] ?? null;
			if ( is_string( $php ) ) {
				audit_match( $items, $file, $root, 'php', '/"php"\s*:\s*"(' . preg_quote( $php, '/' ) . ')"/', 'composer.json config.platform.php' );
			}
		} elseif ( in_array( $name, array( 'pantheon.yml', 'pantheon.upstream.yml' ), true ) ) {
			audit_match( $items, $file, $root, 'php', '/^php_version:\s*[\'"]?([\d.]+)/m', 'Pantheon php_version' );
		} elseif ( '.lando.yml' === $name ) {
			audit_match( $items, $file, $root, 'php', '/^\s+php:\s*[\'"]?([\d.]+)/m', 'Lando php' );
			audit_match( $items, $file, $root, 'php', '/type:\s*[\'"]?php:([\d.]+)/', 'Lando service' );
			audit_match( $items, $file, $root, 'node', '/type:\s*[\'"]?node:([\d.]+)/', 'Lando service' );
		} elseif ( '.ddev/config.yaml' === $rel || str_ends_with( $rel, '/.ddev/config.yaml' ) ) {
			audit_match( $items, $file, $root, 'php', '/^php_version:\s*[\'"]?([\d.]+)/m', 'DDEV php_version' );
			audit_match( $items, $file, $root, 'node', '/^nodejs_version:\s*[\'"]?([\d.]+)/m', 'DDEV nodejs_version' );
		} elseif ( preg_match( '/^(Dockerfile(\..+)?|.+\.Dockerfile|(docker-)?compose(\..+)?\.ya?ml)$/i', $name ) ) {
			audit_scan_images( $items, $file, $root );
		} elseif ( in_array( $name, array( '.nvmrc', '.node-version' ), true ) ) {
			audit_match( $items, $file, $root, 'node', '/^\s*(v?\d[\w.]*)/m', $name );
		} elseif ( '.tool-versions' === $name ) {
			audit_match( $items, $file, $root, 'php', '/^php\s+([\d.]+)/m', '.tool-versions php' );
			audit_match( $items, $file, $root, 'node', '/^nodejs\s+([\d.]+)/m', '.tool-versions nodejs' );
		} elseif ( 'package.json' === $name ) {
			$json = json_decode( (string) file_get_contents( $file ), true );
			$node = $json['volta']['node'] ?? null;
			if ( is_string( $node ) ) {
				audit_match( $items, $file, $root, 'node', '/"node"\s*:\s*"(' . preg_quote( $node, '/' ) . ')"/', 'package.json volta.node' );
			}
		} elseif ( preg_match( '#(^|/)\.github/workflows/[^/]+\.ya?ml$#', $rel ) ) {
			audit_match( $items, $file, $root, 'php', '/^\s*php-version:\s*[\'"]?(\d[\d.x]*)[\'"]?\s*$/m', 'workflow php-version' );
			audit_match( $items, $file, $root, 'node', '/^\s*node-version:\s*[\'"]?(\d[\d.x]*)[\'"]?\s*$/m', 'workflow node-version' );
		}
	}
	return $items;
}

/**
 * Versions of the runtime executing the audit (--runtime / --runtime-php /
 * --runtime-node). Under `wp pressready audit` on a server, this is the real
 * production patch level, which hosted platforms don't commit to the repo.
 *
 * @param array $opts Parsed options.
 * @return array
 */
function audit_runtime_inventory( array $opts ): array {
	$items = array();
	$php   = $opts['runtime-php'] ?? ( isset( $opts['runtime'] ) ? PHP_VERSION : '' );
	$node  = $opts['runtime-node'] ?? '';
	if ( '' === $node && isset( $opts['runtime'] ) && function_exists( 'shell_exec' ) ) {
		$node = trim( (string) @shell_exec( 'node --version 2>/dev/null' ) );
	}
	foreach ( array( 'php' => $php, 'node' => $node ) as $layer => $raw ) {
		$parsed = '' !== $raw ? audit_parse_version( $raw ) : null;
		if ( null === $parsed ) {
			continue;
		}
		$items[] = array(
			'layer'   => $layer,
			'version' => $parsed[0],
			'exact'   => 3 === $parsed[1],
			'file'    => '',
			'line'    => 0,
			'source'  => 'runtime',
		);
	}
	return $items;
}

/**
 * Walk a site tree for version-declaring files, skipping dependency, core and
 * third-party plugin directories (a vendored plugin's .nvmrc says nothing
 * about this site).
 *
 * @param string $root      Absolute directory.
 * @param int    $max_depth Maximum directory depth below $root.
 * @return string[] Absolute file paths.
 */
function audit_walk( string $root, int $max_depth = 6 ): array {
	static $skip_dirs = array( '.git', '.svn', 'node_modules', 'vendor', 'wp-admin', 'uploads', 'cache', 'upgrade', 'build', 'dist' );
	$it = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $cur ) use ( $skip_dirs ): bool {
				// Inside wp-includes, only version.php (core's version) matters.
				if ( 'wp-includes' === basename( $cur->getPath() ) ) {
					return ! $cur->isDir() && 'version.php' === $cur->getFilename();
				}
				if ( ! $cur->isDir() ) {
					return true;
				}
				$name = $cur->getFilename();
				if ( in_array( $name, $skip_dirs, true ) ) {
					return false;
				}
				// Third-party plugins live in wp-content/plugins; mu-plugins and
				// themes are usually first-party and stay in scope.
				return ! ( 'plugins' === $name && 'wp-content' === basename( $cur->getPath() ) );
			}
		),
		RecursiveIteratorIterator::LEAVES_ONLY
	);
	$it->setMaxDepth( $max_depth );

	$files = array();
	foreach ( $it as $file ) {
		$name = $file->getFilename();
		if ( preg_match( '/^(version\.php|composer\.(lock|json)|pantheon(\.upstream)?\.yml|\.lando\.yml|config\.yaml|Dockerfile(\..+)?|.+\.Dockerfile|(docker-)?compose(\..+)?\.ya?ml|\.nvmrc|\.node-version|\.tool-versions|package\.json|.+\.ya?ml)$/i', $name ) ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );
	return $files;
}

/**
 * Record every match of a version pattern in a file as an inventory item.
 *
 * @param array  $items  Inventory (by reference).
 * @param string $file   Absolute file.
 * @param string $root   Audit root.
 * @param string $layer  'wordpress' | 'php' | 'node'.
 * @param string $regex  Pattern; group 1 is the version.
 * @param string $source Human label for where the version came from.
 * @return void
 */
function audit_match( array &$items, string $file, string $root, string $layer, string $regex, string $source ): void {
	$content = (string) file_get_contents( $file );
	if ( ! preg_match_all( $regex, $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return;
	}
	foreach ( $all as $m ) {
		audit_add_item( $items, $layer, $m[1][0], $file, $root, substr_count( substr( $content, 0, $m[1][1] ), "\n" ) + 1, $source );
	}
}

/**
 * Append one inventory item if the raw value parses as a usable version.
 *
 * WordPress needs at least major.minor; PHP needs major.minor (a bare "8" names
 * no branch); Node's branch is its major. Only a full x.y.z is "exact" — a
 * branch-only declaration lets the host pick the patch level, so it can be
 * checked for end of life but not for missed security releases.
 *
 * @param array  $items  Inventory (by reference).
 * @param string $layer  Layer.
 * @param string $raw    Declared value.
 * @param string $file   Absolute file.
 * @param string $root   Audit root.
 * @param int    $line   1-based line.
 * @param string $source Label.
 * @return void
 */
function audit_add_item( array &$items, string $layer, string $raw, string $file, string $root, int $line, string $source ): void {
	$parsed = audit_parse_version( $raw );
	if ( null === $parsed || ( 'node' !== $layer && $parsed[1] < 2 ) ) {
		return;
	}
	$version = $parsed[0];
	// A WordPress pre-release ("7.1-alpha-62161-src", "7.1-RC1") is not the 7.1
	// release; keep the raw value so it reports as unknown, not as insecure 7.1.
	if ( 'wordpress' === $layer && preg_match( '/^v?\d+(?:\.\d+){1,2}-/', trim( $raw ) ) ) {
		$version = trim( $raw );
	}
	$items[] = array(
		'layer'   => $layer,
		'version' => $version,
		'exact'   => 'wordpress' === $layer || 3 === $parsed[1],
		'file'    => baseline_relpath( $file, $root ),
		'line'    => $line,
		'source'  => $source,
	);
}

/**
 * WordPress core from composer.lock (roots/wordpress and friends).
 *
 * @param array  $items Inventory (by reference).
 * @param string $file  composer.lock.
 * @param string $root  Audit root.
 * @return void
 */
function audit_scan_composer_lock( array &$items, string $file, string $root ): void {
	$content = (string) file_get_contents( $file );
	$lock    = json_decode( $content, true );
	foreach ( array_merge( $lock['packages'] ?? array(), $lock['packages-dev'] ?? array() ) as $pkg ) {
		if ( ! in_array( $pkg['name'] ?? '', PRESSREADY_AUDIT_CORE_PACKAGES, true ) ) {
			continue;
		}
		$pos  = strpos( $content, '"name": "' . $pkg['name'] . '"' );
		$line = false === $pos ? 1 : substr_count( substr( $content, 0, $pos ), "\n" ) + 1;
		audit_add_item( $items, 'wordpress', (string) ( $pkg['version'] ?? '' ), $file, $root, $line, 'composer.lock ' . $pkg['name'] );
	}
}

/**
 * php / node / wordpress images in Dockerfiles and compose files. The official
 * wordpress image carries both: wordpress:6.4.3-php8.1-apache.
 *
 * @param array  $items Inventory (by reference).
 * @param string $file  Dockerfile or compose file.
 * @param string $root  Audit root.
 * @return void
 */
function audit_scan_images( array &$items, string $file, string $root ): void {
	$content = (string) file_get_contents( $file );
	if ( ! preg_match_all( '#(?:^\s*FROM\s+(?:--\S+\s+)*|^\s*image:\s*[\'"]?)(?:[\w.\-]+/)*(php|node|wordpress):([\w.\-]+)#mi', $content, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return;
	}
	foreach ( $all as $m ) {
		$image = strtolower( $m[1][0] );
		$tag   = $m[2][0];
		$line  = substr_count( substr( $content, 0, $m[0][1] ), "\n" ) + 1;
		if ( 'wordpress' === $image ) {
			// Only a full x.y.z tag pins core; "6.4" floats to the latest 6.4.x.
			if ( preg_match( '/^(\d+\.\d+\.\d+)/', $tag, $v ) ) {
				audit_add_item( $items, 'wordpress', $v[1], $file, $root, $line, 'wordpress image' );
			}
			if ( preg_match( '/php(\d+\.\d+(?:\.\d+)?)/', $tag, $v ) ) {
				audit_add_item( $items, 'php', $v[1], $file, $root, $line, 'wordpress image' );
			}
			continue;
		}
		audit_add_item( $items, $image, $tag, $file, $root, $line, $image . ' image' );
	}
}

/**
 * Check one inventory item against the snapshot.
 *
 * @param array  $item  Inventory item.
 * @param array  $db    Snapshot.
 * @param string $today Y-m-d.
 * @return array<int,array> Findings (possibly empty).
 */
function audit_check( array $item, array $db, string $today ): array {
	if ( 'wordpress' === $item['layer'] ) {
		return audit_check_wordpress( $item, $db['wordpress']['versions'] ?? array() );
	}
	return audit_check_runtime( $item, $db[ $item['layer'] ]['branches'] ?? array(), $today );
}

/**
 * WordPress core: the stable-check verdict for the exact version.
 *
 * @param array $item     Inventory item.
 * @param array $versions version → latest|outdated|insecure.
 * @return array
 */
function audit_check_wordpress( array $item, array $versions ): array {
	$v = $item['version'];
	// x.y.0 releases are listed as "x.y".
	$status = $versions[ $v ] ?? ( preg_match( '/^(\d+\.\d+)\.0$/', $v, $m ) ? ( $versions[ $m[1] ] ?? null ) : null );

	$latest = null;
	$branch = null;
	$prefix = implode( '.', array_slice( explode( '.', $v ), 0, 2 ) );
	foreach ( $versions as $known => $s ) {
		$known = (string) $known;
		if ( 'latest' === $s ) {
			$latest = $known;
		}
		if ( ( $known === $prefix || str_starts_with( $known, $prefix . '.' ) ) && ( null === $branch || version_compare( $known, $branch, '>' ) ) ) {
			$branch = $known;
		}
	}
	$url = 'https://wordpress.org/news/category/releases/';

	if ( 'insecure' === $status ) {
		$fix = ( null !== $branch && version_compare( $branch, $v, '>' ) ) ? $branch : $latest;
		return array(
			audit_finding(
				$item,
				'wordpress-insecure',
				'fatal',
				sprintf(
					'WordPress %s is marked insecure by WordPress.org — update to %s%s',
					$v,
					$fix ?? 'the latest release',
					( null !== $latest && null !== $fix && $fix !== $latest ) ? ' (or ' . $latest . ', the current release)' : ''
				),
				$fix,
				$url
			),
		);
	}
	if ( 'outdated' === $status && null !== $latest ) {
		return array(
			audit_finding(
				$item,
				'wordpress-outdated',
				'info',
				sprintf( 'WordPress %s has no known vulnerabilities, but %s is current — WordPress.org only guarantees fixes for the latest release', $v, $latest ),
				$latest,
				$url
			),
		);
	}
	if ( null === $status ) {
		return array( audit_finding( $item, 'wordpress-unknown', 'info', sprintf( 'WordPress %s is not in the advisory data (pre-release, or newer than the snapshot — run `pressready update-db`)', $v ), null, $url ) );
	}
	return array();
}

/**
 * PHP / Node: missed security releases on the branch (exact versions only),
 * then end of life (any declaration).
 *
 * @param array  $item     Inventory item.
 * @param array  $branches Branch map from the snapshot.
 * @param string $today    Y-m-d.
 * @return array
 */
function audit_check_runtime( array $item, array $branches, string $today ): array {
	$layer  = $item['layer'];
	$label  = 'php' === $layer ? 'PHP' : 'Node';
	$v      = $item['version'];
	$branch = audit_branch_of( $layer, $v );
	$b      = $branches[ $branch ] ?? null;
	$url    = 'php' === $layer ? 'https://www.php.net/supported-versions.php' : 'https://nodejs.org/en/about/previous-releases';

	if ( null === $b ) {
		return array( audit_finding( $item, $layer . '-unknown', 'info', sprintf( '%s %s is not in the advisory data — run `pressready update-db`', $label, $v ), null, $url ) );
	}

	$out = array();
	if ( $item['exact'] ) {
		$missed = array_values( array_filter( $b['security_releases'], static fn( $r ) => version_compare( $r['version'], $v, '>' ) ) );
		if ( $missed ) {
			$newest = $missed[0];
			$fix    = $b['latest'] ?? $newest['version'];
			$out[]  = audit_finding(
				$item,
				$layer . '-security-release',
				'fatal',
				sprintf(
					'%s %s is behind %d security release%s (newest: %s on %s) — update to %s',
					$label,
					$v,
					count( $missed ),
					1 === count( $missed ) ? '' : 's',
					$newest['version'],
					$newest['date'] ?? 'unknown date',
					$fix
				),
				$fix,
				'php' === $layer
					? 'https://www.php.net/ChangeLog-' . strtok( $v, '.' ) . '.php#' . $newest['version']
					: 'https://nodejs.org/en/blog/vulnerability'
			);
		}
	}

	if ( audit_is_eol( $b['eol'], $today ) ) {
		$supported = audit_newest_supported( $branches, $today );
		$out[]     = audit_finding(
			$item,
			$layer . '-eol',
			'risky',
			sprintf(
				'%s %s reached end of life%s — it gets no more security fixes; move to a supported branch%s',
				$label,
				$branch,
				is_string( $b['eol'] ) ? ' on ' . $b['eol'] : '',
				null !== $supported ? ' (' . $supported . ')' : ''
			),
			$supported,
			$url
		);
	}
	return $out;
}

/**
 * Newest branch that is not past end of life.
 *
 * @param array  $branches Branch map (sorted newest first by update-db).
 * @param string $today    Y-m-d.
 * @return string|null
 */
function audit_newest_supported( array $branches, string $today ): ?string {
	foreach ( $branches as $branch => $b ) {
		if ( ! audit_is_eol( $b['eol'], $today ) && ! empty( $b['latest'] ) ) {
			return (string) $branch;
		}
	}
	return null;
}

/**
 * Build a finding.
 *
 * @param array       $item     Inventory item.
 * @param string      $id       Rule id, e.g. php-security-release.
 * @param string      $severity fatal | risky | info.
 * @param string      $message  Human message.
 * @param string|null $fixed_in Version that resolves it.
 * @param string      $url      Reference.
 * @return array
 */
function audit_finding( array $item, string $id, string $severity, string $message, ?string $fixed_in, string $url ): array {
	return array(
		'id'       => $id,
		'severity' => $severity,
		'layer'    => $item['layer'],
		'version'  => $item['version'],
		'file'     => $item['file'],
		'line'     => $item['line'],
		'source'   => $item['source'],
		'message'  => $message,
		'fixed_in' => $fixed_in,
		'url'      => $url,
	);
}

/**
 * Sort key for severities (worst first).
 *
 * @param string $sev Severity.
 * @return int
 */
function audit_severity_rank( string $sev ): int {
	$ranks = array( 'fatal' => 0, 'risky' => 1, 'info' => 2 );
	return $ranks[ $sev ] ?? 3;
}

/**
 * One-word status for the inventory table, from an item's findings.
 *
 * @param array $findings The item's findings.
 * @return string
 */
function audit_item_status( array $findings ): string {
	$worst = 'ok';
	foreach ( $findings as $f ) {
		if ( 'ok' === $worst || audit_severity_rank( $f['severity'] ) < audit_severity_rank( $worst ) ) {
			$worst = $f['severity'];
		}
	}
	return $worst;
}

/**
 * Where a finding lives, for human output.
 *
 * @param array $f Finding or inventory item.
 * @return string
 */
function audit_location( array $f ): string {
	return '' === $f['file'] ? 'runtime' : $f['file'] . ':' . $f['line'];
}

/**
 * Human-readable audit report.
 *
 * @param array    $inventory      Inventory (with status).
 * @param array    $findings       Findings, worst first.
 * @param array    $tally          Severity tally.
 * @param array    $db             Snapshot.
 * @param int|null $age_days       Snapshot age.
 * @param bool     $show_inventory Print the "Detected" table.
 * @return void
 */
function audit_render( array $inventory, array $findings, array $tally, array $db, ?int $age_days, bool $show_inventory ): void {
	$labels = array( 'wordpress' => 'WordPress', 'php' => 'PHP', 'node' => 'Node' );

	echo "\n  Pressready — security audit\n";
	printf(
		"  Advisory data: %s%s\n",
		isset( $db['generated_at'] ) ? gmdate( 'Y-m-d H:i', (int) strtotime( $db['generated_at'] ) ) . ' UTC' : 'unknown',
		null !== $age_days ? sprintf( ' (%d day%s old)', $age_days, 1 === $age_days ? '' : 's' ) : ''
	);

	$sections = array(
		'fatal' => ansi( '✗ FATAL (%d) — known-vulnerable versions; update now:', '1;31' ),
		'risky' => ansi( '⚠ RISKY (%d) — end-of-life runtimes; no more security fixes:', '1;33' ),
		'info'  => 'ℹ INFO (%d):',
	);
	foreach ( $sections as $sev => $heading ) {
		$rows = array_values( array_filter( $findings, static fn( $f ) => $sev === $f['severity'] ) );
		if ( ! $rows ) {
			continue;
		}
		printf( "\n  " . $heading . "\n", count( $rows ) );
		foreach ( $rows as $f ) {
			printf( "      %s  %s\n", audit_location( $f ), $f['message'] );
		}
	}

	if ( $show_inventory ) {
		$marks = array(
			'ok'    => ansi( '✓ ok', '0;32' ),
			'fatal' => ansi( '✗ vulnerable', '1;31' ),
			'risky' => ansi( '⚠ end of life', '1;33' ),
			'info'  => 'ℹ see info',
		);
		$order = array_flip( array_keys( $labels ) );
		usort( $inventory, static fn( $a, $b ) => ( $order[ $a['layer'] ] <=> $order[ $b['layer'] ] ) ?: strcmp( $a['file'], $b['file'] ) );
		$width = min( 70, max( array_map( static fn( $i ) => strlen( audit_location( $i ) ), $inventory ) ) );

		echo "\n  Detected\n";
		foreach ( $inventory as $item ) {
			printf(
				"    %-10s %-9s %-{$width}s  %s\n",
				$labels[ $item['layer'] ],
				$item['version'],
				truncate_str( audit_location( $item ), $width ),
				$marks[ $item['status'] ]
			);
		}
	}

	$bits = array();
	if ( $tally['fatal'] > 0 ) {
		$bits[] = ansi( sprintf( '%d fatal', $tally['fatal'] ), '1;31' );
	}
	if ( $tally['risky'] > 0 ) {
		$bits[] = ansi( sprintf( '%d risky', $tally['risky'] ), '1;33' );
	}
	if ( $tally['info'] > 0 ) {
		$bits[] = sprintf( '%d info', $tally['info'] );
	}
	if ( $tally['fatal'] > 0 ) {
		$verdict = ansi( '✗ Known-vulnerable versions — update now.', '1;31' );
	} elseif ( $tally['risky'] > 0 ) {
		$verdict = ansi( '⚠ No known vulnerabilities, but end-of-life runtimes get no more fixes.', '1;33' );
	} else {
		$verdict = ansi( '✓ No known-vulnerable or end-of-life versions.', '0;32' );
	}
	printf(
		"\n  %s%s %d detected version%s.  %s\n\n",
		implode( ', ', $bits ),
		$bits ? ' across' : 'Checked',
		count( $inventory ),
		1 === count( $inventory ) ? '' : 's',
		$verdict
	);
}

/**
 * GitHub Actions workflow commands, one annotation per finding.
 *
 * @param array $findings Findings.
 * @return void
 */
function audit_render_github( array $findings ): void {
	$cmds = array( 'fatal' => 'error', 'risky' => 'warning', 'info' => 'notice' );
	foreach ( $findings as $f ) {
		$where = '' === $f['file'] ? '' : sprintf( 'file=%s,line=%d,', gh_escape_prop( $f['file'] ), $f['line'] );
		printf(
			"::%s %stitle=%s::%s\n",
			$cmds[ $f['severity'] ],
			$where,
			gh_escape_prop( 'Pressready audit (' . $f['severity'] . ')' ),
			gh_escape_data( $f['message'] )
		);
	}
}

/**
 * SARIF 2.1.0 for code scanning. Runtime findings (no file) carry no location.
 *
 * @param array $findings Findings.
 * @return void
 */
function audit_render_sarif( array $findings ): void {
	$levels   = array( 'fatal' => 'error', 'risky' => 'warning', 'info' => 'note' );
	$rules    = array();
	$rule_idx = array();
	$results  = array();
	foreach ( $findings as $f ) {
		if ( ! isset( $rule_idx[ $f['id'] ] ) ) {
			$rule_idx[ $f['id'] ] = count( $rules );
			$rules[]              = array(
				'id'      => $f['id'],
				'name'    => $f['id'],
				'helpUri' => $f['url'],
			);
		}
		$result = array(
			'ruleId'    => $f['id'],
			'ruleIndex' => $rule_idx[ $f['id'] ],
			'level'     => $levels[ $f['severity'] ],
			'message'   => array( 'text' => $f['message'] ),
		);
		if ( '' !== $f['file'] ) {
			$result['locations'] = array(
				array(
					'physicalLocation' => array(
						'artifactLocation' => array( 'uri' => $f['file'] ),
						'region'           => array( 'startLine' => max( 1, (int) $f['line'] ) ),
					),
				),
			);
		}
		$results[] = $result;
	}
	$sarif = array(
		'$schema' => 'https://json.schemastore.org/sarif-2.1.0.json',
		'version' => '2.1.0',
		'runs'    => array(
			array(
				'tool'    => array(
					'driver' => array(
						'name'           => 'Pressready audit',
						'version'        => pressready_version(),
						'informationUri' => 'https://github.com/itzmekhokan/pressready',
						'rules'          => $rules,
					),
				),
				'results' => $results,
			),
		),
	);
	echo json_encode( $sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * GitLab Code Quality report.
 *
 * @param array $findings Findings.
 * @return void
 */
function audit_render_gitlab( array $findings ): void {
	$sev_map = array( 'fatal' => 'blocker', 'risky' => 'major', 'info' => 'info' );
	$issues  = array();
	foreach ( $findings as $f ) {
		$issues[] = array(
			'description' => $f['message'],
			'check_name'  => $f['id'],
			'fingerprint' => md5( $f['id'] . '|' . $f['file'] . '|' . $f['line'] . '|' . $f['version'] ),
			'severity'    => $sev_map[ $f['severity'] ],
			'location'    => array(
				'path'  => '' === $f['file'] ? '.' : $f['file'],
				'lines' => array( 'begin' => max( 1, (int) $f['line'] ) ),
			),
		);
	}
	echo json_encode( $issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
}
