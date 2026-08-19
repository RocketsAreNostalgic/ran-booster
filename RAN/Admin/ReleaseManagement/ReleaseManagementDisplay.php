<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;

/** @internal Contributes release-specific state and actions to Core-owned package pages. */
final class ReleaseManagementDisplay {
	private const ELIGIBILITY_RECHECK_QUERY_KEY = 'ran_booster_release_recheck';

	private const ADMIN_POST_ACTIONS = array(
		'enable'           => 'ran_booster_release_enable',
		'refresh'          => 'ran_booster_release_refresh',
		'change_channel'   => 'ran_booster_release_change_channel',
		'return_to_branch' => 'ran_booster_release_return_to_branch',
	);

	public function renderSettings(
		object $package,
		?ReleaseTrackingStatus $status,
		string $settingsUrl,
		string $selectedChannel = '',
		array $nonceActions = array(),
		bool $eligibilityRecheck = false
	): void {
		if ( ! $this->isProjection( $package ) ) {
			return;
		}

		if ( null === $status ) {
			?>
			<section class="ran-booster-release-management" aria-labelledby="ran-booster-release-management-heading">
				<header>
					<h3 id="ran-booster-release-management-heading"><?php esc_html_e( 'Published release readiness', 'ran-booster' ); ?></h3>
					<p><?php esc_html_e( 'Check whether this package can use verified published releases.', 'ran-booster' ); ?></p>
				</header>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Release status is temporarily unavailable. Branch deployment settings have not been changed.', 'ran-booster' ); ?></p>
				</div>
			</section>
			<?php
			return;
		}
		$selectedChannel = 'branch' === $status->source()
			? ( $this->normalizeReleaseChannel( $selectedChannel ) ?? 'stable' )
			: $status->channel();

		$eligibility       = $status->eligibility();
		$eligibilityCode   = $eligibility->code();
		$expectedUpdateUri = $eligibility->expectedUpdateUri();
		$repositoryLabel   = '';
		$repositoryPath    = wp_parse_url( $expectedUpdateUri, PHP_URL_PATH );
		if ( is_string( $repositoryPath ) ) {
			$repositoryLabel = trim( $repositoryPath, '/' );
		}
		$providerReady    = 'unsupported_provider' !== $eligibilityCode;
		$repositoryReady  = $providerReady && 'invalid_repository' !== $eligibilityCode;
		$updateUriReady   = in_array( $eligibilityCode, array( 'eligible', 'target_already_uses_ran_updater' ), true );
		$releaseViewUrl   = add_query_arg( array( 'source_view' => 'release_asset' ), $settingsUrl );
		$recheckUrl       = add_query_arg( self::ELIGIBILITY_RECHECK_QUERY_KEY, '1', $releaseViewUrl );
		$recheckArguments = array();
		wp_parse_str( (string) wp_parse_url( $recheckUrl, PHP_URL_QUERY ), $recheckArguments );
		$recheckQueryPosition = strpos( $recheckUrl, '?' );
		$recheckActionUrl     = false === $recheckQueryPosition
			? $recheckUrl
			: substr( $recheckUrl, 0, $recheckQueryPosition );
		$branchViewUrl        = add_query_arg( array( 'source_view' => 'branch' ), $settingsUrl );
		$trackFormId          = 'ran-booster-release-track-form';
		$trackNonceAction     = null;
		$refreshNonceAction   = null;
		if ( $eligibility->eligible() && 'branch' === $status->source() ) {
			$trackNonceAction = $nonceActions['enable'] ?? null;
		} elseif ( 'release_asset' === $status->source() ) {
			$trackNonceAction   = $nonceActions['change_channel'] ?? null;
			$refreshNonceAction = $nonceActions['refresh'] ?? null;
		}
		?>
		<section class="ran-booster-release-management" aria-labelledby="ran-booster-release-management-heading">
			<header>
				<h3 id="ran-booster-release-management-heading"><?php esc_html_e( 'Published release readiness', 'ran-booster' ); ?></h3>
				<?php if ( 'branch' === $status->source() ) { ?>
					<p><strong><?php esc_html_e( 'Current source remains Branch.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Check eligibility before changing source; nothing changes until you validate and confirm the switch.', 'ran-booster' ); ?></p>
				<?php } else { ?>
					<p><strong><?php esc_html_e( 'Published releases are the package source.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Review package identity and release status. WordPress Updates installs validated releases.', 'ran-booster' ); ?></p>
				<?php } ?>
			</header>
			<div class="ran-booster-readiness-panel">
				<?php if ( $eligibilityRecheck ) { ?>
					<div class="notice notice-success inline" data-ran-booster-package-success data-ran-booster-eligibility-recheck>
						<p><strong><?php esc_html_e( 'Eligibility recheck complete.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'The current eligibility evidence is shown below.', 'ran-booster' ); ?></p>
					</div>
				<?php } ?>
				<div class="ran-booster-readiness-panel__top">
					<div>
						<h4><?php echo esc_html( $eligibility->eligible() ? __( 'Published release tracking is eligible', 'ran-booster' ) : __( 'Published releases are not eligible yet', 'ran-booster' ) ); ?></h4>
						<p><?php echo esc_html( $eligibility->eligible() ? __( 'The installed package identity matches its configured repository.', 'ran-booster' ) : $this->eligibilityMessage( $eligibility ) ); ?></p>
					</div>
					<?php if ( ! $eligibility->eligible() ) { ?>
						<span class="ran-booster-badge ran-booster-badge--error"><?php esc_html_e( 'Unavailable', 'ran-booster' ); ?></span>
					<?php } ?>
				</div>
				<ul class="ran-booster-readiness-list">
					<li class="ran-booster-readiness-item <?php echo $providerReady ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Provider', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( $providerReady ? __( 'The repository provider supports published releases.', 'ran-booster' ) : __( 'The repository provider does not support published releases.', 'ran-booster' ) ); ?></span>
					</li>
					<li class="ran-booster-readiness-item <?php echo $repositoryReady ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Repository', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( $repositoryReady && '' !== $repositoryLabel ? $repositoryLabel : __( 'The saved repository needs attention.', 'ran-booster' ) ); ?></span>
					</li>
					<li class="ran-booster-readiness-item <?php echo $updateUriReady ? 'is-ok' : 'is-warning'; ?>">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Update URI', 'ran-booster' ); ?></strong>
						<span><?php echo esc_html( $updateUriReady ? __( 'Matches the configured repository.', 'ran-booster' ) : $this->updateUriReadinessMessage( $eligibilityCode ) ); ?></span>
					</li>
					<?php if ( 'release_asset' === $status->source() ) { ?>
						<li class="ran-booster-readiness-item is-ok">
							<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Package root', 'ran-booster' ); ?></strong>
							<span><code><?php echo esc_html( $status->packageRoot() ); ?></code></span>
						</li>
						<li class="ran-booster-readiness-item is-ok">
							<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
							<strong><?php esc_html_e( 'Installation route', 'ran-booster' ); ?></strong>
							<span><?php esc_html_e( 'Native WordPress Updates.', 'ran-booster' ); ?></span>
						</li>
						<?php if ( '' === $status->failureCode() ) { ?>
							<li class="ran-booster-readiness-item is-ok">
								<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
								<strong><?php esc_html_e( 'Release status', 'ran-booster' ); ?></strong>
								<span><?php echo esc_html( $this->releaseStatusMessage( $status ) ); ?></span>
							</li>
						<?php } ?>
					<?php } ?>
				</ul>
				<?php if ( 'branch' === $status->source() && ! $eligibility->eligible() && $this->requiresUpdateUriRemediation( $eligibility ) ) { ?>
					<div class="ran-booster-settings-section__body ran-booster-release-remediation">
						<p><strong><?php esc_html_e( 'Add this exact header, deploy the corrected package, then check again:', 'ran-booster' ); ?></strong></p>
						<p class="ran-booster-release-code"><code><?php echo esc_html( 'Update URI: ' . $expectedUpdateUri ); ?></code></p>
					</div>
				<?php } ?>
				<?php if ( $eligibility->eligible() && 'branch' === $status->source() ) { ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'Booster will freshly validate a matching release before changing the package source. Automatic resets to Manual. Existing repository webhook configuration is unchanged.', 'ran-booster' ); ?></p>
					</div>
				<?php } ?>
				<?php if ( 'release_asset' === $status->source() && '' !== $status->failureCode() ) { ?>
					<div class="notice notice-warning inline">
						<p><?php echo esc_html( $this->diagnosticMessage( $status->failureCode() ) ); ?></p>
						<?php $this->renderFailureHelp( $status->failureCode() ); ?>
					</div>
				<?php } ?>
				<div class="ran-booster-readiness-actions">
					<?php if ( 'branch' === $status->source() && null !== $trackNonceAction ) { ?>
						<button type="submit" class="button button-primary" form="<?php echo esc_attr( $trackFormId ); ?>"><?php esc_html_e( 'Validate and switch source', 'ran-booster' ); ?></button>
						<a class="button" href="<?php echo esc_url( $branchViewUrl ); ?>"><?php esc_html_e( 'Keep branch source', 'ran-booster' ); ?></a>
					<?php } elseif ( 'branch' === $status->source() ) { ?>
						<form
							action="<?php echo esc_url( $recheckActionUrl ); ?>"
							method="get"
							class="ran-booster-release-recheck-form"
							data-ran-booster-enhanced-mutation
							data-ran-booster-package-mutation
							data-ran-booster-error-target="#ran-booster-package-mutation-error"
							hx-get="<?php echo esc_url( $recheckUrl ); ?>"
							hx-target="#wpbody-content"
							hx-select="#wpbody-content"
							hx-swap="outerHTML show:#ran-booster-advanced-source-settings:top"
							hx-push-url="<?php echo esc_url( $releaseViewUrl ); ?>"
							hx-sync="this:drop"
						>
							<?php foreach ( $recheckArguments as $name => $value ) { ?>
								<?php if ( is_scalar( $value ) ) { ?>
									<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
								<?php } ?>
							<?php } ?>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Recheck eligibility', 'ran-booster' ); ?></button>
						</form>
					<?php } else { ?>
						<?php if ( null !== $refreshNonceAction ) { ?>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" data-ran-booster-package-mutation>
								<?php $this->adminPostFields( 'refresh', $package, $refreshNonceAction ); ?>
								<input type="hidden" name="return_to_settings" value="1">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Check releases', 'ran-booster' ); ?></button>
							</form>
						<?php } ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Open WordPress updates', 'ran-booster' ); ?></a>
					<?php } ?>
					<?php if ( '' !== $expectedUpdateUri ) { ?>
						<span class="ran-booster-readiness-actions__links">
							<a href="<?php echo esc_url( $expectedUpdateUri ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open repository', 'ran-booster' ); ?></a>
						</span>
					<?php } ?>
				</div>
			</div>
		</section>
		<section class="ran-booster-release-track" aria-labelledby="ran-booster-release-track-heading">
			<header>
				<h3 id="ran-booster-release-track-heading"><?php esc_html_e( 'Release Track', 'ran-booster' ); ?></h3>
			</header>
			<?php if ( null !== $trackNonceAction ) { ?>
				<form id="<?php echo esc_attr( $trackFormId ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="<?php echo esc_attr( 'branch' === $status->source() ? 'ran-booster-release-switch-form' : 'ran-booster-release-channel-form' ); ?>" data-ran-booster-package-mutation>
					<?php $this->adminPostFields( 'branch' === $status->source() ? 'enable' : 'change_channel', $package, $trackNonceAction ); ?>
					<?php if ( 'branch' === $status->source() ) { ?>
						<?php $this->renderReleaseTrack( $selectedChannel, __( 'Preview includes eligible alpha, beta and release-candidate builds. Drafts remain excluded.', 'ran-booster' ), 'ran-booster-release-track-description', true ); ?>
					<?php } else { ?>
						<?php $this->renderManagedReleaseTrack( $selectedChannel ); ?>
					<?php } ?>
				</form>
			<?php } elseif ( $eligibility->eligible() || 'release_asset' === $status->source() ) { ?>
				<?php $this->renderControlsUnavailable(); ?>
			<?php } else { ?>
				<?php $this->renderIneligibleReleaseTrack( $selectedChannel ); ?>
			<?php } ?>
		</section>
		<?php
	}

	public function renderAdvancedSourceSection(
		string $mode,
		string $type,
		string $selectedSource,
		?object $package,
		?ReleaseTrackingStatus $status,
		string $pageUrl,
		string $selectedChannel = '',
		array $nonceActions = array(),
		array $prospective = array(),
		bool $eligibilityRecheck = false
	): void {
		if ( ! in_array( $mode, array( 'create', 'edit' ), true )
			|| ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return;
		}
		if ( 'create' === $mode ) {
			$this->renderProspectiveSetup( $type, $prospective );
			return;
		}
		if ( null === $package ) {
			return;
		}
		if ( 'release_asset' === $selectedSource ) {
			$this->renderSettings( $package, $status, $pageUrl, $selectedChannel, $nonceActions, $eligibilityRecheck );
		} elseif ( 'branch' === $selectedSource && $this->isProjection( $package ) && 'release_asset' === $package->source() ) {
			$this->renderReturnToBranch( $package, $nonceActions['return_to_branch'] ?? null );
		}
	}

	private function renderReturnToBranch( object $package, ?string $nonceAction ): void {
		?>
		<div class="ran-booster-release-return">
			<?php if ( null === $nonceAction ) { ?>
				<?php $this->renderControlsUnavailable(); ?>
			<?php } else { ?>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="ran-booster-release-return-form" data-ran-booster-package-mutation>
					<?php $this->adminPostFields( 'return_to_branch', $package, $nonceAction ); ?>
					<p class="description"><?php esc_html_e( 'Returning resets Automatic to Manual. Existing repository webhook configuration is unchanged.', 'ran-booster' ); ?></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Return to branch deployments', 'ran-booster' ); ?></button></p>
				</form>
			<?php } ?>
		</div>
		<?php
	}

	public function advancedSourceSummary(
		string $fallback,
		string $mode,
		string $selectedSource,
		?object $package,
		?ReleaseTrackingStatus $status
	): string {
		if ( 'create' === $mode ) {
			return 'release_asset' === $selectedSource
				? __( 'Published releases · Stable', 'ran-booster' )
				: $fallback;
		}
		if ( null === $package ) {
			return $fallback;
		}
		if ( null === $status ) {
			return $fallback;
		}
		if ( 'branch' === $selectedSource && 'release_asset' === $status->source() ) {
			return sprintf(
				/* translators: %s is the retained Branch source summary. */
				__( 'Return to %s', 'ran-booster' ),
				$fallback
			);
		}
		if ( 'release_asset' !== $selectedSource ) {
			return $fallback;
		}
		$track = $this->releaseTrackLabel(
			'release_asset' === $status->source() ? $status->channel() : 'stable'
		);

		return sprintf(
			/* translators: %s is the release track. */
			__( 'Published releases · %s', 'ran-booster' ),
			$track
		);
	}

	public function releaseTrackMeta( string $fallback, object $package, ?ReleaseTrackingStatus $status ): string {
		if ( ! $this->isProjection( $package ) || 'release_asset' !== $package->source() ) {
			return $fallback;
		}
		if ( null === $status ) {
			return $fallback;
		}

		return 'prerelease' === $status->channel()
			? __( 'Preview track', 'ran-booster' )
			: __( 'Stable track', 'ran-booster' );
	}

	public function releaseProviderSupported( object $package, ?ReleaseTrackingStatus $status ): ?bool {
		if ( ! $this->isProjection( $package ) ) {
			return null;
		}

		if ( null === $status ) {
			return null;
		}
		return 'unsupported_provider' !== $status->eligibility()->code();
	}

	private function renderReleaseTrack(
		string $selectedChannel,
		string $description,
		string $descriptionId = 'ran-booster-release-track-description',
		bool $visuallyHiddenLegend = false
	): void {
		?>
		<fieldset class="ran-booster-release-track" data-ran-booster-release-channel-control>
			<legend<?php echo $visuallyHiddenLegend ? ' class="screen-reader-text"' : ''; ?>><?php esc_html_e( 'Release track', 'ran-booster' ); ?></legend>
			<label>
				<input type="radio" name="release_channel" value="stable"<?php checked( 'stable' === $selectedChannel ); ?> aria-describedby="<?php echo esc_attr( $descriptionId ); ?>" data-ran-booster-release-channel>
				<?php echo esc_html( $this->releaseTrackLabel( 'stable' ) ); ?>
			</label>
			<label>
				<input type="radio" name="release_channel" value="prerelease"<?php checked( 'prerelease' === $selectedChannel ); ?> aria-describedby="<?php echo esc_attr( $descriptionId ); ?>" data-ran-booster-release-channel>
				<?php echo esc_html( $this->releaseTrackLabel( 'prerelease' ) ); ?>
			</label>
			<p id="<?php echo esc_attr( $descriptionId ); ?>" class="description"><?php echo esc_html( $description ); ?></p>
		</fieldset>
		<?php
	}

	private function renderManagedReleaseTrack( string $currentChannel ): void {
		$preview     = 'prerelease' === $currentChannel;
		$current     = $this->releaseTrackLabel( $currentChannel );
		$nextChannel = $preview ? 'stable' : 'prerelease';
		$switchLabel = $preview ? __( 'Switch to Stable', 'ran-booster' ) : __( 'Switch to Preview', 'ran-booster' );
		?>
		<fieldset class="ran-booster-release-track">
			<legend class="screen-reader-text"><?php esc_html_e( 'Release track', 'ran-booster' ); ?></legend>
			<p>
				<strong><?php esc_html_e( 'Current:', 'ran-booster' ); ?></strong>
				<?php echo esc_html( $current ); ?>
				<input type="hidden" name="release_channel" value="<?php echo esc_attr( $nextChannel ); ?>">
				<button type="submit" class="button"><?php echo esc_html( $switchLabel ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Preview includes prereleases, which may be unstable; switching affects future eligibility only, resets Automatic to Manual, and does not install or downgrade the package.', 'ran-booster' ); ?></p>
		</fieldset>
		<?php
	}

	private function renderIneligibleReleaseTrack( string $selectedChannel ): void {
		?>
		<fieldset class="ran-booster-release-track" disabled>
			<legend class="screen-reader-text"><?php esc_html_e( 'Release track', 'ran-booster' ); ?></legend>
			<label>
				<input type="radio" name="release_channel" value="stable"<?php checked( 'stable' === $selectedChannel ); ?>>
				<?php echo esc_html( $this->releaseTrackLabel( 'stable' ) ); ?>
			</label>
			<label>
				<input type="radio" name="release_channel" value="prerelease"<?php checked( 'prerelease' === $selectedChannel ); ?>>
				<?php echo esc_html( $this->releaseTrackLabel( 'prerelease' ) ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Stable follows final published releases. Preview also includes prereleases. Resolve the eligibility requirements above to choose a release track.', 'ran-booster' ); ?></p>
		</fieldset>
		<?php
	}

	private function renderProspectiveSetup( string $type, array $prospective ): void {
		$candidatesNonce    = is_string( $prospective['list_candidates'] ?? null ) ? $prospective['list_candidates'] : null;
		$inspectNonce       = is_string( $prospective['inspect'] ?? null ) ? $prospective['inspect'] : null;
		$installNonce       = is_string( $prospective['install'] ?? null ) ? $prospective['install'] : null;
		$supportedProviders = is_array( $prospective['providers'] ?? null ) ? $prospective['providers'] : array();
		?>
		<div
			id="ran-booster-source-pane-release_asset"
			class="ran-booster-package-source-pane ran-booster-release-pane"
			aria-labelledby="ran-booster-source-tab-release_asset"
			data-ran-booster-source-pane="release_asset"
			data-ran-booster-release-setup
			data-ran-booster-release-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-ran-booster-release-admin-post-url="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			data-ran-booster-release-type="<?php echo esc_attr( $type ); ?>"
			data-ran-booster-release-supported-providers="<?php echo esc_attr( implode( ',', $supportedProviders ) ); ?>"
			data-ran-booster-release-candidates-nonce="<?php echo esc_attr( $candidatesNonce ?? '' ); ?>"
			data-ran-booster-release-inspect-nonce="<?php echo esc_attr( $inspectNonce ?? '' ); ?>"
			data-ran-booster-release-install-nonce="<?php echo esc_attr( $installNonce ?? '' ); ?>"
			hidden
		>
			<input type="hidden" name="expected_type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="ran_booster_release_install_nonce" value="<?php echo esc_attr( $installNonce ?? '' ); ?>">
			<input type="hidden" name="release_id" value="">
			<input type="hidden" name="release_tag" value="">
			<input type="hidden" name="release_fingerprint" value="">
			<input type="hidden" name="release_channel" value="stable">
			<?php
			$this->renderReleaseTrack(
				'stable',
				__( 'Preview includes eligible alpha, beta and release-candidate builds as well as stable releases. Drafts remain excluded.', 'ran-booster' ),
				'ran-booster-release-channel-description-' . $type
			);
			?>
			<header>
				<h3><?php esc_html_e( 'Published release selection', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Choose one exact release, inspect it, then install the reviewed package.', 'ran-booster' ); ?></p>
			</header>
			<fieldset class="ran-booster-release-candidates" data-ran-booster-release-candidates hidden>
				<legend><strong><?php esc_html_e( 'Choose a published release', 'ran-booster' ); ?></strong></legend>
				<div data-ran-booster-release-candidate-list></div>
			</fieldset>
			<div class="ran-booster-release-status" role="status" aria-live="polite" aria-atomic="true" data-ran-booster-release-status>
				<div>
					<h4 data-ran-booster-release-status-heading><?php esc_html_e( 'Release candidates appear here', 'ran-booster' ); ?></h4>
					<p>
						<span data-ran-booster-release-status-message><?php esc_html_e( 'Choose a repository above, then select Published releases to load eligible stable releases.', 'ran-booster' ); ?></span>
						<button type="button" class="button-link" data-ran-booster-release-switch-branch hidden><?php esc_html_e( 'Use Branch tracking instead', 'ran-booster' ); ?></button>
					</p>
				</div>
				<button type="button" class="button" data-ran-booster-release-retry hidden><?php esc_html_e( 'Retry release check', 'ran-booster' ); ?></button>
			</div>
			<section
				class="ran-booster-readiness-panel ran-booster-release-preflight"
				aria-labelledby="ran-booster-release-preflight-heading-<?php echo esc_attr( $type ); ?>"
				data-ran-booster-release-details
				hidden
			>
				<div class="ran-booster-readiness-panel__top">
					<div>
						<h4 id="ran-booster-release-preflight-heading-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Published release inspected', 'ran-booster' ); ?></h4>
						<p><?php esc_html_e( 'The exact published release was checked. Its ZIP was downloaded, validated for repository identity, digest, headers and package identity, then discarded. Installation will freshly acquire and verify it again before WordPress changes files.', 'ran-booster' ); ?></p>
					</div>
				</div>
				<ul class="ran-booster-readiness-list">
					<li class="ran-booster-readiness-item is-ok">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Release', 'ran-booster' ); ?></strong>
						<span data-ran-booster-release-version></span>
					</li>
					<li class="ran-booster-readiness-item is-ok">
						<span class="ran-booster-readiness-icon" aria-hidden="true"></span>
						<strong><?php echo esc_html( 'plugin' === $type ? __( 'Expected plugin identity', 'ran-booster' ) : __( 'Expected theme identity', 'ran-booster' ) ); ?></strong>
						<span data-ran-booster-release-package></span>
					</li>
				</ul>
				<div class="ran-booster-action-row ran-booster-readiness-actions">
					<button type="submit" class="button button-primary" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" name="action" value="ran_booster_release_install" data-ran-booster-release-install hidden><?php echo esc_html( 'plugin' === $type ? __( 'Install published plugin', 'ran-booster' ) : __( 'Install published theme', 'ran-booster' ) ); ?></button>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<object>                        $packages
	 * @return array<string, array<string, mixed>>
	 */
	public function presentManagement( array $rows, string $surface, array $packages, array $statuses ): array {
		if ( ! in_array( $surface, array( 'plugin', 'theme' ), true ) ) {
			return $rows;
		}

		$projected = array_filter(
			$packages,
			fn ( mixed $package ): bool => is_object( $package )
				&& $this->isProjection( $package )
				&& $surface === $package->type()
				&& 'release_asset' === $package->source()
				&& isset( $rows[ $package->identifier() ] )
		);
		if ( array() === $projected ) {
			return $rows;
		}

		foreach ( $projected as $package ) {
			$identifier = $package->identifier();
			$status     = $statuses[ $identifier ] ?? null;
			if ( null === $status || ! $this->statusMatches( $status, $package ) ) {
				$rows[ $identifier ] = $this->appendExceptionalBadge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Release status unavailable', 'ran-booster' ),
						'tone'  => 'warning',
					)
				);
				continue;
			}
			if ( '' === (string) ( $rows[ $identifier ]['status'] ?? '' ) ) {
				if ( '' !== $status->failureCode() ) {
					$rows[ $identifier ]['status'] = $this->diagnosticMessage( $status->failureCode() );
				} else {
					$rows[ $identifier ]['status'] = $this->releaseStatusMessage( $status );
				}
			}

			if ( 'release_asset' === $status->source() && '' !== $status->failureCode() ) {
				$rows[ $identifier ] = $this->appendExceptionalBadge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Needs attention', 'ran-booster' ),
						'tone'  => 'error',
					)
				);
			} elseif ( 'release_asset' === $status->source() && $status->updateAvailable() ) {
				$rows[ $identifier ] = $this->appendExceptionalBadge(
					$rows[ $identifier ],
					array(
						'label' => __( 'Update available', 'ran-booster' ),
						'tone'  => 'pending',
					)
				);
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, array<string, mixed>> $actions
	 * @return array<string, array<string, mixed>>
	 */
	public function presentManagementActions( array $actions, string $surface, object $package, ?ReleaseTrackingStatus $status, ?string $refreshNonceAction ): array {
		if ( ! in_array( $surface, array( 'plugin', 'theme' ), true )
			|| ! $this->isProjection( $package )
			|| $surface !== $package->type()
			|| 'release_asset' !== $package->source() ) {
			return $actions;
		}

		$available = null !== $status && $status->eligible();
		$key       = 'ran-booster-release:refresh';
		if ( ! $status?->updateAvailable() && ! isset( $actions[ $key ] ) ) {
			$nonceAction = $available ? $refreshNonceAction : null;
			if ( null === $nonceAction ) {
				$actions[ $key ] = array(
					'key'           => $key,
					'label'         => __( 'Release check unavailable', 'ran-booster' ),
					'type'          => 'link',
					'url'           => '',
					'disabled'      => true,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => $this->boundedString( $package->displayName(), 96 ),
					'busy_label'    => __( 'Working…', 'ran-booster' ),
				);
			} else {
				$actions[ $key ] = array(
					'key'           => $key,
					'label'         => __( 'Check releases', 'ran-booster' ),
					'type'          => 'post',
					'url'           => admin_url( 'admin-post.php' ),
					'hidden'        => array(
						'action'                   => self::ADMIN_POST_ACTIONS['refresh'],
						'_wpnonce'                 => $nonceAction,
						'expected_type'            => $package->type(),
						'expected_identifier'      => $package->identifier(),
						'expected_source_revision' => (string) $package->sourceRevision(),
					),
					'disabled'      => false,
					'external'      => false,
					'described_by'  => '',
					'screen_reader' => $this->boundedString( $package->displayName(), 96 ),
					'busy_label'    => __( 'Working…', 'ran-booster' ),
				);
			}
		}

		$nativeKey = 'ran-booster-release:native-update';
		if ( null !== $status && $status->updateAvailable() && ! isset( $actions[ $nativeKey ] ) ) {
			$actions[ $nativeKey ] = array(
				'key'           => $nativeKey,
				'label'         => __( 'Open WordPress updates', 'ran-booster' ),
				'type'          => 'link',
				'url'           => admin_url( 'update-core.php' ),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => $this->boundedString( $package->displayName(), 96 ),
			);
		}

		return $actions;
	}

	private function normalizeReleaseChannel( mixed $channel ): ?string {
		return is_string( $channel )
			&& in_array( $channel, array( 'stable', 'prerelease' ), true )
				? $channel
				: null;
	}

	private function releaseTrackLabel( mixed $channel ): string {
		return 'prerelease' === $this->normalizeReleaseChannel( $channel ) ? __( 'Preview', 'ran-booster' ) : __( 'Stable', 'ran-booster' );
	}

	private function statusMatches( ReleaseTrackingStatus $status, object $package ): bool {
		return hash_equals( $package->type(), $status->type() )
			&& hash_equals( $package->identifier(), $status->identifier() )
			&& $package->sourceRevision() === $status->sourceRevision();
	}

	/**
	 * @param array<string, mixed>              $row
	 * @param array{label:string,tone:string}   $badge
	 * @return array<string, mixed>
	 */
	private function appendExceptionalBadge( array $row, array $badge ): array {
		$existingBadges = is_array( $row['badges'] ?? null ) ? $row['badges'] : array();
		$row['badges']  = array_merge( $existingBadges, array( $badge ) );

		return $row;
	}

	private function adminPostFields( string $operation, object $package, string $nonce ): void {
		?>
		<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_ACTIONS[ $operation ] ); ?>">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" name="expected_type" value="<?php echo esc_attr( $package->type() ); ?>">
		<input type="hidden" name="expected_identifier" value="<?php echo esc_attr( $package->identifier() ); ?>">
		<input type="hidden" name="expected_source_revision" value="<?php echo esc_attr( (string) $package->sourceRevision() ); ?>">
		<?php
	}

	private function renderControlsUnavailable(): void {
		?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'Published release controls are temporarily unavailable. No package settings were changed.', 'ran-booster' ); ?></p></div>
		<?php
	}

	public function renderOperationNotice(
		string $code,
		bool $successful,
		string $type = '',
		string $identifier = '',
		string $channel = '',
		?ReleaseTrackingStatus $status = null
	): void {
		if ( '' === $code ) {
			return;
		}

		$message = $this->resultMessage( $code, $successful, $type, $identifier, $channel, $status );
		$tone    = $successful
			? 'notice-success'
			: ( in_array( $code, array( 'installed_but_unmanaged', 'management_state_uncertain', 'installation_cleanup_failed' ), true )
				? 'notice-warning'
				: 'notice-error' );
		?>
		<div class="notice <?php echo esc_attr( $tone ); ?>"<?php echo $successful ? ' data-ran-booster-package-success' : ''; ?>>
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( ! $successful && $this->isDiagnosticCode( $code ) ) { ?>
				<?php $this->renderFailureHelp( $code, $message ); ?>
			<?php } ?>
		</div>
		<?php
	}

	private function resultMessage( string $code, bool $successful, string $type, string $identifier, string $channel, ?ReleaseTrackingStatus $status ): string {
		if ( ! $successful && $this->isDiagnosticCode( $code ) ) {
			return $this->diagnosticMessage( $code );
		}

		return match ( $code ) {
			'installed' => __( 'The published release was installed and is now managed by Booster. WordPress activation was not changed.', 'ran-booster' ),
			'release_enabled' => __( 'Release tracking enabled.', 'ran-booster' ),
			'release_channel_changed' => __( 'Release track changed. The installed package was not changed and Automatic was reset to Manual.', 'ran-booster' ),
			'release_channel_current' => sprintf(
				/* translators: 1: package type, 2: release track. */
				__( 'This %1$s is already using the %2$s release track. No settings were changed.', 'ran-booster' ),
				( 'theme' === $type ? __( 'theme', 'ran-booster' ) : __( 'plugin', 'ran-booster' ) ),
				$this->releaseTrackLabel( $channel )
			),
			'release_current',
			'release_update_available' => $this->refreshedResultMessage( $status ),
			'refresh_failed' => __( 'The release check could not start. Review this package’s published release settings, then retry.', 'ran-booster' ),
			'branch_restored' => __( 'Branch-based package management is restored.', 'ran-booster' ),
			'source_changed' => __( 'Package settings changed after this browser page was opened. Refresh the page, review the current settings, then try again.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already registers its own release updater. Use one release owner only.', 'ran-booster' ),
			'forbidden' => __( 'You are not authorized to perform this published release operation. No package was installed or adopted.', 'ran-booster' ),
			'unsupported_provider' => __( 'Published releases are not available for this repository provider. Use Branch tracking instead.', 'ran-booster' ),
			'invalid_request' => __( 'The published release request was invalid. No package was installed or adopted. Reload and try again.', 'ran-booster' ),
			'service_unavailable' => __( 'Published release controls are temporarily unavailable. No package settings were changed. No package was installed.', 'ran-booster' ),
			'package_already_exists' => __( 'That plugin or theme is already installed or managed. No package was overwritten.', 'ran-booster' ),
			'installed_but_unmanaged' => __( 'A package now exists but Booster did not adopt it. Verify the installed version and activation state before using Link installed or retrying.', 'ran-booster' ),
			'management_state_uncertain' => __( 'Booster could not confirm the final installed or managed state. Review installed packages and Booster management before retrying.', 'ran-booster' ),
			'installation_cleanup_failed' => __( 'Installation finalization failed while cleaning the temporary archive or releasing the updater lock. Review installed packages and Booster management before retrying.', 'ran-booster' ),
			'wordpress_refused',
			'wordpress_failed',
			'wordpress_restored',
			'wordpress_uncertain',
			'operation_mismatch' => __( 'WordPress did not install the selected published release, and Booster did not adopt it.', 'ran-booster' ),
			'install_failed' => __( 'The install request could not be completed. Review installed packages before retrying because the final state was not reported.', 'ran-booster' ),
			'no_releases' => __( 'No eligible published release is available for the selected channel. No package was installed.', 'ran-booster' ),
			'release_invalid' => __( 'The selected release does not satisfy the selected channel requirements. No package was installed.', 'ran-booster' ),
			'unable_to_check' => __( 'Published releases could not be checked. No package was installed and no package settings were changed.', 'ran-booster' ),
			'release_version_mismatch',
			'release_header_missing',
			'release_header_invalid',
			'release_archive_unreadable',
			'release_unavailable',
			'invalid_release_assets',
			'release_configuration_invalid',
			'target_registration_failed',
			'preflight_unavailable' => $this->diagnosticMessage( $code ),
			default => $successful
				? __( 'The published release operation completed.', 'ran-booster' )
				: __( 'The published release operation could not be completed.', 'ran-booster' ),
		};
	}

	private function diagnosticMessage( string $code ): string {
		return match ( $code ) {
			'release_configuration_invalid',
			'target_registration_failed' => __( 'This package uses retired published-release settings, so Booster could not start its release checker. Return it to Branch, then validate and switch it back to Published releases.', 'ran-booster' ),
			'release_version_mismatch' => __( 'Release version mismatch — check published releases.', 'ran-booster' ),
			'release_header_missing' => __( 'The published package is missing its WordPress version header.', 'ran-booster' ),
			'release_header_invalid' => __( 'The published package has an invalid WordPress version header.', 'ran-booster' ),
			'release_archive_unreadable' => __( 'The published release archive could not be inspected.', 'ran-booster' ),
			'release_unavailable' => __( 'No matching published release is available yet.', 'ran-booster' ),
			'invalid_release_assets' => __( 'The published release assets could not be validated.', 'ran-booster' ),
			'preflight_unavailable' => __( 'Published release validation is temporarily unavailable.', 'ran-booster' ),
			'release_runtime_unavailable' => __( 'This server cannot currently run the published-release validator.', 'ran-booster' ),
			'package_update_uri_missing' => __( 'The published ZIP is missing the required Update URI header.', 'ran-booster' ),
			'package_update_uri_invalid' => __( 'The published ZIP’s Update URI does not match its configured repository.', 'ran-booster' ),
			'package_compatibility_missing',
			'package_compatibility_invalid' => __( 'The published release is not compatible with this WordPress site.', 'ran-booster' ),
			'package_zip_extension_unavailable' => __( 'This server cannot inspect ZIP packages because its ZIP extension is unavailable.', 'ran-booster' ),
			'package_archive_size_invalid',
			'package_archive_too_large' => __( 'The published ZIP has an invalid or excessive uncompressed size.', 'ran-booster' ),
			'package_archive_path_unsafe',
			'package_archive_path_duplicate',
			'package_archive_root_invalid',
			'package_archive_entry_duplicate',
			'package_archive_entry_limit' => __( 'The published ZIP has an unsafe or unsupported archive structure.', 'ran-booster' ),
			'package_header_ambiguous' => __( 'The published ZIP contains more than one possible WordPress package header.', 'ran-booster' ),
			'release_version_invalid' => __( 'The published release tag does not contain a valid package version.', 'ran-booster' ),
			default => __( 'Published release status needs attention.', 'ran-booster' ),
		};
	}

	private function isDiagnosticCode( string $code ): bool {
		return str_starts_with( $code, 'package_' )
			|| str_starts_with( $code, 'release_' )
			|| in_array( $code, array( 'invalid_release_assets', 'preflight_unavailable', 'target_registration_failed' ), true );
	}

	private function diagnosticAction( string $code ): string {
		return match ( $code ) {
			'release_unavailable' => __( 'Publish a release for the selected Stable or Preview track, then recheck eligibility.', 'ran-booster' ),
			'package_update_uri_missing',
			'package_update_uri_invalid' => __( 'Correct the Update URI in the packaged plugin or theme header, publish a new ZIP, then recheck.', 'ran-booster' ),
			'package_compatibility_missing',
			'package_compatibility_invalid' => __( 'Publish a package whose WordPress and PHP compatibility headers include this site, or choose a compatible release track.', 'ran-booster' ),
			'release_version_mismatch',
			'release_version_invalid' => __( 'Make the release tag and packaged WordPress Version header describe the same valid version, then publish a corrected ZIP.', 'ran-booster' ),
			'release_header_missing',
			'release_header_invalid',
			'package_header_missing',
			'package_header_invalid',
			'package_header_ambiguous' => __( 'Correct the plugin or theme header in the distributable ZIP, publish a new release asset, then recheck.', 'ran-booster' ),
			'release_runtime_unavailable',
			'package_zip_extension_unavailable' => __( 'Ask the site administrator to restore the required PHP and temporary-file runtime support, then retry.', 'ran-booster' ),
			'release_configuration_invalid',
			'target_registration_failed' => __( 'Return the package to Branch, verify its repository settings, then validate and switch it back to Published releases.', 'ran-booster' ),
			default => __( 'Review the release, repository access and packaged WordPress headers, then retry. If it still fails, open Troubleshooting with the error code below.', 'ran-booster' ),
		};
	}

	private function refreshedResultMessage( ?ReleaseTrackingStatus $status ): string {
		return null === $status
			? __( 'The release check completed, but its version result is unavailable. Review the package status below.', 'ran-booster' )
			: $this->releaseStatusMessage( $status );
	}

	private function releaseStatusMessage( object $status ): string {
		$installed = $this->boundedString( $status->installedVersion(), 64 );
		$latest    = $this->boundedString( $status->latestVersion(), 64 );
		if ( $status->updateAvailable() && '' !== $latest ) {
			return sprintf(
				/* translators: 1: installed version, 2: available version. */
				__( 'Version %1$s is installed; version %2$s is available. WordPress controls installation.', 'ran-booster' ),
				'' !== $installed ? $installed : __( 'unknown', 'ran-booster' ),
				$latest
			);
		}

		return sprintf(
			/* translators: %s is the installed version. */
			__( 'Version %s is installed; no newer eligible release was found.', 'ran-booster' ),
			'' !== $installed ? $installed : __( 'unknown', 'ran-booster' )
		);
	}

	private function renderFailureHelp( string $code, string $message = '' ): void {
		$troubleshootingUrl = add_query_arg(
			array(
				'page'  => 'ran-booster',
				'tab'   => 'troubleshooting',
				'panel' => 'debug-capture',
			),
			admin_url( 'admin.php' )
		);
		?>
		<details>
			<summary><?php esc_html_e( 'Why this happened and how to fix it', 'ran-booster' ); ?></summary>
			<p><strong><?php esc_html_e( 'What Booster found:', 'ran-booster' ); ?></strong> <?php echo esc_html( '' !== $message ? $message : $this->diagnosticMessage( $code ) ); ?></p>
			<p><strong><?php esc_html_e( 'What to do:', 'ran-booster' ); ?></strong> <?php echo esc_html( $this->diagnosticAction( $code ) ); ?></p>
			<p><a href="<?php echo esc_url( $troubleshootingUrl ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a></p>
			<p><strong><?php esc_html_e( 'Technical reason:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $code ); ?></code></p>
		</details>
		<?php
	}

	private function eligibilityMessage( object $eligibility ): string {
		return match ( $eligibility->code() ) {
			'missing_update_uri' => __( 'This package header does not declare an Update URI for its configured repository.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'This package header Update URI does not match its configured repository.', 'ran-booster' ),
			'unsupported_provider' => __( 'The repository provider does not support published release tracking.', 'ran-booster' ),
			'invalid_repository' => __( 'The configured repository is invalid.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'This package already registers its own release updater. Use either that updater or Booster published-release tracking, not both.', 'ran-booster' ),
			default => __( 'The installed plugin or theme identity is not eligible for published release tracking.', 'ran-booster' ),
		};
	}

	private function updateUriReadinessMessage( string $eligibilityCode ): string {
		return match ( $eligibilityCode ) {
			'missing_update_uri' => __( 'Missing from the installed package header.', 'ran-booster' ),
			'mismatched_update_uri' => __( 'Does not match the configured repository.', 'ran-booster' ),
			'target_already_uses_ran_updater' => __( 'Matches, but this package already registers its own updater.', 'ran-booster' ),
			default => __( 'Cannot be validated until the provider and repository are ready.', 'ran-booster' ),
		};
	}

	private function requiresUpdateUriRemediation( object $eligibility ): bool {
		return '' !== $eligibility->expectedUpdateUri()
			&& 'target_already_uses_ran_updater' !== $eligibility->code();
	}

	private function isProjection( object $package ): bool {
		foreach ( array( 'type', 'identifier', 'displayName', 'source', 'sourceRevision', 'settingsUrl' ) as $method ) {
			if ( ! is_callable( array( $package, $method ) ) ) {
				return false;
			}
		}

		return in_array( $package->type(), array( 'plugin', 'theme' ), true )
			&& in_array( $package->source(), array( 'branch', 'release_asset' ), true )
			&& is_string( $package->identifier() )
			&& '' !== $package->identifier()
			&& $package->sourceRevision() > 0;
	}

	private function boundedString( string $value, int $maximumBytes ): string {
		if ( strlen( $value ) <= $maximumBytes ) {
			return $value;
		}

		return function_exists( 'mb_strcut' )
			? mb_strcut( $value, 0, $maximumBytes, 'UTF-8' )
			: substr( $value, 0, $maximumBytes );
	}
}
