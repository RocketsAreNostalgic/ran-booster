<?php

declare( strict_types = 1 );

namespace RAN\Booster\GitHub\WebhookManagement;

use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Booster\GitHub\WebhookManagement\Admin\WebhookManagementController;
use RAN\Booster\GitHub\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Booster\GitHub\WebhookManagement\Installation\WordPressInstallationStore;
use RAN\Booster\GitHub\WebhookManagement\Operation\WebhookOperationCoordinator;

/** Registers the fixed, GitHub-owned webhook-management UI and request route. */
final class GitHubWebhookManagement {
	private const ADMIN_STYLE_HANDLE = 'ran-booster-github-webhook-management';

	private readonly WebhookManagementController $controller;

	private readonly WebhookDisplayModel $display;

	public function __construct(
		WebhookAssistanceFacade $facade,
		private readonly AdminInteractionFacade $adminInteraction,
		private readonly string $pluginPath,
		private readonly string $pluginUrl
	) {
		$store            = new WordPressInstallationStore();
		$this->display    = new WebhookDisplayModel( $facade, $store );
		$this->controller = new WebhookManagementController(
			new WebhookOperationCoordinator( $facade, $store ),
			$this->display
		);
		$this->controller->useAdminInteractionFacade( $adminInteraction );
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_provider_repository_assistance_active', array( $this, 'repositoryManagementActive' ), 10, 2 );
		add_filter( 'ran_booster_admin_provider_repository_rows', array( $this, 'enrichRepositoryRows' ), 10, 4 );
		add_filter( 'ran_booster_documentation_sections_after_provider_gh', array( $this, 'filterDocumentationSections' ), 10, 3 );
		add_action( 'ran_booster_admin_provider_repository_panel', array( $this, 'renderRepositoryPanel' ), 10, 3 );
		add_action( 'admin_post_' . WebhookManagementController::ADMIN_POST_ACTION, array( $this, 'handleAdminPost' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );
	}

	public static function legacyAddOnIsActive(): bool {
		$retirementBridge = defined( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION' )
			&& 1 === constant( 'RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION' );

		return class_exists( 'RAN\AssistedHooks\Plugin', false ) && ! $retirementBridge;
	}

	public static function registerLegacyAddOnNotice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					esc_html__( 'Bundled GitHub webhook management is inactive because a pre-retirement RAN Booster Assisted Hooks release is active. Deactivate that add-on to use the bundled feature.', 'ran-booster' )
				);
			}
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrichRepositoryRows( array $rows, string $providerCode, array $repositoryProjections, string $returnUrl ): array {
		return $this->display->enrichRows( $rows, $providerCode, $repositoryProjections, $returnUrl );
	}

	public function repositoryManagementActive( bool $active, string $providerCode ): bool {
		return $active || 'gh' === $providerCode;
	}

	public function renderRepositoryPanel( string $providerCode, string $repositoryId, string $returnUrl ): void {
		$context = $this->controller->panelContext();
		$model   = $this->display->panel( $providerCode, $repositoryId, $returnUrl, $context['result'], $context['recovery'], current_user_can( 'manage_options' ) );
		if ( null === $model ) {
			return;
		}

		ob_start();
		$this->adminInteraction->renderFormAttributes( $model['interaction_request'] );
		$formAttributes = (string) ob_get_clean();
		require __DIR__ . '/views/panel.php';
	}

	/** @param list<array<string, mixed>> $sections @return list<array<string, mixed>> */
	public function filterDocumentationSections( array $sections, string $documentationUrl, string $scope ): array {
		unset( $documentationUrl, $scope );
		$sections[] = array(
			'id'      => 'ran-booster-github-webhook-management-guide',
			'summary' => __( 'GitHub webhook management', 'ran-booster' ),
			'content' => array( $this, 'renderDocumentationContent' ),
		);

		return $sections;
	}

	public function renderDocumentationContent(): void {
		$sections = $this->display->documentation();
		require __DIR__ . '/views/documentation.php';
	}

	public function handleAdminPost(): void {
		$request  = is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The controller verifies the operation-bound nonce before dispatch.
		$query    = is_array( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads the nonce that the controller verifies before dispatch.
		$nonce    = is_string( $query['_wpnonce'] ?? null ) ? trim( $query['_wpnonce'] ) : '';
		$redirect = $this->controller->handleAdminPost( $request, $nonce );

		wp_safe_redirect( $redirect );
		exit;
	}

	public function enqueueAdminAssets( string $hookSuffix ): void {
		$query = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( 'toplevel_page_ran-booster' !== $hookSuffix
			|| ! is_array( $query )
			|| 'ran-booster' !== ( $query['page'] ?? '' )
			|| 'gh' !== ( $query['tab'] ?? '' ) ) {
			return;
		}

		$relativePath = 'assets/ran-booster-github-webhook-management.css';
		$stylePath    = $this->pluginPath . $relativePath;
		$styleVersion = file_exists( $stylePath ) ? (string) filemtime( $stylePath ) : null;

		wp_enqueue_style(
			self::ADMIN_STYLE_HANDLE,
			$this->pluginUrl . $relativePath,
			array( 'ran-booster-styles' ),
			$styleVersion
		);
	}
}
