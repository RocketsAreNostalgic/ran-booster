<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Installation\WordPressInstallationStore;
use RAN\Admin\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookSettingsLink;

/** @internal Core placement for providers offering the complete webhook-management capability. */
final class RepositoryWebhookManagementControls {
	private const ADMIN_STYLE_HANDLE = 'ran-booster-repository-webhook-management';

	private readonly WebhookManagementController $controller;

	private readonly WebhookDisplayModel $display;
	private readonly WordPressInstallationStore $installationStore;
	private readonly WebhookAssistanceFacade $assistance;
	private readonly ?ManagedPackageWebhookAuthorityResolver $authorities;
	private bool $enabled = false;

	public function __construct(
		WebhookAssistanceFacade $facade,
		private readonly AdminInteractionFacade $adminInteraction,
		private readonly ProviderRegistry $providers,
		private readonly string $pluginPath,
		private readonly string $pluginUrl,
		?ManagedPackageWebhookAuthorityResolver $authorities = null
	) {
		$this->assistance        = $facade;
		$this->authorities       = $authorities;
		$store                   = new WordPressInstallationStore();
		$this->installationStore = $store;
		$this->display           = new WebhookDisplayModel( $facade, $store );
		$this->controller        = new WebhookManagementController(
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
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'renderPackageWebhookSetup' ), 30, 5 );
	}

	/** Render the Core webhook control on its Branch package source pane. */
	public function renderPackageWebhookSetup( string $mode, string $type, string $selectedSource, ?\RAN\Admin\AdminPackageProjection $package, string $returnUrl ): void {
		if ( 'edit' !== $mode || 'branch' !== $selectedSource || null === $package || 'branch' !== $package->source() ) {
			return;
		}

		$authority = null === $this->authorities ? null : $this->authorities->forPackage( $type, $package->identifier() );
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
		$model = $this->repositoryWebhookPanelModel( $providerCode, $repositoryId, $returnUrl );
		if ( null === $model ) {
			return false;
		}
		$model['webhooks_url'] = $this->repositoryWebhookSettingsUrl( $providerCode, $repositoryId, $model['repository'] );
		echo $this->renderRepositoryWebhookPanelModel( $model ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed Core panel template escapes the normalized model.

		return true;
	}

	/** @return array<string,mixed>|null */
	private function repositoryWebhookPanelModel( string $providerCode, string $repositoryId, string $returnUrl ): ?array {
		$metadata = $this->supportsProvider( $providerCode ) ? $this->controller->providerMetadata( $providerCode ) : null;
		if ( ! $metadata instanceof ProviderMetadata ) {
			return null;
		}

		$context = $this->controller->panelContext();
		return $this->display->panel( $providerCode, $metadata->label, $repositoryId, $returnUrl, $context['result'], $context['recovery'], current_user_can( 'manage_options' ), $context['remediation'] );
	}

	/** @param array<string,mixed> $model */
	private function renderRepositoryWebhookPanelModel( array $model ): string {
		ob_start();
		if ( null !== ( $model['interaction_request'] ?? null ) ) {
			$this->adminInteraction->renderFormAttributes( $model['interaction_request'] );
		}
		$formAttributes = (string) ob_get_clean();
		ob_start();
		require __DIR__ . '/views/panel.php';
		return (string) ob_get_clean();
	}

	/** Render the existing webhook setup disclosure on its repository-owned page. */
	public function renderRepositoryWebhookSetup( string $providerCode, string $repositoryId, string $returnUrl, bool $hasBranchConsumer = true, string $repository = '' ): void {
		$readinessItems = $this->repositoryWebhookReadinessItems( $providerCode, $repositoryId, $hasBranchConsumer );
		$metadata       = $this->controller->providerMetadata( $providerCode );
		$model          = $hasBranchConsumer ? $this->repositoryWebhookPanelModel( $providerCode, $repositoryId, $returnUrl ) : null;
		$notices        = array();
		if ( ! is_array( $model ) && $metadata instanceof ProviderMetadata ) {
			$reason    = ! $hasBranchConsumer
				? __( 'Webhook operations are unavailable while no eligible Branch package uses this repository.', 'ran-booster' )
				: $this->repositoryWebhookUnavailableReason( $providerCode, $repositoryId );
			$model     = $this->display->unavailablePanel(
				$providerCode,
				$metadata->label,
				$repositoryId,
				'' !== trim( $repository ) ? $repository : $repositoryId,
				$returnUrl,
				$reason,
				$this->repositoryWebhookSettingsUrl( $providerCode, $repositoryId, '' !== trim( $repository ) ? $repository : null )
			);
			$notices[] = array(
				'class'   => 'notice-warning',
				'message' => $reason,
			);
		}
		if ( is_array( $model ) ) {
			$model['webhooks_url'] = $this->repositoryWebhookSettingsUrl( $providerCode, $repositoryId, $model['repository'] );
			if ( is_array( $model['result'] ?? null ) ) {
				$notices[] = $model['result'];
			}
			if ( is_string( $model['recovery_warning'] ?? null ) ) {
				$notices[] = array(
					'class'   => 'notice-warning',
					'message' => $model['recovery_warning'],
				);
			}
			$model['result']           = null;
			$model['recovery_warning'] = null;
		}

		$this->renderRepositoryWebhookSection( $readinessItems, is_array( $model ) ? $this->renderRepositoryWebhookPanelModel( $model ) : '', $hasBranchConsumer, $notices );
	}

	private function repositoryWebhookSettingsUrl( string $providerCode, string $repositoryId, ?string $fallbackRepository ): ?string {
		try {
			$repository = $fallbackRepository;
			if ( null === $repository ) {
				return null;
			}
			$provider = $this->providers->requireCapability( $providerCode, RepositoryWebhookSettingsLink::class );
			$url      = null === $repository ? '' : trim( $provider->repositoryWebhookSettingsUrl( $repository ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Provider returns an external display URL which is checked before rendering.
			$parts = parse_url( $url );
			if ( '' === $url || strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) || false === $parts || 'https' !== strtolower( $parts['scheme'] ?? '' ) || '' === ( $parts['host'] ?? '' ) || array_intersect_key( $parts, array_flip( array( 'user', 'pass', 'query', 'fragment' ) ) ) ) {
				return null;
			}

			return $url;
		} catch ( \Throwable ) {
			return null;
		}
	}

	private function repositoryWebhookUnavailableReason( string $providerCode, string $repositoryId ): string {
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
				if ( is_array( $repository ) && $repositoryId === ( $repository['repository_id'] ?? null ) ) {
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

	private function renderRepositoryWebhookSetupRegion( string $panel ): void {
		?>
		<div class="ran-booster-repository-webhook-setup__content">
			<?php echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured output is rendered and escaped by the fixed Core panel view. ?>
		</div>
		<?php
	}

	/** @param list<array{label:string,message:string,state:string}> $items @param list<array{class:string,message:string}> $notices */
	private function renderRepositoryWebhookSection( array $items, string $panel, bool $hasBranchConsumer, array $notices ): void {
		?>
		<section class="ran-booster-settings-section ran-booster-repository-webhook-section" aria-labelledby="ran-booster-repository-webhook-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Repository webhook', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<div class="ran-booster-repository-webhook-management__notices">
					<?php foreach ( $notices as $notice ) { ?>
						<div class="notice <?php echo esc_attr( $notice['class'] ); ?> inline ran-booster-repository-webhook-management__notice"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
					<?php } ?>
				</div>
				<?php $this->renderRepositoryWebhookLifecycle( $items, ! $hasBranchConsumer ); ?>
				<?php $this->renderRepositoryWebhookReadiness( $items, ! $hasBranchConsumer ); ?>
				<section class="ran-booster-readiness-panel ran-booster-repository-webhook-setup<?php echo $hasBranchConsumer ? '' : ' is-inactive'; ?>" aria-labelledby="ran-booster-repository-webhook-setup-heading"<?php echo $hasBranchConsumer ? '' : ' aria-disabled="true"'; ?>>
					<div class="ran-booster-readiness-panel__top"><div><h4 id="ran-booster-repository-webhook-setup-heading"><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></h4><p><?php esc_html_e( 'Sets up this repository’s webhook. Automatic updates remain configured separately for each package.', 'ran-booster' ); ?></p></div></div>
					<div class="ran-booster-repository-webhook-setup__body">
						<?php $this->renderRepositoryWebhookSetupRegion( $panel ); ?>
					</div>
				</section>
			</div>
		</section>
		<?php
	}

	/**
	 * @return list<array{label:string,message:string,state:string}>
	 */
	private function repositoryWebhookReadinessItems( string $providerCode, string $repositoryId, bool $hasBranchConsumer ): array {
		$site       = null;
		$repository = null;
		try {
			$readiness = $this->assistance->readiness( $providerCode )->toArray();
			$site      = is_array( $readiness['site'] ?? null ) ? $readiness['site'] : null;
			foreach ( is_array( $readiness['repositories'] ?? null ) ? $readiness['repositories'] : array() as $candidate ) {
				if ( is_array( $candidate ) && $repositoryId === ( $candidate['repository_id'] ?? null ) ) {
					$repository = $candidate;
					break;
				}
			}
		} catch ( \Throwable ) {
			$site       = null;
			$repository = null;
		}

		$policies       = is_array( $repository['deployment_policies'] ?? null ) ? $repository['deployment_policies'] : array();
		$automaticCount = is_int( $policies['automatic'] ?? null ) ? $policies['automatic'] : 0;
		$record         = $this->installationStore->find( $providerCode, $repositoryId );
		$secretCoverage = is_string( $repository['local_secret_coverage'] ?? null )
			? $repository['local_secret_coverage']
			: ( null === $record ? 'unknown' : 'recorded-' . $record->webhookProfileScope() );
		$receiverReady  = 'ready' === ( $site['status'] ?? null );
		$recordHealthy  = null !== $record
			&& 'configured' === $record->status()
			&& is_string( $site['callback_url'] ?? null )
			&& hash_equals( $record->endpoint(), (string) $site['callback_url'] );

		return array(
			array(
				'label'   => __( 'Branch demand', 'ran-booster' ),
				'message' => ! $hasBranchConsumer
					? __( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', 'ran-booster' )
					: ( 0 < $automaticCount
					? sprintf(
						/* translators: %d: number of Automatic Branch packages using the repository webhook. */
						_n( '%d Automatic Branch package uses this repository webhook.', '%d Automatic Branch packages use this repository webhook.', $automaticCount, 'ran-booster' ),
						$automaticCount
					)
					: __( 'No package uses this webhook yet. You can set it up before turning on Automatic updates.', 'ran-booster' ) ),
				'state'   => 0 < $automaticCount ? 'is-ok' : 'is-pending',
			),
			array(
				'label'   => __( 'Signing profile', 'ran-booster' ),
				'message' => match ( $secretCoverage ) {
					'repository', 'shared' => __( 'A signing secret is ready for this repository.', 'ran-booster' ),
					'recorded-repository', 'recorded-owner' => __( 'A signing secret was available when Booster last checked.', 'ran-booster' ),
					'none' => __( 'This repository needs a signing secret.', 'ran-booster' ),
					default => __( 'Booster could not confirm the signing secret.', 'ran-booster' ),
				},
				'state'   => in_array( $secretCoverage, array( 'repository', 'shared', 'recorded-repository', 'recorded-owner' ), true ) ? 'is-ok' : ( 'none' === $secretCoverage ? 'is-warning' : 'is-pending' ),
			),
			array(
				'label'   => __( 'Provider receiver', 'ran-booster' ),
				'message' => $receiverReady
					? __( 'This site can receive webhook deliveries.', 'ran-booster' )
					: __( 'This site needs a public HTTPS URL to receive webhooks. Manual updates still work.', 'ran-booster' ),
				'state'   => $receiverReady ? 'is-ok' : ( null === $site ? 'is-pending' : 'is-warning' ),
			),
			array(
				'label'   => __( 'Remote hook', 'ran-booster' ),
				'message' => null === $record
					? __( 'Booster has not set up a webhook for this repository.', 'ran-booster' )
					: ( $recordHealthy
						? sprintf(
							/* translators: %s: UTC timestamp of the last recorded webhook check. */
							__( 'Configured at the last recorded check on %s. Run Check to confirm current provider state.', 'ran-booster' ),
							$record->checkedAt()
						)
						: __( 'The recorded remote webhook needs review. Run Check or inspect it at the provider.', 'ran-booster' ) ),
				'state'   => $recordHealthy ? 'is-ok' : ( null === $record ? 'is-pending' : 'is-warning' ),
			),
		);
	}

	/** @param list<array{label:string,message:string,state:string}> $items */
	private function renderRepositoryWebhookLifecycle( array $items, bool $inactive = false ): void {
		$steps = array(
			array(
				'label' => __( 'Site receiver', 'ran-booster' ),
				'item'  => $items[2] ?? null,
			),
			array(
				'label' => __( 'Signing secret', 'ran-booster' ),
				'item'  => $items[1] ?? null,
			),
			array(
				'label' => __( 'Repository webhook', 'ran-booster' ),
				'item'  => $items[3] ?? null,
			),
			array(
				'label' => __( 'Automatic packages', 'ran-booster' ),
				'item'  => $items[0] ?? null,
			),
		);
		?>
		<ol class="ran-booster-webhook-steps ran-booster-repository-webhook-lifecycle<?php echo $inactive ? ' is-inactive' : ''; ?>" aria-label="<?php esc_attr_e( 'Repository webhook lifecycle', 'ran-booster' ); ?>">
		<?php
		foreach ( $steps as $number => $step ) {
			$item  = $step['item'];
			$state = is_array( $item ) && is_string( $item['state'] ?? null ) ? $item['state'] : 'is-pending';
			?>
				<li class="ran-booster-webhook-step <?php echo esc_attr( $state ); ?>">
					<span aria-hidden="true"><?php echo esc_html( (string) ( $number + 1 ) ); ?></span>
					<strong><?php echo esc_html( $step['label'] ); ?></strong>
					<p><?php echo esc_html( is_array( $item ) && is_string( $item['message'] ?? null ) ? $item['message'] : __( 'Booster could not confirm this step.', 'ran-booster' ) ); ?></p>
				</li>
		<?php } ?>
		</ol>
		<?php
	}

	/** @param list<array{label:string,message:string,state:string}> $items */
	private function renderRepositoryWebhookReadiness( array $items, bool $inactive = false ): void {
		?>
		<div class="ran-booster-readiness-panel ran-booster-repository-webhook-readiness<?php echo $inactive ? ' is-inactive' : ''; ?>"<?php echo $inactive ? ' aria-disabled="true"' : ''; ?>>
			<div class="ran-booster-readiness-panel__top"><div><h4><?php esc_html_e( 'Webhook readiness', 'ran-booster' ); ?></h4></div></div>
			<ul class="ran-booster-readiness-list">
			<?php foreach ( $items as $item ) { ?>
				<li class="ran-booster-readiness-item <?php echo esc_attr( $item['state'] ); ?>">
					<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
					<strong><?php echo esc_html( $item['label'] ); ?></strong>
					<span><?php echo esc_html( $item['message'] ); ?></span>
				</li>
			<?php } ?>
			</ul>
		</div>
		<?php
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
