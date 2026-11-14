<?php

declare(strict_types=1);

namespace RAN\Tests\Logging;

// Direct local filesystem operations inspect the bounded capture under test.

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Logging\BoosterLogger;
use RAN\Logging\TemporaryDebugCapture;
use RAN\RepositoryProvider\ProviderFailure;
use RAN\RepositoryProvider\ProviderRequestFailed;
use RuntimeException;

require_once __DIR__ . '/LoggingWordPressFunctions.php';

#[CoversClass( BoosterLogger::class )]
final class BoosterLoggerTest extends TestCase {

	private string $directory;
	private TemporaryDebugCapture $capture;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-booster-logger-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertTrue( mkdir( $this->directory, 0700 ) );

		$this->capture = new TemporaryDebugCapture(
			$this->directory . '/secrets.json',
			static fn(): int => strtotime( '2026-07-23T12:00:00Z' )
		);
		$this->capture->start();
		BoosterLogger::configure_capture( $this->capture );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		BoosterLogger::configure_capture( null );

		foreach (
			array(
				$this->directory . '/ran-booster-debug.php',
				$this->directory . '/ran-booster-debug.php.lock',
				$this->directory . '/wordpress-debug.log',
			) as $path
		) {
			if ( is_file( $path ) || is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
				unlink( $path );
			}
		}
		if ( is_dir( $this->directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
			rmdir( $this->directory );
		}
	}

	public function test_capture_works_without_word_press_debug_logging_and_uses_only_safe_one_line_context(): void {
		self::assertFalse( defined( 'WP_DEBUG_LOG' ) );

		$logged = BoosterLogger::log(
			"deployment\nstarted",
			array(
				'attempt_id' => "attempt-\r\n1",
				'step'       => 'download',
				'token'      => 'sentinel-secret-token',
				'headers'    => array( 'Authorization' => 'sentinel-authorization' ),
			)
		);
		self::assertTrue( $logged );

		$entries = $this->capture->snapshot()['entries'];
		self::assertCount( 1, $entries );
		self::assertSame(
			'[ran-booster] deployment started {"attempt_id":"attempt- 1","step":"download"}',
			$entries[0]['line']
		);
		self::assertStringNotContainsString( 'sentinel-secret-token', $entries[0]['line'] );
		self::assertStringNotContainsString( 'sentinel-authorization', $entries[0]['line'] );
		self::assertStringNotContainsString( "\n", $entries[0]['line'] );
	}

	public function test_exception_messages_and_traces_never_enter_the_capture(): void {
		$exception = new RuntimeException( 'sentinel-raw-exception-secret', 73 );

		BoosterLogger::log_exception(
			'deployment failed',
			$exception,
			array(
				'correlation_id' => str_repeat( 'a', 32 ),
				'diagnostic_id'  => 'ran_booster_manual_action_failed',
				'reference'      => 'ignored-generic-reference',
				'step'           => 'deploy',
				'source'         => 'manual',
			)
		);

		$line = $this->capture->snapshot()['entries'][0]['line'];
		self::assertSame(
			'[ran-booster] deployment failed {"correlation_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","diagnostic_id":"ran_booster_manual_action_failed","step":"deploy","source":"manual","exception_class":"RuntimeException","exception_code":"73"}',
			$line
		);
		self::assertStringNotContainsString( 'sentinel-raw-exception-secret', $line );
		self::assertStringNotContainsString( 'ignored-generic-reference', $line );
		self::assertStringNotContainsString( __FILE__, $line );
	}

	public function test_provider_failure_logs_class_code_and_only_the_closed_reason_and_typed_numbers(): void {
		$carrier = new ProviderRequestFailed( new ProviderFailure( 'rate_limited', 403, 120 ) );

		BoosterLogger::log_provider_failure(
			'provider request failed',
			$carrier,
			array(
				'operation' => 'repository_browse',
				'provider'  => 'gh',
			)
		);

		self::assertSame(
			'[ran-booster] provider request failed {"reason":"rate_limited","http_status":403,"retry_after":120,"operation":"repository_browse","provider":"gh","exception_class":"RAN\\\\RepositoryProvider\\\\ProviderRequestFailed","exception_code":"403"}',
			$this->capture->snapshot()['entries'][0]['line']
		);
	}

	public function test_a_legacy_code_logs_its_derived_reason_and_never_the_message(): void {
		BoosterLogger::log_provider_failure( 'provider request failed', new RuntimeException( 'sentinel-provider-message ghp_abcdefghijklmnop1234', 404 ), array( 'step' => 'prepare_archive' ) );
		BoosterLogger::log_provider_failure( 'provider request failed', new RuntimeException( 'sentinel-provider-message', 0 ) );

		$entries = $this->capture->snapshot()['entries'];
		self::assertSame(
			'[ran-booster] provider request failed {"reason":"repository_not_found_or_no_access","http_status":404,"step":"prepare_archive","exception_class":"RuntimeException","exception_code":"404"}',
			$entries[0]['line']
		);
		self::assertStringNotContainsString( 'reason', $entries[1]['line'] );
		self::assertStringNotContainsString( 'http_status', $entries[1]['line'] );
		foreach ( $entries as $entry ) {
			self::assertStringNotContainsString( 'sentinel-provider-message', $entry['line'] );
			self::assertStringNotContainsString( 'ghp_', $entry['line'] );
		}
	}

	public function test_a_failure_result_logs_without_an_exception(): void {
		BoosterLogger::log_failure( 'credential validation failed', new ProviderFailure( 'credential_expired' ), array( 'operation' => 'credential_validation' ) );

		self::assertSame(
			'[ran-booster] credential validation failed {"reason":"credential_expired","operation":"credential_validation"}',
			$this->capture->snapshot()['entries'][0]['line']
		);
	}

	public function test_reason_and_number_fields_outside_the_closed_shapes_are_dropped(): void {
		foreach (
			array(
				array( 'reason', 'ghp_abcdefghijklmnop1234' ),
				array( 'reason', 'made_up_reason' ),
				array( 'reason', 'Rate limited' ),
				array( 'http_status', '404; Authorization: x' ),
				array( 'http_status', '1234567' ),
				array( 'retry_after', '-1' ),
				array( 'retry_after', 'soon' ),
			) as $case
		) {
			BoosterLogger::log(
				'shape',
				array(
					$case[0] => $case[1],
					'step'   => 'ok',
				)
			);
		}
		BoosterLogger::log(
			'shape',
			array(
				'reason'      => 'http_timeout',
				'http_status' => '504',
				'retry_after' => '30',
			)
		);

		$entries = $this->capture->snapshot()['entries'];
		foreach ( array_slice( $entries, 0, 7 ) as $entry ) {
			self::assertSame( 1, preg_match( '/\{"step":"ok"\}\z/', $entry['line'] ), $entry['line'] );
		}
		self::assertStringEndsWith( '{"reason":"http_timeout","http_status":"504","retry_after":"30"}', $entries[7]['line'] );
	}

	public function test_context_values_shaped_like_secrets_paths_urls_digests_or_markup_are_dropped(): void {
		$shapes = array(
			'token'  => 'ghp_abcdefghijklmnop1234',
			'url'    => 'https://user:pass@example.com/x',
			'path'   => '/Users/someone/site/wp-content/file.php',
			'digest' => str_repeat( 'ab', 24 ),
			'markup' => '<script>alert(1)</script>',
			'long'   => str_repeat( 'a', 192 ),
		);
		foreach ( $shapes as $shape => $value ) {
			BoosterLogger::log(
				'shape ' . $shape,
				array(
					'resolved_ref'   => $value,
					'exception_code' => $value,
					'step'           => 'ok',
				)
			);
		}
		foreach ( $this->capture->snapshot()['entries'] as $entry ) {
			self::assertSame( 1, preg_match( '/\{"step":"ok"\}\z/', $entry['line'] ), $entry['line'] );
		}
	}

	public function test_exact_commit_ids_and_correlation_ids_survive_the_value_screen(): void {
		BoosterLogger::log(
			'ids',
			array(
				'resolved_ref'   => str_repeat( 'a', 40 ),
				'correlation_id' => str_repeat( 'b', 32 ),
			)
		);
		BoosterLogger::log(
			'near',
			array(
				'resolved_ref'   => str_repeat( 'a', 41 ) . 'Z',
				'correlation_id' => str_repeat( 'b', 33 ),
			)
		);
		$entries = $this->capture->snapshot()['entries'];
		self::assertStringContainsString( '"resolved_ref":"' . str_repeat( 'a', 40 ) . '","correlation_id":"' . str_repeat( 'b', 32 ) . '"', $entries[0]['line'] );
		self::assertStringNotContainsString( 'resolved_ref', $entries[1]['line'] );
		self::assertStringNotContainsString( 'correlation_id', $entries[1]['line'] );
	}

	public function test_every_fixed_diagnostic_id_in_production_code_survives_the_value_screen(): void {
		$ids = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/RAN', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				// A trailing underscore marks a prefix that a longer id is built from, not an id.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a test.
				preg_match_all( "/'(ran_booster_[a-z0-9_]{20,}[a-z0-9])'/", (string) file_get_contents( $file->getPathname() ), $found );
				$ids = array_merge( $ids, $found[1] );
			}
		}
		$ids = array_unique( $ids );
		self::assertContains( 'ran_booster_storage_transaction_unavailable', $ids );
		self::assertContains( 'ran_booster_storage_invalid_provider_identity', $ids );
		foreach ( $ids as $id ) {
			BoosterLogger::log( 'id', array( 'diagnostic_id' => $id ) );
		}
		$lines = implode( "\n", array_column( $this->capture->snapshot()['entries'], 'line' ) );
		foreach ( $ids as $id ) {
			self::assertStringContainsString( '"diagnostic_id":"' . $id . '"', $lines, $id );
		}
	}

	public function test_token_like_runs_are_still_dropped_from_diagnostic_ids(): void {
		foreach ( array( 'Ab3dEf9hIjKlMnOpQrStUvWxYz0123456789AbCdEf', 'ghp_' . str_repeat( 'a', 36 ), str_repeat( 'a', 30 ) . '12345678', str_repeat( 'ab', 16 ) ) as $value ) {
			BoosterLogger::log(
				'tokenish',
				array(
					'diagnostic_id' => $value,
					'step'          => 'ok',
				)
			);
		}
		foreach ( $this->capture->snapshot()['entries'] as $entry ) {
			self::assertStringEndsWith( '{"step":"ok"}', $entry['line'] );
		}
	}

	/** True when the logger's one-line folding leaves the text exactly as written. */
	private static function is_one_line_stable( string $text ): bool {
		$folded = preg_replace( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text );

		return '' !== trim( $text ) && trim( $text ) === $text && $folded === $text;
	}

	/** @return array<string, array{string}> */
	private static function corpus_group( string $group ): array {
		/** @var array<string, array<string, string>> $corpus */
		$corpus = require dirname( __DIR__, 2 ) . '/vendor/ran/updater-support/tests/fixtures/failure-text-corpus.php';
		$rows   = array();
		foreach ( $corpus[ $group ] as $label => $text ) {
			$rows[ $label ] = array( $text );
		}

		return $rows;
	}

	/**
	 * Hostile entries the logger can screen as written. The logger folds control, format and
	 * line-separator characters and surrounding space into single spaces before screening (so a
	 * multi-line value stays one log line), which makes those entries ordinary text by then.
	 *
	 * @return array<string, array{string}>
	 */
	public static function hostile_corpus(): array {
		return array_filter(
			self::corpus_group( 'hostile' ),
			static fn ( array $row ): bool => self::is_one_line_stable( $row[0] )
		);
	}

	/** @return array<string, array{string}> */
	public static function strict_only_corpus(): array {
		return self::corpus_group( 'strict_only' );
	}

	/** @return array<string, array{string}> */
	public static function benign_corpus(): array {
		return array_filter(
			self::corpus_group( 'benign' ),
			static fn ( array $row ): bool => strlen( $row[0] ) <= 191 && self::is_one_line_stable( $row[0] )
		);
	}

	/** @return array<string, array{string}> */
	public static function identifier_corpus(): array {
		return self::corpus_group( 'identifiers' );
	}

	/** @return array<string, array{string}> */
	public static function not_identifier_corpus(): array {
		return array_filter(
			self::corpus_group( 'not_identifiers' ),
			static fn ( array $row ): bool => self::is_one_line_stable( $row[0] )
		);
	}

	private function last_line(): string {
		$entries = $this->capture->snapshot()['entries'];
		$entry   = end( $entries );
		self::assertIsArray( $entry );

		return $entry['line'];
	}

	#[DataProvider( 'hostile_corpus' )]
	public function test_every_hostile_corpus_entry_is_withheld_from_the_message_and_dropped_from_every_string_value( string $text ): void {
		BoosterLogger::log(
			$text,
			array(
				'attempt_id' => $text,
				'step'       => 'ok',
			)
		);

		$line = $this->last_line();
		self::assertSame( '[ran-booster] message withheld {"step":"ok"}', $line );
	}

	#[DataProvider( 'strict_only_corpus' )]
	public function test_credential_prose_is_kept_in_the_message_and_dropped_from_values( string $text ): void {
		BoosterLogger::log( $text, array( 'attempt_id' => $text ) );

		$line = $this->last_line();
		self::assertStringStartsWith( '[ran-booster] ' . trim( $text ), $line, 'The message is Core text screened by shape only.' );
		self::assertStringNotContainsString( '"attempt_id"', $line, 'Any other string value is untrusted and screened by the strict mode.' );
	}

	#[DataProvider( 'benign_corpus' )]
	public function test_benign_corpus_entries_survive_in_a_free_text_value( string $text ): void {
		BoosterLogger::log( 'free text', array( 'attempt_id' => $text ) );

		self::assertStringContainsString( '"attempt_id":' . wp_json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $this->last_line() );
	}

	#[DataProvider( 'identifier_corpus' )]
	public function test_closed_identifiers_survive_in_identifier_keys( string $id ): void {
		BoosterLogger::log( 'id', array( 'step' => $id ) );

		self::assertStringContainsString( '"step":' . wp_json_encode( $id, JSON_UNESCAPED_SLASHES ), $this->last_line() );
	}

	#[DataProvider( 'not_identifier_corpus' )]
	public function test_non_identifiers_are_dropped_from_identifier_keys( string $id ): void {
		BoosterLogger::log( 'id', array( 'step' => $id ) );

		self::assertStringNotContainsString( 'step', $this->last_line() );
	}

	public function test_dotted_and_dashed_closed_ids_are_kept_and_a_credential_message_is_withheld(): void {
		BoosterLogger::log(
			'token ghp_abcdefghijklmnop1234 failed',
			array(
				'diagnostic_id' => 'gh.credential.valid',
				'provider'      => 'github-fine-grained',
			)
		);

		self::assertSame( '[ran-booster] message withheld {"diagnostic_id":"gh.credential.valid","provider":"github-fine-grained"}', $this->last_line() );
	}

	/** @return array<string, array{string, bool}> */
	public static function stored_ids(): array {
		return array(
			'leading digit'              => array( '3d-flipbook', true ),
			'trailing underscore'        => array( 'my_plugin_', true ),
			'double underscore'          => array( 'plugin__x', true ),
			'hex-like short slug'        => array( 'a1b2c3d4e5f6a7b8c9', true ),
			'long alphanumeric run'      => array( str_repeat( 'a', 30 ) . '-tools', true ),
			'sk inside a word'           => array( 'disk-image', true ),
			'dotted notice code'         => array( 'ran_booster.admin-error', true ),
			'longest accepted'           => array( 'z' . str_repeat( 'y', 190 ), true ),
			'one over the bound'         => array( 'z' . str_repeat( 'y', 191 ), false ),
			'classic token'              => array( 'ghp_abcdefghijklmnop1234', false ),
			'fine grained token'         => array( 'github_pat_abcdefghijklmnop', false ),
			'gitlab token'               => array( 'glpat-abcdefghijkl', false ),
			'payment key'                => array( 'sk-abcdefghijkl', false ),
			'npm token'                  => array( 'npm_abcdefghijkl', false ),
			'digest word'                => array( 'sha256_abcdef', false ),
			'forty hex'                  => array( str_repeat( 'ab', 20 ), false ),
			'long slug with hex letters' => array( 'advanced-custom-fields-pro-extended-database-feed-cache-deed', true ),
			'long accessible slug'       => array( 'bbpress-notify-nospam-for-accessible-feedback-and-decade-badge-fade', true ),
			'long cafe slug'             => array( 'my-cafe-and-bed-and-breakfast-deadline-facade-accessible-feedback', true ),
			'hex run'                    => array( 'deadbeefdeadbeefdeadbeefdeadbeef', false ),
			'separated hex run'          => array( 'dead-beef-dead-beef-dead-beef-dead-beef', false ),
			'wordpress cookie name'      => array( 'wordpress_logged_in_abc', false ),
			'settings cookie name'       => array( 'wp-settings-1', false ),
			'uppercase'                  => array( 'My-Plugin', false ),
			'slash'                      => array( 'dir/plugin', false ),
			'leading separator'          => array( '-plugin', false ),
		);
	}

	#[DataProvider( 'stored_ids' )]
	public function test_package_slugs_and_notice_codes_keep_the_shape_core_stores_them_in( string $value, bool $kept ): void {
		foreach ( array( 'package_slug', 'diagnostic_id' ) as $key ) {
			BoosterLogger::log( 'stored', array( $key => $value ) );
			self::assertSame( $kept, str_contains( $this->last_line(), '"' . $key . '"' ), $key . ' ' . $value );
		}
	}

	public function test_exception_code_and_class_bounds_are_exact(): void {
		foreach ( array(
			'1234567890'  => true,
			'12345678901' => false,
			'-5'          => true,
		) as $code => $kept ) {
			BoosterLogger::log( 'code', array( 'exception_code' => (string) $code ) );
			self::assertSame( $kept, str_contains( $this->last_line(), 'exception_code' ), (string) $code );
		}
		foreach ( array(
			str_repeat( 'a_', 95 ) . 'a'         => true,
			str_repeat( 'a_', 96 )               => false,
			'RAN\\Deployment\\DeploymentFailure' => true,
			'RAN\\ghp_abcdefghijklmnop1234'      => false,
			'RAN\\GHP_abcdefghijklmnop1234'      => false,
			'Vendor\\github_pat_abc'             => false,
			'RAN\\' . str_repeat( 'a', 41 )      => false,
		) as $class => $kept ) {
			BoosterLogger::log( 'class', array( 'exception_class' => (string) $class ) );
			self::assertSame( $kept, str_contains( $this->last_line(), 'exception_class' ), (string) $class );
		}
	}

	public function test_the_exact_correlation_id_is_kept_locally_and_anything_else_is_dropped(): void {
		foreach ( array( str_repeat( 'a', 32 ), str_repeat( 'A', 32 ), str_repeat( 'a', 31 ), 'attempt-1' ) as $value ) {
			BoosterLogger::log( 'correlation', array( 'correlation_id' => $value ) );
			$line = $this->last_line();
			if ( str_repeat( 'a', 32 ) === $value ) {
				self::assertStringContainsString( '"correlation_id":"' . $value . '"', $line );
			} else {
				self::assertStringNotContainsString( 'correlation_id', $line, $value );
			}
		}
	}

	public function test_resolved_ref_accepts_exact_commit_ids_and_closed_ids_only(): void {
		foreach ( array( str_repeat( 'a', 40 ), str_repeat( 'b', 64 ), 'branch_main', str_repeat( 'a', 39 ), str_repeat( 'A', 40 ), 'refs/heads/main' ) as $value ) {
			BoosterLogger::log( 'ref', array( 'resolved_ref' => $value ) );
			$kept = str_contains( $this->last_line(), 'resolved_ref' );
			self::assertSame( in_array( $value, array( str_repeat( 'a', 40 ), str_repeat( 'b', 64 ), 'branch_main' ), true ), $kept, $value );
		}
	}

	public function test_a_state_transition_is_exactly_two_closed_ids(): void {
		foreach ( array( 'new->running', 'queued->running' ) as $value ) {
			BoosterLogger::log( 'moved', array( 'transition' => $value ) );
			self::assertStringContainsString( '"transition":"' . str_replace( '>', '\u003e', $value ), str_replace( '>', '\u003e', $this->last_line() ) );
		}
		foreach ( array( 'new->', '->running', 'a->b->c', 'ghp_abcdefghijklmnop1234->running', 'new>running' ) as $value ) {
			BoosterLogger::log( 'moved', array( 'transition' => $value ) );
			self::assertStringNotContainsString( 'transition', $this->last_line(), $value );
		}
	}

	public function test_only_integer_exception_codes_and_plain_class_names_are_recorded(): void {
		BoosterLogger::log_exception( 'integer', new RuntimeException( 'x', 73 ) );
		self::assertStringContainsString( '"exception_class":"RuntimeException","exception_code":"73"', $this->last_line() );

		BoosterLogger::log_exception( 'string code', new \PDOException( 'x' ) );
		self::assertStringContainsString( '"exception_class":"PDOException","exception_code":"0"', $this->last_line() );

		$exception = new class( 'x' ) extends RuntimeException {
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Test double reports a string code, as some database exceptions do.
			public function __construct( string $message ) {
				parent::__construct( $message );
				$this->code = 'HY000';
			}
		};
		BoosterLogger::log_exception( 'string state', $exception, array( 'step' => 'ok' ) );
		$digits = new class( 'x' ) extends RuntimeException {
			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing -- Test double reports a digit-only string code, as SQLSTATE values can be.
			public function __construct( string $message ) {
				parent::__construct( $message );
				$this->code = '23000';
			}
		};
		BoosterLogger::log_exception( 'digit state', $digits, array( 'step' => 'ok' ) );
		self::assertStringNotContainsString( 'exception_code', $this->last_line(), 'Only an integer code is recorded, even when a string code is all digits.' );
		$line = $this->last_line();
		self::assertStringNotContainsString( 'exception_code', $line );
		self::assertStringNotContainsString( 'exception_class', $line, 'An anonymous class name carries a path and is dropped.' );
		self::assertStringNotContainsString( 'HY000', $line );

		// A class name is declared code, not remote text: only its closed spelling is checked.
		BoosterLogger::log(
			'forged',
			array(
				'exception_class' => 'Vendor\\Not A Class/Name',
				'exception_code'  => 'HY000',
			)
		);
		self::assertSame( '[ran-booster] forged []', $this->last_line() );
	}

	public function test_the_context_allow_list_cannot_grow_without_changing_this_test(): void {
		$keys = ( new \ReflectionClassConstant( BoosterLogger::class, 'SAFE_CONTEXT_KEYS' ) )->getValue();
		self::assertIsArray( $keys );
		self::assertSame(
			array( 'attempt_id', 'correlation_id', 'diagnostic_id', 'event', 'exception_class', 'exception_code', 'http_status', 'operation', 'outcome_code', 'package_slug', 'provider', 'reason', 'resolved_by', 'resolved_ref', 'retry_after', 'source', 'state', 'step', 'transition' ),
			array_keys( $keys )
		);
	}

	public function test_inactive_or_broken_capture_does_not_affect_logging_callers(): void {
		$this->capture->stop();
		self::assertFalse( BoosterLogger::log( 'after stop', array( 'step' => 'ignored' ) ) );
		self::assertSame( array(), $this->capture->snapshot()['entries'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		chmod( $this->directory . '/ran-booster-debug.php', 0644 );
		self::assertFalse( BoosterLogger::log( 'unsafe file', array( 'step' => 'ignored' ) ) );
		self::assertSame( 'malformed', $this->capture->snapshot()['state'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_word_press_debug_logging_remains_enabled_alongside_capture(): void {
		$wordpress_log = $this->directory . '/wordpress-debug.log';
		define( 'WP_DEBUG_LOG', true );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Separate-process test redirects PHP's normal error-log destination to a disposable fixture.
		self::assertNotFalse( ini_set( 'error_log', $wordpress_log ) );

		self::assertTrue( BoosterLogger::log( 'dual destination', array( 'step' => 'logging' ) ) );

		$expected = '[ran-booster] dual destination {"step":"logging"}';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Native I/O controls the isolated test fixture without requiring WordPress filesystem bootstrap.
		self::assertStringContainsString( $expected, (string) file_get_contents( $wordpress_log ) );
		self::assertSame( $expected, $this->capture->snapshot()['entries'][0]['line'] );
	}
}
