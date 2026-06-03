<?php
/**
 * WordPress deprecations dataset generator.
 *
 * Walks the WordPress core source tree and extracts every deprecation declared
 * via the core deprecation API (`_deprecated_function()`, `apply_filters_deprecated()`,
 * etc.) into a single versioned JSON dataset:
 *
 *     { identifier => { deprecated: "<wp version>", replacement: "...", ... } }
 *
 * The dataset is the data layer for Pressready's WordPress sniff:
 * given a target WP version, any usage of an identifier whose `deprecated`
 * version is <= the target is a finding.
 *
 * This is a build-time dev tool. It is the authoritative WP-side moat: core
 * annotates every deprecation inline with its `@since`/version, so re-running
 * this against a newer core checkout regenerates the dataset for free.
 *
 * Usage:
 *   php bin/gen-wp-deprecations.php \
 *       --src=/path/to/wordpress-develop/src \
 *       --out=data/wp-deprecations.json
 *
 * @package Pressready
 */

error_reporting( E_ALL & ~E_DEPRECATED );

/**
 * The core deprecation API functions we recognise, mapped to how their
 * arguments are laid out. `id` / `version` / `replacement` are 0-based argument
 * indexes; `kind` is the dataset bucket; `id_is_hook` marks hook-name args.
 */
const DEPRECATION_CALLS = array(
	'_deprecated_function'    => array( 'id' => 0, 'version' => 1, 'replacement' => 2, 'kind' => 'function' ),
	'_deprecated_constructor' => array( 'id' => 0, 'version' => 1, 'replacement' => null, 'kind' => 'constructor' ),
	'_deprecated_class'       => array( 'id' => 0, 'version' => 1, 'replacement' => 2, 'kind' => 'class' ),
	'_deprecated_file'        => array( 'id' => 0, 'version' => 1, 'replacement' => 2, 'kind' => 'file' ),
	'_deprecated_argument'    => array( 'id' => 0, 'version' => 1, 'replacement' => null, 'kind' => 'argument' ),
	'_deprecated_hook'        => array( 'id' => 0, 'version' => 1, 'replacement' => 2, 'kind' => 'hook', 'id_is_hook' => true ),
	'apply_filters_deprecated' => array( 'id' => 0, 'version' => 2, 'replacement' => 3, 'kind' => 'hook', 'id_is_hook' => true, 'hook_type' => 'filter' ),
	'do_action_deprecated'    => array( 'id' => 0, 'version' => 2, 'replacement' => 3, 'kind' => 'hook', 'id_is_hook' => true, 'hook_type' => 'action' ),
);

main( $argv );

/**
 * Entry point.
 *
 * @param string[] $argv CLI arguments.
 */
function main( array $argv ): void {
	$opts = parse_opts( $argv );
	$src  = $opts['src'] ?? null;
	$out  = $opts['out'] ?? 'data/wp-deprecations.json';

	if ( ! $src || ! is_dir( $src ) ) {
		fwrite( STDERR, "error: --src=<path to wordpress-develop/src> is required and must exist\n" );
		exit( 1 );
	}

	$wp_version = detect_wp_version( $src );
	$buckets    = array(
		'function' => array(),
		'method'   => array(),
		'class'    => array(),
		'file'     => array(),
		'hook'     => array(),
		'argument' => array(),
	);
	$stats = array( 'files' => 0, 'calls' => 0, 'unresolved' => 0, 'docblock' => 0 );

	foreach ( php_files( $src ) as $file ) {
		++$stats['files'];
		scan_file( $file, $src, $buckets, $stats );
	}

	// Stable output: sort each bucket by identifier.
	foreach ( $buckets as &$bucket ) {
		ksort( $bucket );
	}
	unset( $bucket );

	$dataset = array(
		'schema'       => 1,
		'generated_at' => gmdate( 'c' ),
		'source'       => array( 'wp_version' => $wp_version ),
		'counts'       => array_map( 'count', $buckets ),
		'deprecations' => $buckets,
	);

	$dir = dirname( $out );
	if ( $dir && ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}
	file_put_contents( $out, json_encode( $dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

	fwrite(
		STDERR,
		sprintf(
			"Wrote %s\n  wp_version: %s\n  scanned %d files, %d deprecation calls (%d unresolved), %d docblock tags\n  %s\n",
			$out,
			$wp_version,
			$stats['files'],
			$stats['calls'],
			$stats['unresolved'],
			$stats['docblock'],
			implode( ', ', array_map(
				static fn( $k, $v ) => "$k: $v",
				array_keys( $dataset['counts'] ),
				array_values( $dataset['counts'] )
			) )
		)
	);
}

/**
 * Emit an unresolved-call diagnostic when DEBUG is set in the environment.
 *
 * @param string $msg Diagnostic line.
 */
function debug_unresolved( string $msg ): void {
	if ( getenv( 'DEBUG' ) ) {
		fwrite( STDERR, "  [unresolved] $msg\n" );
	}
}

/**
 * Parse `--key=value` style options.
 *
 * @param string[] $argv CLI arguments.
 * @return array<string,string>
 */
function parse_opts( array $argv ): array {
	$opts = array();
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( preg_match( '/^--([a-z0-9_-]+)=(.*)$/i', $arg, $m ) ) {
			$opts[ $m[1] ] = $m[2];
		}
	}
	return $opts;
}

/**
 * Read $wp_version out of wp-includes/version.php.
 *
 * @param string $src Path to core `src`.
 * @return string
 */
function detect_wp_version( string $src ): string {
	$file = $src . '/wp-includes/version.php';
	if ( is_file( $file ) && preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents( $file ), $m ) ) {
		return $m[1];
	}
	return 'unknown';
}

/**
 * Yield every relevant `.php` file under the source tree.
 *
 * @param string $src Root directory.
 * @return Generator<string>
 */
function php_files( string $src ): Generator {
	$it = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			static function ( $current ): bool {
				$name = $current->getFilename();
				if ( $current->isDir() ) {
					// Restrict to genuine core dirs; wp-content (default themes, bundled/
					// backed-up plugins) is not core and uses non-WP version strings.
					return ! in_array( $name, array( 'wp-content', 'js', 'css', 'images', 'node_modules', 'vendor' ), true );
				}
				return str_ends_with( $name, '.php' ) && ! str_ends_with( $name, '.min.php' );
			}
		)
	);
	foreach ( $it as $file ) {
		yield $file->getPathname();
	}
}

/**
 * Tokenize one file, track class/function scope, and extract deprecation calls.
 *
 * @param string                       $file    Absolute file path.
 * @param string                       $src     Source root (for relative basenames).
 * @param array<string,array<string,mixed>> $buckets Output buckets, by reference.
 * @param array<string,int>            $stats   Running stats, by reference.
 */
function scan_file( string $file, string $src, array &$buckets, array &$stats ): void {
	if ( ! is_file( $file ) ) {
		return;
	}
	$code   = file_get_contents( $file );
	$tokens = @token_get_all( $code );
	if ( ! $tokens ) {
		return;
	}

	$count = count( $tokens );
	// Normalise to [id, text, line] triples; simple chars become [null, "{", line].
	$norm = array();
	$line = 1;
	foreach ( $tokens as $t ) {
		if ( is_array( $t ) ) {
			$norm[] = array( $t[0], $t[1], $t[2] );
			$line   = $t[2] + substr_count( $t[1], "\n" );
		} else {
			$norm[] = array( null, $t, $line );
		}
	}

	$depth        = 0;     // current `{` nesting depth.
	$class_stack  = array(); // [ [name, depth], ... ]
	$func_stack   = array(); // [ [name, depth], ... ]
	$pending_cls  = null;  // class name awaiting its opening brace.
	$pending_fn   = null;  // function name awaiting its `{` or `;`.
	$pending_doc  = null;  // [version, replacement] from a `@deprecated` docblock awaiting its declaration.

	for ( $i = 0; $i < $count; $i++ ) {
		list( $id, $text ) = $norm[ $i ];

		// A `@deprecated <version>` docblock attaches to the next class/function/
		// method declaration. Core deprecates many symbols this way (no runtime
		// `_deprecated_*()` call), so this is the only signal for them.
		if ( T_DOC_COMMENT === $id ) {
			$pending_doc = parse_deprecated_doc( $text );
			continue;
		}

		// --- Scope tracking ----------------------------------------------
		if ( T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id ) {
			// `Foo::class` is not a declaration; the real one is followed by a name.
			$name = next_string( $norm, $i, $count );
			if ( null !== $name && ! preg_match_prev_is_double_colon( $norm, $i ) ) {
				$pending_cls = $name;
				if ( null !== $pending_doc ) {
					record_docblock( 'class', $name, $pending_doc, $buckets, $stats );
				}
			}
			$pending_doc = null;
			continue;
		}
		if ( T_FUNCTION === $id ) {
			$name = next_string( $norm, $i, $count );
			$pending_fn = $name; // null => anonymous function; still tracked for depth balance.
			if ( null !== $pending_doc && null !== $name ) {
				$cur_class = $class_stack ? end( $class_stack )[0] : null;
				if ( $cur_class ) {
					record_docblock( 'method', "$cur_class::$name", $pending_doc, $buckets, $stats );
				} else {
					record_docblock( 'function', $name, $pending_doc, $buckets, $stats );
				}
			}
			$pending_doc = null;
			continue;
		}
		if ( null === $id && '{' === $text ) {
			++$depth;
			if ( null !== $pending_cls ) {
				$class_stack[] = array( $pending_cls, $depth );
				$pending_cls   = null;
			} elseif ( $pending_fn !== false ) {
				// A function body (named or anonymous) opened.
				$func_stack[] = array( $pending_fn, $depth );
				$pending_fn   = false;
			}
			continue;
		}
		if ( null === $id && '}' === $text ) {
			if ( $class_stack && end( $class_stack )[1] === $depth ) {
				array_pop( $class_stack );
			}
			if ( $func_stack && end( $func_stack )[1] === $depth ) {
				array_pop( $func_stack );
			}
			--$depth;
			continue;
		}
		if ( null === $id && ';' === $text ) {
			// A `;` ends a property/const/use statement — a `@deprecated` docblock
			// on one of those is not a class/function we track, so drop it here
			// rather than let it bleed onto the next declaration.
			$pending_doc = null;
			if ( $pending_fn !== false ) {
				$pending_fn = false; // Abstract / interface method: declared, no body.
			}
			continue;
		}
		// Reset a dangling pending function once we pass its signature without a brace.
		if ( null !== $pending_fn && $pending_fn !== false && null === $id && '{' !== $text && '}' !== $text ) {
			// keep pending until brace/semicolon; do nothing.
			$pending_fn = $pending_fn;
		}

		// --- Deprecation call detection ----------------------------------
		if ( T_STRING === $id && isset( DEPRECATION_CALLS[ strtolower( $text ) ] ) ) {
			if ( prev_is_member_access( $norm, $i ) || prev_is_function_keyword( $norm, $i ) ) {
				continue; // $obj->_deprecated_function(...) or its own `function` declaration.
			}
			$open = next_significant( $norm, $i, $count );
			if ( null === $open || '(' !== $norm[ $open ][1] ) {
				continue; // a reference, not a call.
			}
			$args = collect_args( $norm, $open, $count );
			++$stats['calls'];
			record_call(
				strtolower( $text ),
				$args,
				$class_stack ? end( $class_stack )[0] : null,
				$func_stack ? end( $func_stack )[0] : null,
				$file,
				$src,
				$buckets,
				$stats
			);
		}
	}
}

/**
 * Turn a recognised deprecation call into a dataset entry.
 *
 * @param string                  $fn         Lowercased API function name.
 * @param array<int,array>        $args       Argument token slices.
 * @param string|null             $cur_class  Enclosing class name.
 * @param string|null             $cur_func   Enclosing function/method short name.
 * @param string                  $file       Absolute file path.
 * @param string                  $src        Source root.
 * @param array<string,array>     $buckets    Output buckets, by reference.
 * @param array<string,int>       $stats      Stats, by reference.
 */
function record_call( string $fn, array $args, ?string $cur_class, ?string $cur_func, string $file, string $src, array &$buckets, array &$stats ): void {
	$spec    = DEPRECATION_CALLS[ $fn ];
	$id_arg  = $args[ $spec['id'] ] ?? null;
	$version = isset( $args[ $spec['version'] ] ) ? string_literal( $args[ $spec['version'] ] ) : null;
	$replace = ( null !== ( $spec['replacement'] ?? null ) && isset( $args[ $spec['replacement'] ] ) )
		? string_literal( $args[ $spec['replacement'] ] )
		: null;

	$version = normalize_version( $version );
	if ( null === $version ) {
		++$stats['unresolved'];
		debug_unresolved( "no-version  $fn  in " . basename( $file ) );
		return; // Without a version we cannot gate it against a target.
	}

	$kind = $spec['kind'];
	$hook = ! empty( $spec['id_is_hook'] );

	// Resolve the identifier being deprecated.
	if ( $hook ) {
		// A hook name must be a static literal; dynamic/interpolated names
		// (e.g. "auth_{$type}_meta_{$key}") cannot be matched statically.
		$identifier = $id_arg ? clean_string_literal( $id_arg ) : null;
		$bucket     = 'hook';
	} elseif ( 'file' === $kind ) {
		$identifier = resolve_file_id( $id_arg, $file );
		$bucket     = 'file';
	} elseif ( 'constructor' === $kind ) {
		// `_deprecated_constructor( 'WP_Widget', '4.3.0' )` deprecates the PHP4-style
		// constructor method WP_Widget::WP_Widget(), NOT the class. Recording it as a
		// class would false-flag every subclass (e.g. anything extending WP_Widget);
		// the PHP4-constructor concern itself belongs to the PHP-version side.
		$cls = clean_string_literal( $id_arg );
		if ( null === $cls ) {
			// __CLASS__ or get_class( $this ) — it is called inside the class itself.
			$cls = $cur_class;
		}
		$identifier = $cls ? "$cls::$cls" : null;
		$bucket     = 'method';
	} else {
		list( $identifier, $bucket ) = resolve_symbol_id( $id_arg, $cur_class, $cur_func, $kind );
	}

	if ( null === $identifier || '' === $identifier ) {
		++$stats['unresolved'];
		debug_unresolved( "no-id       $fn  ($kind)  in " . basename( $file ) );
		return;
	}

	$entry = array( 'deprecated' => $version );
	if ( null !== $replace && '' !== $replace ) {
		$entry['replacement'] = $replace;
	}
	if ( 'hook' === $bucket && isset( $spec['hook_type'] ) ) {
		$entry['type'] = $spec['hook_type'];
	}
	if ( 'argument' === $bucket ) {
		$entry['note'] = 'deprecated argument';
	}

	// First writer wins (earliest declaration); keep the lower version if duplicated.
	if ( ! isset( $buckets[ $bucket ][ $identifier ] )
		|| version_compare( $version, $buckets[ $bucket ][ $identifier ]['deprecated'], '<' ) ) {
		$buckets[ $bucket ][ $identifier ] = $entry;
	}
}

/**
 * Parse a docblock for a `@deprecated <version> [text]` tag.
 *
 * @param string $doc Raw T_DOC_COMMENT text.
 * @return array{version:string,replacement:?string}|null Null if no usable tag.
 */
function parse_deprecated_doc( string $doc ): ?array {
	if ( ! preg_match( '/@deprecated\s+([0-9][0-9.]*)\b[ \t]*(.*)$/m', $doc, $m ) ) {
		return null;
	}
	$version = normalize_version( $m[1] );
	if ( null === $version ) {
		return null;
	}
	$rest = trim( $m[2] );

	// Replacement only from a clean signal — a `{@see Symbol}` tag or the first
	// `backticked` symbol. Free-text rationale ("Use the X class instead") is too
	// noisy to parse into a symbol, so it's dropped rather than guessed wrong.
	$replacement = null;
	if ( preg_match( '/\{@see\s+([^}]+)\}/', $rest, $r ) ) {
		$replacement = trim( $r[1] );
	} elseif ( preg_match( '/`([^`]+)`/', $rest, $r ) ) {
		$replacement = trim( $r[1] );
	}
	return array( 'version' => $version, 'replacement' => $replacement );
}

/**
 * Record a deprecation discovered via a `@deprecated` docblock (no runtime call).
 *
 * Uses the same first-writer / lower-version merge as record_call(), so it
 * harmlessly overlaps entries that also have a `_deprecated_*()` call.
 *
 * @param string                  $bucket     'class' | 'method' | 'function'.
 * @param string                  $identifier Symbol id (e.g. "WP_Foo" or "WP_Foo::bar").
 * @param array{version:string,replacement:?string} $dep Parsed docblock data.
 * @param array<string,array>     $buckets    Output buckets, by reference.
 * @param array<string,int>       $stats      Stats, by reference.
 */
function record_docblock( string $bucket, string $identifier, array $dep, array &$buckets, array &$stats ): void {
	$entry = array( 'deprecated' => $dep['version'] );
	if ( ! empty( $dep['replacement'] ) ) {
		$entry['replacement'] = $dep['replacement'];
	}
	if ( ! isset( $buckets[ $bucket ][ $identifier ] )
		|| version_compare( $dep['version'], $buckets[ $bucket ][ $identifier ]['deprecated'], '<' ) ) {
		$buckets[ $bucket ][ $identifier ] = $entry;
	}
	++$stats['docblock'];
}

/**
 * Resolve a function/method/class/argument identifier from its arg0 tokens and
 * the enclosing scope (since arg0 is usually __FUNCTION__/__METHOD__/__CLASS__).
 *
 * @param array|null  $id_arg    arg0 token slice.
 * @param string|null $cur_class Enclosing class.
 * @param string|null $cur_func  Enclosing function/method short name.
 * @param string      $kind      Declared kind ('function'|'class'|'argument').
 * @return array{0:?string,1:string} [identifier, bucket]
 */
function resolve_symbol_id( ?array $id_arg, ?string $cur_class, ?string $cur_func, string $kind ): array {
	$magic = magic_constant( $id_arg );

	// Class-kind calls (constructor / class) name a class.
	if ( 'class' === $kind ) {
		if ( '__CLASS__' === $magic ) {
			return array( $cur_class, 'class' );
		}
		$lit = string_literal( $id_arg );
		return array( $lit ?: $cur_class, 'class' );
	}

	// Function / argument kinds.
	if ( '__METHOD__' === $magic ) {
		if ( $cur_class && $cur_func ) {
			return array( "$cur_class::$cur_func", $kind === 'argument' ? 'argument' : 'method' );
		}
		return array( $cur_func, $kind === 'argument' ? 'argument' : 'function' );
	}
	if ( '__FUNCTION__' === $magic ) {
		// __FUNCTION__ inside a class still names a method.
		if ( $cur_class ) {
			return array( "$cur_class::$cur_func", $kind === 'argument' ? 'argument' : 'method' );
		}
		return array( $cur_func, $kind === 'argument' ? 'argument' : 'function' );
	}
	if ( '__CLASS__' === $magic ) {
		return array( $cur_class, 'class' );
	}

	// Literal string, e.g. 'wp_setcookie' or 'WP_Class::method'. Must be a clean
	// single literal — a concatenation like `__CLASS__ . '::' . $name` is dynamic.
	$lit = clean_string_literal( $id_arg );
	if ( null !== $lit && '' !== $lit ) {
		// Discard signature-style literals like "clean_url( $context = 'db' )".
		$name = trim( preg_replace( '/\(.*$/s', '', $lit ) );
		if ( '' === $name ) {
			return array( null, $kind );
		}
		$is_method = str_contains( $name, '::' );
		$bucket    = $kind === 'argument' ? 'argument' : ( $is_method ? 'method' : 'function' );
		return array( $name, $bucket );
	}

	// Dynamic (e.g. __CLASS__ . '::' . $name) — cannot resolve statically.
	return array( null, $kind );
}

/**
 * Resolve a _deprecated_file() identifier to a relative-ish basename.
 *
 * @param array|null $id_arg arg0 tokens.
 * @param string     $file   Current file path.
 * @return string|null
 */
function resolve_file_id( ?array $id_arg, string $file ): ?string {
	// Usually basename( __FILE__ ) or a sprintf() wrapper — both dynamic. Only a
	// clean literal basename is trustworthy; otherwise use the real file basename.
	$lit = clean_string_literal( $id_arg );
	if ( null !== $lit && '' !== $lit && ! str_contains( $lit, '(' ) ) {
		return $lit;
	}
	return basename( $file );
}

/* -------------------------------------------------------------------------
 * Token helpers
 * ---------------------------------------------------------------------- */

/**
 * Collect top-level argument token slices for a call. `$open` points at the `(`.
 *
 * @param array $norm  Normalised tokens.
 * @param int   $open  Index of the opening paren.
 * @param int   $count Token count.
 * @return array<int,array<int,array>> One token-slice per argument.
 */
function collect_args( array $norm, int $open, int $count ): array {
	$args  = array();
	$cur   = array();
	$par   = 0; // () depth
	$sq    = 0; // [] depth
	for ( $i = $open; $i < $count; $i++ ) {
		list( $id, $text ) = $norm[ $i ];
		if ( null === $id ) {
			if ( '(' === $text ) {
				++$par;
				if ( 1 === $par ) {
					continue; // skip the outer opening paren itself.
				}
			} elseif ( ')' === $text ) {
				--$par;
				if ( 0 === $par ) {
					if ( $cur ) {
						$args[] = $cur;
					}
					return $args;
				}
			} elseif ( '[' === $text ) {
				++$sq;
			} elseif ( ']' === $text ) {
				--$sq;
			} elseif ( ',' === $text && 1 === $par && 0 === $sq ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
		}
		if ( $par >= 1 ) {
			// Ignore pure whitespace/comments at the slice edges later.
			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}
			$cur[] = $norm[ $i ];
		}
	}
	if ( $cur ) {
		$args[] = $cur;
	}
	return $args;
}

/**
 * Extract a plain string from a single-token string-literal argument slice.
 *
 * @param array|null $slice Argument token slice.
 * @return string|null
 */
function string_literal( ?array $slice ): ?string {
	if ( ! $slice ) {
		return null;
	}
	// A clean literal is a single encapsed-string token.
	$strings = array_values( array_filter(
		$slice,
		static fn( $t ) => T_CONSTANT_ENCAPSED_STRING === $t[0]
	) );
	if ( count( $slice ) === 1 && T_CONSTANT_ENCAPSED_STRING === $slice[0][0] ) {
		return unquote( $slice[0][1] );
	}
	// Concatenations / function wrappers: take the first literal as a best effort.
	if ( $strings ) {
		return unquote( $strings[0][1] );
	}
	return null;
}

/**
 * Return the string only when the slice is exactly one clean string literal.
 * Used for identifiers (function/hook/file names), where a concatenation or
 * function-wrapped expression must be treated as dynamic, not best-effort.
 *
 * @param array|null $slice Argument token slice.
 * @return string|null
 */
function clean_string_literal( ?array $slice ): ?string {
	if ( $slice && count( $slice ) === 1 && T_CONSTANT_ENCAPSED_STRING === $slice[0][0] ) {
		return unquote( $slice[0][1] );
	}
	return null;
}

/**
 * Identify a magic constant (__FUNCTION__ etc.) when the slice is exactly that.
 *
 * @param array|null $slice Argument token slice.
 * @return string|null
 */
function magic_constant( ?array $slice ): ?string {
	if ( ! $slice || count( $slice ) !== 1 ) {
		return null;
	}
	$t = $slice[0];
	if ( in_array( $t[0], array( T_FUNC_C, T_METHOD_C, T_CLASS_C ), true ) ) {
		return $t[1];
	}
	return null;
}

/**
 * Strip surrounding quotes from a PHP string literal token text.
 *
 * @param string $raw Token text including quotes.
 * @return string
 */
function unquote( string $raw ): string {
	$raw = trim( $raw );
	if ( strlen( $raw ) >= 2 && ( $raw[0] === "'" || $raw[0] === '"' ) ) {
		$inner = substr( $raw, 1, -1 );
		return $raw[0] === "'"
			? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner )
			: stripcslashes( $inner );
	}
	return $raw;
}

/**
 * Normalise a version string to digits-and-dots (e.g. "0.71", "6.9.0").
 *
 * @param string|null $v Raw version.
 * @return string|null
 */
function normalize_version( ?string $v ): ?string {
	if ( null === $v ) {
		return null;
	}
	if ( preg_match( '/^\d+(?:\.\d+)*/', trim( $v ), $m ) ) {
		return $m[0];
	}
	return null;
}

/**
 * Find the next T_STRING text after index $i (skipping whitespace), or null.
 *
 * @param array $norm  Tokens.
 * @param int   $i     Current index.
 * @param int   $count Token count.
 * @return string|null
 */
function next_string( array $norm, int $i, int $count ): ?string {
	for ( $j = $i + 1; $j < $count; $j++ ) {
		$id   = $norm[ $j ][0];
		$text = $norm[ $j ][1];
		if ( T_WHITESPACE === $id ) {
			continue;
		}
		if ( null === $id && '&' === $text ) {
			continue; // by-reference return: `function &foo()`.
		}
		// T_STRING, or a context-sensitive keyword used as a name (e.g. PHP 8.1
		// tokenizes the deprecated `readonly()` function as T_READONLY, `list()`
		// as T_LIST, etc.). Accept any bare-identifier token text.
		if ( preg_match( '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', (string) $text ) ) {
			return $text;
		}
		return null; // anonymous function `(`, etc.
	}
	return null;
}

/**
 * Index of the next significant (non-whitespace/comment) token after $i.
 *
 * @param array $norm  Tokens.
 * @param int   $i     Current index.
 * @param int   $count Token count.
 * @return int|null
 */
function next_significant( array $norm, int $i, int $count ): ?int {
	for ( $j = $i + 1; $j < $count; $j++ ) {
		$id = $norm[ $j ][0];
		if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
			continue;
		}
		return $j;
	}
	return null;
}

/**
 * Whether the token before $i is `->` or `::` (a member access).
 *
 * @param array $norm Tokens.
 * @param int   $i    Current index.
 * @return bool
 */
function prev_is_member_access( array $norm, int $i ): bool {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$id = $norm[ $j ][0];
		if ( T_WHITESPACE === $id ) {
			continue;
		}
		return in_array( $id, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true )
			|| ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $id );
	}
	return false;
}

/**
 * Whether the token before $i is the `function` keyword (a declaration, not a call).
 *
 * @param array $norm Tokens.
 * @param int   $i    Current index.
 * @return bool
 */
function prev_is_function_keyword( array $norm, int $i ): bool {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$id = $norm[ $j ][0];
		if ( T_WHITESPACE === $id ) {
			continue;
		}
		return T_FUNCTION === $id;
	}
	return false;
}

/**
 * Whether the token before the class keyword at $i is `::` (the `Foo::class` form).
 *
 * @param array $norm Tokens.
 * @param int   $i    Index of T_CLASS.
 * @return bool
 */
function preg_match_prev_is_double_colon( array $norm, int $i ): bool {
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$id = $norm[ $j ][0];
		if ( T_WHITESPACE === $id ) {
			continue;
		}
		return T_DOUBLE_COLON === $id;
	}
	return false;
}
