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
	private readonly ManagedPackageWebhookAuthorityResolver $authorities;
	private readonly WebhookAssistanceFacade $assistance;
	private bool $enabled = false;

	public function __construct(
		WebhookAssistanceFacade $facade,
		private readonly AdminInteractionFacade $adminInteraction,
		ManagedPackageWebhookAuthorityResolver $authorities,
		ProviderRegistry $providers,
		private readonly string $pluginPath,
		private readonly string $pluginUrl
	) {
		$this->assistance  = $facade;
		$store             = new WordPressInstallationStore();
		$this->authorities = $authorities;
		$this->display     = new WebhookDisplayModel( $facade, $store );
		$this->history     = new WebhookHistory( $authorities, $store );
		$this->controller  = new WebhookManagementController(
			new WebhookOperationCoordinator( $facade, $store ),
			$this->display,
			$providers,
			$authorities
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
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'renderPackageWebhookSetup' ), 30, 5 );
	}

	/** Render the fixed Core webhook control on its Branch package source pane. */
	public function renderPackageWebhookSetup( string $mode, string $type, string $selectedSource, ?\RAN\Admin\AdminPackageProjection $package, string $returnUrl ): void {
		if ( 'edit' !== $mode || 'branch' !== $selectedSource || null === $package
			|| 'branch' !== $package->source()
		) {
			return;
		}

		$authority = $this->authorities->forPackage( $type, $package->identifier() );
		if ( null === $authority || ! $this->supportsProvider( $authority['provider_code'] ) ) {
			$this->renderUnavailablePackageWebhookSetup(
				null === $authority
					? __( 'A stable repository identity and a provider with assisted webhook management are required before setup can begin.', 'ran-booster' )
					: __( 'This provider does not offer the complete assisted webhook-management capability required by Booster.', 'ran-booster' )
			);
			return;
		}

		$metadata = $this->controller->providerMetadata( $authority['provider_code'] );
		if ( ! $metadata instanceof ProviderMetadata || ! current_user_can( 'manage_options' ) ) {
			$this->renderUnavailablePackageWebhookSetup( __( 'You are not permitted to manage this package webhook.', 'ran-booster' ) );
			return;
		}

		$context = $this->controller->panelContext();
		$model   = $this->display->panel( $authority['provider_code'], $metadata->label, $authority['repository_id'], $returnUrl, $context['result'], $context['recovery'], true, $context['remediation'] );
		if ( null === $model ) {
			$this->renderUnavailablePackageWebhookSetup( $this->packageWebhookUnavailableReason( $authority['provider_code'], $authority['repository_id'], $package->identifier() ) );
			return;
		}

		$formAttributes = '';
		$open           = null !== $context['result'] || null !== $context['recovery'] || null !== $context['remediation'];
		?>
		<details class="ran-booster-package-disclosure ran-booster-package-webhook-setup" data-ran-booster-package-webhook-setup<?php echo $open ? ' open' : ''; ?>>
			<summary><strong><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></strong></summary>
			<div class="ran-booster-package-disclosure__body ran-booster-package-webhook-setup__body">
				<p><?php esc_html_e( 'Set up or check this repository webhook. This does not enable Automatic updates; choose that separately in Package operation. Enhanced operations return to these package settings.', 'ran-booster' ); ?></p>
				<?php require __DIR__ . '/views/panel.php'; ?>
			</div>
		</details>
		<?php
	}

	/** Explain a failed local readiness projection without probing a provider. */
	private function packageWebhookUnavailableReason( string $providerCode, string $repositoryId, string $packageIdentifier ): string {
		$messages = array(
			'callback_requires_public_https'  => __( 'This site needs a public HTTPS URL before a provider can deliver webhooks. Local receivers cannot be reached remotely.', 'ran-booster' ),
			'database_unavailable'            => __( 'Webhook setup is unavailable because Booster database storage is unavailable.', 'ran-booster' ),
			'secrets_storage_unavailable'     => __( 'Webhook setup is unavailable because encrypted signing-secret storage is unavailable.', 'ran-booster' ),
			'managed_packages_unavailable'    => __( 'Webhook setup is unavailable because Booster could not read managed packages.', 'ran-booster' ),
			'repository_identity_unavailable' => __( 'Webhook setup is unavailable because this repository has no stable provider identity.', 'ran-booster' ),
			'repository_identity_conflict'    => __( 'Webhook setup is unavailable because managed packages disagree about this repository identity.', 'ran-booster' ),
			'repository_locator_invalid'      => __( 'Webhook setup is unavailable because the saved repository address is invalid.', 'ran-booster' ),
		);
		try {
			$readiness = $this->assistance->readiness( $providerCode )->toArray();
			$codes     = $readiness['site']['reason_codes'] ?? array();
			foreach ( $readiness['repositories'] ?? array() as $repository ) {
				if ( ! is_array( $repository ) ) {
					continue;
				}
				$references = is_array( $repository['package_references'] ?? null ) ? $repository['package_references'] : array();
				if ( $repositoryId === ( $repository['repository_id'] ?? null ) || in_array( $packageIdentifier, $references, true ) ) {
					$codes = array_merge( $codes, is_array( $repository['reason_codes'] ?? null ) ? $repository['reason_codes'] : array() );
				}
			}
			foreach ( $codes as $code ) {
				if ( is_string( $code ) && isset( $messages[ $code ] ) ) {
					return $messages[ $code ];
				}
			}
		} catch ( \Throwable ) {
			return __( 'Webhook setup is unavailable until Booster can confirm this managed repository.', 'ran-booster' );
		}

		return __( 'Webhook setup is unavailable until Booster can confirm this managed repository.', 'ran-booster' );
	}

	private function renderUnavailablePackageWebhookSetup( string $reason ): void {
		?>
		<details class="ran-booster-package-disclosure ran-booster-package-webhook-setup" data-ran-booster-package-webhook-setup>
			<summary><strong><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></strong></summary>
			<div class="ran-booster-package-disclosure__body ran-booster-package-webhook-setup__body">
				<p><?php echo esc_html( $reason ); ?></p>
				<p><button type="button" class="button" disabled><?php esc_html_e( 'Set up webhook', 'ran-booster' ); ?></button></p>
			</div>
		</details>
		<?php
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

	public function renderRepositoryPanel( string $providerCode, string $repositoryId, string $returnUrl ): bool {
		$metadata = $this->supportsProvider( $providerCode ) ? $this->controller->providerMetadata( $providerCode ) : null;
		if ( ! $metadata instanceof ProviderMetadata ) {
			return false;
		}

		$context = $this->controller->panelContext();
		$model   = $this->display->panel( $providerCode, $metadata->label, $repositoryId, $returnUrl, $context['result'], $context['recovery'], current_user_can( 'manage_options' ), $context['remediation'] );
		if ( null === $model ) {
			return false;
		}

		ob_start();
		$this->adminInteraction->renderFormAttributes( $model['interaction_request'] );
		$formAttributes = (string) ob_get_clean();
		require __DIR__ . '/views/panel.php';

		return true;
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
		$query          = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		$providerCode   = is_array( $query ) && is_string( $query['tab'] ?? null ) ? trim( $query['tab'] ) : '';
		$providerScreen = 'toplevel_page_ran-booster' === $hookSuffix
			&& is_array( $query ) && 'ran-booster' === ( $query['page'] ?? '' )
			&& null !== $this->controller->providerMetadata( $providerCode );
		$packageScreen  = is_array( $query ) && in_array( $hookSuffix, array( 'ran-booster_page_ran-booster-plugins', 'ran-booster_page_ran-booster-themes' ), true )
			&& in_array( $query['page'] ?? null, array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			&& is_string( $query['package'] ?? null ) && '' !== trim( $query['package'] ) && strlen( $query['package'] ) <= 191;
		if ( ! $providerScreen && ! $packageScreen ) {
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
