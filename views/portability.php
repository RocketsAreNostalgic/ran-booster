<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$portabilityReviewRows                   = is_array( $portabilityReviewRows ?? null ) ? $portabilityReviewRows : array();
$portabilityExportRows                   = is_array( $portabilityExportRows ?? null ) ? $portabilityExportRows : array();
$portabilityExportUnavailable            = true === ( $portabilityExportUnavailable ?? false );
$portabilityExportCredentialGroups       = is_array( $portabilityExportCredentialGroups ?? null ) ? $portabilityExportCredentialGroups : array();
$portabilityExportCredentialsUnavailable = true === ( $portabilityExportCredentialsUnavailable ?? false );
$portabilityExportPackageCount           = count( $portabilityExportRows );
/* translators: %d: number of selected managed packages. */
$portabilityPackagePlural = __( '%d packages', 'ran-booster' );
/* translators: %d: number of selected repository credential profiles. */
$portabilityCredentialPlural = __( '%d selected repository credential profiles', 'ran-booster' );
/* translators: 1: selected package count and noun. 2: selected credential-profile count and noun. */
$portabilityProtectedSummary = __( 'Create a Transporter Blueprint for %1$s using %2$s. Credential permissions have not been assessed.', 'ran-booster' );
/* translators: %s: selected package count and noun. */
$portabilityPackageOnlySummary = __( 'Create a Transporter Blueprint for %s without repository credentials.', 'ran-booster' );
/* translators: %d: number of selected managed packages. */
$portabilityInitialPackageCount = sprintf( _nx( '%d package', '%d packages', $portabilityExportPackageCount, 'Selected managed packages', 'ran-booster' ), $portabilityExportPackageCount );
$renderPortabilityExtension     = static function ( string $hook, string $step ): void {
	$bufferLevel = ob_get_level();
	ob_start();
	try {
		do_action( $hook );
		$markup = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Add-ons own and escape their bounded extension markup.
		echo $markup;
	} catch ( \Throwable $failure ) {
		while ( ob_get_level() > $bufferLevel ) {
			ob_end_clean();
		}
		\RAN\Logging\BoosterLogger::logException( 'portability extension rendering failed', $failure, array( 'step' => $step ) );
	}
};

?>
<section class="ran-booster-page-shell ran-booster-portability ran-booster-panel" aria-labelledby="ran-booster-portability-heading">
	<header class="ran-booster-page-shell__header ran-booster-portability__header">
		<p class="ran-booster-portability__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Transporter', 'ran-booster' ); ?></p>
		<h2 id="ran-booster-portability-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Transport Booster-managed packages', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Use Transporter to create a Blueprint here or open one from another site.', 'ran-booster' ); ?></p>
	</header>

	<section class="ran-booster-portability__chooser" aria-labelledby="ran-booster-portability-mode-heading">
		<h3 id="ran-booster-portability-mode-heading" tabindex="-1"><?php esc_html_e( 'What do you want to do on this site?', 'ran-booster' ); ?></h3>
		<div class="ran-booster-portability__mode-options">
			<button type="button" class="ran-booster-portability__mode" data-portability-mode="export" aria-controls="ran-booster-portability-export" aria-expanded="false" aria-pressed="false">
				<span class="ran-booster-portability__mode-icon dashicons dashicons-upload" aria-hidden="true"></span>
				<span class="ran-booster-portability__mode-text">
					<span class="ran-booster-portability__mode-title"><?php esc_html_e( 'Create a Blueprint', 'ran-booster' ); ?></span>
					<span class="ran-booster-portability__mode-description"><?php esc_html_e( 'Create a Transporter Blueprint of the packages Booster manages here.', 'ran-booster' ); ?></span>
				</span>
			</button>
			<button type="button" class="ran-booster-portability__mode" data-portability-mode="import" aria-controls="ran-booster-portability-import" aria-expanded="false" aria-pressed="false">
				<span class="ran-booster-portability__mode-icon dashicons dashicons-download" aria-hidden="true"></span>
				<span class="ran-booster-portability__mode-text">
					<span class="ran-booster-portability__mode-title"><?php esc_html_e( 'Open a Blueprint', 'ran-booster' ); ?></span>
					<span class="ran-booster-portability__mode-description"><?php esc_html_e( 'Open a Transporter Blueprint created elsewhere and decide what should happen here.', 'ran-booster' ); ?></span>
				</span>
			</button>
			<?php $renderPortabilityExtension( 'ran_booster_portability_render_migration_modes', 'portability_migration_modes_render' ); ?>
		</div>
	</section>

	<section class="ran-booster-portability__flow" id="ran-booster-portability-export" aria-labelledby="ran-booster-portability-export-heading" hidden>
		<header class="ran-booster-portability__flow-header">
			<p class="ran-booster-portability__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Transporter', 'ran-booster' ); ?></p>
			<h3 id="ran-booster-portability-export-heading" tabindex="-1"><?php esc_html_e( 'Create a Transporter Blueprint', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'The Transporter Blueprint contains managed plugin and theme configuration. Choose any eligible repository credentials that should travel with it.', 'ran-booster' ); ?></p>
		</header>
		<form class="ran-booster-portability__export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-portability-export-form>
			<input type="hidden" name="action" value="ran_booster_export_blueprint">
			<?php wp_nonce_field( \RAN\Admin\PortabilityController::EXPORT_NONCE_ACTION, 'nonce' ); ?>
		<section class="ran-booster-portability__review ran-booster-portability__export-review" aria-labelledby="ran-booster-portability-export-review-heading">
			<div class="ran-booster-portability__review-heading">
				<h4 id="ran-booster-portability-export-review-heading" class="ran-booster-portability__review-title"><?php esc_html_e( 'Blueprint contents', 'ran-booster' ); ?></h4>
			</div>
			<p><?php esc_html_e( 'Choose the repository credentials and managed packages to include in this Transporter Blueprint.', 'ran-booster' ); ?></p>
		<section class="ran-booster-portability__credential-option" aria-labelledby="ran-booster-portability-export-credentials-heading">
			<h5 id="ran-booster-portability-export-credentials-heading" class="ran-booster-portability__subsection-title ran-booster-portability__credentials-title"><?php esc_html_e( 'Repository credentials', 'ran-booster' ); ?></h5>
			<p><?php esc_html_e( 'Select only the saved credentials that should be copied. Credential permissions have not been assessed.', 'ran-booster' ); ?></p>
			<?php if ( $portabilityExportCredentialsUnavailable ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Booster could not load repository credential choices. You can still create a package-only Blueprint.', 'ran-booster' ); ?></p></div>
			<?php elseif ( array() === $portabilityExportCredentialGroups ) : ?>
				<p class="description"><?php esc_html_e( 'No saved repository credentials are available on this site.', 'ran-booster' ); ?></p>
			<?php else : ?>
				<?php
				$availabilitySections = array(
					'available'   => true,
					'unavailable' => false,
				);
				foreach ( $availabilitySections as $sectionKey => $sectionAvailable ) :
					$sectionCredentialCount = 0;
					foreach ( $portabilityExportCredentialGroups as $group ) {
						foreach ( (array) ( $group['credentials'] ?? array() ) as $credential ) {
							$sectionCredentialCount += $sectionAvailable === ! empty( $credential['available'] ) ? 1 : 0;
						}
					}
					if ( 0 === $sectionCredentialCount ) {
						continue;
					}
					?>
				<div class="ran-booster-portability__credential-availability-group ran-booster-portability__credential-availability-group--<?php echo esc_attr( $sectionKey ); ?>">
				<div class="ran-booster-portability__credential-groups">
					<?php foreach ( $portabilityExportCredentialGroups as $groupIndex => $group ) : ?>
						<?php
						$providerCode  = is_string( $group['code'] ?? null ) ? $group['code'] : '';
						$providerLabel = is_string( $group['label'] ?? null ) ? $group['label'] : $providerCode;
						$credentials   = is_array( $group['credentials'] ?? null ) ? array_filter( $group['credentials'], static fn ( array $credential ): bool => $sectionAvailable === ! empty( $credential['available'] ) ) : array();
						if ( array() === $credentials ) {
							continue;
						}
						?>
					<fieldset class="ran-booster-portability__credential-group">
						<legend class="screen-reader-text"><?php echo esc_html( $providerLabel ); ?> <?php esc_html_e( 'credential choices', 'ran-booster' ); ?></legend>
						<ul class="ran-booster-portability__credential-list">
						<?php foreach ( $credentials as $credentialIndex => $credential ) : ?>
							<?php
							$controlId      = 'ran-booster-portability-export-credential-' . $groupIndex . '-' . $credentialIndex;
							$reasonId       = $controlId . '-reason';
							$available      = ! empty( $credential['available'] );
							$label          = is_string( $credential['label'] ?? null ) && '' !== $credential['label'] ? $credential['label'] : __( 'Unavailable saved credential', 'ran-booster' );
							$kindLabel      = is_string( $credential['kind_label'] ?? null ) ? $credential['kind_label'] : '';
							$packages       = is_array( $credential['packages'] ?? null ) ? $credential['packages'] : array();
							$packageCount   = count( $packages );
							$packageSummary = sprintf(
								/* translators: %d: number of packages using the credential. */
								_n( 'Used by %d package', 'Used by %d packages', $packageCount, 'ran-booster' ),
								$packageCount
							);
							$packageIndexes = implode( ' ', array_map( static fn ( array $package ): string => (string) ( $package['index'] ?? '' ), $packages ) );
							if ( $available ) {
								$description = __( 'The provider permissions for this credential have not been assessed.', 'ran-booster' );
							} elseif ( 'self_destruct' === ( $credential['reason'] ?? null ) ) {
								$destroyOn = is_string( $credential['destroy_on'] ?? null ) ? $credential['destroy_on'] : '';
								/* translators: %s: local automatic-removal date. */
								$description = '' === $destroyOn ? __( 'Booster will automatically remove this saved credential.', 'ran-booster' ) : sprintf( __( 'Booster will automatically remove this saved credential on %s.', 'ran-booster' ), $destroyOn );
							} elseif ( 'unassociated' === ( $credential['reason'] ?? null ) ) {
								$description = __( 'No Booster-managed plugin or theme uses this credential.', 'ran-booster' );
							} else {
								$description = 'configuration' === ( $credential['reason'] ?? null ) ? __( 'This credential is supplied by site configuration.', 'ran-booster' ) : __( 'This saved credential no longer exists.', 'ran-booster' );
							}
							?>
							<li class="ran-booster-portability__credential-row ran-booster-portability__credential-card<?php echo $available ? '' : ' ran-booster-portability__credential-card--unavailable'; ?>" data-portability-export-credential-row data-portability-credential-packages="<?php echo esc_attr( $packageIndexes ); ?>">
								<label for="<?php echo esc_attr( $controlId ); ?>" class="ran-booster-portability__credential-selection">
									<?php if ( $available ) : ?>
										<input id="<?php echo esc_attr( $controlId ); ?>" type="checkbox" name="credentials[<?php echo esc_attr( $providerCode ); ?>][]" value="<?php echo esc_attr( (string) $credential['id'] ); ?>" data-portability-export-credential aria-describedby="<?php echo esc_attr( $reasonId ); ?>">
									<?php else : ?>
										<input id="<?php echo esc_attr( $controlId ); ?>" type="checkbox" disabled aria-describedby="<?php echo esc_attr( $reasonId ); ?>">
									<?php endif; ?>
									<span class="ran-booster-portability__credential-heading">
										<strong class="ran-booster-portability__credential-name"><?php echo esc_html( $label ); ?></strong>
										<span class="ran-booster-tile ran-booster-portability__credential-provider"><span class="screen-reader-text"><?php esc_html_e( 'Provider:', 'ran-booster' ); ?> </span><span class="ran-booster-tile__value"><?php echo esc_html( $providerLabel ); ?></span></span>
										<?php if ( '' !== $kindLabel ) : ?>
											<span class="ran-booster-tile ran-booster-portability__credential-kind"><span class="screen-reader-text"><?php esc_html_e( 'Credential type:', 'ran-booster' ); ?> </span><span class="ran-booster-tile__value"><?php echo esc_html( $kindLabel ); ?></span></span>
										<?php endif; ?>
									</span>
								</label>
								<?php if ( 0 < $packageCount ) : ?>
									<details class="ran-booster-portability__credential-packages">
										<summary><?php echo esc_html( $packageSummary ); ?></summary>
										<ul>
										<?php foreach ( $packages as $package ) : ?>
											<li><span><?php echo esc_html( (string) ( $package['name'] ?? '' ) ); ?></span> <span class="description"><?php echo esc_html( 'theme' === ( $package['type'] ?? null ) ? __( 'Theme', 'ran-booster' ) : __( 'Plugin', 'ran-booster' ) ); ?></span></li>
										<?php endforeach; ?>
										</ul>
									</details>
								<?php endif; ?>
								<?php if ( $available ) : ?>
									<span class="screen-reader-text" id="<?php echo esc_attr( $reasonId ); ?>"><?php echo esc_html( $description ); ?></span>
								<?php else : ?>
									<div class="ran-booster-portability__credential-decision-state ran-booster-portability__credential-decision-state--unavailable" id="<?php echo esc_attr( $reasonId ); ?>"><strong><?php esc_html_e( 'Unavailable for transfer', 'ran-booster' ); ?></strong><span class="description"><?php echo esc_html( $description ); ?></span></div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
						</ul>
					</fieldset>
				<?php endforeach; ?>
				</div>
				</div>
				<?php endforeach; ?>
			<?php endif; ?>
				<div id="ran-booster-portability-export-credential-details" class="ran-booster-portability__credential-details">
					<div class="ran-booster-portability__password-fields">
						<p class="ran-booster-portability__password-primary">
							<label for="ran-booster-portability-export-password"><?php esc_html_e( 'Transporter Blueprint password', 'ran-booster' ); ?></label>
							<span class="ran-booster-portability__password-control">
								<span class="ran-booster-portability__password-input">
									<input id="ran-booster-portability-export-password" name="password" type="password" minlength="20" maxlength="256" autocomplete="new-password" aria-describedby="ran-booster-portability-password-guidance ran-booster-portability-password-validation" data-portability-password>
									<button type="button" class="ran-booster-portability__password-visibility" data-portability-password-visibility data-show-label="<?php esc_attr_e( 'Show password', 'ran-booster' ); ?>" data-hide-label="<?php esc_attr_e( 'Hide password', 'ran-booster' ); ?>" aria-controls="ran-booster-portability-export-password" aria-label="<?php esc_attr_e( 'Show password', 'ran-booster' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Show password', 'ran-booster' ); ?>"><span class="dashicons dashicons-visibility" data-portability-password-visibility-icon aria-hidden="true"></span></button>
								</span>
								<span class="ran-booster-portability__password-actions">
									<button type="button" class="button" data-portability-password-generate><?php esc_html_e( 'Generate password', 'ran-booster' ); ?></button>
									<button type="button" class="button ran-booster-portability__icon-button" data-portability-password-copy data-copy-label="<?php esc_attr_e( 'Copy password', 'ran-booster' ); ?>" data-copied-label="<?php esc_attr_e( 'Password copied', 'ran-booster' ); ?>" aria-label="<?php esc_attr_e( 'Copy password', 'ran-booster' ); ?>" title="<?php esc_attr_e( 'Copy password', 'ran-booster' ); ?>" disabled>
										<svg class="ran-booster-portability__button-icon" data-portability-password-copy-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
											<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
											<rect x="9" y="3" width="6" height="4" rx="1"/>
										</svg>
										<svg class="ran-booster-portability__button-icon" data-portability-password-copy-success-icon viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" hidden>
											<path d="m5 12 4 4L19 6"/>
										</svg>
										<span class="screen-reader-text"><?php esc_html_e( 'Copy password', 'ran-booster' ); ?></span>
									</button>
								</span>
							</span>
							<span class="ran-booster-portability__password-status" data-portability-password-status data-generated-message="<?php esc_attr_e( 'A secure 32-character password was generated.', 'ran-booster' ); ?>" data-copied-message="<?php esc_attr_e( 'Password copied to the clipboard.', 'ran-booster' ); ?>" data-generation-failed-message="<?php esc_attr_e( 'Booster could not generate a password securely in this browser. Enter one manually.', 'ran-booster' ); ?>" data-copy-failed-message="<?php esc_attr_e( 'Clipboard access failed. The password is selected; use your browser’s copy command.', 'ran-booster' ); ?>" role="status" aria-live="polite" aria-atomic="true"></span>
						</p>
						<p><label for="ran-booster-portability-export-password-confirmation"><?php esc_html_e( 'Confirm password', 'ran-booster' ); ?></label><br><input id="ran-booster-portability-export-password-confirmation" name="password_confirmation" type="password" minlength="20" maxlength="256" autocomplete="new-password" aria-describedby="ran-booster-portability-password-validation" data-portability-password-confirmation></p>
						<p><span id="ran-booster-portability-password-guidance" class="description ran-booster-portability__password-guidance"><?php esc_html_e( 'Generate a secure 32-character password or enter at least 20 characters of your own.', 'ran-booster' ); ?></span></p>
						<div id="ran-booster-portability-password-validation" class="notice notice-warning inline ran-booster-portability__password-validation" data-portability-password-validation data-required-message="<?php esc_attr_e( 'Choose a Transporter Blueprint password before exporting credentials.', 'ran-booster' ); ?>" data-mismatch-message="<?php esc_attr_e( 'The Transporter Blueprint passwords do not match. Nothing was exported.', 'ran-booster' ); ?>" role="alert" hidden><p data-portability-password-validation-message></p></div>
					</div>
					<div class="ran-booster-portability__credential-guidance">
						<p><strong><?php esc_html_e( 'Only transfer this Transporter Blueprint between sites you control.', 'ran-booster' ); ?></strong><br/><?php esc_html_e( 'Transporter Blueprints can contain file-backed repository credentials and must be handled as sensitive material.', 'ran-booster' ); ?></p>
						<p class="description"><?php esc_html_e( 'Passwords are never stored by Booster. Keep yours safe; it is required to open this Transporter Blueprint.', 'ran-booster' ); ?></p>
					</div>
				</div>
		</section>
		<section class="ran-booster-portability__export-packages" aria-labelledby="ran-booster-portability-export-packages-heading">
			<h5 id="ran-booster-portability-export-packages-heading" class="ran-booster-portability__subsection-title ran-booster-portability__packages-title"><?php esc_html_e( 'Packages', 'ran-booster' ); ?></h5>
			<p><?php esc_html_e( 'Review the managed plugins and themes that will be recorded in this Transporter Blueprint.', 'ran-booster' ); ?></p>
			<div class="ran-booster-portability__table-scroll" role="region" aria-labelledby="ran-booster-portability-export-packages-heading" tabindex="0">
				<table class="widefat striped ran-booster-portability__review-table ran-booster-portability__export-table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Managed packages available for export', 'ran-booster' ); ?></caption>
					<thead><tr>
						<td class="check-column"><input type="checkbox" data-portability-export-select-all aria-label="<?php esc_attr_e( 'Select all managed packages', 'ran-booster' ); ?>"<?php checked( array() !== $portabilityExportRows ); ?><?php disabled( array() === $portabilityExportRows ); ?>></td>
						<th scope="col"><?php esc_html_e( 'Package', 'ran-booster' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'ran-booster' ); ?></th>
					</tr></thead>
					<tbody>
					<?php if ( $portabilityExportUnavailable ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'Booster could not load the managed package list. Reload this page and try again.', 'ran-booster' ); ?></td></tr>
					<?php elseif ( array() === $portabilityExportRows ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'Booster is not managing any packages on this site yet.', 'ran-booster' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $portabilityExportRows as $rowIndex => $row ) : ?>
							<?php
							$packageType = 'theme' === ( $row['type'] ?? null ) ? 'theme' : 'plugin';
							$identifier  = is_string( $row['identifier'] ?? null ) ? $row['identifier'] : '';
							$name        = is_string( $row['name'] ?? null ) ? $row['name'] : '';
							?>
							<tr>
								<?php /* translators: %s: managed plugin or theme name. */ ?>
				<th scope="row" class="check-column"><input id="ran-booster-portability-export-package-<?php echo esc_attr( (string) $rowIndex ); ?>" name="packages[<?php echo esc_attr( $packageType ); ?>][]" value="<?php echo esc_attr( $identifier ); ?>" type="checkbox" checked data-portability-export-select data-portability-export-package-index="<?php echo esc_attr( (string) $rowIndex ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Include %s', 'ran-booster' ), $name ) ); ?>"></th>
								<td><label for="ran-booster-portability-export-package-<?php echo esc_attr( (string) $rowIndex ); ?>"><strong><?php echo esc_html( $name ); ?></strong></label><code><?php echo esc_html( $identifier ); ?></code></td>
								<td><?php echo esc_html( 'plugin' === $packageType ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>
		</section>
		<p class="ran-booster-portability__export-summary" data-portability-export-summary data-package-singular="<?php esc_attr_e( '1 package', 'ran-booster' ); ?>" data-package-plural="<?php echo esc_attr( $portabilityPackagePlural ); ?>" data-credential-singular="<?php esc_attr_e( '1 selected repository credential profile', 'ran-booster' ); ?>" data-credential-plural="<?php echo esc_attr( $portabilityCredentialPlural ); ?>" data-protected-template="<?php echo esc_attr( $portabilityProtectedSummary ); ?>" data-package-only-template="<?php echo esc_attr( $portabilityPackageOnlySummary ); ?>" aria-live="polite"><?php echo esc_html( sprintf( $portabilityPackageOnlySummary, $portabilityInitialPackageCount ) ); ?></p>
		<p><button type="submit" class="button button-primary" data-portability-export-submit<?php disabled( $portabilityExportUnavailable || array() === $portabilityExportRows ); ?>><?php esc_html_e( 'Download Transporter Blueprint', 'ran-booster' ); ?></button></p>
		<div class="notice notice-error inline" data-portability-export-message role="alert" hidden><p data-portability-export-message-text></p></div>
		</form>
	</section>

	<section class="ran-booster-portability__flow" id="ran-booster-portability-import" aria-labelledby="ran-booster-portability-import-heading" hidden>
		<header class="ran-booster-portability__flow-header">
			<p class="ran-booster-portability__eyebrow ran-booster-eyebrow"><?php esc_html_e( 'Transporter', 'ran-booster' ); ?></p>
			<h3 id="ran-booster-portability-import-heading" tabindex="-1"><?php esc_html_e( 'Open a Transporter Blueprint', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Choose a Transporter Blueprint and Booster will validate it and build a review before anything can change.', 'ran-booster' ); ?></p>
		</header>
		<form class="ran-booster-portability__upload" data-portability-preview data-portability-apply-nonce="<?php echo esc_attr( wp_create_nonce( \RAN\Admin\PortabilityController::APPLY_NONCE_ACTION ) ); ?>" aria-labelledby="ran-booster-portability-upload-heading">
			<input type="hidden" name="action" value="ran_booster_preview_blueprint">
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( \RAN\Admin\PortabilityController::PREVIEW_NONCE_ACTION ) ); ?>">
			<h4 id="ran-booster-portability-upload-heading"><?php esc_html_e( 'Choose a Transporter Blueprint', 'ran-booster' ); ?></h4>
			<div class="ran-booster-portability__import-fields">
				<p><label for="ran-booster-portability-file"><?php esc_html_e( 'Transporter Blueprint ZIP', 'ran-booster' ); ?></label><input id="ran-booster-portability-file" name="blueprint" type="file" accept="application/zip,application/x-zip-compressed,.zip"></p>
				<p><label for="ran-booster-portability-import-password"><?php esc_html_e( 'ZIP password (optional)', 'ran-booster' ); ?></label><input id="ran-booster-portability-import-password" name="password" type="password" autocomplete="off"></p>
				<button type="submit" class="button button-primary ran-booster-portability__progress-button" data-portability-preview-submit data-idle-label="<?php esc_attr_e( 'Review Transporter Blueprint', 'ran-booster' ); ?>" data-busy-label="<?php esc_attr_e( 'Reviewing Transporter Blueprint…', 'ran-booster' ); ?>"><span data-portability-preview-label><?php esc_html_e( 'Review Transporter Blueprint', 'ran-booster' ); ?></span></button>
			</div>
			<p data-portability-preview-message hidden aria-live="polite"></p>
		</form>

		<section class="ran-booster-portability__review" aria-labelledby="ran-booster-portability-review-heading">
			<div class="ran-booster-portability__review-heading">
				<h4 id="ran-booster-portability-review-heading" class="ran-booster-portability__review-title"><?php esc_html_e( 'Blueprint review', 'ran-booster' ); ?></h4>
				<span id="ran-booster-portability-review-progress" class="ran-booster-portability__review-progress htmx-indicator" role="status" aria-live="polite"><?php esc_html_e( 'Rechecking repository access…', 'ran-booster' ); ?></span>
			</div>
			<p><?php esc_html_e( 'Review repository access decisions, package changes and any credential-only recovery proposed for this site.', 'ran-booster' ); ?></p>
			<div class="notice notice-error inline ran-booster-portability__review-error" data-portability-review-error role="alert" hidden><p><?php esc_html_e( 'The review could not be updated. Choose again or review the Transporter Blueprint before applying changes.', 'ran-booster' ); ?></p></div>
			<div data-portability-review><?php require __DIR__ . '/portability-review.php'; ?></div>
		</section>
		<section class="ran-booster-portability__apply" aria-labelledby="ran-booster-portability-apply-heading">
			<h4 id="ran-booster-portability-apply-heading"><?php esc_html_e( 'Apply selected changes', 'ran-booster' ); ?></h4>
			<p><?php esc_html_e( 'Eligible package changes and credential-only recovery rows are applied independently. Credential-only recovery does not change an already-managed package’s settings.', 'ran-booster' ); ?></p>
			<p data-portability-apply-summary aria-live="polite"><?php esc_html_e( 'No changes selected.', 'ran-booster' ); ?></p>
			<p><button type="button" class="button button-primary ran-booster-portability__progress-button" data-portability-apply data-idle-label="<?php esc_attr_e( 'Apply selected changes', 'ran-booster' ); ?>" data-busy-label="<?php esc_attr_e( 'Applying…', 'ran-booster' ); ?>" disabled><span data-portability-apply-label><?php esc_html_e( 'Apply selected changes', 'ran-booster' ); ?></span></button></p>
			<div data-portability-apply-results aria-live="polite"></div>
		</section>
	</section>

	<?php $renderPortabilityExtension( 'ran_booster_portability_render_migration_flows', 'portability_migration_flows_render' ); ?>
</section>
