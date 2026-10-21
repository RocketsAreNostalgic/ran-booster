<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

require_once __DIR__ . '/AuthenticatedPreparedArchiveWordPressFunctions.php';

use Closure;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use RuntimeException;

final class AuthenticatedPreparedArchiveTest extends TestCase {

	private const REF = '0123456789abcdef0123456789abcdef01234567';

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		parent::setUp();

		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		\RAN\RepositoryProvider\authenticated_archive_hooks_reset();

		parent::tearDown();
	}

	public function test_distinct_same_and_cross_provider_archives_coexist_without_cross_authentication(): void {
		$github_one = new AuthenticatedPreparedArchive(
			'https://api.github.com/repos/example/plugin/zipball/' . self::REF,
			self::REF,
			$this->authorizer( 'Bearer github-one-canary' )
		);
		$github_two = new AuthenticatedPreparedArchive(
			'https://api.github.com/repos/example/theme/zipball/' . self::REF,
			self::REF,
			$this->authorizer( 'Bearer github-two-canary' )
		);
		$bitbucket  = new AuthenticatedPreparedArchive(
			'https://bitbucket.org/example/plugin/get/' . self::REF . '.zip',
			self::REF,
			$this->authorizer( 'Basic bitbucket-canary' )
		);

		self::assertCount( 3, $this->filters() );
		self::assertCount( 3, $this->actions() );

		$arguments = $this->apply_filters( $github_one->get_url() );
		self::assertSame( 'Bearer github-one-canary', $arguments['headers']['Authorization'] );
		self::assertCount( 2, $this->filters() );

		$arguments = $this->apply_filters( $github_two->get_url() );
		self::assertSame( 'Bearer github-two-canary', $arguments['headers']['Authorization'] );
		self::assertCount( 1, $this->filters() );

		$arguments = $this->apply_filters( $bitbucket->get_url() );
		self::assertSame( 'Basic bitbucket-canary', $arguments['headers']['Authorization'] );
		self::assertSame( array(), $this->filters() );
		self::assertCount( 3, $this->actions() );

		$github_one->cleanup();
		$github_two->cleanup();
		$bitbucket->cleanup();
		$this->assert_no_hooks();
	}

	public function test_public_archive_reserves_and_releases_its_exact_url(): void {
		$url    = 'https://archives.example.test/public.zip';
		$public = new AuthenticatedPreparedArchive( $url, self::REF );

		$this->expectExceptionMessage( 'already prepared' );
		try {
			new AuthenticatedPreparedArchive( $url, self::REF, $this->authorizer( 'Bearer private-canary' ) );
		} finally {
			$public->cleanup();
		}
	}

	public function test_released_public_url_can_be_prepared_again(): void {
		$url    = 'https://archives.example.test/reusable.zip';
		$public = new AuthenticatedPreparedArchive( $url, self::REF );
		$public->cleanup();
		$private = new AuthenticatedPreparedArchive( $url, self::REF, $this->authorizer( 'Bearer private-canary' ) );

		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );

		$private->cleanup();
		$this->assert_no_hooks();
	}

	public function test_clone_cannot_create_or_release_asecond_cleanup_authority(): void {
		$original = $this->private_archive( $this->authorizer( 'Bearer original-canary' ), '-clone' );

		try {
			clone $original; // @phpstan-ignore expr.resultUnused (Executing the private clone hook must throw; the result is intentionally unused.)
			self::fail( 'Prepared archives must not be cloneable.' );
		} catch ( \Error $error ) { // @phpstan-ignore catch.neverThrown (PHP throws Error for the private clone hook; PHPStan does not model that runtime rejection.)
			self::assertStringNotContainsString( 'original-canary', $error->getMessage() );
		}

		self::assertCount( 1, $this->filters() ); // @phpstan-ignore deadCode.unreachable (The private-clone Error is caught above and cleanup authority must remain intact.)
		self::assertCount( 1, $this->actions() );
		$this->assert_duplicate_url_rejected( $original->get_url() );
		self::assertCount( 1, $this->filters() );
		self::assertCount( 1, $this->actions() );

		$original->cleanup();
		$replacement = new AuthenticatedPreparedArchive( $original->get_url(), self::REF );
		$replacement->cleanup();
		$this->assert_no_hooks();
	}

	public function test_serialized_public_copy_cannot_release_its_owners_reservation(): void {
		$url   = 'https://archives.example.test/serialized-public.zip';
		$owner = new AuthenticatedPreparedArchive( $url, self::REF );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Exercise an adversarial copy of the runtime value.
		$serialized = serialize( $owner );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The allowlist contains only the value under test.
		$copy = unserialize( $serialized, array( 'allowed_classes' => array( AuthenticatedPreparedArchive::class ) ) );

		self::assertInstanceOf( AuthenticatedPreparedArchive::class, $copy );
		$copy->cleanup();
		$this->assert_duplicate_url_rejected( $url );
		$this->assert_no_hooks();

		$owner->cleanup();
		$replacement = new AuthenticatedPreparedArchive( $url, self::REF );
		$replacement->cleanup();
		$this->assert_no_hooks();
	}

	public function test_pre_existing_mixed_case_authorization_fails_closed_and_cleans_explicitly(): void {
		$archive  = $this->private_archive( $this->authorizer( 'Bearer archive-canary' ) );
		$callback = $this->filters()[0]['callback'];

		try {
			$callback( array( 'headers' => array( 'aUtHoRiZaTiOn' => 'foreign-secret-canary' ) ), $archive->get_url() );
			self::fail( 'An inherited authorization header must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'foreign-secret-canary', $exception->getMessage() );
			self::assertStringNotContainsString( 'archive-canary', $exception->getMessage() );
		}

		self::assertSame( array(), $this->filters() );
		self::assertCount( 1, $this->actions() );
		$archive->cleanup();
		$this->assert_no_hooks();
	}

	public function test_throwing_and_malformed_authorizers_expose_only_fixed_failures(): void {
		$authorizers = array(
			static function (): never {
				throw new RuntimeException( 'authorizer-secret-canary' );
			},
			static fn (): string => 'malformed-secret-canary',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The archive authorizer accepts request arguments; this negative fixture deliberately returns malformed authorization headers.
			static fn ( array $arguments ): array => array(
				'headers' => array(
					'Authorization' => 'Bearer first-secret-canary',
					'authorization' => 'Bearer second-secret-canary',
				),
			),
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The archive authorizer accepts request arguments; this negative fixture deliberately returns malformed authorization headers.
			static fn ( array $arguments ): array => array( 'headers' => array( 'Authorization' => array( 'invalid-secret-canary' ) ) ),
		);

		foreach ( $authorizers as $index => $authorizer ) {
			$archive  = $this->private_archive( Closure::fromCallable( $authorizer ), '-' . $index );
			$callback = $this->filters()[0]['callback'];

			try {
				$callback( array( 'headers' => array() ), $archive->get_url() );
				self::fail( 'Unsafe authorizer output must be rejected.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame( 'Provider archive authentication could not be applied safely.', $exception->getMessage() );
				self::assertStringNotContainsString( 'secret-canary', $exception->getMessage() );
			}

			self::assertSame( array(), $this->filters() );
			self::assertCount( 1, $this->actions() );
			$archive->cleanup();
			$this->assert_no_hooks();
		}
	}

	public function test_cleanup_is_idempotent_and_verifier_survives_it(): void {
		$verified = 0;
		$archive  = new AuthenticatedPreparedArchive(
			'https://archives.example.test/verifier.zip',
			self::REF,
			$this->authorizer( 'Bearer verifier-canary' ),
			static function () use ( &$verified ): void {
				++$verified;
			}
		);
		$callback = $this->filters()[0]['callback'];

		$archive->cleanup();
		$archive->cleanup();
		$archive->verify_current_head();

		self::assertSame( 1, $verified );
		$this->assert_no_hooks();

		try {
			$callback( array( 'headers' => array() ), $archive->get_url() );
			self::fail( 'Cleaned authentication must remain unavailable.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringNotContainsString( 'verifier-canary', $exception->getMessage() );
		}
	}

	public function test_cleanup_closes_guarded_archive_resources_but_retains_fresh_head_authority(): void {
		$allowed       = true;
		$verifications = 0;
		$archive       = new AuthenticatedPreparedArchive(
			'https://archives.example.test/guarded-cleanup-verifier.zip',
			self::REF,
			$this->authorizer( 'Bearer guarded-cleanup-canary' ),
			static function () use ( &$allowed, &$verifications ): void {
				++$verifications;
				$allowed = 1 === $verifications;
			},
			static function () use ( &$allowed ): bool {
				return $allowed;
			}
		);
		$archive->cleanup();
		$this->assert_no_hooks();

		$this->assert_operation_refused( fn () => $archive->get_url() );
		$this->assert_operation_refused( fn () => $archive->get_resolved_ref() );
		$archive->verify_current_head();
		self::assertSame( 1, $verifications );

		$allowed = false;
		$this->assert_operation_refused( fn () => $archive->verify_current_head() );
		self::assertSame( 1, $verifications );

		$allowed = true;
		$this->assert_operation_refused( fn () => $archive->verify_current_head() );
		self::assertSame( 2, $verifications );
	}

	public function test_anonymous_operation_guard_survives_repeated_http_and_never_touches_foreign_urls(): void {
		$allowed = true;
		$calls   = 0;
		$url     = 'https://archives.example.test/guarded-public.zip';
		$archive = new AuthenticatedPreparedArchive(
			$url,
			self::REF,
			operation_guard: static function () use ( &$allowed, &$calls ): bool {
				++$calls;
				return $allowed;
			}
		);
		self::assertSame( $url, $archive->get_url() );
		self::assertSame( self::REF, $archive->get_resolved_ref() );
		self::assertSame( array( 'headers' => array() ), $this->apply_filters( $url ) );
		$before = $calls;
		$this->apply_filters( $url );
		self::assertSame( $before + 1, $calls );
		self::assertCount( 1, $this->filters() );
		$allowed = false;
		$before  = $calls;
		self::assertSame( array( 'headers' => array() ), $this->apply_filters( 'https://other.example.test/archive.zip' ) );
		self::assertSame( $before, $calls );
		foreach ( array( fn () => $this->apply_filters( $url ), fn () => $archive->get_url(), fn () => $archive->get_resolved_ref(), fn () => $archive->verify_current_head() ) as $operation ) {
			$this->assert_operation_refused( $operation );
		}
		$before = $calls;
		$archive->cleanup();
		$archive->cleanup();
		self::assertSame( $before, $calls );
		$this->assert_no_hooks();
		$allowed = true;
		$this->assert_operation_refused( fn () => $archive->get_url() );
		$replacement = new AuthenticatedPreparedArchive( $url, self::REF );
		$replacement->cleanup();
	}

	public function test_private_guard_preserves_one_request_authentication_and_rechecks_authorizer_drift(): void {
		foreach ( array( false, true ) as $drift ) {
			$allowed        = true;
			$authorizations = 0;
			$url            = 'https://archives.example.test/guarded-private-' . (int) $drift . '.zip';
			$archive        = new AuthenticatedPreparedArchive(
				$url,
				self::REF,
				static function ( array $arguments ) use ( &$allowed, &$authorizations, $drift ): array {
					++$authorizations;
					$arguments['headers']['Authorization'] = 'Bearer guarded-test-only';
					$allowed                               = ! $drift;
					return $arguments;
				},
				operation_guard: static function () use ( &$allowed ): bool {
					return $allowed;
				}
			);
			if ( $drift ) {
				$this->assert_operation_refused( fn () => $this->apply_filters( $url ) );
			} else {
				self::assertSame( 'Bearer guarded-test-only', $this->apply_filters( $url )['headers']['Authorization'] );
				self::assertSame( array( 'headers' => array() ), $this->apply_filters( $url ) );
			}
			self::assertSame( 1, $authorizations );
			self::assertCount( 1, $this->filters() );
			$allowed = false;
			$this->assert_operation_refused( fn () => $this->apply_filters( $url ) );
			$archive->cleanup();
			$this->assert_no_hooks();
		}
	}

	public function test_guard_refuses_before_authorizer_and_after_head_verifier(): void {
		$allowed        = false;
		$authorizations = 0;
		$verifications  = 0;
		$url            = 'https://archives.example.test/guarded-head.zip';
		$archive        = new AuthenticatedPreparedArchive(
			$url,
			self::REF,
			static function ( array $arguments ) use ( &$authorizations ): array {
				++$authorizations;
				return $arguments; },
			static function () use ( &$allowed, &$verifications ): void {
				++$verifications;
				$allowed = false; },
			static function () use ( &$allowed ): bool {
				return $allowed; }
		);
		$this->assert_operation_refused( fn () => $this->apply_filters( $url ) );
		$this->assert_operation_refused( fn () => $archive->verify_current_head() );
		self::assertSame( 0, $authorizations );
		self::assertSame( 0, $verifications );
		$allowed = true;
		$this->assert_operation_refused( fn () => $archive->verify_current_head() );
		self::assertSame( 1, $verifications );
		$archive->cleanup();
	}

	public function test_throwing_and_nonboolean_guards_only_expose_fixed_refusals(): void {
		foreach ( array( static fn (): int => 1, static fn (): string => 'true', static fn (): mixed => null, static fn (): never => throw new RuntimeException( 'secret-canary' ) ) as $index => $guard ) {
			$url     = 'https://archives.example.test/invalid-guard-' . $index . '.zip';
			$archive = new AuthenticatedPreparedArchive( $url, self::REF, operation_guard: $guard );
			$this->assert_operation_refused( fn () => $archive->get_url() );
			$this->assert_operation_refused( fn () => $this->apply_filters( $url ) );
			$archive->cleanup();
			$this->assert_no_hooks();
		}
	}

	public function test_final_http_guard_catches_drift_from_an_intervening_argument_filter(): void {
		$allowed = true;
		$url     = 'https://archives.example.test/late-filter.zip';
		$archive = new AuthenticatedPreparedArchive(
			$url,
			self::REF,
			operation_guard: static function () use ( &$allowed ): bool {
				return $allowed;
			}
		);
		\RAN\RepositoryProvider\add_filter(
			'http_request_args',
			static function ( array $arguments ) use ( &$allowed ): array {
				$allowed = false;
				return $arguments;
			},
			20
		);
		$this->assert_operation_refused( fn () => $this->apply_filters( $url ) );
		$archive->cleanup();
	}

	public function test_guarded_redirects_follow_only_the_owned_requests_chain_and_refuse_later_drift(): void {
		foreach ( array( false, true ) as $private ) {
			$allowed  = true;
			$url      = 'https://archives.example.test/redirect-guard-' . (int) $private . '.zip';
			$archive  = new AuthenticatedPreparedArchive(
				$url,
				self::REF,
				$private ? $this->authorizer( 'Bearer redirect-canary' ) : null,
				operation_guard: static function () use ( &$allowed ): bool {
					return $allowed;
				}
			);
			$hooks    = new \stdClass();
			$location = 'https://cdn.example.test/first';
			$headers  = array(
				'Authorization' => 'Bearer redirect-canary',
				'Accept'        => 'application/zip',
			);
			$callback = $this->actions()[0]['callback'];
			$callback( $location, $headers, null, array( 'hooks' => $hooks ), (object) array( 'url' => $url ) );
			self::assertSame( array( 'Accept' => 'application/zip' ), $headers );
			$callback( $location, $headers, null, array( 'hooks' => $hooks ), (object) array( 'url' => 'https://cdn.example.test/first' ) );
			$allowed         = false;
			$foreign_headers = array( 'Authorization' => 'Bearer foreign-canary' );
			$callback( $location, $foreign_headers, null, array( 'hooks' => new \stdClass() ), (object) array( 'url' => 'https://cdn.example.test/first' ) );
			self::assertSame( array( 'Authorization' => 'Bearer foreign-canary' ), $foreign_headers );
			$this->assert_operation_refused(
				static function () use ( $callback, &$location, &$headers, $hooks ): void {
					$callback( $location, $headers, null, array( 'hooks' => $hooks ), (object) array( 'url' => 'https://cdn.example.test/second' ) );
				}
			);
			$archive->cleanup();
			$this->assert_no_hooks();
		}
	}

	private function assert_operation_refused( Closure $operation ): void {
		try {
			$operation();
			self::fail( 'Unavailable archive operation proceeded.' );
		} catch ( RuntimeException $failure ) {
			self::assertSame( 'Provider archive operation is unavailable.', $failure->getMessage() );
		}
	}

	private function private_archive( Closure $authorizer, string $suffix = '' ): AuthenticatedPreparedArchive {
		return new AuthenticatedPreparedArchive(
			'https://archives.example.test/private' . $suffix . '.zip',
			self::REF,
			$authorizer
		);
	}

	private function authorizer( string $authorization ): Closure {
		return static function ( array $arguments ) use ( $authorization ): array {
			$arguments['headers']['Authorization'] = $authorization;

			return $arguments;
		};
	}

	private function assert_duplicate_url_rejected( string $url ): void {
		try {
			new AuthenticatedPreparedArchive( $url, self::REF );
			self::fail( 'A reserved archive URL must be rejected.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'The provider archive URL is already prepared for this request.', $exception->getMessage() );
		}
	}

	/**
	 * @return array<string, mixed>
	 * @phpstan-impure HTTP hooks consume authentication and recheck mutable authority.
	 */
	private function apply_filters( string $url ): array {
		$arguments = array( 'headers' => array() );
		$filters   = $this->filters();
		usort( $filters, static fn ( array $left, array $right ): int => $left['priority'] <=> $right['priority'] );
		foreach ( $filters as $filter ) {
			$arguments = $filter['callback']( $arguments, $url );
		}

		return $arguments;
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function filters(): array {
		return \RAN\RepositoryProvider\authenticated_archive_filters( 'http_request_args' );
	}

	/** @return list<array{callback: callable, priority: int, accepted_args: int}> */
	private function actions(): array {
		return \RAN\RepositoryProvider\authenticated_archive_actions( AuthenticatedPreparedArchive::REDIRECT_HOOK );
	}

	private function assert_no_hooks(): void {
		self::assertSame( array(), $this->filters() );
		self::assertSame( array(), $this->actions() );
	}
}
