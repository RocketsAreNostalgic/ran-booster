<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Native local filesystem behavior is the subject of these tests.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Secrets\WpConfigPathWriteException;
use RAN\Secrets\WpConfigPathWriteResult;
use RAN\Secrets\WpConfigSecretsPathWriter;

#[CoversClass( WpConfigSecretsPathWriter::class )]
#[CoversClass( WpConfigPathWriteException::class )]
#[CoversClass( WpConfigPathWriteResult::class )]
final class WpConfigSecretsPathWriterTest extends TestCase {

	private string $directory;
	private string $config_path;
	private string $sidecar_path;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->directory    = sys_get_temp_dir() . '/ran-booster-wp-config-' . bin2hex( random_bytes( 8 ) );
		$this->config_path  = $this->directory . '/wp-config.php';
		$this->sidecar_path = $this->directory . '/private/secrets.json';

		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->write_config( $this->valid_config() );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->remove_tree( $this->directory );
	}

	public function test_it_adds_the_fixed_definition_and_requires_asecond_request_verification(): void {
		self::assertTrue( chmod( $this->config_path, 0640 ) );

		$result = ( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path );

		self::assertSame( WpConfigPathWriteResult::STATUS_PENDING_VERIFICATION, $result->status() );
		self::assertTrue( $result->requires_next_request_verification() );
		self::assertSame( 0640, fileperms( $this->config_path ) & 0777 );
		self::assertSame( fileowner( $this->config_path . '.ran-booster.lock' ), fileowner( $this->config_path ) );
		self::assertSame( 0600, fileperms( $this->config_path . '.ran-booster.lock' ) & 0777 );
		self::assertSame(
			"<?php\n\ndefine( 'DB_NAME', 'example' );\n\n"
			. "/* RAN Booster encrypted secrets storage. */\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->sidecar_path ) . "' );\n\n"
			. "/* That's all, stop editing! Happy publishing. */\n",
			file_get_contents( $this->config_path )
		);
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_it_preserves_cr_lf_line_endings_and_safely_encodes_the_path(): void {
		$config  = str_replace( "\n", "\r\n", $this->valid_config() );
		$sidecar = $this->directory . "/private/agency's\\directory/secrets.json";
		$this->write_config( $config );

		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $sidecar );

		$written = file_get_contents( $this->config_path );
		self::assertIsString( $written );
		self::assertStringNotContainsString( "\n", str_replace( "\r\n", '', $written ) );
		self::assertStringContainsString( "agency\\'s\\\\directory' );\r\n", $written );
		token_get_all( $written, TOKEN_PARSE );
	}

	public function test_arepeated_write_refuses_the_existing_constant_without_changing_bytes(): void {
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->config_path, $this->sidecar_path );
		$written = file_get_contents( $this->config_path );

		$this->assert_refused(
			'constant_exists',
			fn() => $writer->write( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $written, file_get_contents( $this->config_path ) );
	}

	public function test_successful_write_invalidates_the_wp_config_opcode_cache(): void {
		$writer = new class() extends WpConfigSecretsPathWriter {
			/** @var list<string> */
			public array $invalidated = array();

			protected function invalidate_opcode_cache( string $config_path ): void {
				$this->invalidated[] = $config_path;
			}
		};

		$writer->write( $this->config_path, $this->sidecar_path );

		self::assertSame( array( $this->config_path ), $writer->invalidated );
	}

	public function test_it_removes_only_the_owned_definition_and_preserves_surrounding_bytes_and_metadata(): void {
		self::assertTrue( chmod( $this->config_path, 0640 ) );
		$original = file_get_contents( $this->config_path );
		$owner    = fileowner( $this->config_path );
		$group    = filegroup( $this->config_path );
		$writer   = new WpConfigSecretsPathWriter();
		$writer->write( $this->config_path, $this->sidecar_path );

		self::assertTrue( $writer->remove_owned_definition( $this->config_path, $this->sidecar_path ) );
		self::assertSame( $original, file_get_contents( $this->config_path ) );
		self::assertSame( 0640, fileperms( $this->config_path ) & 0777 );
		self::assertSame( $owner, fileowner( $this->config_path ) );
		self::assertSame( $group, filegroup( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_it_atomically_retargets_only_the_owned_definition_and_preserves_metadata(): void {
		$replacement = $this->directory . '/private/previous/secrets.json';
		self::assertTrue( chmod( $this->config_path, 0640 ) );
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->config_path, $this->sidecar_path );
		$owner = fileowner( $this->config_path );
		$group = filegroup( $this->config_path );

		$result  = $writer->retarget_owned_definition( $this->config_path, $this->sidecar_path, $replacement );
		$written = (string) file_get_contents( $this->config_path );

		self::assertTrue( $result->requires_next_request_verification() );
		self::assertStringNotContainsString(
			"define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->sidecar_path ) . "' );",
			$written
		);
		self::assertStringContainsString( dirname( $replacement ), $written );
		self::assertSame( 0640, fileperms( $this->config_path ) & 0777 );
		self::assertSame( $owner, fileowner( $this->config_path ) );
		self::assertSame( $group, filegroup( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_retarget_leaves_manual_definition_untouched(): void {
		$config = "<?php\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '" . $this->sidecar_path . "' );\n"
			. "/* That's all, stop editing! Happy publishing. */\n";
		$this->write_config( $config );

		self::assertFalse(
			( new WpConfigSecretsPathWriter() )->retarget_owned_definition(
				$this->config_path,
				$this->sidecar_path,
				$this->directory . '/private/previous/secrets.json'
			)
		);
		self::assertSame( $config, file_get_contents( $this->config_path ) );
		self::assertFileDoesNotExist( $this->config_path . '.ran-booster.lock' );
	}

	public function test_removal_preserves_cr_lf_line_endings_and_matches_the_decoded_active_path(): void {
		$config  = str_replace( "\n", "\r\n", $this->valid_config() );
		$sidecar = $this->directory . "/private/agency's\\secrets.json";
		$this->write_config( $config );
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->config_path, $sidecar );

		self::assertTrue( $writer->remove_owned_definition( $this->config_path, $sidecar ) );
		self::assertSame( $config, file_get_contents( $this->config_path ) );
	}

	public function test_removal_is_idempotent(): void {
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->config_path, $this->sidecar_path );
		self::assertTrue( $writer->remove_owned_definition( $this->config_path, $this->sidecar_path ) );
		$removed = file_get_contents( $this->config_path );

		self::assertFalse( $writer->remove_owned_definition( $this->config_path, $this->sidecar_path ) );
		self::assertSame( $removed, file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_removal_preflight_proves_the_exact_definition_without_changing_bytes(): void {
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->config_path, $this->sidecar_path );
		$written = file_get_contents( $this->config_path );

		self::assertTrue(
			$writer->assert_owned_definition_removable( $this->config_path, $this->sidecar_path )
		);
		self::assertTrue( $writer->has_owned_definition( $this->config_path, $this->sidecar_path ) );
		self::assertSame( $written, file_get_contents( $this->config_path ) );
	}

	public function test_removal_leaves_amanual_matching_definition_untouched(): void {
		$config = "<?php\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '" . $this->sidecar_path . "' );\n"
			. "/* That's all, stop editing! Happy publishing. */\n";
		$this->write_config( $config );
		self::assertTrue( chmod( $this->config_path, 0400 ) );

		self::assertFalse(
			( new WpConfigSecretsPathWriter() )->remove_owned_definition( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $config, file_get_contents( $this->config_path ) );
		self::assertFileDoesNotExist( $this->config_path . '.ran-booster.lock' );
	}

	public function test_removal_leaves_amismatched_owned_definition_untouched(): void {
		$config = "<?php\n"
			. "/* RAN Booster encrypted secrets storage. */\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '/manually/changed/secrets.json' );\n\n"
			. "/* That's all, stop editing! Happy publishing. */\n";
		$this->write_config( $config );

		self::assertFalse(
			( new WpConfigSecretsPathWriter() )->remove_owned_definition( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $config, file_get_contents( $this->config_path ) );
		self::assertFileDoesNotExist( $this->config_path . '.ran-booster.lock' );
	}

	public function test_removal_refuses_duplicate_matching_owned_definitions(): void {
		$block  = "/* RAN Booster encrypted secrets storage. */\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->sidecar_path ) . "' );\n\n";
		$config = "<?php\n" . $block . $block . "/* That's all, stop editing! Happy publishing. */\n";
		$this->write_config( $config );

		$this->assert_refused(
			'owned_definition_ambiguous',
			fn() => ( new WpConfigSecretsPathWriter() )->remove_owned_definition(
				$this->config_path,
				$this->sidecar_path
			)
		);
		self::assertSame( $config, file_get_contents( $this->config_path ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function unsafe_path_provider(): iterable {
		yield 'relative config' => array( 'wp-config.php', '/private/secrets.json' );
		yield 'relative sidecar' => array( '/tmp/wp-config.php', 'private/secrets.json' );
		yield 'parent segment' => array( '/tmp/wp-config.php', '/private/../secrets.json' );
		yield 'current segment' => array( '/tmp/wp-config.php', '/private/./secrets.json' );
		yield 'duplicate separator' => array( '/tmp/wp-config.php', '/private//secrets.json' );
		yield 'line break' => array( '/tmp/wp-config.php', "/private/secrets\n.json" );
		yield 'directory value' => array( '/tmp/wp-config.php', '/private/' );
	}

	#[DataProvider( 'unsafe_path_provider' )]
	public function test_it_refuses_unsafe_paths( string $config_path, string $sidecar_path ): void {
		$expected = '/tmp/wp-config.php' === $config_path ? 'sidecar_path_invalid' : 'config_path_invalid';
		$this->assert_refused(
			$expected,
			static fn() => ( new WpConfigSecretsPathWriter() )->write( $config_path, $sidecar_path )
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalid_config_provider(): iterable {
		yield 'missing marker' => array( "<?php\n\ndefine( 'DB_NAME', 'example' );\n", 'marker_invalid' );
		yield 'duplicate marker' => array(
			"<?php\n/* That's all, stop editing! Happy publishing. */\n/* That's all, stop editing! Happy publishing. */\n",
			'marker_invalid',
		);
		yield 'bad PHP' => array( "<?php\nif (\n/* That's all, stop editing! Happy publishing. */\n", 'config_parse_failed' );
		yield 'existing single quoted define' => array(
			"<?php\ndefine( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '/old/path' );\n/* That's all, stop editing! Happy publishing. */\n",
			'constant_exists',
		);
		yield 'existing double quoted define' => array(
			"<?php\ndefine(\"RAN_BOOSTER_ENCRYPTED_SECRETS_FILE\", \"/old/path\");\n/* That's all, stop editing! Happy publishing. */\n",
			'constant_exists',
		);
		yield 'existing const declaration' => array(
			"<?php\nconst RAN_BOOSTER_ENCRYPTED_SECRETS_FILE = '/old/path';\n/* That's all, stop editing! Happy publishing. */\n",
			'constant_exists',
		);
		yield 'mixed line endings' => array(
			"<?php\r\ndefine( 'DB_NAME', 'example' );\n/* That's all, stop editing! Happy publishing. */\r\n",
			'line_endings_unsupported',
		);
	}

	#[DataProvider( 'invalid_config_provider' )]
	public function test_it_refuses_ambiguous_malformed_or_previously_configured_files( string $config, string $reason ): void {
		$this->write_config( $config );
		$original = file_get_contents( $this->config_path );
		$this->assert_refused(
			$reason,
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $original, file_get_contents( $this->config_path ) );
	}

	public function test_it_refuses_asymlinked_config(): void {
		$target = $this->directory . '/actual-config.php';
		self::assertTrue( rename( $this->config_path, $target ) );
		self::assertTrue( symlink( $target, $this->config_path ) );
		$this->assert_refused(
			'config_file_invalid',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path )
		);
	}

	public function test_it_refuses_amultiply_linked_config(): void {
		$link = $this->directory . '/config-copy.php';
		self::assertTrue( link( $this->config_path, $link ) );
		$this->assert_refused(
			'config_file_invalid',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path )
		);
	}

	public function test_it_refuses_agroup_writable_config(): void {
		self::assertTrue( chmod( $this->config_path, 0660 ) );
		$this->assert_refused(
			'config_permissions_unsafe',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path )
		);
	}

	public function test_it_refuses_an_oversized_config(): void {
		$this->write_config(
			"<?php\n/* " . str_repeat( 'x', 1048576 ) . " */\n/* That's all, stop editing! Happy publishing. */\n"
		);
		$this->assert_refused(
			'config_size_unsupported',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path )
		);
	}

	public function test_it_detects_aconcurrent_config_change_before_replacement(): void {
		$original = file_get_contents( $this->config_path );
		$writer   = new class() extends WpConfigSecretsPathWriter {
			protected function before_final_config_check( string $config_path ): void {
				file_put_contents( $config_path, "\n// concurrent edit\n", FILE_APPEND );
			}
		};
		$this->assert_refused(
			'config_changed',
			fn() => $writer->write( $this->config_path, $this->sidecar_path )
		);
		self::assertNotSame( $original, file_get_contents( $this->config_path ) );
		self::assertStringNotContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', file_get_contents( $this->config_path ) );
	}

	public function test_removal_detects_aconcurrent_config_change_before_replacement(): void {
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path );
		$writer = new class() extends WpConfigSecretsPathWriter {
			protected function before_final_config_check( string $config_path ): void {
				file_put_contents( $config_path, "\n// concurrent edit\n", FILE_APPEND );
			}
		};
		$this->assert_refused(
			'config_changed',
			fn() => $writer->remove_owned_definition( $this->config_path, $this->sidecar_path )
		);
		self::assertStringContainsString(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR',
			(string) file_get_contents( $this->config_path )
		);
		self::assertStringContainsString( '// concurrent edit', (string) file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	/**
	 * @return iterable<string, array{string, callable(): WpConfigSecretsPathWriter}>
	 */
	public static function failure_writer_provider(): iterable {
		yield 'lock' => array(
			'lock_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of acquire_lock retains the production method contract; these inputs do not affect this controlled result.
				protected function acquire_lock( mixed $lock ): bool {
					return false;
				}
			},
		);
		yield 'temporary creation' => array(
			'temporary_create_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of create_temporary retains the production method contract; these inputs do not affect this controlled result.
				protected function create_temporary( string $directory ): array {
					throw new WpConfigPathWriteException( 'temporary_create_failed', 'Test temporary creation failure.' );
				}
			},
		);
		yield 'write' => array(
			'temporary_write_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of write_handle retains the production method contract; these inputs do not affect this controlled result.
				protected function write_handle( mixed $handle, string $contents ): int|false {
					return false;
				}
			},
		);
		yield 'flush' => array(
			'temporary_flush_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of flush_handle retains the production method contract; these inputs do not affect this controlled result.
				protected function flush_handle( mixed $handle ): bool {
					return false;
				}
			},
		);
		yield 'fsync' => array(
			'temporary_sync_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of sync_handle retains the production method contract; these inputs do not affect this controlled result.
				protected function sync_handle( mixed $handle ): bool {
					return false;
				}
			},
		);
		yield 'fsync after permissions' => array(
			'temporary_permissions_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				private int $sync_calls = 0;

				protected function sync_handle( mixed $handle ): bool {
					++$this->sync_calls;

					return 1 === $this->sync_calls && parent::sync_handle( $handle );
				}
			},
		);
		yield 'temporary read-back' => array(
			'temporary_readback_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of read_back retains the production method contract; these inputs do not affect this controlled result.
				protected function read_back( string $path ): string|false {
					return false;
				}
			},
		);
		yield 'atomic rename' => array(
			'replace_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The fixture implementation of replace_path retains the production method contract; these inputs do not affect this controlled result.
				protected function replace_path( string $source, string $destination ): bool {
					return false;
				}
			},
		);
	}

	#[DataProvider( 'failure_writer_provider' )]
	public function test_failure_seams_leave_the_original_bytes_and_no_temporary_file(
		string $reason,
		callable $writer_factory
	): void {
		$original = file_get_contents( $this->config_path );
		$writer   = $writer_factory();
		$this->assert_refused(
			$reason,
			fn() => $writer->write( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $original, file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	#[DataProvider( 'failure_writer_provider' )]
	public function test_removal_failure_seams_leave_the_owned_definition_and_no_temporary_file(
		string $reason,
		callable $writer_factory
	): void {
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path );
		$original = file_get_contents( $this->config_path );
		$writer   = $writer_factory();
		$this->assert_refused(
			$reason,
			fn() => $writer->remove_owned_definition( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $original, file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_afailed_post_replacement_readback_restores_the_original_bytes(): void {
		$original = file_get_contents( $this->config_path );
		$writer   = new class() extends WpConfigSecretsPathWriter {
			private int $installed_reads = 0;

			protected function read_installed( string $path ): array {
				$snapshot = parent::read_installed( $path );
				if ( 0 === $this->installed_reads++ ) {
					$snapshot['contents'] .= '// mismatch';
				}

				return $snapshot;
			}
		};
		$this->assert_refused(
			'replacement_readback_failed',
			fn() => $writer->write( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $original, file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_afailed_removal_post_replacement_readback_restores_the_owned_definition(): void {
		( new WpConfigSecretsPathWriter() )->write( $this->config_path, $this->sidecar_path );
		$original = file_get_contents( $this->config_path );
		$writer   = new class() extends WpConfigSecretsPathWriter {
			private int $installed_reads = 0;

			protected function read_installed( string $path ): array {
				$snapshot = parent::read_installed( $path );
				if ( 0 === $this->installed_reads++ ) {
					$snapshot['contents'] .= '// mismatch';
				}

				return $snapshot;
			}
		};
		$this->assert_refused(
			'replacement_readback_failed',
			fn() => $writer->remove_owned_definition( $this->config_path, $this->sidecar_path )
		);
		self::assertSame( $original, file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	public function test_partial_temporary_writes_complete_and_preserve_metadata(): void {
		self::assertTrue( chmod( $this->config_path, 0640 ) );
		$owner  = fileowner( $this->config_path );
		$group  = filegroup( $this->config_path );
		$writer = new class() extends WpConfigSecretsPathWriter {
			protected function write_handle( mixed $handle, string $contents ): int|false {
				return parent::write_handle( $handle, substr( $contents, 0, 7 ) );
			}
		};

		$result = $writer->write( $this->config_path, $this->sidecar_path );

		self::assertTrue( $result->requires_next_request_verification() );
		self::assertSame( 0640, fileperms( $this->config_path ) & 0777 );
		self::assertSame( $owner, fileowner( $this->config_path ) );
		self::assertSame( $group, filegroup( $this->config_path ) );
		self::assertStringContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', (string) file_get_contents( $this->config_path ) );
		self::assertSame( array(), $this->temporary_files() );
	}

	private function valid_config(): string {
		return "<?php\n\ndefine( 'DB_NAME', 'example' );\n\n/* That's all, stop editing! Happy publishing. */\n";
	}

	private function write_config( string $contents ): void {
		self::assertNotFalse( file_put_contents( $this->config_path, $contents ) );
		self::assertTrue( chmod( $this->config_path, 0600 ) );
	}

	/**
	 * @param callable(): mixed $operation
	 */
	private function assert_refused( string $reason, callable $operation ): void {
		try {
			$operation();
			self::fail( 'Expected the automatic configuration edit to be refused.' );
		} catch ( WpConfigPathWriteException $exception ) {
			self::assertSame( $reason, $exception->reason() );
			self::assertDoesNotMatchRegularExpression( '#/[A-Za-z0-9_.-]+/#', $exception->getMessage() );
		}
	}

	/**
	 * @return list<string>
	 */
	private function temporary_files(): array {
		$files   = array();
		$entries = scandir( $this->directory );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( str_starts_with( $entry, '.ran-booster-wp-config-' ) ) {
				$files[] = $entry;
			}
		}

		return $files;
	}

	private function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->remove_tree( $path . '/' . $entry );
		}
		rmdir( $path );
	}
}
