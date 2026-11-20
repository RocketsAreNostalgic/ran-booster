<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\Interaction;

use function RAN\Admin\Interaction\wp_make_link_relative;

require_once __DIR__ . '/AdminInteractionWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
		$GLOBALS['ran_booster_interaction_test_meta']         = array();
		unset( $GLOBALS['ran_booster_notice_scope_screen'], $GLOBALS['ran_booster_interaction_test_user'], $GLOBALS['ran_booster_interaction_test_blog'], $GLOBALS['ran_booster_interaction_test_write_fails'] );
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
		self::assertSame( 2, substr_count( $attributes, '#ran-booster-provider-profile-region' ) );
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
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
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

	public function test_transporter_target_requires_a_complete_instance(): void {
		$target   = AdminInteractionTarget::TRANSPORTER_MIGRATION_SOURCE;
		$instance = str_repeat( 'a', 32 );
		self::assertSame( 'transporter_migration_source_' . $instance, $target->key( $instance ) );
		self::assertSame( '#ran-booster-transporter-migration-source-' . $instance, $target->selector( $instance ) );
		foreach ( array( null, '', 'invalid', str_repeat( 'a', 31 ) ) as $invalid ) {
			foreach ( array( 'key', 'selector' ) as $method ) {
				try {
					$target->$method( $invalid );
					self::fail( 'Expected an invalid migration instance to be rejected.' );
				} catch ( InvalidArgumentException $exception ) {
					self::assertSame( 'Transporter migration targets require a Core-derived row instance.', $exception->getMessage() );
				}
			}
		}
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

	public function test_enhanced_success_reloads_the_provider_view_with_signed_feedback(): void {
		$facade = $this->facade();
		$this->set_enhanced_request();
		$GLOBALS['ran_booster_interaction_test_actions'] = array();

		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'GitHub webhook configured.' )
			)
		);

		// A located swap would carry the message only on its follow-up request, where nothing shows it.
		self::assertSame( array( 200 ), $this->statuses );
		$redirect = $this->header( 'HX-Redirect' );
		self::assertNotNull( $redirect );
		self::assertNull( $this->header( 'HX-Location' ) );
		self::assertNull( $this->header( 'HX-Trigger-After-Swap' ) );
		self::assertSame( array(), $this->redirects );
		self::assertStringNotContainsString( 'configured', rawurldecode( $redirect ) );

		$this->load_query_from_url( $redirect );
		$this->headers = array();
		$_POST         = array();
		unset( $_SERVER['HTTP_HX_REQUEST'], $_SERVER['HTTP_HX_TARGET'] );
		$facade->prepare_pending_feedback();
		$notices = $GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array();
		self::assertCount( 1, $notices );
		ob_start();
		$notices[0]['callback']();
		self::assertStringContainsString( 'GitHub webhook configured.', (string) ob_get_clean() );
	}

	public function test_tampered_pending_feedback_is_ignored(): void {
		$facade = $this->facade();
		$this->set_enhanced_request();
		$this->capture_termination(
			fn () => $facade->respond(
				AdminInteractionOutcome::success( $this->request(), 'Original success.' )
			)
		);
		$this->load_query_from_url( (string) $this->header( 'HX-Redirect' ) );
		$_GET['ran_booster_interaction_outcome'] = 'validation_failure';
		$_SERVER['HTTP_HX_REQUEST']              = 'true';
		$_SERVER['HTTP_HX_TARGET']               = 'ran-booster-provider-profile-region';
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
		$this->load_query_from_url( (string) $this->header( 'HX-Redirect' ) );
		$_GET['repository']         = '202';
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_TARGET']  = 'ran-booster-provider-profile-region';
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
		self::assertNull( $this->header( 'HX-Redirect' ), 'A failure stays in the panel; only a success reloads the provider view.' );
		self::assertSame( 'unset', $this->header( 'HX-Reselect' ) );
		self::assertSame( 'outerHTML', $this->header( 'HX-Reswap' ) );
		self::assertStringContainsString( 'Could not verify &lt;the hook&gt;.', $html );
		self::assertStringNotContainsString( '<the hook>', $html );
		self::assertStringContainsString( 'data-ran-booster-ui-notice-intent="error"', $html );
		self::assertStringContainsString( 'data-ran-booster-ui-notice-scope="global" role="alert" tabindex="-1"', $html );
		self::assertStringContainsString( 'class="notice notice-error inline ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-admin-mutation-error', $html );
		self::assertMatchesRegularExpression( '~<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p data-ran-booster-ui-notice-primary>Could not verify &lt;the hook&gt;\.</p></div><svg class="ran-wp-ui-notice__icon"[^>]*>(?:<path [^>]*></path>)+</svg></div>~', $html );
	}

	public function test_normal_request_retains_signed_post_redirect_get_notice(): void {
		$request = $this->request();
		$cases   = array(
			array( AdminInteractionOutcome::success( $request, 'GitHub webhook configured.' ), 'success', 'status', 'GitHub webhook configured.' ),
			array( AdminInteractionOutcome::accepted( $request, 'Request accepted.' ), 'info', 'status', 'Request accepted.' ),
			array( AdminInteractionOutcome::validation_failure( $request, 'Could not verify <the hook>.' ), 'error', 'alert', 'Could not verify &lt;the hook&gt;.' ),
			array( AdminInteractionOutcome::unexpected_failure( $request ), 'error', 'alert', 'We could not complete that request. Please try again.' ),
		);
		foreach ( $cases as [ $outcome, $intent, $role, $message ] ) {
			$this->redirects                                 = array();
			$GLOBALS['ran_booster_interaction_test_actions'] = array();
			$facade = $this->facade();
			$this->capture_termination( fn () => $facade->respond( $outcome ) );

			self::assertCount( 1, $this->redirects );
			$this->load_query_from_url( $this->redirects[0] );
			$facade->prepare_pending_feedback();
			$notices = $GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array();
			self::assertCount( 1, $notices );

			ob_start();
			$notices[0]['callback']();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'notice notice-' . $intent, $html );
			self::assertStringContainsString( 'data-ran-booster-ui-notice-intent="' . $intent . '"', $html );
			self::assertStringContainsString( 'data-ran-booster-ui-notice-scope="global" role="' . $role . '"', $html );
			self::assertStringContainsString( '<div data-ran-booster-ui-notice-content><p>' . $message . '</p></div></div>', $html );
		}
	}

	private const FALLBACK = 'The result details are not available. Check the page or Activity for the current state.';

	private function redirect_for( AdminInteractionOutcome $outcome ): string {
		$this->redirects = array();
		$facade          = $this->facade();
		$this->capture_termination( fn () => $facade->respond( $outcome ) );

		return $this->redirects[0];
	}

	/** Runs the pending-feedback hook against a redirect URL and returns what the notices print. */
	private function pending_html( string $url ): string {
		$GLOBALS['ran_booster_interaction_test_actions'] = array();
		$this->load_query_from_url( $url );
		$this->facade()->prepare_pending_feedback();
		ob_start();
		foreach ( $GLOBALS['ran_booster_interaction_test_actions']['admin_notices'] ?? array() as $notice ) {
			$notice['callback']();
		}

		return (string) ob_get_clean();
	}

	private function held_row(): mixed {
		return $GLOBALS['ran_booster_interaction_test_meta'][1][ SignedAdminInteractionFlow::MESSAGE_META_KEY ] ?? null;
	}

	public function test_no_outcome_redirect_carries_display_text(): void {
		$request = $this->request();
		foreach ( array(
			AdminInteractionOutcome::success( $request, 'Secret-looking success text.' ),
			AdminInteractionOutcome::accepted( $request, 'Accepted text.' ),
			AdminInteractionOutcome::validation_failure( $request, 'Failure text.' ),
		) as $outcome ) {
			$url = $this->redirect_for( $outcome );

			self::assertStringNotContainsString( 'message', $url );
			self::assertStringNotContainsString( rawurlencode( $outcome->message() ), $url );
			self::assertStringNotContainsString( 'text', $url );
			// Negative control: the text is held server-side, so the check above could fail.
			self::assertSame( $outcome->message(), $this->held_row()['message'] );
		}
	}

	/** @return array<string, array{0: string}> */
	public static function round_trip_messages(): array {
		return array(
			'plain'            => array( 'Add-on saved.' ),
			'single backslash' => array( 'Path C:\\Temp saved.' ),
			'double backslash' => array( 'UNC \\\\server\\share saved.' ),
		);
	}

	#[DataProvider( 'round_trip_messages' )]
	public function test_outcome_text_round_trips_through_the_one_shot_row( string $message ): void {
		$url  = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), $message ) );
		$html = $this->pending_html( $url );

		self::assertStringContainsString( '<p>' . htmlspecialchars( $message, ENT_QUOTES ) . '</p>', $html );
		self::assertStringNotContainsString( self::FALLBACK, $html );
		self::assertNull( $this->held_row(), 'The claim must delete the row.' );
	}

	public function test_outcome_text_is_one_shot_and_a_no_javascript_reload_shows_the_neutral_fallback(): void {
		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Saved once.' ) );

		self::assertStringContainsString( 'Saved once.', $this->pending_html( $url ) );
		$reload = $this->pending_html( $url );
		self::assertStringContainsString( self::FALLBACK, $reload );
		self::assertStringContainsString( 'notice notice-info', $reload );
		self::assertStringContainsString( 'role="status"', $reload );
		self::assertStringNotContainsString( 'Saved once.', $reload );
	}

	public function test_another_user_never_receives_the_text_and_sees_only_the_fallback(): void {
		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Private.' ) );
		$GLOBALS['ran_booster_interaction_test_user'] = 2;
		$html = $this->pending_html( $url );
		unset( $GLOBALS['ran_booster_interaction_test_user'] );

		self::assertStringNotContainsString( 'Private.', $html );
		self::assertStringContainsString( self::FALLBACK, $html );
		self::assertNotNull( $this->held_row(), 'The owner keeps their own row.' );
	}

	public function test_text_is_scoped_to_the_site_that_wrote_it(): void {
		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Site one.' ) );
		$GLOBALS['ran_booster_interaction_test_blog'] = 2;
		$html = $this->pending_html( $url );
		unset( $GLOBALS['ran_booster_interaction_test_blog'] );

		self::assertStringNotContainsString( 'Site one.', $html );
		self::assertStringContainsString( self::FALLBACK, $html );
		self::assertSame( 1, $this->held_row()['site'] );
		self::assertStringContainsString( 'Site one.', $this->pending_html( $url ) );
	}

	public function test_expired_text_shows_the_fallback_and_is_deleted(): void {
		$url        = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Old.' ) );
		$held       = $this->held_row();
		$held['at'] = time() - 120;
		$GLOBALS['ran_booster_interaction_test_meta'][1][ SignedAdminInteractionFlow::MESSAGE_META_KEY ] = $held;
		$html = $this->pending_html( $url );

		self::assertStringContainsString( self::FALLBACK, $html );
		self::assertStringNotContainsString( 'Old.', $html );
		self::assertNull( $this->held_row() );
	}

	public function test_an_expired_row_is_reaped_on_admin_init_without_any_marker(): void {
		$this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Abandoned.' ) );
		$_GET       = array();
		$held       = $this->held_row();
		$held['at'] = time() - 120;
		$GLOBALS['ran_booster_interaction_test_meta'][1][ SignedAdminInteractionFlow::MESSAGE_META_KEY ] = $held;
		$this->facade()->prepare_pending_feedback();

		self::assertNull( $this->held_row() );
	}

	public function test_an_unexpired_row_survives_admin_init_without_its_marker(): void {
		$this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Pending.' ) );
		$_GET = array();
		$this->facade()->prepare_pending_feedback();

		self::assertNotNull( $this->held_row() );
	}

	public function test_a_failed_write_falls_back_to_the_neutral_notice(): void {
		$GLOBALS['ran_booster_interaction_test_write_fails'] = true;
		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Lost.' ) );
		unset( $GLOBALS['ran_booster_interaction_test_write_fails'] );
		$html = $this->pending_html( $url );

		self::assertStringContainsString( self::FALLBACK, $html );
		self::assertStringNotContainsString( 'Lost.', $html );
		self::assertStringNotContainsStringIgnoringCase( 'retry', self::FALLBACK );
		self::assertStringNotContainsStringIgnoringCase( 'failed', self::FALLBACK );
	}

	public function test_the_fallback_is_neutral_for_every_kind_and_never_a_timed_success_event(): void {
		foreach ( array(
			AdminInteractionOutcome::success( $this->request(), 'S.' ),
			AdminInteractionOutcome::accepted( $this->request(), 'A.' ),
			AdminInteractionOutcome::validation_failure( $this->request(), 'V.' ),
		) as $outcome ) {
			$GLOBALS['ran_booster_interaction_test_meta'] = array();
			$url = $this->redirect_for( $outcome );
			$GLOBALS['ran_booster_interaction_test_meta'] = array();
			$html = $this->pending_html( $url );
			self::assertStringContainsString( 'notice notice-info', $html );
			self::assertStringContainsString( self::FALLBACK, $html );
		}

		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Gone.' ) );
		$GLOBALS['ran_booster_interaction_test_meta'] = array();
		$this->load_query_from_url( $url );
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_SERVER['HTTP_HX_TARGET']  = 'ran-booster-provider-profile-region';
		$this->headers              = array();
		$this->facade()->prepare_pending_feedback();

		self::assertNull( $this->header( 'HX-Trigger-After-Swap' ) );
		self::assertNotNull( $this->header( 'HX-Replace-Url' ) );
	}

	public function test_a_wrong_kind_or_wrong_nonce_shows_nothing_even_with_a_held_row(): void {
		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Real.' ) );
		foreach ( array(
			'wrong kind'  => array( 'ran_booster_interaction_outcome', 'validation_failure' ),
			'wrong nonce' => array( '_ran_booster_interaction_nonce', 'forged' ),
		) as [ $field, $value ] ) {
			$GLOBALS['ran_booster_interaction_test_actions'] = array();
			$this->load_query_from_url( $url );
			$_GET[ $field ] = $value;
			$this->facade()->prepare_pending_feedback();

			self::assertArrayNotHasKey( 'admin_notices', $GLOBALS['ran_booster_interaction_test_actions'] );
			self::assertNotNull( $this->held_row(), 'A rejected marker must not consume the row.' );
		}
	}

	public function test_no_outcome_row_carries_a_secret_shaped_event_id(): void {
		$this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Bounded.' ) );

		self::assertSame( array( 'at', 'site', 'action', 'message' ), array_keys( (array) $this->held_row() ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_success_and_accepted_use_the_feedback_host_and_failures_and_fallback_stay_notices_on_booster_screens(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/BoosterNoticeScopeWordPressFunctions.php';
		require_once dirname( __DIR__, 2 ) . '/Support/FeedbackWordPressFunctions.php';
		require_once dirname( __DIR__, 2 ) . '/Support/NoticeIconGlobalWordPressFunctions.php';
		$GLOBALS['ran_booster_notice_scope_screen'] = (object) array( 'id' => 'toplevel_page_ran-booster' );
		$request                                    = $this->request();
		$render                                     = function ( AdminInteractionOutcome $outcome, bool $lose_row = false ): string {
			$GLOBALS['ran_booster_interaction_test_meta'] = array();
			$url = $this->redirect_for( $outcome );
			if ( $lose_row ) {
				$GLOBALS['ran_booster_interaction_test_meta'] = array();
			}
			$this->pending_html( $url );
			ob_start();
			\RAN\Admin\BoosterNoticeScope::render_shell_notices();

			return (string) ob_get_clean();
		};

		foreach ( array(
			array( AdminInteractionOutcome::success( $request, 'Add-on saved <it>.' ), 'interaction_success', 'success', 'Add-on saved &lt;it&gt;.' ),
			array( AdminInteractionOutcome::accepted( $request, 'Queued.' ), 'interaction_accepted', 'info', 'Queued.' ),
		) as [ $outcome, $key, $intent, $text ] ) {
			$html = $render( $outcome );

			self::assertStringContainsString( '<div class="ran-booster-feedback" data-ran-booster-feedback>', $html );
			self::assertStringContainsString( 'data-feedback-key="' . $key . '" data-intent="' . $intent . '" role="status">' . $text . '</div>', $html );
			self::assertStringNotContainsString( 'notice notice-', $html );
		}

		foreach ( array(
			array( AdminInteractionOutcome::validation_failure( $request, 'Could not <verify>.' ), 'interaction_validation_failure' ),
			array( AdminInteractionOutcome::unexpected_failure( $request ), 'interaction_unexpected_failure' ),
		) as [ $failure, $key ] ) {
			$html = $render( $failure );
			self::assertStringContainsString( 'notice notice-error', $html );
			self::assertStringContainsString( 'role="alert"', $html );
			// The notice carries the key, so the host takes it, shows a pointer and strips the address.
			self::assertStringContainsString( 'data-feedback-key="' . $key . '"', $html );
			self::assertStringNotContainsString( 'data-ran-booster-feedback', $html );
		}

		$html = $render( AdminInteractionOutcome::success( $request, 'Gone.' ), true );
		self::assertStringContainsString( 'notice notice-info', $html );
		self::assertStringContainsString( self::FALLBACK, $html );
		self::assertStringNotContainsString( 'data-ran-booster-feedback', $html, 'The fallback is persistent, not a timed snackbar.' );
		self::assertStringNotContainsString( 'data-feedback-key', $html );
	}

	public function test_capability_and_nonce_markers_are_unchanged_by_text_removal(): void {
		$url = $this->redirect_for( AdminInteractionOutcome::success( $this->request(), 'Saved.' ) );
		$this->load_query_from_url( $url );
		$GLOBALS['ran_booster_interaction_test_actions'] = array();
		$_GET['_ran_booster_interaction_nonce']          = 'forged';
		$this->facade()->prepare_pending_feedback();

		self::assertArrayNotHasKey( 'admin_notices', $GLOBALS['ran_booster_interaction_test_actions'] );
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
				'view'      => 'signing-secrets',
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
				array( 'delete-webhook-profile', 'signing-secrets', 'Push-to-Deploy secret removed.' ),
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
		$_GET['view']                     = 'signing-secrets';
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
		$_GET['view'] = 'signing-secrets';

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
		$_SERVER['HTTP_HX_TARGET']        = 'ran-booster-provider-profile-region';
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
