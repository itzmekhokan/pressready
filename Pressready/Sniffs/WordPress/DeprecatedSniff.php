<?php
/**
 * Pressready — WordPress upgrade-readiness scanner.
 *
 * @copyright 2026 Khokan Sardar
 * @license   GPL-2.0-or-later
 * @link      https://github.com/itzmekhokan/pressready
 *
 * Flags usage of WordPress core APIs deprecated as of a target WP version.
 *
 * Driven by the generated dataset (data/wp-deprecations.json). Detects calls to
 * deprecated functions, static method calls, class usage (new/extends/
 * implements/instanceof/static access), hook registrations against deprecated
 * hooks, and includes of deprecated core files.
 *
 * Target version: PRESSREADY_WP env var ("flag everything deprecated <= this").
 * Optional PRESSREADY_WP_SINCE narrows it to a delta ("> since AND <= target"),
 * i.e. only what becomes deprecated when upgrading from SINCE to WP.
 *
 * @package Pressready
 */

namespace Pressready\Sniffs\WordPress;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * The deprecated-core-API sniff.
 */
class DeprecatedSniff implements Sniff {

	/**
	 * Hook-registering core functions whose first string argument is a hook name.
	 *
	 * @var array<string,true>
	 */
	private const HOOK_FUNCTIONS = array(
		'add_filter'             => true,
		'add_action'             => true,
		'remove_filter'          => true,
		'remove_action'          => true,
		'do_action'              => true,
		'do_action_ref_array'    => true,
		'apply_filters'          => true,
		'apply_filters_ref_array' => true,
		'has_filter'             => true,
		'has_action'             => true,
		'doing_filter'           => true,
		'doing_action'           => true,
		'did_action'             => true,
	);

	/**
	 * Indexed dataset, lazily loaded and cached per dataset path.
	 *
	 * @var array<string,array<string,array>>|null
	 */
	private $index = null;

	/**
	 * Per-file cache of symbols the current file declares itself (lowercased),
	 * split into 'function' and 'class' sets. Used to suppress false positives
	 * where a component shadows a deprecated core name with its own definition.
	 *
	 * @var array{function:array<string,true>,class:array<string,true>}|null
	 */
	private $declared = null;

	/**
	 * Filename the $declared cache was built for.
	 *
	 * @var string|null
	 */
	private $declaredFile = null;

	/**
	 * Path to the deprecations dataset JSON. Defaults relative to this file.
	 *
	 * @var string
	 */
	public $datasetPath = '';

	/**
	 * Token types this sniff listens for.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return array(
			T_STRING,
			T_NEW,
			T_DOUBLE_COLON,
			T_EXTENDS,
			T_IMPLEMENTS,
			T_INSTANCEOF,
			T_INCLUDE,
			T_INCLUDE_ONCE,
			T_REQUIRE,
			T_REQUIRE_ONCE,
		);
	}

	/**
	 * Process a matching token.
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  Position of the token in the stack.
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$index  = $this->index();
		$tokens = $phpcsFile->getTokens();
		$code   = $tokens[ $stackPtr ]['code'];

		if ( T_STRING === $code ) {
			$this->process_string( $phpcsFile, $stackPtr );
			return;
		}
		if ( T_NEW === $code || T_EXTENDS === $code || T_IMPLEMENTS === $code || T_INSTANCEOF === $code ) {
			$this->process_class_ref( $phpcsFile, $stackPtr );
			return;
		}
		if ( T_DOUBLE_COLON === $code ) {
			$this->process_static( $phpcsFile, $stackPtr );
			return;
		}
		// Include / require of a deprecated core file.
		$this->process_include( $phpcsFile, $stackPtr );
	}

	/**
	 * Handle a T_STRING: function call, hook-API call, or method-name token.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token index.
	 * @return void
	 */
	private function process_string( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$name   = $tokens[ $stackPtr ]['content'];
		$lower  = strtolower( $name );

		$prev = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );
		$next = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );

		// Method names (after ->/::) are handled elsewhere or are instance calls we skip.
		if ( false !== $prev ) {
			$pc = $tokens[ $prev ]['code'];
			if ( in_array( $pc, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_OBJECT_OPERATOR ), true ) ) {
				return;
			}
		}

		$is_call = ( false !== $next && T_OPEN_PARENTHESIS === $tokens[ $next ]['code'] );

		// Hook-registering call: inspect the first string argument.
		if ( $is_call && isset( self::HOOK_FUNCTIONS[ $lower ] ) && ! $this->is_namespaced_call( $phpcsFile, $stackPtr, $prev ) ) {
			$this->check_hook_arg( $phpcsFile, $stackPtr, $next );
			return;
		}

		// Deprecated function call — unless this file declares its own function of
		// the same name (a component shadowing the deprecated core symbol).
		if ( $is_call && isset( $this->index()['function'][ $lower ] )
			&& ! isset( $this->declared_symbols( $phpcsFile )['function'][ $lower ] )
			&& ! $this->is_namespaced_call( $phpcsFile, $stackPtr, $prev ) ) {
			$entry  = $this->index()['function'][ $lower ];
			$status = $this->finding_status( $entry );
			if ( null !== $status ) {
				$this->report( $phpcsFile, $stackPtr, 'DeprecatedFunction', 'Function', $name . '()', $entry, $status );
			}
		}
	}

	/**
	 * Handle new/extends/implements/instanceof: the class name follows.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token index of the keyword.
	 * @return void
	 */
	private function process_class_ref( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$name   = $this->read_name_after( $phpcsFile, $stackPtr );
		if ( null === $name ) {
			return;
		}
		$this->maybe_report_class( $phpcsFile, $stackPtr, $name );
	}

	/**
	 * Handle a `::` token: static method call, or static access to a class.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token index of `::`.
	 * @return void
	 */
	private function process_static( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		// Class name precedes `::`.
		$class = $this->read_name_before( $phpcsFile, $stackPtr );
		if ( null === $class ) {
			return;
		}

		// A component defining its own class of this (unqualified) name shadows the
		// deprecated core class — neither its static methods nor the class itself
		// are the core API. Skip both report paths below.
		$short = $class;
		$pos   = strrpos( $short, '\\' );
		if ( false !== $pos ) {
			$short = substr( $short, $pos + 1 );
		}
		if ( isset( $this->declared_symbols( $phpcsFile )['class'][ strtolower( $short ) ] ) ) {
			return;
		}

		// Member after `::`.
		$member = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );
		$after  = ( false !== $member ) ? $phpcsFile->findNext( Tokens::$emptyTokens, $member + 1, null, true ) : false;
		$is_method_call = ( false !== $after && T_OPEN_PARENTHESIS === $tokens[ $after ]['code'] );

		if ( $is_method_call && false !== $member && T_STRING === $tokens[ $member ]['code'] ) {
			$key   = strtolower( $class . '::' . $tokens[ $member ]['content'] );
			$entry = $this->index()['method'][ $key ] ?? null;
			$status = ( null !== $entry ) ? $this->finding_status( $entry ) : null;
			if ( null !== $status ) {
				$this->report( $phpcsFile, $member, 'DeprecatedMethod', 'Method', $class . '::' . $tokens[ $member ]['content'] . '()', $entry, $status );
				return;
			}
		}

		// Static access (Class::CONST or Class::method) to a deprecated class.
		$this->maybe_report_class( $phpcsFile, $stackPtr, $class );
	}

	/**
	 * Handle include/require of a deprecated core file by basename.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token index of the include keyword.
	 * @return void
	 */
	private function process_include( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$str    = $phpcsFile->findNext( array( T_CONSTANT_ENCAPSED_STRING ), $stackPtr + 1, $stackPtr + 6 );
		if ( false === $str ) {
			return;
		}
		$path     = trim( $tokens[ $str ]['content'], "'\"" );
		$basename = basename( $path );
		$entry    = $this->index()['file'][ $basename ] ?? null;
		$status = ( null !== $entry ) ? $this->finding_status( $entry ) : null;
		if ( null !== $status ) {
			$this->report( $phpcsFile, $str, 'DeprecatedFile', 'File', $basename, $entry, $status );
		}
	}

	/**
	 * Report a class usage if the (unqualified) name is a deprecated core class.
	 *
	 * @param File   $phpcsFile File.
	 * @param int    $stackPtr  Token index to anchor the message.
	 * @param string $name      Class name (possibly namespaced).
	 * @return void
	 */
	private function maybe_report_class( File $phpcsFile, $stackPtr, $name ) {
		$unqualified = $name;
		$pos         = strrpos( $unqualified, '\\' );
		if ( false !== $pos ) {
			$unqualified = substr( $unqualified, $pos + 1 );
		}
		$lower = strtolower( $unqualified );
		// A component defining its own class/interface/trait of this name shadows
		// the deprecated core class — don't flag its own type.
		if ( isset( $this->declared_symbols( $phpcsFile )['class'][ $lower ] ) ) {
			return;
		}
		$entry = $this->index()['class'][ $lower ] ?? null;
		$status = ( null !== $entry ) ? $this->finding_status( $entry ) : null;
		if ( null !== $status ) {
			$this->report( $phpcsFile, $stackPtr, 'DeprecatedClass', 'Class', $unqualified, $entry, $status );
		}
	}

	/**
	 * Check the first argument of a hook-registering call against deprecated hooks.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token index of the function name.
	 * @param int  $paren     Token index of the opening parenthesis.
	 * @return void
	 */
	private function check_hook_arg( File $phpcsFile, $stackPtr, $paren ) {
		$tokens = $phpcsFile->getTokens();
		$arg    = $phpcsFile->findNext( Tokens::$emptyTokens, $paren + 1, null, true );
		if ( false === $arg || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $arg ]['code'] ) {
			return; // dynamic / interpolated hook name — can't match statically.
		}
		$hook  = trim( $tokens[ $arg ]['content'], "'\"" );
		$entry = $this->index()['hook'][ $hook ] ?? null; // hooks are case-sensitive.
		$status = ( null !== $entry ) ? $this->finding_status( $entry ) : null;
		if ( null !== $status ) {
			$label = isset( $entry['type'] ) ? ucfirst( $entry['type'] ) . ' hook' : 'Hook';
			$this->report( $phpcsFile, $arg, 'DeprecatedHook', $label, "'$hook'", $entry, $status );
		}
	}

	/**
	 * Emit a finding for a deprecated- or removed-core-API usage.
	 *
	 * A symbol the target WP version has *removed* is a real fatal on upgrade
	 * (undefined function/class), so it is reported as an error with "removed
	 * since" wording the driver promotes to fatal; a still-shimmed deprecation
	 * stays a warning.
	 *
	 * @param File   $phpcsFile File.
	 * @param int    $stackPtr  Anchor token.
	 * @param string $code      PHPCS message code (for filtering).
	 * @param string $label     Human label ("Function", "Hook", …).
	 * @param string $subject   The thing being used.
	 * @param array  $entry     Dataset entry.
	 * @param array  $status    Finding status from finding_status(): removed flag + version.
	 * @return void
	 */
	private function report( File $phpcsFile, $stackPtr, $code, $label, $subject, array $entry, array $status ) {
		$suffix = '';
		if ( ! empty( $entry['replacement'] ) ) {
			$suffix = ' Use ' . $entry['replacement'] . ' instead.';
		}
		$data = array( $label, $subject, $status['version'], $suffix );
		if ( $status['removed'] ) {
			$phpcsFile->addError( '%s %s is removed since WordPress %s.%s', $stackPtr, $code, $data );
			return;
		}
		$phpcsFile->addWarning( '%s %s is deprecated since WordPress %s.%s', $stackPtr, $code, $data );
	}

	/**
	 * Decide whether a dataset entry is in scope for the target window, and if so
	 * whether it is a removal (fatal) or a deprecation (warning).
	 *
	 * Removal takes precedence: a symbol the target has removed is fatal even if
	 * its deprecation predates a --since floor — for a delta scan it is the
	 * removal landing in the (since, target] window that newly breaks the upgrade.
	 *
	 * @param array $entry Dataset entry (has 'deprecated', optionally 'removed').
	 * @return array{removed:bool,version:string}|null Null when out of scope.
	 */
	private function finding_status( array $entry ) {
		$target  = getenv( 'PRESSREADY_WP' );
		$since   = getenv( 'PRESSREADY_WP_SINCE' );
		$removed = $entry['removed'] ?? null;

		// A removal in the window is a fatal; check it first so it wins over the
		// (earlier) deprecation version.
		if ( null !== $removed && '' !== $removed && $this->in_window( $removed, $target, $since ) ) {
			return array( 'removed' => true, 'version' => (string) $removed );
		}
		if ( $this->in_window( $entry['deprecated'], $target, $since ) ) {
			return array( 'removed' => false, 'version' => (string) $entry['deprecated'] );
		}
		return null;
	}

	/**
	 * Whether a version falls within the target window: <= target (when set) and
	 * > since (when set, for a delta scan).
	 *
	 * @param string      $version Version to test.
	 * @param string|false $target  PRESSREADY_WP target, or false/'' for unbounded.
	 * @param string|false $since   PRESSREADY_WP_SINCE floor, or false/'' for none.
	 * @return bool
	 */
	private function in_window( $version, $target, $since ) {
		if ( false !== $target && '' !== $target && version_compare( $version, $target, '>' ) ) {
			return false;
		}
		if ( false !== $since && '' !== $since && version_compare( $version, $since, '<=' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a call token is namespaced (e.g. `Foo\bar()`), which would not
	 * resolve to the global core function/hook of the same short name.
	 *
	 * @param File     $phpcsFile File.
	 * @param int      $stackPtr  Token index of the name.
	 * @param int|bool $prev      Previous significant token index.
	 * @return bool
	 */
	private function is_namespaced_call( File $phpcsFile, $stackPtr, $prev ) {
		$tokens = $phpcsFile->getTokens();
		if ( false === $prev || T_NS_SEPARATOR !== $tokens[ $prev ]['code'] ) {
			return false;
		}
		// `\func()` (leading separator only) is the global function — allowed.
		$before = $phpcsFile->findPrevious( Tokens::$emptyTokens, $prev - 1, null, true );
		if ( false === $before ) {
			return false;
		}
		return in_array( $tokens[ $before ]['code'], array( T_STRING, T_NAMESPACE ), true );
	}

	/**
	 * Read a (possibly namespaced) name token sequence after $stackPtr.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token to read after.
	 * @return string|null
	 */
	private function read_name_after( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$i      = $phpcsFile->findNext( Tokens::$emptyTokens, $stackPtr + 1, null, true );
		if ( false === $i ) {
			return null;
		}
		$name = '';
		$name_tokens = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$name_tokens[] = T_NAME_QUALIFIED;
			$name_tokens[] = T_NAME_FULLY_QUALIFIED;
		}
		while ( false !== $i && in_array( $tokens[ $i ]['code'], $name_tokens, true ) ) {
			$name .= $tokens[ $i ]['content'];
			$i     = $phpcsFile->findNext( Tokens::$emptyTokens, $i + 1, null, true );
		}
		return '' === $name ? null : $name;
	}

	/**
	 * Read a (possibly namespaced) name token sequence ending just before $stackPtr.
	 *
	 * @param File $phpcsFile File.
	 * @param int  $stackPtr  Token to read before (e.g. the `::`).
	 * @return string|null
	 */
	private function read_name_before( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();
		$i      = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );
		if ( false === $i ) {
			return null;
		}
		$name_tokens = array( T_STRING, T_NS_SEPARATOR );
		if ( defined( 'T_NAME_QUALIFIED' ) ) {
			$name_tokens[] = T_NAME_QUALIFIED;
			$name_tokens[] = T_NAME_FULLY_QUALIFIED;
		}
		// `self`, `static`, `parent` are not class names we track.
		if ( in_array( $tokens[ $i ]['code'], array( T_SELF, T_STATIC, T_PARENT, T_VARIABLE ), true ) ) {
			return null;
		}
		$parts = '';
		while ( false !== $i && in_array( $tokens[ $i ]['code'], $name_tokens, true ) ) {
			$parts = $tokens[ $i ]['content'] . $parts;
			$i     = $phpcsFile->findPrevious( Tokens::$emptyTokens, $i - 1, null, true );
		}
		return '' === $parts ? null : $parts;
	}

	/**
	 * Symbols the file being scanned declares itself, so a component that defines
	 * its own function/class shadowing a deprecated core name is not falsely
	 * flagged as using the core API.
	 *
	 * Scoped to the file (not the whole component): it is deterministic under
	 * parallel phpcs and never over-suppresses — a file that declares
	 * `function foo()` genuinely resolves `foo()` to its own definition. Only
	 * top-level functions count (methods are excluded via hasCondition); a
	 * shadowed core *class* covers its methods, since a static call only matches
	 * when the literal class name precedes `::`.
	 *
	 * @param File $phpcsFile File.
	 * @return array{function:array<string,true>,class:array<string,true>}
	 */
	private function declared_symbols( File $phpcsFile ) {
		$file = $phpcsFile->getFilename();
		if ( $this->declaredFile === $file && null !== $this->declared ) {
			return $this->declared;
		}

		$class_like = array( T_CLASS, T_INTERFACE, T_TRAIT );
		if ( defined( 'T_ANON_CLASS' ) ) {
			$class_like[] = T_ANON_CLASS;
		}
		if ( defined( 'T_ENUM' ) ) {
			$class_like[] = T_ENUM;
		}

		$declared = array( 'function' => array(), 'class' => array() );
		$tokens   = $phpcsFile->getTokens();
		foreach ( $tokens as $ptr => $tok ) {
			$code = $tok['code'];
			if ( T_FUNCTION === $code ) {
				// A method (inside a class/trait/interface/enum) does not shadow a
				// global core function — only top-level function declarations do.
				if ( $phpcsFile->hasCondition( $ptr, $class_like ) ) {
					continue;
				}
				$name = $phpcsFile->getDeclarationName( $ptr );
				if ( null !== $name && '' !== $name ) {
					$declared['function'][ strtolower( $name ) ] = true;
				}
			} elseif ( in_array( $code, $class_like, true ) ) {
				$name = $phpcsFile->getDeclarationName( $ptr );
				if ( null !== $name && '' !== $name ) {
					$declared['class'][ strtolower( $name ) ] = true;
				}
			}
		}

		$this->declared     = $declared;
		$this->declaredFile = $file;
		return $declared;
	}

	/**
	 * Load and index the dataset (function/class/method lowercased; hook/file exact).
	 *
	 * @return array<string,array<string,array>>
	 */
	private function index() {
		if ( null !== $this->index ) {
			return $this->index;
		}
		// PRESSREADY_DATASET lets a run point at a custom/newer dataset (e.g. one
		// regenerated against a more recent core, or a test fixture).
		$env  = getenv( 'PRESSREADY_DATASET' );
		$path = ( false !== $env && '' !== $env ) ? $env : ( $this->datasetPath ?: __DIR__ . '/../../../data/wp-deprecations.json' );
		$data = array();
		if ( is_file( $path ) ) {
			$json = json_decode( (string) file_get_contents( $path ), true );
			$data = $json['deprecations'] ?? array();
		}

		$index = array(
			'function' => array(),
			'method'   => array(),
			'class'    => array(),
			'hook'     => array(),
			'file'     => array(),
		);
		foreach ( array( 'function', 'method', 'class' ) as $bucket ) {
			foreach ( $data[ $bucket ] ?? array() as $key => $entry ) {
				$index[ $bucket ][ strtolower( $key ) ] = $entry;
			}
		}
		foreach ( array( 'hook', 'file' ) as $bucket ) {
			foreach ( $data[ $bucket ] ?? array() as $key => $entry ) {
				$index[ $bucket ][ $key ] = $entry; // case-sensitive.
			}
		}
		$this->index = $index;
		return $this->index;
	}
}
