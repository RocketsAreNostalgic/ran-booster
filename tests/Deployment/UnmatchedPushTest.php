<?php

declare(strict_types=1);

namespace RAN\Tests\Deployment;

use PHPUnit\Framework\TestCase;
use RAN\BoosterGitHubProvider\V1\WebhookNormalizer as GitHubWebhookNormalizer;
use RAN\Deployment\DeploymentCoordinator;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\PackageMutationGuard;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Plugin;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\WebhookNormalizer;
use RAN\RepositoryProvider\WebhookRequest;
use RAN\Secrets\SecretsFile;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\Tests\RepositoryProvider\Support\EmptyAuthenticatedWebhookDeliveryEvidenceReader;
use RAN\Tests\RepositoryProvider\Support\InertWebhookPolicy;
use RAN\Webhook\SignedWebhookVerifier;
use RAN\Webhook\WebhookProcessor;
use ReflectionClass;

require_once __DIR__ . '/AttemptRepositoryDatabase.php';
require_once __DIR__ . '/../Logging/LoggingWordPressFunctions.php';

/** Which signed pushes match a package, and the reason recorded when none does. */
final class UnmatchedPushTest extends TestCase {
	use \RAN\Tests\Support\QueuedSelectionFixture;

	private const SECRET = 'unmatched-push-test-webhook-secret-01';

	public function test_matched_packages_are_unchanged_and_the_closest_rejection_is_named(): void {
		$event = new PushEvent( ProviderCode::parse( 'gh' ), 'owner/example', 'R_example', 'main', str_repeat( 'a', 40 ), 'delivery-one' );
		foreach ( array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET ) as $source ) {
			foreach ( DeploymentPolicy::cases() as $policy ) {
				foreach ( array( 'gh', 'bb' ) as $provider ) {
					foreach ( array( 'owner/example', 'owner/other' ) as $repository ) {
						foreach ( array( 'main', 'develop' ) as $branch ) {
							foreach ( array( 'R_example', 'R_other' ) as $repository_id ) {
								foreach ( array( 'example/example.php', PackageMutationGuard::BOOSTER_PLUGIN_FILE ) as $file ) {
									$package = $this->package( $file, $source, $policy, new ManagedRepository( $provider, $repository, $repository_id, $branch ) );
									// The single expression matching_packages() used before reasons were named.
									$matched = PackageSource::BRANCH === $source && $policy->allows_webhook_mutation() && ! PackageMutationGuard::is_booster_plugin_file( $file )
										&& 'gh' === $provider && 'owner/example' === $repository && 'main' === $branch && 'R_example' === $repository_id;
									$reason  = match ( true ) {
										PackageMutationGuard::BOOSTER_PLUGIN_FILE === $file || 'gh' !== $provider || 'owner/example' !== $repository => 'no_candidate',
										PackageSource::BRANCH !== $source => 'release_source',
										! $policy->allows_webhook_mutation() => 'policy_not_automatic',
										'main' !== $branch => 'branch_mismatch',
										default => 'repository_id_mismatch',
									};
									list( $count, $actual ) = $this->match( array( $package ), $event );
									$case                   = implode( ' ', array( $source->value, $policy->value, $provider, $repository, $branch, $repository_id, $file ) );
									self::assertSame( $matched ? 1 : 0, $count, $case );
									if ( ! $matched ) {
										self::assertSame( $reason, $actual, $case );
									}
								}
							}
						}
					}
				}
			}
		}

		// Among several candidates the one that passed the most checks names the reason.
		$repository = new ManagedRepository( 'gh', 'owner/example', 'R_example', 'develop' );
		$candidates = array(
			$this->package( 'release/release.php', PackageSource::RELEASE_ASSET, DeploymentPolicy::AUTOMATIC, $repository ),
			$this->package( 'branch/branch.php', PackageSource::BRANCH, DeploymentPolicy::AUTOMATIC, $repository ),
			$this->package( 'manual/manual.php', PackageSource::BRANCH, DeploymentPolicy::MANUAL, $repository ),
		);
		self::assertSame( array( 0, 'branch_mismatch' ), $this->match( $candidates, $event ) );
		self::assertSame( array( 0, 'no_candidate' ), $this->match( array(), $event ) );
	}

	public function test_tag_pushes_branch_deletions_and_other_events_never_reach_admission(): void {
		$secrets = $this->createStub( SecretsFile::class );
		$secrets->method( 'webhook_materials' )->willReturn(
			array(
				'unmatched-profile' => array(
					'scope'        => 'owner',
					'target'       => 'owner',
					'authority_id' => '',
					'secret'       => self::SECRET,
				),
			)
		);
		$normalizer = new GitHubWebhookNormalizer( $secrets->credentials_for( 'gh' ), new EmptyAuthenticatedWebhookDeliveryEvidenceReader() );
		$provider   = $this->createMockForIntersectionOfInterfaces( array( RepositoryProvider::class, WebhookNormalizer::class ) );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_webhook_policy' )->willReturn( $normalizer->get_webhook_policy() );
		$provider->method( 'normalize_webhook' )->willReturnCallback( static fn ( WebhookRequest $request ) => $normalizer->normalize_webhook( $request ) );
		$registry    = new ProviderRegistry( array( $provider ) );
		$coordinator = $this->createMock( DeploymentCoordinator::class );
		$coordinator->expects( self::once() )->method( 'accept_webhook' )->willReturn(
			array(
				'status'           => 'accepted',
				'correlation_id'   => str_repeat( 'c', 32 ),
				'accepted_targets' => 0,
				'runner_status'    => 'not_required',
			)
		);
		$processor = new WebhookProcessor( $registry, $coordinator, new SignedWebhookVerifier( $secrets ), $this->queued_selection( new AttemptRepositoryDatabase(), $registry ) );
		$push      = static fn ( string $ref, bool $deleted = false ): string => (string) wp_json_encode(
			array(
				'ref'        => $ref,
				'after'      => $deleted ? str_repeat( '0', 40 ) : str_repeat( 'a', 40 ),
				'deleted'    => $deleted,
				'repository' => array(
					'id'        => 4001,
					'full_name' => 'owner/example',
				),
			)
		);
		foreach ( array(
			'tag push'        => array( 'push', $push( 'refs/tags/v1.0.0' ), 202 ),
			'branch deletion' => array( 'push', $push( 'refs/heads/main', true ), 202 ),
			'other event'     => array( 'issues', '{"action":"opened"}', 202 ),
			'branch push'     => array( 'push', $push( 'refs/heads/main' ), 202 ),
		) as $case => list( $event, $body, $status ) ) {
			$headers  = array(
				'X-GitHub-Event'      => $event,
				'X-GitHub-Delivery'   => 'delivery-' . md5( $case ),
				'X-Hub-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET ),
			);
			$response = $processor->handle(
				'gh',
				static fn (): array => array(
					'body'    => $body,
					'headers' => $headers,
				)
			);
			self::assertSame( $status, $response->get_status(), $case );
		}
	}

	/**
	 * @param list<Plugin> $plugins
	 * @return array{0: int, 1: string} How many packages matched, and the reason when none did.
	 */
	private function match( array $plugins, PushEvent $event ): array {
		$plugin_repository = $this->createStub( PluginRepository::class );
		$plugin_repository->method( 'all_deployment_plugins' )->willReturn( $plugins );
		$theme_repository = $this->createStub( ThemeRepository::class );
		$theme_repository->method( 'all_deployment_themes' )->willReturn( array() );
		$provider = $this->createStubForIntersectionOfInterfaces( array( RepositoryProvider::class, WebhookNormalizer::class ) );
		self::assertInstanceOf( RepositoryProvider::class, $provider );
		$provider->method( 'get_metadata' )->willReturn( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner' ) );
		$provider->method( 'get_webhook_policy' )->willReturn( new InertWebhookPolicy( ProviderCode::parse( 'gh' ), array( 'x-fixture-signature' ) ) );
		$registry = new ProviderRegistry( array( $provider ) );

		$reflection  = new ReflectionClass( DeploymentCoordinator::class );
		$coordinator = $reflection->newInstanceWithoutConstructor();
		foreach ( array(
			'plugins'   => $plugin_repository,
			'themes'    => $theme_repository,
			'providers' => $registry,
		) as $property => $value ) {
			$reflection->getProperty( $property )->setValue( $coordinator, $value );
		}
		$result  = $reflection->getMethod( 'matching_packages' )->invoke( $coordinator, $event );
		$matches = is_array( $result ) ? $result[0] ?? null : null;
		$reason  = is_array( $result ) ? $result[1] ?? null : null;
		self::assertIsArray( $matches );
		self::assertIsString( $reason );

		return array( count( $matches ), $reason );
	}

	private function package( string $file, PackageSource $source, DeploymentPolicy $policy, ManagedRepository $repository ): Plugin {
		$reflection = new ReflectionClass( Plugin::class );
		$package    = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty( 'file' )->setValue( $package, $file );
		$package->set_repository( $repository );
		$package->set_source( $source, 1 );
		$package->set_deployment_policy( $policy );

		return $package;
	}
}
