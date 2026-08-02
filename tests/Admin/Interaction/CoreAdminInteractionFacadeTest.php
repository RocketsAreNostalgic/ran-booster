<?php

declare(strict_types=1);

namespace Tests\Admin\Interaction;

require_once __DIR__ . '/AdminInteractionWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\Interaction\AdminInteractionTarget;
use RAN\Admin\Interaction\CoreAdminInteractionFacade;
use RAN\Admin\Interaction\CoreProviderProfileInteraction;
use RAN\Admin\Interaction\SignedAdminInteractionFlow;
use RAN\Admin\Interaction\SignedAdminInteractionRequest;
use RAN\Admin\Interaction\TransporterRowAdminInteractionFacade;
use RuntimeException;

#[CoversClass( AdminInteractionRequest::class )]
#[CoversClass( AdminInteractionOutcome::class )]
#[CoversClass( AdminInteractionTarget::class )]
#[CoversClass( CoreAdminInteractionFacade::class )]
#[CoversClass( SignedAdminInteractionFlow::class )]
#[CoversClass( SignedAdminInteractionRequest::class )]
final class CoreAdminInteractionFacadeTest extends TestCase {

	/** @var list<array{0: string, 1: string}> */
	private array $headers = array();

	/** @var list<int> */
	private array $statuses = array();

	/** @var list<string> */
	private array $redirects = array();

	protected function setUp(): void {
		$this->headers   = array();
		$this->statuses  = array();
		$this->redirects = array();

		$GLOBALS['ran_booster_interaction_test_actions'] = array();
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET'] );
	}

	public function testCorePublishesAnIndependentVersionedReadyFacade(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertSame( 2, AdminInteractionFacade::API_VERSION );
		self::assertStringContainsString( "RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2", $bootstrap );
		self::assertStringContainsString(
			"do_action( 'ran_booster_admin_interaction_ready', \$adminInteraction )",
			$bootstrap
		);
	}

	public function testRequestAndOutcomeRejectUnboundedPublicValues(): void {
		$this->expectException( InvalidArgumentException::class );
		AdminInteractionRequest::providerRepositories(
			'not-namespaced',
			$this->canonicalUrl(),
			'assisted-hooks-error'
		);
	}

	public function testOutcomeRejectsControlCharactersAndFixesUnexpectedCopy(): void {
		$request = $this->request();

		try {
			AdminInteractionOutcome::success( $request, "Unsafe\nmessage" );
			self::fail( 'Control characters must be rejected.' );
		} catch ( InvalidArgumentException ) {
			self::assertTrue( true );
		}

		$failure = AdminInteractionOutcome::unexpectedFailure( $request );
		self::assertSame( 500, $failure->status() );
		self::assertSame( 'We could not complete that request. Please try again.', $failure->message() );
	}

	public function testCoreRendersOnlyTheAllowlistedProviderPanelContract(): void {
		$facade = $this->facade();

		ob_start();
		$facade->renderFormAttributes( $this->request() );
		$attributes = (string) ob_get_clean();

		self::assertStringContainsString( ' data-ran-booster-enhanced-mutation', $attributes );
		self::assertStringContainsString( ' data-ran-booster-error-target="#assisted-hooks-error"', $attributes );
		self::assertStringContainsString( ' hx-post="https://example.test/wp-admin/admin-post.php"', $attributes );
		self::assertSame( 2, substr_count( $attributes, '#ran-booster-provider-task-panel' ) );
		self::assertStringContainsString( ' hx-sync="this:drop"', $attributes );
		self::assertStringContainsString( '&quot;assisted-hooks:manage-webhook&quot;', $attributes );

		$this->expectException( InvalidArgumentException::class );
		$facade->renderFormAttributes(
			AdminInteractionRequest::providerRepositories(
				'assisted-hooks:manage-webhook',
				'https://attacker.example/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories',
				'assisted-hooks-error'
			)
		);
	}

	public function testEnhancedRequestRequiresTheExactTargetAndDeclaredValues(): void {
		$facade                           = $this->facade();
		$request                          = $this->request();
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-task-panel';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'assisted-hooks:manage-webhook',
			'target'    => 'provider_repositories',
		);

		self::assertTrue( $facade->isEnhancedRequest( $request ) );

		$_POST['ran_booster_interaction']['operation'] = 'other-addon:operation';
		self::assertFalse( $facade->isEnhancedRequest( $request ) );
	}

	public function testTransporterRowTargetIsCoreDerivedAndRouteBounded(): void {
		$facade  = $this->facade();
		$request = $this->transporterRequest();

		self::assertInstanceOf( TransporterRowAdminInteractionFacade::class, $facade );
		self::assertSame( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE, $request->target() );
		self::assertMatchesRegularExpression(
			'/^transporter_migration_source_[a-f0-9]{32}$/',
			$request->targetKey()
		);
		self::assertMatchesRegularExpression(
			'/^ran-booster-transporter-migration-source-[a-f0-9]{32}$/',
			$request->targetElementId()
		);

		ob_start();
		$facade->renderFormAttributes( $request );
		$attributes = (string) ob_get_clean();
		self::assertSame( 2, substr_count( $attributes, $request->targetSelector() ) );

		$this->expectException( InvalidArgumentException::class );
		$facade->renderFormAttributes(
			AdminInteractionRequest::transporterMigrationSourceRow(
				'wp-pusher:review-package',
				'wp-pusher:package-deadbeef',
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability&source=12',
				'wp-pusher-migration-error'
			)
		);
	}

	public function testTransporterEnhancedSuccessReturnsOneExactRowFragment(): void {
		$facade  = $this->facade();
		$request = $this->transporterRequest();
		$this->setTransporterEnhancedRequest( $request );

		ob_start();
		$this->captureTermination(
			fn () => $facade->respondWithTransporterRowFragment(
				AdminInteractionOutcome::success( $request, 'Package checked.' ),
				static function ( string $elementId ): void {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact Core-derived safe element ID fixture.
					echo '<tr id="' . $elementId . '"><td>Checked</td></tr>';
				}
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( array( 200 ), $this->statuses );
		self::assertSame(
			'<tr id="' . $request->targetElementId() . '"><td>Checked</td></tr>',
			$html
		);
		self::assertSame( $this->transporterCanonicalUrl(), $this->header( 'HX-Replace-Url' ) );
		self::assertStringContainsString( 'Package checked.', (string) $this->header( 'HX-Trigger-After-Swap' ) );
		self::assertSame( array(), $this->redirects );
	}

	public function testTransporterRowFragmentKeepsPrgWhenRequestIsNotEnhanced(): void {
		$facade   = $this->facade();
		$request  = $this->transporterRequest();
		$rendered = false;

		$this->captureTermination(
			function () use ( $facade, $request, &$rendered ): void {
				$facade->respondWithTransporterRowFragment(
					AdminInteractionOutcome::success( $request, 'Package checked.' ),
					static function () use ( &$rendered ): void {
						$rendered = true;
					}
				);
			}
		);

		self::assertFalse( $rendered );
		self::assertCount( 1, $this->redirects );
		self::assertStringContainsString( 'ran_booster_interaction_outcome=success', $this->redirects[0] );
		$this->loadQueryFromUrl( $this->redirects[0] );
		$facade->preparePendingFeedback();
		self::assertCount(
			1,
			$GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array()
		);
	}

	public function testInvalidTransporterRowFragmentRefreshesAfterTruthfulSuccess(): void {
		foreach (
			array(
				'<tr id="%s"></tr><tr id="other-row"></tr>',
				'<tr id="%s"><td><tr id="nested-row"></tr></td></tr>',
			) as $invalidFragment
		) {
			$this->headers  = array();
			$this->statuses = array();
			$facade         = $this->facade();
			$request        = $this->transporterRequest();
			$this->setTransporterEnhancedRequest( $request );

			ob_start();
			$this->captureTermination(
				fn () => $facade->respondWithTransporterRowFragment(
					AdminInteractionOutcome::success( $request, 'Package checked.' ),
					static function ( string $elementId ) use ( $invalidFragment ): void {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberately invalid closed local fragments exercise rejection.
						printf( $invalidFragment, $elementId );
					}
				)
			);
			$html = (string) ob_get_clean();

			self::assertSame( '', $html );
			self::assertSame( array( 200 ), $this->statuses );
			self::assertSame( 'true', $this->header( 'HX-Refresh' ) );
			self::assertStringContainsString( 'Package checked.', (string) $this->header( 'HX-Trigger' ) );
		}
	}

	public function testEnhancedSuccessUsesCoreLocationAndSignedFeedback(): void {
		$facade = $this->facade();
		$this->setEnhancedRequest();

		$this->captureTermination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'GitHub webhook configured.' )
			)
		);

		self::assertSame( array( 200 ), $this->statuses );
		$location = $this->header( 'HX-Location' );
		self::assertNotNull( $location );
		$decoded = json_decode( $location, true );
		self::assertIsArray( $decoded );
		self::assertSame( '#ran-booster-provider-task-panel', $decoded['target'] );
		self::assertStringContainsString( 'ran_booster_interaction_message=GitHub%20webhook%20configured.', $decoded['path'] );

		$this->loadQueryFromUrl( $decoded['path'] );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_TARGET']  = 'ran-booster-provider-task-panel';
		$this->headers              = array();
		$facade->preparePendingFeedback();

		self::assertStringContainsString(
			'GitHub webhook configured.',
			(string) $this->header( 'HX-Trigger-After-Swap' )
		);
		self::assertSame( $this->canonicalUrl(), $this->header( 'HX-Replace-Url' ) );
	}

	public function testTamperedPendingFeedbackIsIgnored(): void {
		$facade = $this->facade();
		$this->setEnhancedRequest();
		$this->captureTermination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'Original success.' )
			)
		);
		$location = json_decode( (string) $this->header( 'HX-Location' ), true );
		self::assertIsArray( $location );
		$this->loadQueryFromUrl( $location['path'] );
		$_GET['ran_booster_interaction_message'] = 'Forged success.';
		$_SERVER['HTTP_HX_REQUEST']              = 'true';
		$_SERVER['HTTP_HX_TARGET']               = 'ran-booster-provider-task-panel';
		$this->headers                           = array();

		$facade->preparePendingFeedback();

		self::assertSame( array(), $this->headers );
	}

	public function testPendingFeedbackCannotBeReplayedForAnotherRepository(): void {
		$facade = $this->facade();
		$this->setEnhancedRequest();
		$this->captureTermination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'Original success.' )
			)
		);
		$location = json_decode( (string) $this->header( 'HX-Location' ), true );
		self::assertIsArray( $location );
		$this->loadQueryFromUrl( $location['path'] );
		$_GET['assisted_repository'] = '202';
		$_SERVER['HTTP_HX_REQUEST']  = 'true';
		$_SERVER['HTTP_HX_TARGET']   = 'ran-booster-provider-task-panel';
		$this->headers               = array();

		$facade->preparePendingFeedback();

		self::assertSame( array(), $this->headers );
	}

	public function testEnhancedFailureUsesCoreEscapedPersistentError(): void {
		$facade = $this->facade();
		$this->setEnhancedRequest();

		ob_start();
		$this->captureTermination(
			fn () => $facade->respond(
				AdminInteractionOutcome::validationFailure(
					$this->request(),
					'Could not verify <the hook>.'
				)
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( array( 422 ), $this->statuses );
		self::assertSame( '#assisted-hooks-error', $this->header( 'HX-Retarget' ) );
		self::assertSame( 'unset', $this->header( 'HX-Reselect' ) );
		self::assertSame( 'outerHTML', $this->header( 'HX-Reswap' ) );
		self::assertStringContainsString( 'Could not verify &lt;the hook&gt;.', $html );
		self::assertStringNotContainsString( '<the hook>', $html );
	}

	public function testNormalRequestRetainsSignedPostRedirectGetNotice(): void {
		$facade = $this->facade();
		$this->captureTermination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'GitHub webhook configured.' )
			)
		);

		self::assertCount( 1, $this->redirects );
		$this->loadQueryFromUrl( $this->redirects[0] );
		$facade->preparePendingFeedback();
		$notices = $GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array();
		self::assertCount( 1, $notices );

		ob_start();
		$notices[0]['callback']();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'notice notice-success', $html );
		self::assertStringContainsString( 'GitHub webhook configured.', $html );
	}

	public function testRegisterOwnsOnlyThePendingFeedbackHook(): void {
		$facade = $this->facade();
		$facade->register();

		self::assertArrayHasKey( 'admin_init', $GLOBALS['ran_booster_interaction_test_actions'] );
		self::assertCount( 1, $GLOBALS['ran_booster_interaction_test_actions']['admin_init'] );
	}

	public function testCoreProviderProfileSuccessUsesTheSharedSignedPrgFlow(): void {
		$facade                           = $this->facade();
		$_GET                             = array(
			'view'     => 'credentials',
			's'        => 'Deployment',
			'kind'     => 'api-key',
			'status'   => 'ready',
			'orderby'  => 'usage',
			'order'    => 'desc',
			'paged'    => '2',
			'per_page' => '50',
		);
		$request                          = $facade->providerProfileRequest(
			'save-access-profile',
			'fixture'
		);
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'core:save-access-profile',
			'target'    => CoreProviderProfileInteraction::TARGET_KEY,
		);

		$this->captureTermination(
			fn () => $facade->respondToProviderProfileSuccess(
				$request,
				'Repository access token saved.'
			)
		);

		self::assertSame( array( 200 ), $this->statuses );
		$location = json_decode( (string) $this->header( 'HX-Location' ), true );
		self::assertIsArray( $location );
		self::assertSame( CoreProviderProfileInteraction::TARGET_SELECTOR, $location['target'] );
		self::assertSame( CoreProviderProfileInteraction::TARGET_SELECTOR, $location['select'] );
		self::assertStringNotContainsString( 'secret-canary', $location['path'] );

		$this->loadQueryFromUrl( $location['path'] );
		$this->headers = array();
		$facade->preparePendingFeedback();

		$trigger = json_decode( (string) $this->header( 'HX-Trigger-After-Swap' ), true );
		self::assertIsArray( $trigger );
		self::assertSame(
			'core:save-access-profile',
			$trigger['ran-booster:admin-mutation-success']['operation']
		);
		self::assertSame(
			'Repository access token saved.',
			$trigger['ran-booster:admin-mutation-success']['message']
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture&view=credentials&s=Deployment&status=ready&orderby=usage&order=desc&paged=2&per_page=50&kind=api-key',
			$this->header( 'HX-Replace-Url' )
		);
	}

	public function testCoreWebhookProfileRequestPreservesBoundedRepositoryDetail(): void {
		$_GET = array(
			'panel'      => 'repositories',
			'repository' => 'repository:42/example',
		);

		$request = $this->facade()->providerProfileRequest( 'save-webhook-profile', 'fixture' );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture&panel=repositories&repository=repository:42/example',
			$request->canonicalUrl
		);

		$_GET['repository'] = str_repeat( 'a', 192 );
		$request            = $this->facade()->providerProfileRequest( 'save-webhook-profile', 'fixture' );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture&panel=repositories',
			$request->canonicalUrl
		);
	}

	public function testCoreProviderProfileFailureIsLocalForHtmxAndPrgForNoJavaScript(): void {
		$facade                           = $this->facade();
		$_GET['view']                     = 'secrets';
		$request                          = $facade->providerProfileRequest(
			'save-webhook-profile',
			'fixture'
		);
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'core:save-webhook-profile',
			'target'    => CoreProviderProfileInteraction::TARGET_KEY,
		);

		ob_start();
		$this->captureTermination(
			fn () => $facade->respondToProviderProfileValidationFailure(
				$request,
				'Enter the Push-to-Deploy secret.'
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( array( 422 ), $this->statuses );
		self::assertSame( '#ran-booster-webhook-profile-error', $this->header( 'HX-Retarget' ) );
		self::assertStringContainsString( 'Enter the Push-to-Deploy secret.', $html );
		self::assertNull( $this->header( 'HX-Trigger-After-Swap' ) );

		$this->headers  = array();
		$this->statuses = array();
		$_POST          = array();
		unset( $_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET'] );
		$this->captureTermination(
			fn () => $facade->respondToProviderProfileValidationFailure(
				$request,
				'Enter the Push-to-Deploy secret.'
			)
		);

		self::assertCount( 1, $this->redirects );
		self::assertStringContainsString( 'ran_booster_interaction_outcome=validation_failure', $this->redirects[0] );
	}

	public function testCoreProviderProfileRouteRejectsAnOperationViewMismatch(): void {
		$_GET['view'] = 'secrets';

		$this->expectException( InvalidArgumentException::class );
		$this->facade()->providerProfileRequest( 'save-access-profile', 'fixture' );
	}

	private function request(): AdminInteractionRequest {
		return AdminInteractionRequest::providerRepositories(
			'assisted-hooks:manage-webhook',
			$this->canonicalUrl(),
			'assisted-hooks-error'
		);
	}

	private function canonicalUrl(): string {
		return 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories&assisted_repository=101#ran-booster-assisted-hooks-operation-heading';
	}

	private function transporterRequest(): AdminInteractionRequest {
		return AdminInteractionRequest::transporterMigrationSourceRow(
			'wp-pusher:review-package',
			'wp-pusher:package-deadbeef',
			$this->transporterCanonicalUrl(),
			'wp-pusher-migration-error'
		);
	}

	private function transporterCanonicalUrl(): string {
		return 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability#ran-booster-portability-wp-pusher';
	}

	private function facade(): CoreAdminInteractionFacade {
		return new CoreAdminInteractionFacade(
			function ( string $name, string $value ): void {
				$this->headers[] = array( $name, $value );
			},
			function ( int $status ): void {
				$this->statuses[] = $status;
			},
			function ( string $url ): void {
				$this->redirects[] = $url;
			},
			static function (): never {
				throw new InteractionTerminated();
			}
		);
	}

	private function setEnhancedRequest(): void {
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-task-panel';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'assisted-hooks:manage-webhook',
			'target'    => 'provider_repositories',
		);
	}

	private function setTransporterEnhancedRequest( AdminInteractionRequest $request ): void {
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = $request->targetElementId();
		$_POST['ran_booster_interaction'] = array(
			'operation' => $request->operation(),
			'target'    => $request->targetKey(),
		);
	}

	private function captureTermination( callable $callback ): void {
		try {
			$callback();
			self::fail( 'The facade response must terminate the request.' );
		} catch ( InteractionTerminated ) {
			self::assertTrue( true );
		}
	}

	private function header( string $name ): ?string {
		foreach ( $this->headers as $header ) {
			if ( $name === $header[0] ) {
				return $header[1];
			}
		}

		return null;
	}

	private function loadQueryFromUrl( string $url ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused local URL fixture.
		$query = (string) parse_url( $url, PHP_URL_QUERY );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The tested facade verifies the signed query after this fixture assignment.
		parse_str( $query, $_GET );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused termination sentinel belongs with its facade test.
final class InteractionTerminated extends RuntimeException {
}
