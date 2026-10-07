<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\Interaction;

use function RAN\Admin\Interaction\wp_make_link_relative;

require_once __DIR__ . '/AdminInteractionWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\Interaction\AdminInteractionTarget;
use RAN\Admin\Interaction\CoreAdminInteractionFacade;
use RAN\Admin\ProviderProfileAdminController;
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

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$this->headers   = array();
		$this->statuses  = array();
		$this->redirects = array();

		$GLOBALS['ran_booster_interaction_test_actions']      = array();
		$GLOBALS['ran_booster_interaction_test_translations'] = array();
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET'] );
	}

	public function test_core_publishes_an_independent_versioned_ready_facade(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static local bootstrap contract.
		$bootstrap = file_get_contents( dirname( __DIR__, 3 ) . '/ran-booster.php' );

		self::assertIsString( $bootstrap );
		self::assertSame( 3, AdminInteractionFacade::API_VERSION );
		self::assertStringContainsString( "RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3", $bootstrap );
		self::assertStringContainsString(
			"do_action( 'ran_booster_admin_interaction_ready', \$admin_interaction )",
			$bootstrap
		);
	}

	public function test_request_and_outcome_reject_unbounded_public_values(): void {
		$this->expectException( InvalidArgumentException::class );
		AdminInteractionRequest::provider_repositories(
			'not-namespaced',
			$this->canonical_url(),
			'assisted-hooks-error'
		);
	}

	public function test_outcome_rejects_control_characters_and_fixes_unexpected_copy(): void {
		$request = $this->request();

		try {
			AdminInteractionOutcome::success( $request, "Unsafe\nmessage" );
			self::fail( 'Control characters must be rejected.' );
		} catch ( InvalidArgumentException ) {
			$this->addToAssertionCount( 1 );
		}

		$failure = AdminInteractionOutcome::unexpected_failure( $request );
		self::assertSame( 500, $failure->status() );
		self::assertSame( 'We could not complete that request. Please try again.', $failure->message() );
	}

	public function test_unexpected_failure_uses_the_plugin_translation_domain(): void {
		$source = 'We could not complete that request. Please try again.';
		$GLOBALS['ran_booster_interaction_test_translations'] = array(
			'ran-booster' => array( $source => 'Nous n’avons pas pu effectuer cette demande. Veuillez réessayer.' ),
		);

		$failure = AdminInteractionOutcome::unexpected_failure( $this->request() );

		self::assertSame( 'Nous n’avons pas pu effectuer cette demande. Veuillez réessayer.', $failure->message() );
	}

	public function test_core_renders_only_the_allowlisted_provider_panel_contract(): void {
		$facade = $this->facade();

		ob_start();
		$facade->render_form_attributes( $this->request() );
		$attributes = (string) ob_get_clean();

		self::assertStringContainsString( ' data-ran-booster-enhanced-mutation', $attributes );
		self::assertStringContainsString( ' data-ran-booster-error-target="#repository-webhook-management-error"', $attributes );
		self::assertStringContainsString( ' hx-post="/wp-admin/admin-post.php"', $attributes );
		self::assertSame( 2, substr_count( $attributes, '#ran-booster-provider-task-panel' ) );
		self::assertStringContainsString( ' hx-sync="this:drop"', $attributes );
		self::assertStringContainsString( '&quot;repository-webhook-management:manage-webhook&quot;', $attributes );

		$this->expectException( InvalidArgumentException::class );
		$facade->render_form_attributes(
			AdminInteractionRequest::provider_repositories(
				'repository-webhook-management:manage-webhook',
				'https://attacker.example/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories',
				'repository-webhook-management-error'
			)
		);
	}

	public function test_enhanced_request_requires_the_exact_target_and_declared_values(): void {
		$facade                           = $this->facade();
		$request                          = $this->request();
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-task-panel';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'repository-webhook-management:manage-webhook',
			'target'    => 'provider_repositories',
		);

		self::assertTrue( $facade->is_enhanced_request( $request ) );

		$_POST['ran_booster_interaction']['operation'] = 'other-addon:operation';
		self::assertFalse( $facade->is_enhanced_request( $request ) );
	}

	public function test_transporter_row_target_is_core_derived_and_route_bounded(): void {
		$facade  = $this->facade();
		$request = $this->transporter_request();

		self::assertInstanceOf( TransporterRowAdminInteractionFacade::class, $facade );
		self::assertSame( AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE, $request->target() );
		self::assertMatchesRegularExpression(
			'/^transporter_migration_source_[a-f0-9]{32}$/',
			$request->target_key()
		);
		self::assertMatchesRegularExpression(
			'/^ran-booster-transporter-migration-source-[a-f0-9]{32}$/',
			$request->target_element_id()
		);

		ob_start();
		$facade->render_form_attributes( $request );
		$attributes = (string) ob_get_clean();
		self::assertSame( 2, substr_count( $attributes, $request->target_selector() ) );

		$this->expectException( InvalidArgumentException::class );
		$facade->render_form_attributes(
			AdminInteractionRequest::transporter_migration_source_row(
				'wp-pusher:review-package',
				'wp-pusher:package-deadbeef',
				'https://example.test/wp-admin/admin.php?page=ran-booster&tab=portability&source=12',
				'wp-pusher-migration-error'
			)
		);
	}

	public function test_transporter_enhanced_success_returns_one_exact_row_fragment(): void {
		$facade  = $this->facade();
		$request = $this->transporter_request();
		$this->set_transporter_enhanced_request( $request );

		ob_start();
		$this->capture_termination(
			fn () => $facade->respond_with_transporter_row_fragment(
				AdminInteractionOutcome::success( $request, 'Package checked.' ),
				static function ( string $element_id ): void {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact Core-derived safe element ID fixture.
					echo '<tr id="' . $element_id . '"><td>Checked</td></tr>';
				}
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( array( 200 ), $this->statuses );
		self::assertSame(
			'<tr id="' . $request->target_element_id() . '"><td>Checked</td></tr>',
			$html
		);
		self::assertSame( wp_make_link_relative( $this->transporter_canonical_url() ), $this->header( 'HX-Replace-Url' ) );
		self::assertStringContainsString( 'Package checked.', (string) $this->header( 'HX-Trigger-After-Swap' ) );
		self::assertSame( array(), $this->redirects );
	}

	public function test_transporter_row_fragment_keeps_prg_when_request_is_not_enhanced(): void {
		$facade   = $this->facade();
		$request  = $this->transporter_request();
		$rendered = false;

		$this->capture_termination(
			function () use ( $facade, $request, &$rendered ): void {
				$facade->respond_with_transporter_row_fragment(
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
		$this->load_query_from_url( $this->redirects[0] );
		$facade->prepare_pending_feedback();
		self::assertCount(
			1,
			$GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array()
		);
	}

	public function test_invalid_transporter_row_fragment_refreshes_after_truthful_success(): void {
		foreach (
			array(
				'<tr id="%s"></tr><tr id="other-row"></tr>',
				'<tr id="%s"><td><tr id="nested-row"></tr></td></tr>',
			) as $invalid_fragment
		) {
			$this->headers  = array();
			$this->statuses = array();
			$facade         = $this->facade();
			$request        = $this->transporter_request();
			$this->set_transporter_enhanced_request( $request );

			ob_start();
			$this->capture_termination(
				fn () => $facade->respond_with_transporter_row_fragment(
					AdminInteractionOutcome::success( $request, 'Package checked.' ),
					static function ( string $element_id ) use ( $invalid_fragment ): void {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberately invalid closed local fragments exercise rejection.
						printf( $invalid_fragment, $element_id );
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

	public function test_enhanced_success_uses_core_location_and_signed_feedback(): void {
		$facade = $this->facade();
		$this->set_enhanced_request();

		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'GitHub webhook configured.' )
			)
		);

		self::assertSame( array( 200 ), $this->statuses );
		$location = $this->header( 'HX-Location' );
		self::assertNotNull( $location );
		self::assertNull( $this->header( 'HX-Redirect' ) );
		$decoded = json_decode( $location, true );
		self::assertIsArray( $decoded );
		self::assertSame( '#ran-booster-provider-task-panel', $decoded['target'] );
		self::assertStringStartsWith( '/wp-admin/', $decoded['path'] );
		self::assertStringNotContainsString( 'https://example.test', $decoded['path'] );
		self::assertStringContainsString( 'ran_booster_interaction_message=GitHub%20webhook%20configured.', $decoded['path'] );

		$this->load_query_from_url( $decoded['path'] );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_TARGET']  = 'ran-booster-provider-task-panel';
		$this->headers              = array();
		$facade->prepare_pending_feedback();

		self::assertStringContainsString(
			'GitHub webhook configured.',
			(string) $this->header( 'HX-Trigger-After-Swap' )
		);
		self::assertSame( wp_make_link_relative( $this->canonical_url() ), $this->header( 'HX-Replace-Url' ) );
	}

	public function test_tampered_pending_feedback_is_ignored(): void {
		$facade = $this->facade();
		$this->set_enhanced_request();
		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'Original success.' )
			)
		);
		$location = json_decode( (string) $this->header( 'HX-Location' ), true );
		self::assertIsArray( $location );
		$this->load_query_from_url( $location['path'] );
		$_GET['ran_booster_interaction_message'] = 'Forged success.';
		$_SERVER['HTTP_HX_REQUEST']              = 'true';
		$_SERVER['HTTP_HX_TARGET']               = 'ran-booster-provider-task-panel';
		$this->headers                           = array();

		$facade->prepare_pending_feedback();

		self::assertSame( array(), $this->headers );
	}

	public function test_pending_feedback_cannot_be_replayed_for_another_repository(): void {
		$facade = $this->facade();
		$this->set_enhanced_request();
		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'Original success.' )
			)
		);
		$location = json_decode( (string) $this->header( 'HX-Location' ), true );
		self::assertIsArray( $location );
		$this->load_query_from_url( $location['path'] );
		$_GET['repository']         = '202';
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_TARGET']  = 'ran-booster-provider-task-panel';
		$this->headers              = array();

		$facade->prepare_pending_feedback();

		self::assertSame( array(), $this->headers );
	}

	public function test_enhanced_failure_uses_core_escaped_persistent_error(): void {
		$facade = $this->facade();
		$this->set_enhanced_request();

		ob_start();
		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::validation_failure(
					$this->request(),
					'Could not verify <the hook>.'
				)
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( array( 422 ), $this->statuses );
		self::assertSame( '#repository-webhook-management-error', $this->header( 'HX-Retarget' ) );
		self::assertSame( 'unset', $this->header( 'HX-Reselect' ) );
		self::assertSame( 'outerHTML', $this->header( 'HX-Reswap' ) );
		self::assertStringContainsString( 'Could not verify &lt;the hook&gt;.', $html );
		self::assertStringNotContainsString( '<the hook>', $html );
	}

	public function test_normal_request_retains_signed_post_redirect_get_notice(): void {
		$facade = $this->facade();
		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'GitHub webhook configured.' )
			)
		);

		self::assertCount( 1, $this->redirects );
		$this->load_query_from_url( $this->redirects[0] );
		$facade->prepare_pending_feedback();
		$notices = $GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array();
		self::assertCount( 1, $notices );

		ob_start();
		$notices[0]['callback']();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'notice notice-success', $html );
		self::assertStringContainsString( 'GitHub webhook configured.', $html );
	}

	public function test_register_owns_only_the_pending_feedback_hook(): void {
		$facade = $this->facade();
		$facade->register();

		self::assertArrayHasKey( 'admin_init', $GLOBALS['ran_booster_interaction_test_actions'] );
		self::assertCount( 1, $GLOBALS['ran_booster_interaction_test_actions']['admin_init'] );
	}

	public function test_core_provider_profile_save_success_uses_signed_full_page_navigation(): void {
		$cases = array(
			array(
				'action'    => 'save-access-profile',
				'view'      => 'credentials',
				'message'   => 'Repository credential saved.',
				'list_args' => array(
					's'        => 'Deployment',
					'kind'     => 'api-key',
					'status'   => 'ready',
					'orderby'  => 'usage',
					'order'    => 'desc',
					'paged'    => '2',
					'per_page' => '50',
				),
			),
			array(
				'action'    => 'save-webhook-profile',
				'view'      => 'secrets',
				'message'   => 'Push-to-Deploy secret saved.',
				'list_args' => array(),
			),
		);

		foreach ( $cases as $case ) {
			$this->headers   = array();
			$this->statuses  = array();
			$this->redirects = array();

			$GLOBALS['ran_booster_interaction_test_actions'] = array();
			$_GET                             = array_merge( array( 'view' => $case['view'] ), $case['list_args'] );
			$facade                           = $this->facade();
			$request                          = $facade->provider_profile_request( $case['action'], 'fixture' );
			$_SERVER['HTTP_HX_REQUEST']       = 'true';
			$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
			$_POST['ran_booster_interaction'] = array(
				'operation' => 'core:' . $case['action'],
				'target'    => ProviderProfileAdminController::TARGET_KEY,
			);
			$_POST['ran_booster']['secret']   = 'secret-canary-provider-profile';

			$this->capture_termination(
				fn () => $facade->respond_to_provider_profile_success( $request, $case['message'] )
			);

			self::assertSame( array( 200 ), $this->statuses );
			$redirect = $this->header( 'HX-Redirect' );
			self::assertNotNull( $redirect );
			self::assertNull( $this->header( 'HX-Location' ) );
			self::assertNull( $this->header( 'HX-Trigger-After-Swap' ) );
			self::assertSame( array(), $this->redirects );
			foreach ( $this->headers as $header ) {
				self::assertStringNotContainsString( 'secret-canary', $header[1] );
			}

			$this->load_query_from_url( $redirect );
			self::assertSame( 'core:' . $case['action'], $_GET['ran_booster_interaction_operation'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixture loads and verifies the signed outcome query.
			self::assertSame( $request->canonical_url, $_GET['ran_booster_interaction_return'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The fixture loads and verifies the signed outcome query.
			$this->headers = array();
			$_POST         = array();
			unset( $_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET'] );
			$facade->prepare_pending_feedback();
			$notices = $GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array();
			self::assertCount( 1, $notices );

			ob_start();
			$notices[0]['callback']();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'notice notice-success', $html );
			self::assertStringContainsString( $case['message'], $html );
		}
	}

	public function test_core_provider_profile_delete_success_keeps_the_authoritative_region_swap(): void {
		foreach (
			array(
				array( 'delete-access-profile', 'credentials', 'Repository credential removed.' ),
				array( 'delete-webhook-profile', 'secrets', 'Push-to-Deploy secret removed.' ),
			) as $case
		) {
			$this->headers                    = array();
			$this->statuses                   = array();
			$_GET['view']                     = $case[1];
			$facade                           = $this->facade();
			$request                          = $facade->provider_profile_request( $case[0], 'fixture' );
			$_SERVER['HTTP_HX_REQUEST']       = 'true';
			$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
			$_POST['ran_booster_interaction'] = array(
				'operation' => 'core:' . $case[0],
				'target'    => ProviderProfileAdminController::TARGET_KEY,
			);

			$this->capture_termination(
				fn () => $facade->respond_to_provider_profile_success( $request, $case[2] )
			);

			self::assertSame( array( 200 ), $this->statuses );
			$location = json_decode( (string) $this->header( 'HX-Location' ), true );
			self::assertIsArray( $location );
			self::assertSame( ProviderProfileAdminController::TARGET_SELECTOR, $location['target'] );
			self::assertSame( ProviderProfileAdminController::TARGET_SELECTOR, $location['select'] );
			self::assertNull( $this->header( 'HX-Redirect' ) );
		}
	}

	public function test_core_provider_profile_save_keeps_native_signed_prg_fallback(): void {
		$facade       = $this->facade();
		$_GET['view'] = 'credentials';
		$request      = $facade->provider_profile_request( 'save-access-profile', 'fixture' );

		$this->capture_termination(
			fn () => $facade->respond_to_provider_profile_success( $request, 'Repository credential saved.' )
		);

		self::assertSame( array(), $this->headers );
		self::assertSame( array(), $this->statuses );
		self::assertCount( 1, $this->redirects );
		$this->load_query_from_url( $this->redirects[0] );
		$facade->prepare_pending_feedback();
		self::assertCount(
			1,
			$GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array()
		);
	}

	public function test_core_webhook_profile_request_preserves_bounded_repository_detail(): void {
		$_GET = array(
			'panel'      => 'repositories',
			'repository' => 'repository:42/example',
		);

		$request = $this->facade()->provider_profile_request( 'save-webhook-profile', 'fixture' );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture&panel=repositories&repository=repository:42/example',
			$request->canonical_url
		);

		$_GET['repository'] = str_repeat( 'a', 192 );
		$request            = $this->facade()->provider_profile_request( 'save-webhook-profile', 'fixture' );

		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=fixture&panel=repositories',
			$request->canonical_url
		);
	}

	public function test_core_provider_profile_failure_is_local_for_htmx_and_prg_for_no_java_script(): void {
		$facade                           = $this->facade();
		$_GET['view']                     = 'secrets';
		$request                          = $facade->provider_profile_request(
			'save-webhook-profile',
			'fixture'
		);
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'core:save-webhook-profile',
			'target'    => ProviderProfileAdminController::TARGET_KEY,
		);
		$_POST['ran_booster']['secret']   = 'secret-canary-provider-profile';

		ob_start();
		$this->capture_termination(
			fn () => $facade->respond_to_provider_profile_validation_failure(
				$request,
				'Enter the Push-to-Deploy secret.'
			)
		);
		$html = (string) ob_get_clean();

		self::assertSame( array( 422 ), $this->statuses );
		self::assertSame( '#ran-booster-webhook-profile-error', $this->header( 'HX-Retarget' ) );
		self::assertSame( 'unset', $this->header( 'HX-Reselect' ) );
		self::assertSame( 'outerHTML', $this->header( 'HX-Reswap' ) );
		self::assertNull( $this->header( 'HX-Redirect' ) );
		self::assertNull( $this->header( 'HX-Location' ) );
		self::assertSame( array(), $this->redirects );
		self::assertStringContainsString( 'Enter the Push-to-Deploy secret.', $html );
		self::assertStringNotContainsString( 'secret-canary', $html );
		self::assertNull( $this->header( 'HX-Trigger-After-Swap' ) );

		$this->headers  = array();
		$this->statuses = array();
		$_POST          = array();
		unset( $_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET'] );
		$this->capture_termination(
			fn () => $facade->respond_to_provider_profile_validation_failure(
				$request,
				'Enter the Push-to-Deploy secret.'
			)
		);

		self::assertCount( 1, $this->redirects );
		self::assertStringContainsString( 'ran_booster_interaction_outcome=validation_failure', $this->redirects[0] );
	}

	public function test_core_provider_profile_route_rejects_an_operation_view_mismatch(): void {
		$_GET['view'] = 'secrets';

		$this->expectException( InvalidArgumentException::class );
		$this->facade()->provider_profile_request( 'save-access-profile', 'fixture' );
	}

	private function request(): AdminInteractionRequest {
		return AdminInteractionRequest::provider_repositories(
			'repository-webhook-management:manage-webhook',
			$this->canonical_url(),
			'repository-webhook-management-error'
		);
	}

	private function canonical_url(): string {
		return 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh&panel=repositories&repository=101#ran-booster-repository-webhook-management-operation-heading';
	}

	private function transporter_request(): AdminInteractionRequest {
		return AdminInteractionRequest::transporter_migration_source_row(
			'wp-pusher:review-package',
			'wp-pusher:package-deadbeef',
			$this->transporter_canonical_url(),
			'wp-pusher-migration-error'
		);
	}

	private function transporter_canonical_url(): string {
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

	private function set_enhanced_request(): void {
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-task-panel';
		$_POST['ran_booster_interaction'] = array(
			'operation' => 'repository-webhook-management:manage-webhook',
			'target'    => 'provider_repositories',
		);
	}

	private function set_transporter_enhanced_request( AdminInteractionRequest $request ): void {
		$_SERVER['HTTP_HX_REQUEST']       = 'true';
		$_SERVER['HTTP_HX_TARGET']        = $request->target_element_id();
		$_POST['ran_booster_interaction'] = array(
			'operation' => $request->operation(),
			'target'    => $request->target_key(),
		);
	}

	private function capture_termination( callable $callback ): void {
		try {
			$callback();
			self::fail( 'The facade response must terminate the request.' );
		} catch ( InteractionTerminated ) {
			$this->addToAssertionCount( 1 );
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

	private function load_query_from_url( string $url ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Focused local URL fixture.
		$query = (string) parse_url( $url, PHP_URL_QUERY );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The tested facade verifies the signed query after this fixture assignment.
		parse_str( $query, $_GET );
	}
}


// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused termination sentinel belongs with its facade test.
final class InteractionTerminated extends RuntimeException {
}
