<?php

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$secretsStorage                    = isset( $onboarding['secrets_storage'] ) && is_array( $onboarding['secrets_storage'] )
	? $onboarding['secrets_storage']
	: null;
$storageStatus                     = null === $secretsStorage ? '' : (string) $secretsStorage['status'];
$storageReasonCode                 = null === $secretsStorage ? '' : (string) ( $secretsStorage['reason_code'] ?? '' );
$storageCandidatePath              = null === $secretsStorage ? null : ( $secretsStorage['candidate_path'] ?? null );
$storageDirectory                  = null === $secretsStorage
	? null
	: ( $secretsStorage['candidate_directory'] ?? ( is_string( $storageCandidatePath ) ? dirname( $storageCandidatePath ) : null ) );
$storageStatusLabels               = array(
	'path_configured'         => __( 'Path configured', 'ran-booster' ),
	'storage_healthy'         => __( 'Storage healthy', 'ran-booster' ),
	'storage_needs_attention' => __( 'Storage needs attention', 'ran-booster' ),
	'setup_available'         => __( 'Setup available', 'ran-booster' ),
	'manual_required'         => __( 'Needs attention', 'ran-booster' ),
	'unsupported'             => __( 'Unavailable', 'ran-booster' ),
	'pending_verification'    => __( 'Verification pending', 'ran-booster' ),
);
$storageStatusClasses              = array(
	'path_configured'         => 'neutral',
	'storage_healthy'         => 'ok',
	'storage_needs_attention' => 'warning',
	'setup_available'         => 'neutral',
	'manual_required'         => 'warning',
	'unsupported'             => 'error',
	'pending_verification'    => 'pending',
);
$storagePathSource                 = null === $secretsStorage ? null : ( $secretsStorage['path_source'] ?? null );
$storagePathSourceLabels           = array(
	'automatic' => __( 'Booster default', 'ran-booster' ),
	'manual'    => __( 'Custom wp-config.php path', 'ran-booster' ),
);
$storageRecovery                   = null === $secretsStorage || ! is_array( $secretsStorage['recovery'] ?? null )
	? null
	: $secretsStorage['recovery'];
$storageCanReset                   = null !== $storageRecovery && true === ( $storageRecovery['can_reset'] ?? false );
$storageDiscardedCandidates        = null === $secretsStorage || ! is_array( $secretsStorage['discarded_candidates'] ?? null )
	? array()
	: $secretsStorage['discarded_candidates'];
$credentialStorageDocumentationUrl = $onboarding['documentation_url'] . '#ran-booster-credential-storage';
$storageDetailsOpen                = in_array(
	$storageStatus,
	array( 'storage_needs_attention', 'setup_available', 'manual_required', 'unsupported', 'pending_verification' ),
	true
);
$showsStorageOverride              = in_array( $storageStatus, array( 'storage_needs_attention', 'manual_required' ), true )
	&& null === ( $secretsStorage['config_alternatives'] ?? null );
$hasStorageDetails                 = null !== $secretsStorage
	&& (
		null !== $secretsStorage['candidate_path']
		|| $secretsStorage['can_provision']
		|| null !== $storageRecovery
		|| array() !== $storageDiscardedCandidates
		|| null !== $secretsStorage['config_alternatives']
		|| $showsStorageOverride
	);

?>
<section class="ran-booster-page-shell ran-booster-panel ran-booster-onboarding" aria-labelledby="ran-booster-onboarding-heading">
	<header class="ran-booster-page-shell__header ran-booster-onboarding__header">
		<p class="ran-booster-eyebrow">Ignition</p>
		<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Start with a repository', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Public repositories do not need access tokens or webhooks.', 'ran-booster' ); ?></p>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Install and manage custom plugins and themes from supported Git repositories; private access and Push-to-Deploy are optional.', 'ran-booster' ); ?></p>
		<p class="ran-booster-onboarding__actions">
			<a class="button button-primary" href="<?php echo esc_url( $onboarding['install_plugin_url'] ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
			<a class="button" href="<?php echo esc_url( $onboarding['install_theme_url'] ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a>
		</p>
	</header>

	<div class="ran-booster-onboarding__columns">
		<section class="ran-booster-onboarding__column" aria-labelledby="ran-booster-onboarding-connect-heading">
			<h3 id="ran-booster-onboarding-connect-heading"><?php esc_html_e( 'Private repository access', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Add a provider credential only when anonymous access is not enough.', 'ran-booster' ); ?></p>

			<h4 class="ran-booster-onboarding__provider-heading"><?php esc_html_e( 'Connect a provider', 'ran-booster' ); ?></h4>
			<?php if ( array() !== $onboarding['provider_links'] ) { ?>
				<ul class="ran-booster-onboarding__links">
					<?php foreach ( $onboarding['provider_links'] as $providerLink ) { ?>
						<?php // translators: %s is the display name of a registered Git provider. ?>
						<li><a href="<?php echo esc_url( $providerLink['url'] ); ?>"><?php echo esc_html( sprintf( __( 'Add %s access', 'ran-booster' ), $providerLink['label'] ) ); ?></a></li>
					<?php } ?>
				</ul>
			<?php } else { ?>
				<p><?php esc_html_e( 'Provider settings will appear here when an integration is available.', 'ran-booster' ); ?></p>
			<?php } ?>
		</section>

		<section class="ran-booster-onboarding__column" aria-labelledby="ran-booster-onboarding-next-heading">
			<h3 id="ran-booster-onboarding-next-heading"><?php esc_html_e( 'Move or automate later', 'ran-booster' ); ?></h3>
			<p><?php esc_html_e( 'Packages begin in Manual mode. Enable Push-to-Deploy only when the target is ready.', 'ran-booster' ); ?></p>
			<ul class="ran-booster-onboarding__links">
				<li><a href="<?php echo esc_url( $onboarding['portability_url'] ); ?>"><?php esc_html_e( 'Move an existing Booster setup', 'ran-booster' ); ?></a></li>
				<li><a href="<?php echo esc_url( $onboarding['documentation_url'] ); ?>"><?php esc_html_e( 'Read the documentation', 'ran-booster' ); ?></a></li>
				<li><a href="<?php echo esc_url( $onboarding['troubleshooting_url'] ); ?>"><?php esc_html_e( 'Open troubleshooting', 'ran-booster' ); ?></a></li>
			</ul>
		</section>
	</div>

	<?php
	$migrationPromptBufferLevel = ob_get_level();
	ob_start();
	try {
		do_action( 'ran_booster_overview_render_migration_prompt' );
		$migrationPrompt = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Add-ons own and escape their bounded migration prompt.
		echo $migrationPrompt;
	} catch ( \Throwable $failure ) {
		while ( ob_get_level() > $migrationPromptBufferLevel ) {
			ob_end_clean();
		}
		\RAN\Logging\BoosterLogger::logException( 'overview migration prompt rendering failed', $failure, array( 'step' => 'overview_migration_prompt_render' ) );
	}
	?>

	<?php if ( null !== $secretsStorage ) { ?>
		<section class="ran-booster-onboarding__storage" aria-labelledby="ran-booster-onboarding-storage-heading" data-ran-booster-storage-reason="<?php echo esc_attr( $storageReasonCode ); ?>">
			<div class="ran-booster-onboarding__storage-heading">
				<h3 id="ran-booster-onboarding-storage-heading"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
				<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $storageStatusClasses[ $storageStatus ] ?? 'neutral' ); ?>" data-ran-booster-storage-status="<?php echo esc_attr( $storageStatus ); ?>"><?php echo esc_html( $storageStatusLabels[ $storageStatus ] ?? __( 'Status unknown', 'ran-booster' ) ); ?></span>
			</div>
			<p><?php echo esc_html( $secretsStorage['message'] ); ?></p>
			<?php if ( '' !== $storageReasonCode && in_array( $storageStatus, array( 'storage_needs_attention', 'manual_required', 'unsupported' ), true ) ) { ?>
				<p class="description"><strong><?php esc_html_e( 'Diagnostic code:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storageReasonCode ); ?></code></p>
			<?php } ?>

			<?php if ( $hasStorageDetails ) { ?>
				<details class="ran-booster-onboarding__storage-details"<?php echo $storageDetailsOpen ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Storage details', 'ran-booster' ); ?></summary>
					<div>
						<?php if ( null !== $secretsStorage['candidate_path'] ) { ?>
							<dl class="ran-booster-onboarding__storage-facts">
								<?php if ( null !== $storageDirectory ) { ?>
									<div>
										<dt><?php esc_html_e( 'Storage directory', 'ran-booster' ); ?></dt>
										<dd><code><?php echo esc_html( $storageDirectory ); ?></code></dd>
									</div>
								<?php } ?>
								<div>
									<dt><?php esc_html_e( 'Storage file', 'ran-booster' ); ?></dt>
									<dd><code><?php echo esc_html( $secretsStorage['candidate_path'] ); ?></code></dd>
								</div>
								<?php if ( is_string( $storagePathSource ) && isset( $storagePathSourceLabels[ $storagePathSource ] ) && in_array( $storageStatus, array( 'path_configured', 'storage_healthy', 'storage_needs_attention' ), true ) ) { ?>
									<div>
										<dt><?php esc_html_e( 'Path selection', 'ran-booster' ); ?></dt>
										<dd><?php echo esc_html( $storagePathSourceLabels[ $storagePathSource ] ); ?></dd>
									</div>
								<?php } ?>
							</dl>
							<?php if ( 'setup_available' === $storageStatus ) { ?>
								<p class="description"><?php esc_html_e( 'Booster selected this private location automatically; you do not need to choose a path.', 'ran-booster' ); ?></p>
							<?php } elseif ( 'path_configured' === $storageStatus ) { ?>
								<p class="description"><?php esc_html_e( 'The encrypted file and its separate database key are created when you save the first credential.', 'ran-booster' ); ?></p>
							<?php } elseif ( 'storage_healthy' === $storageStatus ) { ?>
								<p class="description"><?php esc_html_e( 'Credentials are encrypted in this file. WordPress stores the encryption key separately.', 'ran-booster' ); ?></p>
							<?php } ?>
						<?php } ?>

						<?php if ( $secretsStorage['can_provision'] ) { ?>
							<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secretsStorage['action_url'] ); ?>">
								<?php wp_nonce_field( 'ran-booster-create-secure-storage' ); ?>
								<input type="hidden" name="ran_booster[action]" value="create-secure-storage">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Create secure storage', 'ran-booster' ); ?></button>
							</form>
						<?php } ?>

						<?php if ( array() !== $storageDiscardedCandidates ) { ?>
							<div class="ran-booster-onboarding__storage-discarded">
								<h4><?php esc_html_e( 'Automatic locations considered and discarded', 'ran-booster' ); ?></h4>
								<p class="description"><?php esc_html_e( 'This check is read-only; Booster did not create files in these locations.', 'ran-booster' ); ?></p>
								<ul>
									<?php foreach ( $storageDiscardedCandidates as $discardedCandidate ) { ?>
										<?php if ( is_array( $discardedCandidate ) && is_string( $discardedCandidate['directory'] ?? null ) && is_string( $discardedCandidate['code'] ?? null ) && is_string( $discardedCandidate['reason'] ?? null ) ) { ?>
											<li>
												<code><?php echo esc_html( $discardedCandidate['directory'] ); ?></code>
												<p class="description"><strong><?php esc_html_e( 'Reason:', 'ran-booster' ); ?></strong> <?php echo esc_html( $discardedCandidate['reason'] ); ?> <code><?php echo esc_html( $discardedCandidate['code'] ); ?></code></p>
												<?php if ( is_string( $discardedCandidate['component'] ?? null ) ) { ?>
													<p class="description"><strong><?php esc_html_e( 'Blocking component:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $discardedCandidate['component'] ); ?></code></p>
												<?php } ?>
											</li>
										<?php } ?>
									<?php } ?>
								</ul>
							</div>
						<?php } ?>

						<?php if ( null !== $storageRecovery ) { ?>
							<div class="ran-booster-onboarding__storage-recovery">
								<h4><?php echo esc_html( $storageCanReset ? __( 'Start over with empty credential storage', 'ran-booster' ) : __( 'Existing Booster storage found', 'ran-booster' ) ); ?></h4>
								<p><?php echo esc_html( $storageRecovery['message'] ); ?></p>
								<?php if ( $storageRecovery['can_adopt'] ) { ?>
									<?php if ( null !== $storageRecovery['candidate_directory'] ) { ?>
										<p class="description"><strong><?php esc_html_e( 'Verified storage directory:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storageRecovery['candidate_directory'] ); ?></code></p>
									<?php } ?>
									<p class="description"><?php esc_html_e( 'This proves decryption, canonical storage and current provider credential shape, including GitHub token prefixes. It does not contact the provider or prove that a token is still active. Adoption changes only Booster’s owned wp-config.php pointer; it does not move or delete files.', 'ran-booster' ); ?></p>
									<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secretsStorage['action_url'] ); ?>">
										<?php wp_nonce_field( 'ran-booster-adopt-secure-storage' ); ?>
										<input type="hidden" name="ran_booster[action]" value="adopt-secure-storage">
										<input type="hidden" name="ran_booster[recovery_token]" value="<?php echo esc_attr( $storageRecovery['token'] ); ?>">
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Adopt existing storage', 'ran-booster' ); ?></button>
									</form>
								<?php } elseif ( $storageCanReset ) { ?>
									<p><strong><?php esc_html_e( 'Permanent reset:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching secrets.json first if it is available. Resetting abandons every missing repository credential and webhook secret that used this key.', 'ran-booster' ); ?></p>
									<p class="description"><?php esc_html_e( 'A Transporter Blueprint is not a complete credential backup. It may transfer selected repository credentials only while installing or adopting applicable package rows, and it never contains webhook secrets.', 'ran-booster' ); ?></p>
									<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secretsStorage['action_url'] ); ?>">
										<?php wp_nonce_field( 'ran-booster-reset-empty-storage' ); ?>
										<input type="hidden" name="ran_booster[action]" value="reset-empty-storage">
										<label for="ran-booster-reset-confirmation">
											<?php
											// translators: %s is the exact confirmation phrase the administrator must type.
											echo esc_html( sprintf( __( 'Type %s to confirm:', 'ran-booster' ), $storageRecovery['reset_confirmation'] ) );
											?>
										</label>
										<input id="ran-booster-reset-confirmation" type="text" name="ran_booster[reset_confirmation]" required autocomplete="off" pattern="<?php echo esc_attr( $storageRecovery['reset_confirmation'] ); ?>">
										<button type="submit" class="button"><?php esc_html_e( 'Reset empty storage', 'ran-booster' ); ?></button>
									</form>
								<?php } else { ?>
									<p class="description"><?php esc_html_e( 'Automatic adoption remains disabled. Booster will not bypass private-path checks or rewrite an operator-managed storage definition.', 'ran-booster' ); ?></p>
								<?php } ?>
							</div>
						<?php } ?>

						<?php if ( $showsStorageOverride ) { ?>
							<details class="ran-booster-onboarding__storage-manual" open>
								<summary><?php echo esc_html( 'storage_needs_attention' === $storageStatus ? __( 'Use a different storage location', 'ran-booster' ) : __( 'Set a storage location manually', 'ran-booster' ) ); ?></summary>
								<div>
									<p><?php esc_html_e( 'Choose a durable absolute directory outside the public web root. Create it as a real directory owned by the PHP process user with mode 0700. Booster manages secrets.json and its lock inside it; if the file already exists, it must be owned by PHP with mode 0600.', 'ran-booster' ); ?></p>
									<p><?php esc_html_e( 'Replace any existing RAN_BOOSTER_ENCRYPTED_SECRETS_FILE definition with this preferred directory constant in wp-config.php before WordPress loads plugins. Use __DIR__ to anchor a relative layout to wp-config.php:', 'ran-booster' ); ?></p>
									<code>define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', dirname( __DIR__ ) . '/private/ran-booster' );</code>
									<p><?php esc_html_e( 'Or update the same constant with WP-CLI:', 'ran-booster' ); ?></p>
									<code>wp config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR '/absolute/private/path' --type=constant</code>
									<p><?php esc_html_e( 'For environment-managed hosting, replace the existing definition with this wp-config.php bridge. An environment variable by itself is not read by Booster:', 'ran-booster' ); ?></p>
									<pre><code>$ran_booster_secrets_dir = getenv( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' );
if ( is_string( $ran_booster_secrets_dir ) &amp;&amp; '' !== trim( $ran_booster_secrets_dir ) ) {
	define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $ran_booster_secrets_dir );
}</code></pre>
									<p><?php esc_html_e( 'The legacy exact-file constant can use the same anchor:', 'ran-booster' ); ?></p>
									<code>define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', dirname( __DIR__ ) . '/private/ran-booster/secrets.json' );</code>
									<p><?php esc_html_e( 'If credentials already exist, move the secrets file together with its matching database key; do not copy or reset only one half.', 'ran-booster' ); ?></p>
								</div>
							</details>
						<?php } ?>

						<?php if ( null !== $secretsStorage['config_alternatives'] ) { ?>
							<details class="ran-booster-onboarding__storage-manual" <?php echo esc_attr( 'manual_required' === $storageStatus ? 'open' : '' ); ?>>
								<summary><?php echo esc_html( $secretsStorage['can_provision'] ? __( 'Set up manually instead', 'ran-booster' ) : __( 'Manual setup instructions', 'ran-booster' ) ); ?></summary>
								<div>
									<p><?php echo esc_html( $secretsStorage['manual_preflight'] ); ?></p>
									<p><?php esc_html_e( 'Use an absolute private directory outside the public web root on durable local storage. It must be owned by PHP, readable and writable by PHP, and mode 0700; Booster manages secrets.json within it.', 'ran-booster' ); ?></p>
									<p><?php esc_html_e( 'Create the owner-only private directories:', 'ran-booster' ); ?></p>
									<ol>
										<?php foreach ( $secretsStorage['directory_commands'] as $command ) { ?>
											<li><code><?php echo esc_html( $command ); ?></code></li>
										<?php } ?>
									</ol>
									<p><?php esc_html_e( 'Then choose one configuration method, not both:', 'ran-booster' ); ?></p>
									<ul>
										<li>
											<?php esc_html_e( 'Add this line to wp-config.php:', 'ran-booster' ); ?>
											<code><?php echo esc_html( $secretsStorage['config_alternatives']['define'] ); ?></code>
										</li>
										<?php if ( '' !== $secretsStorage['config_alternatives']['wp_cli'] ) { ?>
											<li>
												<?php esc_html_e( 'Or run this WP-CLI command:', 'ran-booster' ); ?>
												<code><?php echo esc_html( $secretsStorage['config_alternatives']['wp_cli'] ); ?></code>
											</li>
										<?php } ?>
									</ul>
								</div>
							</details>
						<?php } ?>

						<p class="ran-booster-onboarding__storage-documentation">
							<a href="<?php echo esc_url( $credentialStorageDocumentationUrl ); ?>"><?php esc_html_e( 'Learn how Booster manages credentials and keys', 'ran-booster' ); ?></a>
						</p>
					</div>
				</details>
			<?php } ?>
		</section>
	<?php } ?>
</section>
