<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Deployment/AttemptRepositoryDatabase.php';
require_once dirname( __DIR__ ) . '/Support/RepositoryAdminWordPressFunctions.php';
require_once __DIR__ . '/CredentialExpiryWordPressFunctions.php';
require_once __DIR__ . '/BackgroundDeploymentFailureWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\BackgroundDeploymentFailureEmail;
use RAN\Admin\BackgroundDeploymentFailureMonitor;
use RAN\Admin\DeploymentAdminController;
use RAN\Admin\DeploymentAdminPresenter;
use RAN\Admin\ManagedPluginFailureRows;
use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Dashboard;
use RAN\Deployment\DeploymentRequest;
use RAN\ManagedRepository;
use RAN\Plugin;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use Tests\Deployment\AttemptRepositoryDatabase;

final class BackgroundDeploymentFailureTest extends TestCase {

	private AttemptRepositoryDatabase $database;
	private DeploymentAttemptRepository $attempts;

	protected function setUp(): void {
		parent::setUp();

		$this->database = new AttemptRepositoryDatabase();
		$this->attempts = new DeploymentAttemptRepository(
			$this->database,
			'wp_ran_booster_deployment_attempts',
			databaseLifecycle: $this->createStub( Database::class )
		);

		$GLOBALS['ran_booster_repository_admin_allowed']               = true;
		$GLOBALS['ran_booster_repository_admin_capabilities']          = array(
			'manage_options' => true,
			'update_plugins' => true,
		);
		$GLOBALS['ran_booster_repository_admin_nonce_valid']           = true;
		$GLOBALS['ran_booster_repository_admin_user_id']               = 17;
		$GLOBALS['ran_booster_repository_admin_user_meta']             = array();
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = false;
		$GLOBALS['ran_booster_background_failure_actions']             = array();
		$GLOBALS['ran_booster_background_failure_site_options']        = array( 'admin_email' => 'admin@example.test' );
		$GLOBALS['ran_booster_background_failure_options']             = array( 'blogname' => 'Example &amp; Site' );
		$GLOBALS['ran_booster_background_failure_mail']                = array();
		$GLOBALS['ran_booster_background_failure_mail_result']         = true;
		unset(
			$GLOBALS['ran_booster_background_failure_email_filter'],
			$GLOBALS['ran_booster_background_failure_filter_context']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_booster_repository_admin_allowed'],
			$GLOBALS['ran_booster_repository_admin_capabilities'],
			$GLOBALS['ran_booster_repository_admin_nonce_valid'],
			$GLOBALS['ran_booster_repository_admin_user_id'],
			$GLOBALS['ran_booster_repository_admin_user_meta'],
			$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'],
			$GLOBALS['ran_booster_background_failure_actions'],
			$GLOBALS['ran_booster_background_failure_site_options'],
			$GLOBALS['ran_booster_background_failure_options'],
			$GLOBALS['ran_booster_background_failure_mail'],
			$GLOBALS['ran_booster_background_failure_mail_result'],
			$GLOBALS['ran_booster_background_failure_email_filter'],
			$GLOBALS['ran_booster_background_failure_filter_context']
		);

		parent::tearDown();
	}

	public function testMonitorReportsOnlyTheNewestCurrentWebhookFailureForEachPackage(): void {
		$this->database->rows    = array(
			$this->row( 1, 'old-failure', 'example', 'webhook', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED ),
			$this->row( 2, 'new-success', 'example', 'webhook', DeploymentOutcome::CODE_DEPLOYED ),
			$this->row( 3, 'manual-failure', 'manual-only', 'manual', DeploymentOutcome::CODE_PROVIDER_ACCESS_DENIED ),
			$this->row( 4, 'current-failure', 'affected', 'webhook', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED ),
			$this->row( 5, 'uncertain', 'uncertain', 'webhook', DeploymentOutcome::CODE_INTERRUPTED, 'theme' ),
		);
		$resolved                = $this->row( 6, 'resolved', 'resolved', 'webhook', DeploymentOutcome::CODE_INTERRUPTED );
		$resolved['resolved_at'] = '2026-07-23 12:07:00';
		$resolved['resolved_by'] = 17;
		$this->database->rows[]  = $resolved;

		$monitor  = $this->monitor();
		$failures = $monitor->failures();

		self::assertSame( array( 'uncertain', 'affected' ), array_column( $failures, 'package_slug' ) );
		self::assertSame( 'needs_attention', $failures[0]['state'] );
		self::assertSame( 'profile_123', $failures[1]['credential_id'] );
		self::assertNull( $monitor->forPackage( 'plugin', 'example' ) );
		self::assertSame( 'affected', $monitor->forPackage( 'plugin', 'affected' )['package_slug'] ?? null );
		self::assertNotNull( $monitor->fingerprint() );
	}

	public function testNoticeIsAdministratorOnlyEscapedRequestDeduplicatedAndDismissiblePerUser(): void {
		$this->database->rows = array(
			$this->row( 1, 'affected', 'affected', 'webhook', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED ),
		);
		$monitor              = $this->monitor();
		$notice               = new DeploymentAdminPresenter( $monitor );

		ob_start();
		$notice->render();
		$notice->render();
		$html = (string) ob_get_clean();

		self::assertSame( 1, substr_count( $html, 'data-ran-booster-background-failure-notice' ) );
		self::assertStringContainsString( 'RAN Booster automatic deployment failed', $html );
		self::assertStringContainsString( 'tab=troubleshooting&amp;panel=activity', $html );
		self::assertStringContainsString( 'tab=gh&amp;replace_credential=profile_123', $html );
		self::assertStringNotContainsString( 'secret-canary', $html );

		$result = ( new DeploymentAdminController( $this->createStub( Dashboard::class ), monitor: $monitor ) )->handle();

		self::assertTrue( $result['success'] );
		self::assertFalse( ( new DeploymentAdminPresenter( $monitor ) )->shouldRender() );
		$GLOBALS['ran_booster_repository_admin_user_id'] = 18;
		self::assertTrue( ( new DeploymentAdminPresenter( $monitor ) )->shouldRender() );

		$GLOBALS['ran_booster_repository_admin_capabilities']['manage_options'] = false;
		ob_start();
		( new DeploymentAdminPresenter( $monitor ) )->render();
		self::assertSame( '', (string) ob_get_clean() );
	}

	public function testDismissalRejectsUnauthorizedInvalidNonceAndPersistenceFailure(): void {
		$this->database->rows = array(
			$this->row( 1, 'affected', 'affected', 'webhook', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED ),
		);
		$controller           = new DeploymentAdminController( $this->createStub( Dashboard::class ), monitor: $this->monitor() );

		$GLOBALS['ran_booster_repository_admin_capabilities']['manage_options'] = false;
		self::assertSame( 403, $controller->handle()['status'] );
		$GLOBALS['ran_booster_repository_admin_capabilities']['manage_options'] = true;
		$GLOBALS['ran_booster_repository_admin_nonce_valid']                    = false;
		self::assertSame( 403, $controller->handle()['status'] );
		$GLOBALS['ran_booster_repository_admin_nonce_valid']           = true;
		$GLOBALS['ran_booster_repository_admin_user_meta_write_fails'] = true;
		self::assertSame( 500, $controller->handle()['status'] );
	}

	public function testPluginScreenRegistersAndRendersAWordPressNativeFailureRow(): void {
		$this->database->rows = array(
			$this->row( 1, 'affected', 'example', 'webhook', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED ),
		);
		$plugin               = $this->plugin();
		$rows                 = new ManagedPluginFailureRows(
			new class( $plugin ) extends PluginRepository {

				public function __construct( private Plugin $plugin ) {
				}

				public function allBoosterPlugins(): array {
					return array( $this->plugin->getIdentifier() => $this->plugin );
				}
			},
			$this->monitor()
		);

		$rows->register();

		$registrations = $GLOBALS['ran_booster_background_failure_actions']['after_plugin_row_example/example.php'] ?? array();
		self::assertCount( 1, $registrations );
		ob_start();
		$registrations[0]['callback']( 'example/example.php' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'plugin-update-tr', $html );
		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringContainsString( 'repository provider rejected', $html );
		self::assertStringContainsString( 'Replace credential', $html );
	}

	public function testEmailIsLimitedToBackgroundFailuresAndContainsOnlySafeActionableData(): void {
		$email   = new BackgroundDeploymentFailureEmail();
		$failure = DeploymentAttempt::fromDatabase(
			$this->row( 1, 'affected', 'example', 'webhook', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED )
		);

		self::assertTrue( $email->notify( $failure ) );
		self::assertCount( 1, $GLOBALS['ran_booster_background_failure_mail'] );
		$sent = $GLOBALS['ran_booster_background_failure_mail'][0];
		self::assertSame( 'admin@example.test', $sent['to'] );
		self::assertStringContainsString( 'Example & Site', $sent['subject'] );
		self::assertStringContainsString( 'repository provider rejected', $sent['message'] );
		self::assertStringContainsString( 'Support reference:', $sent['message'] );
		self::assertStringNotContainsString( 'profile_123', $sent['message'] );
		self::assertStringNotContainsString( 'secret-canary', $sent['message'] );

		$manual  = DeploymentAttempt::fromDatabase(
			$this->row( 2, 'manual-failure', 'example', 'manual', DeploymentOutcome::CODE_PROVIDER_CREDENTIAL_REJECTED )
		);
		$success = DeploymentAttempt::fromDatabase(
			$this->row( 3, 'success', 'example', 'webhook', DeploymentOutcome::CODE_DEPLOYED )
		);
		self::assertFalse( $email->notify( $manual ) );
		self::assertFalse( $email->notify( $success ) );
		self::assertCount( 1, $GLOBALS['ran_booster_background_failure_mail'] );
	}

	private function monitor(): BackgroundDeploymentFailureMonitor {
		return new BackgroundDeploymentFailureMonitor( $this->attempts, new ProviderRegistry() );
	}

	private function plugin(): Plugin {
		$plugin = Plugin::fromWpArray(
			'example/example.php',
			array(
				'Name'        => 'Example',
				'PluginURI'   => '',
				'Version'     => '1.0.0',
				'Description' => '',
				'Author'      => '',
				'AuthorURI'   => '',
				'TextDomain'  => '',
				'DomainPath'  => '',
				'Network'     => false,
				'Title'       => 'Example',
				'AuthorName'  => '',
			)
		);
		$plugin->setRepository( new ManagedRepository( 'gh', 'owner/example', 'R_example', 'main', true, 'profile_123' ) );
		$plugin->setDeploymentPolicy( DeploymentPolicy::AUTOMATIC );

		return $plugin;
	}

	/** @return array<string, mixed> */
	private function row(
		int $id,
		string $correlationSeed,
		string $slug,
		string $source,
		string $outcome,
		string $packageType = 'plugin'
	): array {
		$request = new DeploymentRequest(
			'owner/' . $slug,
			'profile_123',
			true,
			'main',
			$slug,
			null,
			DeploymentPolicy::AUTOMATIC,
			null
		);
		$state   = DeploymentOutcome::fromCode( $outcome )->getState()->value;

		return array(
			'id'                      => $id,
			'correlation_id'          => substr( hash( 'sha256', $correlationSeed ), 0, 32 ),
			'source'                  => $source,
			'operation'               => 'update',
			'package_type'            => $packageType,
			'package_slug'            => $slug,
			'package_source'          => 'branch',
			'package_source_revision' => 1,
			'release_identity'        => null,
			'provider'                => 'gh',
			'provider_repository_id'  => 'R_' . $slug,
			'requested_ref'           => 'main',
			'resolved_ref'            => null,
			'delivery_id'             => 'webhook' === $source ? 'delivery-' . $id : null,
			'delivery_digest'         => 'webhook' === $source ? str_repeat( dechex( $id % 15 ), 64 ) : null,
			'state'                   => $state,
			'mutation_started_at'     => null,
			'outcome_code'            => $outcome,
			'request_json'            => $request->toJson(),
			'created_at'              => sprintf( '2026-07-23 12:%02d:00', $id ),
			'finished_at'             => sprintf( '2026-07-23 12:%02d:30', $id ),
		);
	}
}
