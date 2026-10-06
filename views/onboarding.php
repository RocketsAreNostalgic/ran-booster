<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * View locals supplied by Dashboard::render().
 *
 * @var array<string, mixed> $onboarding
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

$secrets_storage                      = isset( $onboarding['secrets_storage'] ) && is_array( $onboarding['secrets_storage'] )
	? $onboarding['secrets_storage']
	: null;
$storage_status                       = null === $secrets_storage ? '' : (string) $secrets_storage['status'];
$storage_reason_code                  = null === $secrets_storage ? '' : (string) ( $secrets_storage['reason_code'] ?? '' );
$storage_candidate_path               = null === $secrets_storage ? null : ( $secrets_storage['candidate_path'] ?? null );
$storage_directory                    = null === $secrets_storage
	? null
	: ( $secrets_storage['candidate_directory'] ?? ( is_string( $storage_candidate_path ) ? dirname( $storage_candidate_path ) : null ) );
$storage_status_labels                = array(
	'path_configured'         => __( 'Path configured', 'ran-booster' ),
	'storage_healthy'         => __( 'Storage healthy', 'ran-booster' ),
	'storage_needs_attention' => __( 'Storage needs attention', 'ran-booster' ),
	'setup_available'         => __( 'Setup available', 'ran-booster' ),
	'manual_required'         => __( 'Needs attention', 'ran-booster' ),
	'unsupported'             => __( 'Unavailable', 'ran-booster' ),
	'pending_verification'    => __( 'Verification pending', 'ran-booster' ),
);
$storage_status_classes               = array(
	'path_configured'         => 'neutral',
	'storage_healthy'         => 'ok',
	'storage_needs_attention' => 'warning',
	'setup_available'         => 'neutral',
	'manual_required'         => 'warning',
	'unsupported'             => 'error',
	'pending_verification'    => 'pending',
);
$storage_path_source                  = null === $secrets_storage ? null : ( $secrets_storage['path_source'] ?? null );
$storage_path_source_labels           = array(
	'automatic' => __( 'Booster default', 'ran-booster' ),
	'manual'    => __( 'Custom wp-config.php path', 'ran-booster' ),
);
$storage_recovery                     = null === $secrets_storage || ! is_array( $secrets_storage['recovery'] ?? null )
	? null
	: $secrets_storage['recovery'];
$storage_can_reset                    = null !== $storage_recovery && true === ( $storage_recovery['can_reset'] ?? false );
$storage_discarded_candidates         = null === $secrets_storage || ! is_array( $secrets_storage['discarded_candidates'] ?? null )
	? array()
	: $secrets_storage['discarded_candidates'];
$credential_storage_documentation_url = $onboarding['documentation_url'] . '#ran-booster-credential-storage';
$storage_details_open                 = in_array(
	$storage_status,
	array( 'storage_needs_attention', 'setup_available', 'manual_required', 'unsupported', 'pending_verification' ),
	true
);
$shows_storage_override               = in_array( $storage_status, array( 'storage_needs_attention', 'manual_required' ), true )
	&& null === ( $secrets_storage['config_alternatives'] ?? null );
$has_storage_details                  = null !== $secrets_storage
	&& (
		null !== $secrets_storage['candidate_path']
		|| $secrets_storage['can_provision']
		|| null !== $storage_recovery
		|| array() !== $storage_discarded_candidates
		|| null !== $secrets_storage['config_alternatives']
		|| $shows_storage_override
	);

?>
<section class="ran-booster-page-shell ran-booster-panel ran-booster-onboarding" aria-labelledby="ran-booster-onboarding-heading">
	<header class="ran-booster-page-shell__header ran-booster-onboarding__header">
		<p class="ran-booster-eyebrow"><?php esc_html_e( 'Ignition', 'ran-booster' ); ?></p>
		<h2 id="ran-booster-onboarding-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Start with a repository', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Public repositories do not need repository credentials or webhooks.', 'ran-booster' ); ?></p>
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
					<?php foreach ( $onboarding['provider_links'] as $provider_link ) { ?>
						<?php // translators: %s is the display name of a registered Git provider. ?>
						<li><a href="<?php echo esc_url( $provider_link['url'] ); ?>"><?php echo esc_html( sprintf( __( 'Add %s access', 'ran-booster' ), $provider_link['label'] ) ); ?></a></li>
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
	$migration_prompt_buffer_level = ob_get_level();
	ob_start();
	try {
		do_action( 'ran_booster_overview_render_migration_prompt' );
		$migration_prompt = (string) ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Add-ons own and escape their bounded migration prompt.
		echo $migration_prompt;
	} catch ( \Throwable $failure ) {
		while ( ob_get_level() > $migration_prompt_buffer_level ) {
			ob_end_clean();
		}
		\RAN\Logging\BoosterLogger::log_exception( 'overview migration prompt rendering failed', $failure, array( 'step' => 'overview_migration_prompt_render' ) );
	}
	?>

	<?php if ( null !== $secrets_storage ) { ?>
		<section class="ran-booster-onboarding__storage" aria-labelledby="ran-booster-onboarding-storage-heading" data-ran-booster-storage-reason="<?php echo esc_attr( $storage_reason_code ); ?>">
			<div class="ran-booster-onboarding__storage-heading">
				<h3 id="ran-booster-onboarding-storage-heading"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
				<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $storage_status_classes[ $storage_status ] ?? 'neutral' ); ?>" data-ran-booster-storage-status="<?php echo esc_attr( $storage_status ); ?>"><?php echo esc_html( $storage_status_labels[ $storage_status ] ?? __( 'Status unknown', 'ran-booster' ) ); ?></span>
			</div>
			<p><?php echo esc_html( $secrets_storage['message'] ); ?></p>
			<?php if ( '' !== $storage_reason_code && in_array( $storage_status, array( 'storage_needs_attention', 'manual_required', 'unsupported' ), true ) ) { ?>
				<p class="description"><strong><?php esc_html_e( 'Diagnostic code:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storage_reason_code ); ?></code></p>
			<?php } ?>

			<?php if ( $has_storage_details ) { ?>
				<details class="ran-booster-onboarding__storage-details"<?php echo $storage_details_open ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Storage details', 'ran-booster' ); ?></summary>
					<div>
						<?php if ( null !== $secrets_storage['candidate_path'] ) { ?>
							<dl class="ran-booster-onboarding__storage-facts">
								<?php if ( null !== $storage_directory ) { ?>
									<div>
										<dt><?php esc_html_e( 'Storage directory', 'ran-booster' ); ?></dt>
										<dd><code><?php echo esc_html( $storage_directory ); ?></code></dd>
									</div>
								<?php } ?>
								<div>
									<dt><?php esc_html_e( 'Storage file', 'ran-booster' ); ?></dt>
									<dd><code><?php echo esc_html( $secrets_storage['candidate_path'] ); ?></code></dd>
								</div>
								<?php if ( is_string( $storage_path_source ) && isset( $storage_path_source_labels[ $storage_path_source ] ) && in_array( $storage_status, array( 'path_configured', 'storage_healthy', 'storage_needs_attention' ), true ) ) { ?>
									<div>
										<dt><?php esc_html_e( 'Path selection', 'ran-booster' ); ?></dt>
										<dd><?php echo esc_html( $storage_path_source_labels[ $storage_path_source ] ); ?></dd>
									</div>
								<?php } ?>
							</dl>
							<?php if ( 'setup_available' === $storage_status ) { ?>
								<p class="description"><?php esc_html_e( 'Booster selected this private location automatically; you do not need to choose a path.', 'ran-booster' ); ?></p>
							<?php } elseif ( 'path_configured' === $storage_status ) { ?>
								<p class="description"><?php esc_html_e( 'The encrypted file and its separate database key are created when you save the first credential.', 'ran-booster' ); ?></p>
							<?php } elseif ( 'storage_healthy' === $storage_status ) { ?>
								<p class="description"><?php esc_html_e( 'Credentials are encrypted in this file. WordPress stores the encryption key separately.', 'ran-booster' ); ?></p>
							<?php } ?>
						<?php } ?>

						<?php if ( $secrets_storage['can_provision'] ) { ?>
							<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
								<?php wp_nonce_field( 'ran-booster-create-secure-storage' ); ?>
								<input type="hidden" name="ran_booster[action]" value="create-secure-storage">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Create secure storage', 'ran-booster' ); ?></button>
							</form>
						<?php } ?>

						<?php if ( array() !== $storage_discarded_candidates ) { ?>
							<div class="ran-booster-onboarding__storage-discarded">
								<h4><?php esc_html_e( 'Automatic locations considered and discarded', 'ran-booster' ); ?></h4>
								<p class="description"><?php esc_html_e( 'This check is read-only; Booster did not create files in these locations.', 'ran-booster' ); ?></p>
								<ul>
									<?php foreach ( $storage_discarded_candidates as $discarded_candidate ) { ?>
										<?php if ( is_array( $discarded_candidate ) && is_string( $discarded_candidate['directory'] ?? null ) && is_string( $discarded_candidate['code'] ?? null ) && is_string( $discarded_candidate['reason'] ?? null ) ) { ?>
											<li>
												<code><?php echo esc_html( $discarded_candidate['directory'] ); ?></code>
												<p class="description"><strong><?php esc_html_e( 'Reason:', 'ran-booster' ); ?></strong> <?php echo esc_html( $discarded_candidate['reason'] ); ?> <code><?php echo esc_html( $discarded_candidate['code'] ); ?></code></p>
												<?php if ( is_string( $discarded_candidate['component'] ?? null ) ) { ?>
													<p class="description"><strong><?php esc_html_e( 'Blocking component:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $discarded_candidate['component'] ); ?></code></p>
												<?php } ?>
											</li>
										<?php } ?>
									<?php } ?>
								</ul>
							</div>
						<?php } ?>

						<?php if ( null !== $storage_recovery ) { ?>
							<div class="ran-booster-onboarding__storage-recovery">
								<h4><?php echo esc_html( $storage_can_reset ? __( 'Start over with empty credential storage', 'ran-booster' ) : __( 'Existing Booster storage found', 'ran-booster' ) ); ?></h4>
								<p><?php echo esc_html( $storage_recovery['message'] ); ?></p>
								<?php if ( $storage_recovery['can_adopt'] ) { ?>
									<?php if ( null !== $storage_recovery['candidate_directory'] ) { ?>
										<p class="description"><strong><?php esc_html_e( 'Verified storage directory:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storage_recovery['candidate_directory'] ); ?></code></p>
									<?php } ?>
									<p class="description"><?php esc_html_e( 'This proves decryption, canonical storage and the current registered provider credential shape. It does not contact a provider or prove that a credential is still active. Adoption changes only Booster’s owned wp-config.php pointer; it does not move or delete files.', 'ran-booster' ); ?></p>
									<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
										<?php wp_nonce_field( 'ran-booster-adopt-secure-storage' ); ?>
										<input type="hidden" name="ran_booster[action]" value="adopt-secure-storage">
										<input type="hidden" name="ran_booster[recovery_token]" value="<?php echo esc_attr( $storage_recovery['token'] ); ?>">
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Adopt existing storage', 'ran-booster' ); ?></button>
									</form>
								<?php } elseif ( $storage_can_reset ) { ?>
									<p><strong><?php esc_html_e( 'Permanent reset:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching secrets.json and database key from the same backup first if they are available. Resetting abandons every repository credential and webhook secret that cannot be recovered from that matching pair.', 'ran-booster' ); ?></p>
									<p class="description"><?php esc_html_e( 'A Transporter Blueprint is not a complete credential backup. It may transfer selected repository credentials for applicable package rows, including explicit credential-only recovery for an already-managed package, but it never contains webhook secrets.', 'ran-booster' ); ?></p>
									<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
										<?php wp_nonce_field( 'ran-booster-reset-empty-storage' ); ?>
										<input type="hidden" name="ran_booster[action]" value="reset-empty-storage">
										<label for="ran-booster-reset-confirmation">
											<?php
											// translators: %s is the exact confirmation phrase the administrator must type.
											echo esc_html( sprintf( __( 'Type %s to confirm:', 'ran-booster' ), $storage_recovery['reset_confirmation'] ) );
											?>
										</label>
										<input id="ran-booster-reset-confirmation" type="text" name="ran_booster[reset_confirmation]" required autocomplete="off" pattern="<?php echo esc_attr( $storage_recovery['reset_confirmation'] ); ?>">
										<button type="submit" class="button"><?php esc_html_e( 'Reset credential storage', 'ran-booster' ); ?></button>
									</form>
								<?php } else { ?>
									<p class="description"><?php esc_html_e( 'Automatic adoption remains disabled. Booster will not bypass private-path checks or rewrite an operator-managed storage definition.', 'ran-booster' ); ?></p>
								<?php } ?>
							</div>
						<?php } ?>

						<?php if ( $shows_storage_override ) { ?>
							<details class="ran-booster-onboarding__storage-manual" open>
								<summary><?php echo esc_html( 'storage_needs_attention' === $storage_status ? __( 'Use a different storage location', 'ran-booster' ) : __( 'Set a storage location manually', 'ran-booster' ) ); ?></summary>
								<div>
									<p><?php esc_html_e( 'Choose a durable absolute directory outside the public web root. Create it as a real directory owned by the PHP process user with mode 0700. Booster manages secrets.json and its lock inside it; if the file already exists, it must be owned by PHP with mode 0600.', 'ran-booster' ); ?></p>
									<p><?php esc_html_e( 'Remove any existing RAN_BOOSTER_ENCRYPTED_SECRETS_FILE definition, then define this directory constant in wp-config.php before WordPress loads plugins. Use __DIR__ to anchor a relative layout to wp-config.php:', 'ran-booster' ); ?></p>
									<code>define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', dirname( __DIR__ ) . '/private/ran-booster' );</code>
									<p><?php esc_html_e( 'Or update the same constant with WP-CLI:', 'ran-booster' ); ?></p>
									<code>wp config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR '/absolute/private/path' --type=constant</code>
									<p><?php esc_html_e( 'For environment-managed hosting, replace the existing definition with this wp-config.php bridge. An environment variable by itself is not read by Booster:', 'ran-booster' ); ?></p>
									<pre><code>$ran_booster_secrets_dir = getenv( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' );
if ( is_string( $ran_booster_secrets_dir ) &amp;&amp; '' !== trim( $ran_booster_secrets_dir ) ) {
	define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $ran_booster_secrets_dir );
}</code></pre>
									<p><?php esc_html_e( 'If credentials already exist, move the secrets file together with its matching database key; do not copy or reset only one half.', 'ran-booster' ); ?></p>
								</div>
							</details>
						<?php } ?>

						<?php if ( null !== $secrets_storage['config_alternatives'] ) { ?>
							<details class="ran-booster-onboarding__storage-manual" <?php echo esc_attr( 'manual_required' === $storage_status ? 'open' : '' ); ?>>
								<summary><?php echo esc_html( $secrets_storage['can_provision'] ? __( 'Set up manually instead', 'ran-booster' ) : __( 'Manual setup instructions', 'ran-booster' ) ); ?></summary>
								<div>
									<p><?php echo esc_html( $secrets_storage['manual_preflight'] ); ?></p>
									<p><?php esc_html_e( 'Use an absolute private directory outside the public web root on durable local storage. It must be owned by PHP, readable and writable by PHP, and mode 0700; Booster manages secrets.json within it.', 'ran-booster' ); ?></p>
									<p><?php esc_html_e( 'Create the owner-only private directories:', 'ran-booster' ); ?></p>
									<ol>
										<?php foreach ( $secrets_storage['directory_commands'] as $command ) { ?>
											<li><code><?php echo esc_html( $command ); ?></code></li>
										<?php } ?>
									</ol>
									<p><?php esc_html_e( 'Then choose one configuration method, not both:', 'ran-booster' ); ?></p>
									<ul>
										<li>
											<?php esc_html_e( 'Add this line to wp-config.php:', 'ran-booster' ); ?>
											<code><?php echo esc_html( $secrets_storage['config_alternatives']['define'] ); ?></code>
										</li>
										<?php if ( '' !== $secrets_storage['config_alternatives']['wp_cli'] ) { ?>
											<li>
												<?php esc_html_e( 'Or run this WP-CLI command:', 'ran-booster' ); ?>
												<code><?php echo esc_html( $secrets_storage['config_alternatives']['wp_cli'] ); ?></code>
											</li>
										<?php } ?>
									</ul>
								</div>
							</details>
						<?php } ?>

						<p class="ran-booster-onboarding__storage-documentation">
							<a href="<?php echo esc_url( $credential_storage_documentation_url ); ?>"><?php esc_html_e( 'Learn how Booster manages credentials and keys', 'ran-booster' ); ?></a>
						</p>
					</div>
				</details>
			<?php } ?>
		</section>
	<?php } ?>
</section>
