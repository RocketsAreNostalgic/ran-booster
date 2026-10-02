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
	private readonly WordPressInstallationStore $installation_store;
	private readonly WebhookAssistanceFacade $assistance;
	private readonly ?ManagedPackageWebhookAuthorityResolver $authorities;
	private bool $enabled = false;

	public function __construct(
		WebhookAssistanceFacade $facade,
		private readonly AdminInteractionFacade $admin_interaction,
		private readonly ProviderRegistry $providers,
		private readonly string $plugin_path,
		private readonly string $plugin_url,
		?ManagedPackageWebhookAuthorityResolver $authorities = null
	) {
		$this->assistance         = $facade;
		$this->authorities        = $authorities;
		$store                    = new WordPressInstallationStore();
		$this->installation_store = $store;
		$this->display            = new WebhookDisplayModel( $facade, $store );
		$this->controller         = new WebhookManagementController(
			new WebhookOperationCoordinator( $facade, $store ),
			$this->display,
			$providers,
			$authorities
		);
		$this->controller->use_admin_interaction_facade( $admin_interaction );
	}

	public function register(): void {
		$this->enabled = true;
		foreach ( $this->controller->provider_metadata_list() as $metadata ) {
			$provider_code  = $metadata->code->value;
			$provider_label = $metadata->label;
			add_filter(
				'ran_booster_documentation_sections_after_provider_' . $provider_code,
				fn ( array $sections ): array => $this->documentation_sections( $sections, $provider_code, $provider_label ),
				10,
				1
			);
		}
		add_action( 'admin_post_' . WebhookManagementController::ADMIN_POST_ACTION, array( $this, 'handle_admin_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'ran_booster_admin_package_advanced_source_sections', array( $this, 'render_package_webhook_setup' ), 30, 5 );
	}

	/** Render the Core webhook control on its Branch package source pane. */
	public function render_package_webhook_setup( string $mode, string $type, string $selected_source, ?\RAN\Admin\AdminPackageProjection $package, string $return_url ): void {
		if ( 'edit' !== $mode || 'branch' !== $selected_source || null === $package || 'branch' !== $package->source() ) {
			return;
		}

		$authority = null === $this->authorities ? null : $this->authorities->for_package( $type, $package->identifier() );
		if ( null === $authority || ! $this->supports_provider( $authority['provider_code'] ) ) {
			$this->render_unavailable_package_webhook_setup(
				null === $authority
					? __( 'A stable repository identity and a provider with assisted webhook management are required before setup can begin.', 'ran-booster' )
					: __( 'This provider does not offer the complete assisted webhook-management capability required by Booster.', 'ran-booster' )
			);
			return;
		}

		$metadata = $this->controller->provider_metadata( $authority['provider_code'] );
		if ( ! $metadata instanceof ProviderMetadata || ! current_user_can( 'manage_options' ) ) {
			$this->render_unavailable_package_webhook_setup( __( 'You are not permitted to manage this package webhook.', 'ran-booster' ) );
			return;
		}

		$context = $this->controller->panel_context();
		$model   = $this->display->panel( $authority['provider_code'], $metadata->label, $authority['repository_id'], $return_url, $context['result'], $context['recovery'], true, $context['remediation'] );
		if ( null === $model ) {
			$this->render_unavailable_package_webhook_setup( $this->package_webhook_unavailable_reason( $authority['provider_code'], $authority['repository_id'], $package->identifier() ) );
			return;
		}

		$form_attributes = '';
		$open            = null !== $context['result'] || null !== $context['recovery'] || null !== $context['remediation'];
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
	private function package_webhook_unavailable_reason( string $provider_code, string $repository_id, string $package_identifier ): string {
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
			$readiness = $this->assistance->readiness( $provider_code )->to_array();
			$codes     = $readiness['site']['reason_codes'] ?? array();
			foreach ( $readiness['repositories'] ?? array() as $repository ) {
				if ( ! is_array( $repository ) ) {
					continue;
				}
				$references = is_array( $repository['package_references'] ?? null ) ? $repository['package_references'] : array();
				if ( ( $repository['repository_id'] ?? null ) === $repository_id || in_array( $package_identifier, $references, true ) ) {
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

	private function render_unavailable_package_webhook_setup( string $reason ): void {
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

	public function supports_provider( string $provider_code ): bool {
		return $this->enabled && $this->controller->provider_metadata( $provider_code ) instanceof ProviderMetadata;
	}

	/** Whether a registered provider claims either webhook-management facet. */
	public function has_management_capability( string $provider_code ): bool {
		return $this->enabled && $this->claimed_provider_metadata( $provider_code ) instanceof ProviderMetadata;
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repository_projections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrich_repository_rows( array $rows, string $provider_code, array $repository_projections, string $return_url ): array {
		$metadata = $this->supports_provider( $provider_code ) ? $this->controller->provider_metadata( $provider_code ) : null;
		if ( ! $metadata instanceof ProviderMetadata ) {
			return $this->display->enrich_historical_rows( $rows, $provider_code, $repository_projections );
		}

		return $this->display->enrich_rows( $rows, $provider_code, $metadata->label, $metadata->repository_url_base, $repository_projections, $return_url );
	}

	public function render_repository_panel( string $provider_code, string $repository_id, string $return_url ): bool {
		$model = $this->repository_webhook_panel_model( $provider_code, $repository_id, $return_url );
		if ( null === $model ) {
			return false;
		}
		$model['webhooks_url'] = $this->repository_webhook_settings_url( $provider_code, $repository_id, $model['repository'] );
		echo $this->render_repository_webhook_panel_model( $model ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed Core panel template escapes the normalized model.

		return true;
	}

	/** @return array<string,mixed>|null */
	private function repository_webhook_panel_model( string $provider_code, string $repository_id, string $return_url ): ?array {
		$metadata = $this->supports_provider( $provider_code ) ? $this->controller->provider_metadata( $provider_code ) : null;
		if ( ! $metadata instanceof ProviderMetadata ) {
			return null;
		}

		$context = $this->controller->panel_context();
		return $this->display->panel( $provider_code, $metadata->label, $repository_id, $return_url, $context['result'], $context['recovery'], current_user_can( 'manage_options' ), $context['remediation'] );
	}

	/** @param array<string,mixed> $model */
	private function render_repository_webhook_panel_model( array $model ): string {
		ob_start();
		if ( null !== ( $model['interaction_request'] ?? null ) ) {
			$this->admin_interaction->render_form_attributes( $model['interaction_request'] );
		}
		$form_attributes = (string) ob_get_clean();
		ob_start();
		require __DIR__ . '/views/panel.php';
		return (string) ob_get_clean();
	}

	/** Render the existing webhook setup disclosure on its repository-owned page. */
	public function render_repository_webhook_setup( string $provider_code, string $repository_id, string $return_url, bool $has_branch_consumer = true, string $repository = '' ): void {
		$claimed_metadata = $this->claimed_provider_metadata( $provider_code );
		if ( ! $this->supports_provider( $provider_code ) && $claimed_metadata instanceof ProviderMetadata ) {
			$this->render_repository_webhook_section(
				$this->incomplete_capability_readiness_items( $has_branch_consumer ),
				'',
				$has_branch_consumer,
				array(
					array(
						'class'   => 'notice-warning',
						/* translators: %s: provider name. */
						'message' => sprintf( __( '%s webhook configuration is incomplete. Webhook setup is unavailable until the provider supplies its complete management capability.', 'ran-booster' ), $claimed_metadata->label ),
					),
				),
				false
			);

			return;
		}
		$readiness_items = $this->repository_webhook_readiness_items( $provider_code, $repository_id, $has_branch_consumer );
		$metadata        = $this->controller->provider_metadata( $provider_code );
		$model           = $has_branch_consumer ? $this->repository_webhook_panel_model( $provider_code, $repository_id, $return_url ) : null;
		$notices         = array();
		if ( ! is_array( $model ) && $metadata instanceof ProviderMetadata ) {
			$reason    = ! $has_branch_consumer
				? __( 'Webhook operations are unavailable while no eligible Branch package uses this repository.', 'ran-booster' )
				: $this->repository_webhook_unavailable_reason( $provider_code, $repository_id );
			$model     = $this->display->unavailable_panel(
				$provider_code,
				$metadata->label,
				$repository_id,
				( '' !== trim( $repository ) ) ? $repository : $repository_id,
				$return_url,
				$reason,
				$this->repository_webhook_settings_url( $provider_code, $repository_id, ( '' !== trim( $repository ) ) ? $repository : null )
			);
			$notices[] = array(
				'class'   => 'notice-warning',
				'message' => $reason,
			);
		}
		if ( is_array( $model ) ) {
			$model['webhooks_url'] = $this->repository_webhook_settings_url( $provider_code, $repository_id, $model['repository'] );
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

		$this->render_repository_webhook_section( $readiness_items, is_array( $model ) ? $this->render_repository_webhook_panel_model( $model ) : '', $has_branch_consumer, $notices );
	}

	private function repository_webhook_settings_url( string $provider_code, string $repository_id, ?string $fallback_repository ): ?string {
		try {
			$repository = $fallback_repository;
			if ( null === $repository ) {
				return null;
			}
			$provider = $this->providers->require_capability( $provider_code, RepositoryWebhookSettingsLink::class );
			$url      = null === $repository ? '' : trim( $provider->repository_webhook_settings_url( $repository ) );
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

	private function repository_webhook_unavailable_reason( string $provider_code, string $repository_id ): string {
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
			$readiness = $this->assistance->readiness( $provider_code )->to_array();
			$codes     = $readiness['site']['reason_codes'] ?? array();
			foreach ( $readiness['repositories'] ?? array() as $repository ) {
				if ( is_array( $repository ) && ( $repository['repository_id'] ?? null ) === $repository_id ) {
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

	private function render_repository_webhook_setup_region( string $panel ): void {
		?>
		<div class="ran-booster-repository-webhook-setup__content">
			<?php echo $panel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured output is rendered and escaped by the fixed Core panel view. ?>
		</div>
		<?php
	}

	/** @param list<array{label:string,message:string,state:string}> $items @param list<array{class:string,message:string}> $notices */
	private function render_repository_webhook_section( array $items, string $panel, bool $has_branch_consumer, array $notices, bool $setup_available = true ): void {
		$inactive = ! $has_branch_consumer || ! $setup_available;
		?>
		<section class="ran-booster-settings-section ran-booster-repository-webhook-section" aria-labelledby="ran-booster-repository-webhook-heading">
			<header class="ran-booster-settings-section__header">
				<h3 id="ran-booster-repository-webhook-heading"><?php esc_html_e( 'Push-to-deploy', 'ran-booster' ); ?></h3>
			</header>
			<div class="ran-booster-settings-section__body">
				<div class="ran-booster-repository-webhook-management__notices">
					<?php foreach ( $notices as $notice ) { ?>
						<div class="notice <?php echo esc_attr( $notice['class'] ); ?> inline ran-booster-repository-webhook-management__notice"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
					<?php } ?>
				</div>
				<?php $this->render_repository_webhook_lifecycle( $items, $inactive ); ?>
				<?php $this->render_repository_webhook_readiness( $items, $inactive ); ?>
				<section class="ran-booster-readiness-panel ran-booster-repository-webhook-setup<?php echo $inactive ? ' is-inactive' : ''; ?>" aria-labelledby="ran-booster-repository-webhook-setup-heading"<?php echo $inactive ? ' aria-disabled="true"' : ''; ?>>
					<div class="ran-booster-readiness-panel__top"><div><h4 id="ran-booster-repository-webhook-setup-heading"><?php esc_html_e( 'Webhook setup', 'ran-booster' ); ?></h4><p><?php esc_html_e( 'Sets up this repository’s webhook. Automatic updates remain configured separately for each package.', 'ran-booster' ); ?></p></div></div>
					<div class="ran-booster-repository-webhook-setup__body">
						<?php $this->render_repository_webhook_setup_region( $panel ); ?>
					</div>
				</section>
			</div>
		</section>
		<?php
	}

	/** @return list<array{label:string,message:string,state:string}> */
	private function incomplete_capability_readiness_items( bool $has_branch_consumer ): array {
		return array(
			array(
				'label'   => __( 'Branch demand', 'ran-booster' ),
				'message' => $has_branch_consumer
					? __( 'Branch packages can still be updated manually while webhook setup is unavailable.', 'ran-booster' )
					: __( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', 'ran-booster' ),
				'state'   => 'is-pending',
			),
			array(
				'label'   => __( 'Signing profile', 'ran-booster' ),
				'message' => __( 'Provider configuration is incomplete, so Booster did not inspect signing-secret availability.', 'ran-booster' ),
				'state'   => 'is-pending',
			),
			array(
				'label'   => __( 'Provider receiver', 'ran-booster' ),
				'message' => __( 'Provider configuration is incomplete, so Booster did not inspect receiver readiness.', 'ran-booster' ),
				'state'   => 'is-pending',
			),
			array(
				'label'   => __( 'Remote hook', 'ran-booster' ),
				'message' => __( 'Provider configuration is incomplete, so Booster did not inspect remote webhook state.', 'ran-booster' ),
				'state'   => 'is-pending',
			),
		);
	}

	private function claimed_provider_metadata( string $provider_code ): ?ProviderMetadata {
		try {
			$metadata = $this->providers->metadata()[ $provider_code ] ?? null;
			$provider = $this->providers->get( $provider_code );
		} catch ( \Throwable ) {
			return null;
		}

		return $metadata instanceof ProviderMetadata
			&& hash_equals( $provider_code, $metadata->code->value )
			&& ( $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookFitness || $provider instanceof \RAN\RepositoryProvider\RepositoryWebhookManagement )
			? $metadata
			: null;
	}

	/**
	 * @return list<array{label:string,message:string,state:string}>
	 */
	private function repository_webhook_readiness_items( string $provider_code, string $repository_id, bool $has_branch_consumer ): array {
		$site       = null;
		$repository = null;
		try {
			$readiness = $this->assistance->readiness( $provider_code )->to_array();
			$site      = is_array( $readiness['site'] ?? null ) ? $readiness['site'] : null;
			foreach ( is_array( $readiness['repositories'] ?? null ) ? $readiness['repositories'] : array() as $candidate ) {
				if ( is_array( $candidate ) && ( $candidate['repository_id'] ?? null ) === $repository_id ) {
					$repository = $candidate;
					break;
				}
			}
		} catch ( \Throwable ) {
			$site       = null;
			$repository = null;
		}

		$policies        = is_array( $repository['deployment_policies'] ?? null ) ? $repository['deployment_policies'] : array();
		$automatic_count = is_int( $policies['automatic'] ?? null ) ? $policies['automatic'] : 0;
		$record          = $this->installation_store->find( $provider_code, $repository_id );
		$secret_coverage = is_string( $repository['local_secret_coverage'] ?? null )
			? $repository['local_secret_coverage']
			: ( null === $record ? 'unknown' : 'recorded-' . $record->webhook_profile_scope() );
		$receiver_ready  = 'ready' === ( $site['status'] ?? null );
		$record_healthy  = null !== $record
			&& 'configured' === $record->status()
			&& is_string( $site['callback_url'] ?? null )
			&& hash_equals( $record->endpoint(), (string) $site['callback_url'] );

		return array(
			array(
				'label'   => __( 'Branch demand', 'ran-booster' ),
				'message' => ! $has_branch_consumer
					? __( 'Published-release packages ignore pushes; no Branch package currently uses this repository webhook.', 'ran-booster' )
					: ( 0 < $automatic_count
					? sprintf(
						/* translators: %d: number of Automatic Branch packages using the repository webhook. */
						_n( '%d Automatic Branch package uses this repository webhook.', '%d Automatic Branch packages use this repository webhook.', $automatic_count, 'ran-booster' ),
						$automatic_count
					)
					: __( 'No package uses this webhook yet. You can set it up before turning on Automatic updates.', 'ran-booster' ) ),
				'state'   => 0 < $automatic_count ? 'is-ok' : 'is-pending',
			),
			array(
				'label'   => __( 'Signing profile', 'ran-booster' ),
				'message' => match ( $secret_coverage ) {
					'repository', 'shared' => __( 'A signing secret is ready for this repository.', 'ran-booster' ),
					'recorded-repository', 'recorded-owner' => __( 'A signing secret was available when Booster last checked.', 'ran-booster' ),
					'none' => __( 'This repository needs a signing secret.', 'ran-booster' ),
					default => __( 'Booster could not confirm the signing secret.', 'ran-booster' ),
				},
				'state'   => in_array( $secret_coverage, array( 'repository', 'shared', 'recorded-repository', 'recorded-owner' ), true ) ? 'is-ok' : ( 'none' === $secret_coverage ? 'is-warning' : 'is-pending' ),
			),
			array(
				'label'   => __( 'Provider receiver', 'ran-booster' ),
				'message' => $receiver_ready
					? __( 'This site can receive webhook deliveries.', 'ran-booster' )
					: __( 'This site needs a public HTTPS URL to receive webhooks. Manual updates still work.', 'ran-booster' ),
				'state'   => $receiver_ready ? 'is-ok' : ( null === $site ? 'is-pending' : 'is-warning' ),
			),
			array(
				'label'   => __( 'Remote hook', 'ran-booster' ),
				'message' => null === $record
					? __( 'Booster has not set up a webhook for this repository.', 'ran-booster' )
					: ( $record_healthy
						? sprintf(
							/* translators: %s: UTC timestamp of the last recorded webhook check. */
							__( 'Configured at the last recorded check on %s. Run Check to confirm current provider state.', 'ran-booster' ),
							$record->checked_at()
						)
						: __( 'The recorded remote webhook needs review. Run Check or inspect it at the provider.', 'ran-booster' ) ),
				'state'   => $record_healthy ? 'is-ok' : ( null === $record ? 'is-pending' : 'is-warning' ),
			),
		);
	}

	/** @param list<array{label:string,message:string,state:string}> $items */
	private function render_repository_webhook_lifecycle( array $items, bool $inactive = false ): void {
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
	private function render_repository_webhook_readiness( array $items, bool $inactive = false ): void {
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
	public function documentation_sections( array $sections, string $provider_code, string $provider_label ): array {
		if ( ! $this->enabled || null === $this->controller->provider_metadata( $provider_code ) ) {
			return $sections;
		}
		/* translators: %s: repository provider name. */
		$summary    = sprintf( __( '%s webhook management', 'ran-booster' ), $provider_label ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$sections[] = array(
			'id'      => 'ran-booster-repository-webhook-management-guide-' . $provider_code,
			'summary' => $summary,
			'content' => fn (): null => $this->render_documentation_content( $provider_label ),
		);

		return $sections;
	}

	public function render_documentation_content( string $provider_label ): null {
		$sections = $this->display->documentation( $provider_label );
		require __DIR__ . '/views/documentation.php';

		return null;
	}

	public function handle_admin_post(): void {
		$request  = is_array( $_POST ) ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The controller verifies the operation-bound nonce before dispatch.
		$query    = is_array( $_GET ) ? wp_unslash( $_GET ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads the nonce that the controller verifies before dispatch.
		$nonce    = is_string( $query['_wpnonce'] ?? null ) ? trim( $query['_wpnonce'] ) : '';
		$redirect = $this->controller->handle_admin_post( $request, $nonce );

		wp_safe_redirect( $redirect );
		exit;
	}

	public function enqueue_admin_assets( string $hook_suffix ): void {
		$query           = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		$provider_code   = is_array( $query ) && is_string( $query['tab'] ?? null ) ? trim( $query['tab'] ) : '';
		$provider_screen = 'toplevel_page_ran-booster' === $hook_suffix
			&& is_array( $query ) && 'ran-booster' === ( $query['page'] ?? '' )
			&& null !== $this->controller->provider_metadata( $provider_code );
		$package_screen  = is_array( $query ) && in_array( $hook_suffix, array( 'ran-booster_page_ran-booster-plugins', 'ran-booster_page_ran-booster-themes' ), true )
			&& in_array( $query['page'] ?? null, array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			&& is_string( $query['package'] ?? null ) && '' !== trim( $query['package'] ) && strlen( $query['package'] ) <= 191;
		if ( ! $provider_screen && ! $package_screen ) {
			return;
		}

		$relative_path = 'assets/ran-booster-repository-webhook-management.css';
		$style_path    = $this->plugin_path . $relative_path;
		$style_version = file_exists( $style_path ) ? (string) filemtime( $style_path ) : null;

		wp_enqueue_style(
			self::ADMIN_STYLE_HANDLE,
			$this->plugin_url . $relative_path,
			array( 'ran-booster-styles' ),
			$style_version
		);
	}
}
