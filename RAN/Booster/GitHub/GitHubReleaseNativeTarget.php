<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use Closure;
use LogicException;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargetStatus;
use Throwable;

final class GitHubReleaseNativeTarget implements RepositoryReleaseNativeTarget {
	private const PACKAGE_BOOTSTRAP = '/vendor/ran/wp-github-release-updater/bootstrap.php';

	private static ?Closure $packageFactory = null;

	private ?object $updater = null;

	/** @var array<string, mixed> */
	private array $options;

	private Closure $factory;

	/**
	 * @param string|callable|null $accessToken
	 * @param callable|null        $factory Test-only package factory override.
	 */
	public function __construct(
		string $packageType,
		string $metadataFile,
		string $repository,
		string $providerRepositoryId,
		string $packageRoot,
		string $installedIdentifier,
		string|callable|null $accessToken,
		string $channel,
		string $deploymentPolicy,
		?callable $factory = null
	) {
		if ( ! in_array( $packageType, array( 'plugin', 'theme' ), true )
			|| ! in_array( $channel, array( 'stable', 'prerelease' ), true ) ) {
			throw new LogicException( 'The GitHub release native target is incompatible.' );
		}
		$this->factory = self::factory( $factory );
		$this->options = array(
			'pluginFile'           => $metadataFile,
			'repository'           => $repository,
			'providerRepositoryId' => $providerRepositoryId,
			'pluginSlug'           => $packageRoot,
			'channel'              => $channel,
			'accessToken'          => $accessToken,
			'autoUpdatePolicy'     => $deploymentPolicy,
			'cacheDuration'        => 21_600,
			'failureCacheDuration' => 900,
			'targetType'           => $packageType,
		);
		if ( 'theme' === $packageType ) {
			$this->options['stylesheet'] = $installedIdentifier;
		}
	}

	public function register(): bool {
		if ( null !== $this->updater ) {
			return true;
		}
		$updater = ( $this->factory )( ...$this->options );
		if ( ! is_object( $updater )
			|| ! is_callable( array( $updater, 'register' ) )
			|| ! is_callable( array( $updater, 'diagnostics' ) )
			|| ! is_callable( array( $updater, 'refresh' ) ) ) {
			throw new LogicException( 'The GitHub release native target is incompatible.' );
		}
		$result = $updater->register();
		if ( false === $result ) {
			return false;
		}
		$this->updater = $updater;

		return true === ( $this->diagnostics()['registered'] ?? null );
	}

	public function status(): RepositoryReleaseNativeTargetStatus {
		$diagnostics = $this->diagnostics();
		$validation  = $diagnostics['candidate_validation'] ?? array();
		if ( ! is_array( $validation ) ) {
			throw new LogicException( 'The GitHub release native target status is incompatible.' );
		}
		$runtime = $diagnostics['state'] ?? null;
		$version = $diagnostics['selected_version'] ?? null;
		$active  = true === ( $diagnostics['registered'] ?? null )
			&& true === ( $diagnostics['selection_fixed'] ?? null )
			&& is_string( $version )
			&& '' !== $version
			&& is_string( $runtime )
			&& '' !== $runtime
			&& 'inactive' !== $runtime;

		return new RepositoryReleaseNativeTargetStatus(
			$active,
			$this->stringValue( $diagnostics, 'offered_version' ),
			$this->stringValue( $diagnostics, 'version_relationship' ),
			$this->timeValue( $diagnostics, 'last_check' ),
			$this->timeValue( $diagnostics, 'next_check' ),
			in_array( $runtime, array( 'error', 'failed' ), true )
				? $this->stringValue( $diagnostics, 'code' )
				: '',
			$this->stringValue( $validation, 'code' ),
			$this->stringValue( $validation, 'release_tag' ),
			$this->stringValue( $validation, 'release_version' ),
			$this->stringValue( $validation, 'package_header_version' ),
			$this->candidateReleaseId( $validation )
		);
	}

	public function refresh(): bool {
		if ( null === $this->updater ) {
			return false;
		}

		return true === $this->updater->refresh();
	}

	/** @return array<string, mixed> */
	private function diagnostics(): array {
		if ( null === $this->updater ) {
			throw new LogicException( 'The GitHub release native target is not registered.' );
		}
		$value = $this->updater->diagnostics();
		if ( ! is_array( $value ) ) {
			throw new LogicException( 'The GitHub release native target status is incompatible.' );
		}

		return $value;
	}

	/** @param array<string, mixed> $values */
	private function stringValue( array $values, string $key ): string {
		$value = $values[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}

	/** @param array<string, mixed> $values */
	private function timeValue( array $values, string $key ): ?int {
		$value = $values[ $key ] ?? null;

		return is_int( $value ) && $value > 0 ? $value : null;
	}

	/** @param array<string, mixed> $validation */
	private function candidateReleaseId( array $validation ): string {
		$identity  = $validation['identity'] ?? null;
		$releaseId = is_array( $identity ) ? $identity['release_id'] ?? null : null;

		return is_int( $releaseId ) && $releaseId > 0 ? (string) $releaseId : '';
	}

	private static function factory( ?callable $factory ): Closure {
		if ( null !== $factory ) {
			return Closure::fromCallable( $factory );
		}
		if ( null !== self::$packageFactory ) {
			return self::$packageFactory;
		}
		$bootstrap = dirname( __DIR__, 3 ) . self::PACKAGE_BOOTSTRAP;
		if ( ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			throw new LogicException( 'The GitHub release native target dependency is unavailable.' );
		}
		$loaded = require $bootstrap;
		if ( ! is_callable( $loaded ) ) {
			throw new LogicException( 'The GitHub release native target bootstrap is incompatible.' );
		}
		self::$packageFactory = Closure::fromCallable( $loaded );

		return self::$packageFactory;
	}
}
