<?php

declare(strict_types=1);

namespace RAN\Tests\Quality;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TypeContractRegressionTest extends TestCase {

	public function test_real_checker_preserves_callback_document_and_presenter_contracts(): void {
		$root      = dirname( __DIR__, 2 );
		$directory = sys_get_temp_dir() . '/ran-type-contracts-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Allocate only the private analyzer fixture and cache directory.
		self::assertTrue( mkdir( $directory, 0700 ) );
		try {
			$fixture = $directory . '/consumer.php';
			$config  = $directory . '/phpstan.neon';
			$source  = $this->consumer_source();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert consumer code; the checker parses it but never executes it.
			self::assertSame( strlen( $source ), file_put_contents( $fixture, $source ) );
			$settings = "includes:\n\t- '" . $root . "/phpstan.neon'\nparameters:\n\tlevel: 9\n\ttmpDir: '" . $directory . "/cache'\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Inherit the real production profile, changing only diagnostic level and disposable cache location.
			self::assertSame( strlen( $settings ), file_put_contents( $config, $settings ) );

			[ $exit, $output, $error ] = $this->analyse( $root, $config, $fixture );
			self::assertSame( 1, $exit, $output . $error );
			$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
			self::assertIsArray( $report );
			self::assertSame( array(), $report['errors'] );
			self::assertIsArray( $report['files'] );
			self::assertSame( array( $fixture ), array_keys( $report['files'] ) );
			$file = $report['files'][ $fixture ];
			self::assertIsArray( $file );
			self::assertIsArray( $file['messages'] );
			$actual = array();
			foreach ( $file['messages'] as $message ) {
				self::assertIsArray( $message );
				self::assertIsInt( $message['line'] );
				self::assertIsString( $message['identifier'] );
				$actual[] = array( $message['line'], $message['identifier'] );
			}
			$expected = array();
			foreach ( explode( "\n", $source ) as $index => $line ) {
				if ( 1 === preg_match( '/\/\/ expect: ([a-zA-Z.]+)$/', $line, $match ) ) {
					$expected[] = array( $index + 1, $match[1] );
				}
			}
			self::assertNotEmpty( $expected, 'Negative consumers must prove the checker is enforcing each boundary.' );
			self::assertSame( $expected, $actual, $output );
		} finally {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test's generated fixture and analyzer cache.
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the empty uniquely allocated fixture root.
			rmdir( $directory );
		}
	}

	/** @return array{int, string, string} */
	private function analyse( string $root, string $config, string $fixture ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Execute only the locked local analyzer; fixture PHP is never executed.
		$process = proc_open(
			array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--memory-limit=512M', '--error-format=json', $fixture ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close this test's analyzer stdout pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close this test's analyzer stderr pipe.
		fclose( $pipes[2] );
		return array( proc_close( $process ), $output, $error );
	}

	private function consumer_source(): string {
		return <<<'PHP'
<?php
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentAttempt;
use RAN\Secrets\SecretsFile;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Admin\PackagePagePresenter;
use RAN\Package;

/** @return list<Closure|null> */
function ran_private_contracts(): array {
    return [
        Closure::bind(static function (DeploymentAttemptRepository $repository): int {
            return $repository->transaction(static fn(): int => 1);
        }, null, DeploymentAttemptRepository::class),
        Closure::bind(static function (DeploymentAttemptRepository $repository, DeploymentAttempt $attempt): DeploymentAttempt {
            return $repository->transaction(static fn(): DeploymentAttempt => $attempt);
        }, null, DeploymentAttemptRepository::class),
        Closure::bind(static function (DeploymentAttemptRepository $repository, DeploymentAttempt $attempt): DeploymentAttempt {
            return $repository->transaction(static fn(): array => [$attempt])[0];
        }, null, DeploymentAttemptRepository::class),
        Closure::bind(static function (DeploymentAttemptRepository $repository, ?DeploymentAttempt $attempt): ?DeploymentAttempt {
            return $repository->transaction(static fn(): ?DeploymentAttempt => $attempt);
        }, null, DeploymentAttemptRepository::class),
        Closure::bind(static function (SecretsFile $secrets): string {
            return $secrets->mutate(static fn(array $document): array => [$document, 'saved']);
        }, null, SecretsFile::class),
        Closure::bind(static function (SecretsFile $secrets): int {
            return $secrets->mutate(static fn(array $document): array => [$document, strlen($document['credentials']['gh']['fixture']['label'])]);
        }, null, SecretsFile::class),
        Closure::bind(static function (SecretsFile $secrets): false|null {
            return $secrets->mutate(static fn(array $document): array => [$document, null, false]);
        }, null, SecretsFile::class),
        Closure::bind(static function (SecretsFile $secrets): string {
            return implode(',', $secrets->mutate(static fn(array $document): array => [$document, ['saved']]));
        }, null, SecretsFile::class),
        Closure::bind(static function (SecretsFile $secrets): int {
            $document = $secrets->file_document();
            $length = $document['schema_version'];
            foreach ($document['credentials'] as $records) {
                foreach ($records as $record) { $length += strlen($record['secret']); }
            }
            foreach ($document['webhooks'] as $records) {
                foreach ($records as $record) { $length += strlen($record['authority_id']) + $record['revision']; }
            }
            $validated = $secrets->validate_document([123 => ['untrusted' => false]]);
            return $length + $validated['schema_version'] + strlen($validated['credentials']['gh']['fixture']['secret'])
                + strlen($validated['webhooks']['gh']['fixture']['authority_id']) + $validated['webhooks']['gh']['fixture']['revision'];
        }, null, SecretsFile::class),
    ];
}

function ran_profile_contract(SecretsFile $secrets): int {
    $length = 0;
    foreach ($secrets->credential_profiles('gh') as $profile) {
        $length += strlen($profile['label']) + strlen($profile['kind']);
        if ($profile['immutable']) { ++$length; }
    }
    return $length;
}

function ran_presenter_contract(ProviderSettingsPresenter $settings, PackagePagePresenter $pages, Package $package): int {
    $create = $pages->create($settings->build_package_form(), false, false, 'branch');
    $edit = $pages->edit($package, $settings->build_existing_package_form('temporarily-offline'), null, 'branch');
    $unavailable = $pages->unavailable_create($settings->build_package_form(), false);
    $length = 0;
    foreach ([$create['package_provider_settings'], $edit['package_provider_settings'], $unavailable['package_provider_settings']] as $payload) {
        foreach ($payload['providers'] as $provider) {
            foreach ($provider['credential_profiles'] as $profile) {
                $length += strlen($profile['id']) + strlen($profile['label']) + strlen($profile['kind_label']) + strlen($profile['detail']);
            }
            foreach ($provider['credential_kind_labels'] ?? [] as $label) { $length += strlen($label); }
        }
    }
    return $length;
}

/** @return list<Closure|null> */
function ran_wrong_results(): array {
    return [
        Closure::bind(static function (DeploymentAttemptRepository $repository): string {
            return $repository->transaction(static fn(): int => 1); // expect: return.type
        }, null, DeploymentAttemptRepository::class),
        Closure::bind(static function (SecretsFile $secrets): int {
            return $secrets->mutate(static fn(array $document): array => [$document, 'saved']); // expect: return.type
        }, null, SecretsFile::class),
        Closure::bind(static function (SecretsFile $secrets): string {
            return $secrets->mutate(static fn(array $document): array => [['schema_version' => 2, 'credentials' => [], 'webhooks' => false], 'saved']); // expect: argument.type
        }, null, SecretsFile::class),
        Closure::bind(static function (SecretsFile $secrets): int {
            return $secrets->file_document()['credentials']; // expect: return.type
        }, null, SecretsFile::class),
    ];
}

function ran_wrong_profile(SecretsFile $secrets): int {
    return $secrets->credential_profiles('gh')['fixture']['label']; // expect: return.type
}

function ran_opaque_configuration(SecretsFile $secrets): int {
    return strlen($secrets->credential_profiles('gh')['fixture']['configuration']['untrusted']); // expect: argument.type
}

/** @return array<string, string> */
function ran_unavailable_provider(ProviderSettingsPresenter $settings): array {
    foreach ($settings->build_existing_package_form('temporarily-offline')['providers'] as $provider) {
        return $provider['credential_kind_labels']; // expect: offsetAccess.notFound
    }
    return [];
}
PHP;
	}
}
