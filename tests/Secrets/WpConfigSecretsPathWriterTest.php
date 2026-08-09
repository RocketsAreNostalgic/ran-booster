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
	private string $configPath;
	private string $sidecarPath;

	protected function setUp(): void {
		$this->directory   = sys_get_temp_dir() . '/ran-booster-wp-config-' . bin2hex( random_bytes( 8 ) );
		$this->configPath  = $this->directory . '/wp-config.php';
		$this->sidecarPath = $this->directory . '/private/secrets.json';

		self::assertTrue( mkdir( $this->directory, 0700 ) );
		$this->writeConfig( $this->validConfig() );
	}

	protected function tearDown(): void {
		$this->removeTree( $this->directory );
	}

	public function testItAddsTheFixedDefinitionAndRequiresASecondRequestVerification(): void {
		self::assertTrue( chmod( $this->configPath, 0640 ) );

		$result = ( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath );

		self::assertSame( WpConfigPathWriteResult::STATUS_PENDING_VERIFICATION, $result->status() );
		self::assertTrue( $result->requiresNextRequestVerification() );
		self::assertSame( 0640, fileperms( $this->configPath ) & 0777 );
		self::assertSame( fileowner( $this->configPath . '.ran-booster.lock' ), fileowner( $this->configPath ) );
		self::assertSame( 0600, fileperms( $this->configPath . '.ran-booster.lock' ) & 0777 );
		self::assertSame(
			"<?php\n\ndefine( 'DB_NAME', 'example' );\n\n"
			. "/* RAN Booster encrypted secrets storage. */\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->sidecarPath ) . "' );\n\n"
			. "/* That's all, stop editing! Happy publishing. */\n",
			file_get_contents( $this->configPath )
		);
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testItPreservesCrLfLineEndingsAndSafelyEncodesThePath(): void {
		$config  = str_replace( "\n", "\r\n", $this->validConfig() );
		$sidecar = $this->directory . "/private/agency's\\directory/secrets.json";
		$this->writeConfig( $config );

		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $sidecar );

		$written = file_get_contents( $this->configPath );
		self::assertIsString( $written );
		self::assertStringNotContainsString( "\n", str_replace( "\r\n", '', $written ) );
		self::assertStringContainsString( "agency\\'s\\\\directory' );\r\n", $written );
		token_get_all( $written, TOKEN_PARSE );
	}

	public function testARepeatedWriteRefusesTheExistingConstantWithoutChangingBytes(): void {
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->configPath, $this->sidecarPath );
		$written = file_get_contents( $this->configPath );

		$this->assertRefused(
			'constant_exists',
			fn() => $writer->write( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $written, file_get_contents( $this->configPath ) );
	}

	public function testSuccessfulWriteInvalidatesTheWpConfigOpcodeCache(): void {
		$writer = new class() extends WpConfigSecretsPathWriter {
			/** @var list<string> */
			public array $invalidated = array();

			protected function invalidateOpcodeCache( string $configPath ): void {
				$this->invalidated[] = $configPath;
			}
		};

		$writer->write( $this->configPath, $this->sidecarPath );

		self::assertSame( array( $this->configPath ), $writer->invalidated );
	}

	public function testItRemovesOnlyTheOwnedDefinitionAndPreservesSurroundingBytesAndMetadata(): void {
		self::assertTrue( chmod( $this->configPath, 0640 ) );
		$original = file_get_contents( $this->configPath );
		$owner    = fileowner( $this->configPath );
		$group    = filegroup( $this->configPath );
		$writer   = new WpConfigSecretsPathWriter();
		$writer->write( $this->configPath, $this->sidecarPath );

		self::assertTrue( $writer->removeOwnedDefinition( $this->configPath, $this->sidecarPath ) );
		self::assertSame( $original, file_get_contents( $this->configPath ) );
		self::assertSame( 0640, fileperms( $this->configPath ) & 0777 );
		self::assertSame( $owner, fileowner( $this->configPath ) );
		self::assertSame( $group, filegroup( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testItAtomicallyRetargetsOnlyTheOwnedDefinitionAndPreservesMetadata(): void {
		$replacement = $this->directory . '/private/previous/secrets.json';
		self::assertTrue( chmod( $this->configPath, 0640 ) );
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->configPath, $this->sidecarPath );
		$owner = fileowner( $this->configPath );
		$group = filegroup( $this->configPath );

		$result  = $writer->retargetOwnedDefinition( $this->configPath, $this->sidecarPath, $replacement );
		$written = (string) file_get_contents( $this->configPath );

		self::assertTrue( $result->requiresNextRequestVerification() );
		self::assertStringNotContainsString(
			"define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->sidecarPath ) . "' );",
			$written
		);
		self::assertStringContainsString( dirname( $replacement ), $written );
		self::assertSame( 0640, fileperms( $this->configPath ) & 0777 );
		self::assertSame( $owner, fileowner( $this->configPath ) );
		self::assertSame( $group, filegroup( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testRetargetLeavesManualDefinitionUntouched(): void {
		$config = "<?php\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '" . $this->sidecarPath . "' );\n"
			. "/* That's all, stop editing! Happy publishing. */\n";
		$this->writeConfig( $config );

		self::assertFalse(
			( new WpConfigSecretsPathWriter() )->retargetOwnedDefinition(
				$this->configPath,
				$this->sidecarPath,
				$this->directory . '/private/previous/secrets.json'
			)
		);
		self::assertSame( $config, file_get_contents( $this->configPath ) );
		self::assertFileDoesNotExist( $this->configPath . '.ran-booster.lock' );
	}

	public function testRemovalPreservesCrLfLineEndingsAndMatchesTheDecodedActivePath(): void {
		$config  = str_replace( "\n", "\r\n", $this->validConfig() );
		$sidecar = $this->directory . "/private/agency's\\secrets.json";
		$this->writeConfig( $config );
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->configPath, $sidecar );

		self::assertTrue( $writer->removeOwnedDefinition( $this->configPath, $sidecar ) );
		self::assertSame( $config, file_get_contents( $this->configPath ) );
	}

	public function testRemovalIsIdempotent(): void {
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->configPath, $this->sidecarPath );
		self::assertTrue( $writer->removeOwnedDefinition( $this->configPath, $this->sidecarPath ) );
		$removed = file_get_contents( $this->configPath );

		self::assertFalse( $writer->removeOwnedDefinition( $this->configPath, $this->sidecarPath ) );
		self::assertSame( $removed, file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testRemovalPreflightProvesTheExactDefinitionWithoutChangingBytes(): void {
		$writer = new WpConfigSecretsPathWriter();
		$writer->write( $this->configPath, $this->sidecarPath );
		$written = file_get_contents( $this->configPath );

		self::assertTrue(
			$writer->assertOwnedDefinitionRemovable( $this->configPath, $this->sidecarPath )
		);
		self::assertTrue( $writer->hasOwnedDefinition( $this->configPath, $this->sidecarPath ) );
		self::assertSame( $written, file_get_contents( $this->configPath ) );
	}

	public function testRemovalLeavesAManualMatchingDefinitionUntouched(): void {
		$config = "<?php\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '" . $this->sidecarPath . "' );\n"
			. "/* That's all, stop editing! Happy publishing. */\n";
		$this->writeConfig( $config );
		self::assertTrue( chmod( $this->configPath, 0400 ) );

		self::assertFalse(
			( new WpConfigSecretsPathWriter() )->removeOwnedDefinition( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $config, file_get_contents( $this->configPath ) );
		self::assertFileDoesNotExist( $this->configPath . '.ran-booster.lock' );
	}

	public function testRemovalLeavesAMismatchedOwnedDefinitionUntouched(): void {
		$config = "<?php\n"
			. "/* RAN Booster encrypted secrets storage. */\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', '/manually/changed/secrets.json' );\n\n"
			. "/* That's all, stop editing! Happy publishing. */\n";
		$this->writeConfig( $config );

		self::assertFalse(
			( new WpConfigSecretsPathWriter() )->removeOwnedDefinition( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $config, file_get_contents( $this->configPath ) );
		self::assertFileDoesNotExist( $this->configPath . '.ran-booster.lock' );
	}

	public function testRemovalRefusesDuplicateMatchingOwnedDefinitions(): void {
		$block  = "/* RAN Booster encrypted secrets storage. */\n"
			. "define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', '" . dirname( $this->sidecarPath ) . "' );\n\n";
		$config = "<?php\n" . $block . $block . "/* That's all, stop editing! Happy publishing. */\n";
		$this->writeConfig( $config );

		$this->assertRefused(
			'owned_definition_ambiguous',
			fn() => ( new WpConfigSecretsPathWriter() )->removeOwnedDefinition(
				$this->configPath,
				$this->sidecarPath
			)
		);
		self::assertSame( $config, file_get_contents( $this->configPath ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function unsafePathProvider(): iterable {
		yield 'relative config' => array( 'wp-config.php', '/private/secrets.json' );
		yield 'relative sidecar' => array( '/tmp/wp-config.php', 'private/secrets.json' );
		yield 'parent segment' => array( '/tmp/wp-config.php', '/private/../secrets.json' );
		yield 'current segment' => array( '/tmp/wp-config.php', '/private/./secrets.json' );
		yield 'duplicate separator' => array( '/tmp/wp-config.php', '/private//secrets.json' );
		yield 'line break' => array( '/tmp/wp-config.php', "/private/secrets\n.json" );
		yield 'directory value' => array( '/tmp/wp-config.php', '/private/' );
	}

	#[DataProvider( 'unsafePathProvider' )]
	public function testItRefusesUnsafePaths( string $configPath, string $sidecarPath ): void {
		$expected = $configPath === '/tmp/wp-config.php' ? 'sidecar_path_invalid' : 'config_path_invalid';
		$this->assertRefused(
			$expected,
			static fn() => ( new WpConfigSecretsPathWriter() )->write( $configPath, $sidecarPath )
		);
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalidConfigProvider(): iterable {
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

	#[DataProvider( 'invalidConfigProvider' )]
	public function testItRefusesAmbiguousMalformedOrPreviouslyConfiguredFiles( string $config, string $reason ): void {
		$this->writeConfig( $config );
		$original = file_get_contents( $this->configPath );
		$this->assertRefused(
			$reason,
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $original, file_get_contents( $this->configPath ) );
	}

	public function testItRefusesASymlinkedConfig(): void {
		$target = $this->directory . '/actual-config.php';
		self::assertTrue( rename( $this->configPath, $target ) );
		self::assertTrue( symlink( $target, $this->configPath ) );
		$this->assertRefused(
			'config_file_invalid',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath )
		);
	}

	public function testItRefusesAMultiplyLinkedConfig(): void {
		$link = $this->directory . '/config-copy.php';
		self::assertTrue( link( $this->configPath, $link ) );
		$this->assertRefused(
			'config_file_invalid',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath )
		);
	}

	public function testItRefusesAGroupWritableConfig(): void {
		self::assertTrue( chmod( $this->configPath, 0660 ) );
		$this->assertRefused(
			'config_permissions_unsafe',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath )
		);
	}

	public function testItRefusesAnOversizedConfig(): void {
		$this->writeConfig(
			"<?php\n/* " . str_repeat( 'x', 1048576 ) . " */\n/* That's all, stop editing! Happy publishing. */\n"
		);
		$this->assertRefused(
			'config_size_unsupported',
			fn() => ( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath )
		);
	}

	public function testItDetectsAConcurrentConfigChangeBeforeReplacement(): void {
		$original = file_get_contents( $this->configPath );
		$writer   = new class() extends WpConfigSecretsPathWriter {
			protected function beforeFinalConfigCheck( string $configPath ): void {
				file_put_contents( $configPath, "\n// concurrent edit\n", FILE_APPEND );
			}
		};
		$this->assertRefused(
			'config_changed',
			fn() => $writer->write( $this->configPath, $this->sidecarPath )
		);
		self::assertNotSame( $original, file_get_contents( $this->configPath ) );
		self::assertStringNotContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', file_get_contents( $this->configPath ) );
	}

	public function testRemovalDetectsAConcurrentConfigChangeBeforeReplacement(): void {
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath );
		$writer = new class() extends WpConfigSecretsPathWriter {
			protected function beforeFinalConfigCheck( string $configPath ): void {
				file_put_contents( $configPath, "\n// concurrent edit\n", FILE_APPEND );
			}
		};
		$this->assertRefused(
			'config_changed',
			fn() => $writer->removeOwnedDefinition( $this->configPath, $this->sidecarPath )
		);
		self::assertStringContainsString(
			'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR',
			(string) file_get_contents( $this->configPath )
		);
		self::assertStringContainsString( '// concurrent edit', (string) file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	/**
	 * @return iterable<string, array{string, callable(): WpConfigSecretsPathWriter}>
	 */
	public static function failureWriterProvider(): iterable {
		yield 'lock' => array(
			'lock_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function acquireLock( mixed $lock ): bool {
					return false;
				}
			},
		);
		yield 'temporary creation' => array(
			'temporary_create_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function createTemporary( string $directory ): array {
					throw new WpConfigPathWriteException( 'temporary_create_failed', 'Test temporary creation failure.' );
				}
			},
		);
		yield 'write' => array(
			'temporary_write_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function writeHandle( mixed $handle, string $contents ): int|false {
					return false;
				}
			},
		);
		yield 'flush' => array(
			'temporary_flush_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function flushHandle( mixed $handle ): bool {
					return false;
				}
			},
		);
		yield 'fsync' => array(
			'temporary_sync_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function syncHandle( mixed $handle ): bool {
					return false;
				}
			},
		);
		yield 'temporary read-back' => array(
			'temporary_readback_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function readBack( string $path ): string|false {
					return false;
				}
			},
		);
		yield 'atomic rename' => array(
			'replace_failed',
			static fn(): WpConfigSecretsPathWriter => new class() extends WpConfigSecretsPathWriter {
				protected function replacePath( string $source, string $destination ): bool {
					return false;
				}
			},
		);
	}

	#[DataProvider( 'failureWriterProvider' )]
	public function testFailureSeamsLeaveTheOriginalBytesAndNoTemporaryFile(
		string $reason,
		callable $writerFactory
	): void {
		$original = file_get_contents( $this->configPath );
		$writer   = $writerFactory();
		$this->assertRefused(
			$reason,
			fn() => $writer->write( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $original, file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	#[DataProvider( 'failureWriterProvider' )]
	public function testRemovalFailureSeamsLeaveTheOwnedDefinitionAndNoTemporaryFile(
		string $reason,
		callable $writerFactory
	): void {
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath );
		$original = file_get_contents( $this->configPath );
		$writer   = $writerFactory();
		$this->assertRefused(
			$reason,
			fn() => $writer->removeOwnedDefinition( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $original, file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testAFailedPostReplacementReadbackRestoresTheOriginalBytes(): void {
		$original = file_get_contents( $this->configPath );
		$writer   = new class() extends WpConfigSecretsPathWriter {
			private int $installedReads = 0;

			protected function readInstalled( string $path ): array {
				$snapshot = parent::readInstalled( $path );
				if ( 0 === $this->installedReads++ ) {
					$snapshot['contents'] .= '// mismatch';
				}

				return $snapshot;
			}
		};
		$this->assertRefused(
			'replacement_readback_failed',
			fn() => $writer->write( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $original, file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testAFailedRemovalPostReplacementReadbackRestoresTheOwnedDefinition(): void {
		( new WpConfigSecretsPathWriter() )->write( $this->configPath, $this->sidecarPath );
		$original = file_get_contents( $this->configPath );
		$writer   = new class() extends WpConfigSecretsPathWriter {
			private int $installedReads = 0;

			protected function readInstalled( string $path ): array {
				$snapshot = parent::readInstalled( $path );
				if ( 0 === $this->installedReads++ ) {
					$snapshot['contents'] .= '// mismatch';
				}

				return $snapshot;
			}
		};
		$this->assertRefused(
			'replacement_readback_failed',
			fn() => $writer->removeOwnedDefinition( $this->configPath, $this->sidecarPath )
		);
		self::assertSame( $original, file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	public function testPartialTemporaryWritesCompleteAndPreserveMetadata(): void {
		self::assertTrue( chmod( $this->configPath, 0640 ) );
		$owner  = fileowner( $this->configPath );
		$group  = filegroup( $this->configPath );
		$writer = new class() extends WpConfigSecretsPathWriter {
			protected function writeHandle( mixed $handle, string $contents ): int|false {
				return parent::writeHandle( $handle, substr( $contents, 0, 7 ) );
			}
		};

		$result = $writer->write( $this->configPath, $this->sidecarPath );

		self::assertTrue( $result->requiresNextRequestVerification() );
		self::assertSame( 0640, fileperms( $this->configPath ) & 0777 );
		self::assertSame( $owner, fileowner( $this->configPath ) );
		self::assertSame( $group, filegroup( $this->configPath ) );
		self::assertStringContainsString( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', (string) file_get_contents( $this->configPath ) );
		self::assertSame( array(), $this->temporaryFiles() );
	}

	private function validConfig(): string {
		return "<?php\n\ndefine( 'DB_NAME', 'example' );\n\n/* That's all, stop editing! Happy publishing. */\n";
	}

	private function writeConfig( string $contents ): void {
		self::assertNotFalse( file_put_contents( $this->configPath, $contents ) );
		self::assertTrue( chmod( $this->configPath, 0600 ) );
	}

	/**
	 * @param callable(): mixed $operation
	 */
	private function assertRefused( string $reason, callable $operation ): void {
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
	private function temporaryFiles(): array {
		$files   = array();
		$entries = scandir( $this->directory );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( str_starts_with( $entry, '.ran-booster-wp-config-' ) ) {
				$files[] = $entry;
			}
		}

		return $files;
	}

	private function removeTree( string $path ): void {
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
			$this->removeTree( $path . '/' . $entry );
		}
		rmdir( $path );
	}
}
