<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Privileged Settings-only payload from Dashboard::get_settings().
 *
 * @var array<string, mixed>|null $secrets_storage
 * @var string $credential_storage_documentation_url
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

$storage_constant             = defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' ) ? 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' : ( defined( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' ) ? 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' : null );
$storage_status               = null === $secrets_storage ? '' : (string) ( $secrets_storage['status'] ?? '' );
$storage_reason_code          = null === $secrets_storage ? '' : (string) ( $secrets_storage['reason_code'] ?? '' );
$storage_candidate_path       = null === $secrets_storage ? null : ( $secrets_storage['candidate_path'] ?? null );
$storage_directory            = null === $secrets_storage
	? null
	: ( $secrets_storage['candidate_directory'] ?? ( is_string( $storage_candidate_path ) ? dirname( $storage_candidate_path ) : null ) );
$storage_status_labels        = array(
	'path_configured'         => __( 'Path configured', 'ran-booster' ),
	'storage_healthy'         => __( 'Storage healthy', 'ran-booster' ),
	'storage_needs_attention' => __( 'Storage needs attention', 'ran-booster' ),
	'setup_available'         => __( 'Setup available', 'ran-booster' ),
	'manual_required'         => __( 'Needs attention', 'ran-booster' ),
	'unsupported'             => __( 'Unavailable', 'ran-booster' ),
	'pending_verification'    => __( 'Verification pending', 'ran-booster' ),
);
$storage_status_classes       = array(
	'path_configured'         => 'neutral',
	'storage_healthy'         => 'ok',
	'storage_needs_attention' => 'warning',
	'setup_available'         => 'neutral',
	'manual_required'         => 'warning',
	'unsupported'             => 'error',
	'pending_verification'    => 'pending',
);
$storage_status_intents       = array(
	'path_configured'         => 'none',
	'storage_healthy'         => 'stable',
	'storage_needs_attention' => 'medium',
	'setup_available'         => 'none',
	'manual_required'         => 'medium',
	'unsupported'             => 'high',
	'pending_verification'    => 'informational',
);
$storage_path_source          = null === $secrets_storage ? null : ( $secrets_storage['path_source'] ?? null );
$storage_path_source_labels   = array(
	'automatic' => __( 'Booster default', 'ran-booster' ),
	'manual'    => __( 'Custom wp-config.php path', 'ran-booster' ),
);
$storage_recovery             = null === $secrets_storage || ! is_array( $secrets_storage['recovery'] ?? null )
	? null
	: $secrets_storage['recovery'];
$storage_can_reset            = null !== $storage_recovery && true === ( $storage_recovery['can_reset'] ?? false );
$storage_discarded_candidates = null === $secrets_storage || ! is_array( $secrets_storage['discarded_candidates'] ?? null )
	? array()
	: $secrets_storage['discarded_candidates'];
$storage_details_open         = 'storage_needs_attention' === $storage_status;
$shows_storage_override       = in_array( $storage_status, array( 'storage_needs_attention', 'manual_required' ), true )
	&& null === ( $secrets_storage['config_alternatives'] ?? null );
$has_storage_details          = in_array( $storage_status, array( 'path_configured', 'storage_healthy', 'storage_needs_attention' ), true );

?>
	<?php if ( null !== $secrets_storage ) { ?>
		<section class="ran-booster-panel ran-booster-onboarding__storage" aria-labelledby="ran-booster-storage" data-ran-booster-storage-reason="<?php echo esc_attr( $storage_reason_code ); ?>">
			<div class="ran-booster-onboarding__storage-heading">
				<h3 id="ran-booster-storage"><?php esc_html_e( 'Secure credential storage', 'ran-booster' ); ?></h3>
				<?php $storage_status_label = $storage_status_labels[ $storage_status ] ?? __( 'Status unknown', 'ran-booster' ); ?>
				<span data-ran-booster-storage-status="<?php echo esc_attr( $storage_status ); ?>" data-ran-booster-ui-badge data-ran-booster-ui-badge-label="<?php echo esc_attr( $storage_status_label ); ?>" data-ran-booster-ui-badge-intent="<?php echo esc_attr( $storage_status_intents[ $storage_status ] ?? 'none' ); ?>"><span data-ran-booster-ui-badge-fallback class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $storage_status_classes[ $storage_status ] ?? 'neutral' ); ?>"><?php echo esc_html( $storage_status_label ); ?></span><span data-ran-booster-ui-badge-mount></span></span>
			</div>
			<p><?php echo esc_html( $secrets_storage['message'] ); ?></p>
			<?php if ( '' !== $storage_reason_code && in_array( $storage_status, array( 'storage_needs_attention', 'manual_required', 'unsupported' ), true ) ) { ?>
				<p class="description"><strong><?php esc_html_e( 'Diagnostic code:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storage_reason_code ); ?></code></p>
			<?php } ?>

			<?php if ( $secrets_storage['can_provision'] ) { ?>
				<p class="description"><?php esc_html_e( 'Booster checks a private location and, if it is safe, updates wp-config.php. The encrypted file and its separate database key are created when you save the first credential.', 'ran-booster' ); ?></p>
				<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
					<?php wp_nonce_field( 'ran-booster-create-secure-storage' ); ?>
					<input type="hidden" name="ran_booster[action]" value="create-secure-storage">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create secure storage', 'ran-booster' ); ?></button>
				</form>
			<?php } ?>

			<?php if ( null !== $storage_recovery ) { ?>
				<div class="ran-booster-onboarding__storage-recovery">
						<h4><?php echo esc_html( $storage_can_reset ? __( 'Start over with empty credential storage', 'ran-booster' ) : __( 'Existing Booster storage found', 'ran-booster' ) ); ?></h4>
						<p><?php echo esc_html( $storage_recovery['message'] ); ?></p>
						<?php if ( $storage_recovery['can_adopt'] ) { ?>
							<?php if ( null !== $storage_recovery['candidate_directory'] ) { ?>
								<p class="description"><strong><?php esc_html_e( 'Verified storage directory:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storage_recovery['candidate_directory'] ); ?></code></p>
							<?php } ?>
							<p class="description"><?php esc_html_e( 'This proves decryption, canonical storage and the current registered Git provider credential shape. It does not contact a Git provider or prove that a credential is still active. Adoption changes only Booster’s owned wp-config.php pointer; it does not move or delete files.', 'ran-booster' ); ?></p>
							<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
								<?php wp_nonce_field( 'ran-booster-adopt-secure-storage' ); ?>
								<input type="hidden" name="ran_booster[action]" value="adopt-secure-storage">
								<input type="hidden" name="ran_booster[recovery_token]" value="<?php echo esc_attr( $storage_recovery['token'] ); ?>">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Adopt existing storage', 'ran-booster' ); ?></button>
							</form>
						<?php } elseif ( $storage_can_reset ) { ?>
							<p><strong><?php esc_html_e( 'Permanent reset:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching ran-booster-store.json and database key from the same backup first if they are available. Resetting abandons every repository credential and webhook secret that cannot be recovered from that matching pair.', 'ran-booster' ); ?></p>
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
					<details class="ran-booster-onboarding__storage-manual ran-booster-disclosure" open>
						<summary><?php echo esc_html( 'storage_needs_attention' === $storage_status ? __( 'Use a different storage location', 'ran-booster' ) : __( 'Set a storage location manually', 'ran-booster' ) ); ?></summary>
						<div class="ran-booster-disclosure__body">
							<p><?php esc_html_e( 'Choose a durable absolute directory outside the public web root. Create it as a real directory owned by the PHP process user with mode 0700. Booster manages ran-booster-store.json and its lock inside it; if the file already exists, it must be owned by PHP with mode 0600.', 'ran-booster' ); ?></p>
							<p><?php esc_html_e( 'Remove any existing RAN_BOOSTER_ENCRYPTED_SECRETS_FILE definition, then define this directory constant in wp-config.php before WordPress loads plugins. Use __DIR__ to anchor a relative layout to wp-config.php:', 'ran-booster' ); ?></p>
							<?php
							$snippet_value      = 'define( \'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR\', dirname( __DIR__ ) . \'/private/ran-booster\' );';
							$snippet_label      = __( 'Example wp-config.php line', 'ran-booster' );
							$snippet_copy_label = __( 'Copy example wp-config.php line', 'ran-booster' );
							include __DIR__ . '/copy-setup-snippet.php';
							?>
							<p><?php esc_html_e( 'Or update the same constant with WP-CLI:', 'ran-booster' ); ?></p>
							<?php
							$snippet_value      = 'wp config set RAN_BOOSTER_ENCRYPTED_SECRETS_DIR \'/absolute/private/path\' --type=constant';
							$snippet_label      = __( 'Example WP-CLI command', 'ran-booster' );
							$snippet_copy_label = __( 'Copy example WP-CLI command', 'ran-booster' );
							include __DIR__ . '/copy-setup-snippet.php';
							?>
							<p><?php esc_html_e( 'For environment-managed hosting, replace the existing definition with this wp-config.php bridge. An environment variable by itself is not read by Booster:', 'ran-booster' ); ?></p>
							<?php
							$snippet_value      = '$ran_booster_secrets_dir = getenv( \'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR\' );
if ( is_string( $ran_booster_secrets_dir ) && \'\' !== trim( $ran_booster_secrets_dir ) ) {
	define( \'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR\', $ran_booster_secrets_dir );
}';
							$snippet_label      = __( 'Environment-managed wp-config.php bridge', 'ran-booster' );
							$snippet_copy_label = __( 'Copy environment-managed wp-config.php bridge', 'ran-booster' );
							include __DIR__ . '/copy-setup-snippet.php';
							?>
							<p><?php esc_html_e( 'If credentials already exist, move the secrets file together with its matching database key; do not copy or reset only one half.', 'ran-booster' ); ?></p>
					</div>
				</details>
			<?php } ?>

			<?php if ( null !== $secrets_storage['config_alternatives'] ) { ?>
				<details class="ran-booster-onboarding__storage-manual ran-booster-disclosure" <?php echo esc_attr( 'manual_required' === $storage_status ? 'open' : '' ); ?>>
					<summary><?php echo esc_html( $secrets_storage['can_provision'] ? __( 'Set up manually instead', 'ran-booster' ) : __( 'Manual setup instructions', 'ran-booster' ) ); ?></summary>
					<div class="ran-booster-disclosure__body">
						<p><?php echo esc_html( $secrets_storage['manual_preflight'] ); ?></p>
						<p><?php esc_html_e( 'Use an absolute private directory outside the public web root on durable local storage. It must be owned by PHP, readable and writable by PHP, and mode 0700; Booster manages ran-booster-store.json within it.', 'ran-booster' ); ?></p>
						<ol>
							<li>
								<p><?php esc_html_e( 'Create the owner-only private directories:', 'ran-booster' ); ?></p>
								<?php foreach ( $secrets_storage['directory_commands'] as $command_index => $command ) { ?>
									<?php
										$snippet_value = $command;
										/* translators: %d: directory command number. */
										$snippet_label = sprintf( __( 'Directory command %d', 'ran-booster' ), $command_index + 1 );
										/* translators: %d: directory command number. */
										$snippet_copy_label = sprintf( __( 'Copy directory command %d', 'ran-booster' ), $command_index + 1 );
										include __DIR__ . '/copy-setup-snippet.php';
									?>
								<?php } ?>
							</li>
							<li>
								<p><?php esc_html_e( 'Then choose one configuration method, not both:', 'ran-booster' ); ?></p>
								<ul>
									<li>
										<?php esc_html_e( 'Add this line to wp-config.php:', 'ran-booster' ); ?>
										<?php
											$snippet_value      = $secrets_storage['config_alternatives']['define'];
											$snippet_label      = __( 'wp-config.php line', 'ran-booster' );
											$snippet_copy_label = __( 'Copy wp-config.php line', 'ran-booster' );
											include __DIR__ . '/copy-setup-snippet.php';
										?>
									</li>
									<?php if ( '' !== $secrets_storage['config_alternatives']['wp_cli'] ) { ?>
										<li>
											<?php esc_html_e( 'Or run this WP-CLI command:', 'ran-booster' ); ?>
											<?php
													$snippet_value      = $secrets_storage['config_alternatives']['wp_cli'];
													$snippet_label      = __( 'WP-CLI command', 'ran-booster' );
													$snippet_copy_label = __( 'Copy WP-CLI command', 'ran-booster' );
													include __DIR__ . '/copy-setup-snippet.php';
											?>
										</li>
									<?php } ?>
								</ul>
							</li>
						</ol>
					</div>
				</details>
			<?php } ?>

			<?php if ( array() !== $storage_discarded_candidates ) { ?>
				<details class="ran-booster-onboarding__storage-discarded ran-booster-disclosure">
					<summary><?php esc_html_e( 'Why automatic setup is unavailable', 'ran-booster' ); ?></summary>
					<div class="ran-booster-disclosure__body">
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
				</details>
			<?php } ?>

			<?php if ( $has_storage_details ) { ?>
				<details class="ran-booster-onboarding__storage-details ran-booster-disclosure"<?php echo $storage_details_open ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Storage details', 'ran-booster' ); ?></summary>
					<div class="ran-booster-disclosure__body">
						<dl class="ran-booster-onboarding__storage-facts">
							<div><dt><?php esc_html_e( 'Configuration constant', 'ran-booster' ); ?></dt><dd>
							<?php
							if ( null !== $storage_constant ) {
								?>
								<code class="ran-booster-onboarding__storage-inline"><?php echo esc_html( $storage_constant ); ?></code>
								<?php
							} else {
								esc_html_e( 'Not configured', 'ran-booster' ); }
							?>
							</dd></div>
							<?php if ( null !== $secrets_storage['candidate_path'] ) { ?>
								<?php if ( is_string( $storage_path_source ) && isset( $storage_path_source_labels[ $storage_path_source ] ) ) { ?>
									<div>
										<dt><?php esc_html_e( 'Path selection', 'ran-booster' ); ?></dt>
										<dd><code class="ran-booster-onboarding__storage-inline"><?php echo esc_html( $storage_path_source_labels[ $storage_path_source ] ); ?></code></dd>
									</div>
								<?php } ?>
								<?php if ( null !== $storage_directory ) { ?>
									<div>
										<dt><?php esc_html_e( 'Storage directory', 'ran-booster' ); ?></dt>
										<dd>
										<?php
										$copy_value = $storage_directory;
										$copy_label = __( 'Copy storage directory', 'ran-booster' );
										include __DIR__ . '/copy-displayed-value.php';
										?>
										</dd>
									</div>
								<?php } ?>
								<div>
									<dt><?php esc_html_e( 'Storage file', 'ran-booster' ); ?></dt>
									<dd>
									<?php
									$copy_value = $secrets_storage['candidate_path'];
									$copy_label = __( 'Copy storage file', 'ran-booster' );
									include __DIR__ . '/copy-displayed-value.php';
									?>
									</dd>
								</div>

							<?php } ?>
						</dl>
						<?php if ( null !== $secrets_storage['candidate_path'] ) { ?>
							<?php if ( 'path_configured' === $storage_status ) { ?>
								<p class="description ran-booster-onboarding__storage-facts-note"><?php esc_html_e( 'The encrypted file and its separate database key are created when you save the first credential.', 'ran-booster' ); ?></p>
							<?php } elseif ( 'storage_healthy' === $storage_status ) { ?>
								<p class="description ran-booster-onboarding__storage-facts-note"><?php esc_html_e( 'Credentials are encrypted in this file. WordPress stores the encryption key separately.', 'ran-booster' ); ?></p>
							<?php } ?>
						<?php } ?>


					</div>
				</details>
			<?php } ?>
			<p class="ran-booster-onboarding__storage-documentation">
				<a href="<?php echo esc_url( $credential_storage_documentation_url ); ?>"><?php esc_html_e( 'Learn how Booster manages credentials and keys', 'ran-booster' ); ?></a>
					<span aria-hidden="true"> · </span><a href="<?php echo esc_url( str_replace( '#ran-booster-credential-storage', '#ran-booster-manual-storage-override', $credential_storage_documentation_url ) ); ?>"><?php esc_html_e( 'Manual storage override', 'ran-booster' ); ?></a>
			</p>
		</section>
	<?php } ?>
