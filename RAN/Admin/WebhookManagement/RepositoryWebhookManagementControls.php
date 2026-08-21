<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Display\WebhookHistory;
use RAN\Admin\WebhookManagement\Installation\WordPressInstallationStore;
use RAN\Admin\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;

/** @internal Core placement for providers offering the complete webhook-management capability. */
final class RepositoryWebhookManagementControls {
	private const ADMIN_STYLE_HANDLE = 'ran-booster-repository-webhook-management';

	private readonly WebhookManagementController $controller;

	private readonly WebhookDisplayModel $display;
	private readonly WebhookHistory $history;
	private bool $enabled = false;

	public function __construct(
		WebhookAssistanceFacade $facade,
		private readonly AdminInteractionFacade $adminInteraction,
		ManagedPackageWebhookAuthorityResolver $authorities,
		ProviderRegistry $providers,
		private readonly string $pluginPath,
		private readonly string $pluginUrl
	) {
		$store            = new WordPressInstallationStore();
		$this->display    = new WebhookDisplayModel( $facade, $store );
		$this->history    = new WebhookHistory( $authorities, $store );
		$this->controller = new WebhookManagementController(
			new WebhookOperationCoordinator( $facade, $store ),
			$this->display,
			$providers
		);
		$this->controller->useAdminInteractionFacade( $adminInteraction );
	}

	public function register(): void {
		$this->enabled = true;
		foreach ( $this->controller->providerMetadataList() as $metadata ) {
			$providerCode  = $metadata->code->value;
			$providerLabel = $metadata->label;
			add_filter(
				'ran_booster_documentation_sections_after_provider_' . $providerCode,
				fn ( array $sections ): array => $this->documentationSections( $sections, $providerCode, $providerLabel ),
				10,
				1
			);
		}
		add_action( 'admin_post_' . WebhookManagementController::ADMIN_POST_ACTION, array( $this, 'handleAdminPost' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdminAssets' ) );
		add_action( 'ran_booster_admin_package_settings_sections', array( $this, 'renderPackageHistory' ), 10, 2 );
	}

	public function renderPackageHistory( \RAN\Admin\AdminPackageProjection $package, string $returnUrl ): void {
		$history = $this->history->forPackage( $package->type(), $package->identifier() );
		if ( null === $history ) {
			return;
		}
		$view = $history->toArray();
		echo '<section class="ran-booster-package-webhook-history"><h3>' . esc_html__( 'Remote webhook history', 'ran-booster' ) . '</h3><p>' . esc_html( $this->historicalStatusLabel( $view['recorded_status'] ) ) . '</p><p>' . esc_html__( 'Last checked by RAN Booster', 'ran-booster' ) . ': ' . esc_html( $view['checked_at'] ) . '</p><p>' . esc_html__( 'This is a historical record, not live readiness or a signed delivery.', 'ran-booster' ) . '</p></section>';
	}

	private function historicalStatusLabel( string $status ): string {
		return match ( $status ) {
			'configured'             => __( 'Configured at last check', 'ran-booster' ),
			'profile_revision_stale' => __( 'Signing secret changed; webhook update required', 'ran-booster' ),
			'local_profile_missing'  => __( 'Secret needs attention', 'ran-booster' ),
			default                  => sprintf(
				/* translators: %s: webhook status label. */
				__( 'Needs attention: %s at last check', 'ran-booster' ),
				'configuration_drift' === $status ? __( 'Configuration drift', 'ran-booster' ) : ucwords( str_replace( '_', ' ', $status ) )
			),
		};
	}

	public function supportsProvider( string $providerCode ): bool {
		return $this->enabled && $this->controller->providerMetadata( $providerCode ) instanceof ProviderMetadata;
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrichRepositoryRows( array $rows, string $providerCode, array $repositoryProjections, string $returnUrl ): array {
		$metadata = $this->supportsProvider( $providerCode ) ? $this->controller->providerMetadata( $providerCode ) : null;
		if ( ! $metadata instanceof ProviderMetadata ) {
			return $this->display->enrichHistoricalRows( $rows, $providerCode, $repositoryProjections );
		}

		return $this->display->enrichRows( $rows, $providerCode, $metadata->label, $metadata->repositoryUrlBase, $repositoryProjections, $returnUrl );
	}

	public function renderRepositoryPanel( string $providerCode, string $repositoryId, string $returnUrl ): void {
		$metadata = $this->supportsProvider( $providerCode ) ? $this->controller->providerMetadata( $providerCode ) : null;
		if ( ! $metadata instanceof ProviderMetadata ) {
			return;
		}

		$context = $this->controller->panelContext();
		$model   = $this->display->panel( $providerCode, $metadata->label, $repositoryId, $returnUrl, $context['result'], $context['recovery'], current_user_can( 'manage_options' ), $context['remediation'] );
		if ( null === $model ) {
			return;
		}

		ob_start();
		$this->adminInteraction->renderFormAttributes( $model['interaction_request'] );
		$formAttributes = (string) ob_get_clean();
		require __DIR__ . '/views/panel.php';
	}

	/** @param list<array<string, mixed>> $sections @return list<array<string, mixed>> */
	public function documentationSections( array $sections, string $providerCode, string $providerLabel ): array {
		if ( ! $this->enabled || null === $this->controller->providerMetadata( $providerCode ) ) {
			return $sections;
		}
		/* translators: %s: repository provider name. */
		$summary    = sprintf( __( '%s webhook management', 'ran-booster' ), $providerLabel );
		$sections[] = array(
			'id'      => 'ran-booster-repository-webhook-management-guide-' . $providerCode,
			'summary' => $summary,
			'content' => fn (): null => $this->renderDocumentationContent( $providerLabel ),
		);

		return $sections;
	}

	public function renderDocumentationContent( string $providerLabel ): null {
		$sections = $this->display->documentation( $providerLabel );
		require __DIR__ . '/views/documentation.php';

		return null;
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
		$query        = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		$providerCode = is_array( $query ) && is_string( $query['tab'] ?? null ) ? trim( $query['tab'] ) : '';
		if ( 'toplevel_page_ran-booster' !== $hookSuffix
			|| ! is_array( $query )
			|| 'ran-booster' !== ( $query['page'] ?? '' )
			|| null === $this->controller->providerMetadata( $providerCode ) ) {
			return;
		}

		$relativePath = 'assets/ran-booster-repository-webhook-management.css';
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
