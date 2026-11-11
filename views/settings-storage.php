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
	'setup_available'         => 'warning',
	'manual_required'         => 'warning',
	'unsupported'             => 'error',
	'pending_verification'    => 'pending',
);
$storage_status_intents       = array(
	'path_configured'         => 'none',
	'storage_healthy'         => 'stable',
	'storage_needs_attention' => 'medium',
	'setup_available'         => 'medium',
	'manual_required'         => 'medium',
	'unsupported'             => 'high',
	'pending_verification'    => 'informational',
);
$storage_path_source          = null === $secrets_storage ? null : ( $secrets_storage['path_source'] ?? null );
$storage_path_source_labels   = array(
	'automatic' => __( 'Booster default', 'ran-booster' ),
	'manual'    => __( 'Configured path', 'ran-booster' ),
	'unknown'   => __( 'Unknown', 'ran-booster' ),
);
$storage_recovery             = null === $secrets_storage || ! is_array( $secrets_storage['recovery'] ?? null )
	? null
	: $secrets_storage['recovery'];
$storage_can_reset            = null !== $storage_recovery && true === ( $storage_recovery['can_reset'] ?? false );
$storage_discarded_candidates = null === $secrets_storage || ! is_array( $secrets_storage['discarded_candidates'] ?? null )
	? array()
	: $secrets_storage['discarded_candidates'];
$shows_storage_override       = in_array( $storage_status, array( 'storage_needs_attention', 'manual_required' ), true )
	&& null === ( $secrets_storage['config_alternatives'] ?? null );
$has_storage_details          = in_array( $storage_status, array( 'path_configured', 'storage_healthy', 'storage_needs_attention' ), true );
// The Card rests closed only while storage is healthy and nothing needs a decision; every other state, and any recovery route, opens it.
$storage_card_open = 'storage_healthy' !== $storage_status || null !== $storage_recovery;

?>
	<?php
	if ( null !== $secrets_storage ) {
		$storage_status_label = $storage_status_labels[ $storage_status ] ?? __( 'Status unknown', 'ran-booster' );
		ob_start();
		?>
			<?php if ( '' !== $storage_reason_code && in_array( $storage_status, array( 'storage_needs_attention', 'manual_required', 'unsupported' ), true ) ) { ?>
				<p class="description"><strong><?php esc_html_e( 'Diagnostic code:', 'ran-booster' ); ?></strong> <code><?php echo esc_html( $storage_reason_code ); ?></code></p>
			<?php } ?>

			<?php if ( $secrets_storage['can_provision'] ) { ?>
				<p class="description"><?php esc_html_e( 'Booster checks a private location and, if it is safe, updates wp-config.php. The encrypted file and its separate database key are created when you save the first credential.', 'ran-booster' ); ?></p>
				<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
					<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-create-secure-storage' ); ?>
					<input type="hidden" name="ran_booster[action]" value="create-secure-storage">
					<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"><?php esc_html_e( 'Set up default storage', 'ran-booster' ); ?></button>
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
								<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-adopt-secure-storage' ); ?>
								<input type="hidden" name="ran_booster[action]" value="adopt-secure-storage">
								<input type="hidden" name="ran_booster[recovery_token]" value="<?php echo esc_attr( $storage_recovery['token'] ); ?>">
								<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::primary() ); ?>"><?php esc_html_e( 'Adopt existing storage', 'ran-booster' ); ?></button>
							</form>
						<?php } elseif ( $storage_can_reset ) { ?>
							<p><strong><?php esc_html_e( 'Permanent reset:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Restore the matching ran-booster-store.json and database key from the same backup first if they are available. Resetting abandons every repository credential and webhook secret that cannot be recovered from that matching pair.', 'ran-booster' ); ?></p>
							<p class="description"><?php esc_html_e( 'A Transporter Blueprint is not a complete credential backup. It may transfer selected repository credentials for applicable package rows, including explicit credential-only recovery for an already-managed package, but it never contains webhook secrets.', 'ran-booster' ); ?></p>
							<form class="ran-booster-onboarding__storage-actions" method="post" action="<?php echo esc_url( $secrets_storage['action_url'] ); ?>">
								<?php \RAN\Admin\Component\NonceField::print( 'ran-booster-reset-empty-storage' ); ?>
								<input type="hidden" name="ran_booster[action]" value="reset-empty-storage">
								<label for="ran-booster-reset-confirmation">
									<?php
									// translators: %s is the exact confirmation phrase the administrator must type.
									echo esc_html( sprintf( __( 'Type %s to confirm:', 'ran-booster' ), $storage_recovery['reset_confirmation'] ) );
									?>
								</label>
								<input id="ran-booster-reset-confirmation" type="text" name="ran_booster[reset_confirmation]" required autocomplete="off" pattern="<?php echo esc_attr( $storage_recovery['reset_confirmation'] ); ?>">
								<button type="submit" class="<?php echo esc_attr( \RAN\Admin\Component\ButtonClasses::secondary() ); ?>"><?php esc_html_e( 'Reset credential storage', 'ran-booster' ); ?></button>
							</form>
						<?php } else { ?>
							<p class="description"><?php esc_html_e( 'Automatic adoption remains disabled. Booster will not bypass private-path checks or rewrite an operator-managed storage definition.', 'ran-booster' ); ?></p>
						<?php } ?>
					</div>
				<?php } ?>

				<?php if ( $shows_storage_override ) { ?>
					<div class="ran-booster-onboarding__storage-manual" data-ran-booster-ui-disclosure="settings-storage" data-ran-booster-ui-disclosure-density="compact">
					<details class="ran-booster-onboarding__storage-manual ran-booster-disclosure" open data-ran-booster-ui-disclosure-fallback>
						<summary><?php echo esc_html( 'storage_needs_attention' === $storage_status ? __( 'Use a different storage location', 'ran-booster' ) : __( 'Set a storage location manually', 'ran-booster' ) ); ?></summary>
						<div class="ran-booster-disclosure__body" data-ran-booster-ui-disclosure-content>
							<p><?php esc_html_e( 'Choose a durable absolute directory outside the public web root. Create it as a real directory owned by the PHP process user with mode 0700. Booster manages ran-booster-store.json and its lock inside it; if the file already exists, it must be owned by PHP with mode 0600.', 'ran-booster' ); ?></p>
							<?php if ( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE' === $storage_constant ) { ?>
								<p><?php esc_html_e( 'Remove any existing RAN_BOOSTER_ENCRYPTED_SECRETS_FILE definition, then define this directory constant in wp-config.php before WordPress loads plugins. Use __DIR__ to anchor a relative layout to wp-config.php:', 'ran-booster' ); ?></p>
							<?php } else { ?>
								<p><?php esc_html_e( 'Define this directory constant in wp-config.php before WordPress loads plugins. Use __DIR__ to anchor a relative layout to wp-config.php:', 'ran-booster' ); ?></p>
							<?php } ?>
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
				</div>
			<?php } ?>

			<?php if ( null !== $secrets_storage['config_alternatives'] ) { ?>
				<div class="ran-booster-onboarding__storage-manual" data-ran-booster-ui-disclosure="settings-storage" data-ran-booster-ui-disclosure-density="compact">
				<details data-ran-booster-ui-disclosure-fallback class="ran-booster-onboarding__storage-manual ran-booster-disclosure" <?php echo esc_attr( 'manual_required' === $storage_status ? 'open' : '' ); ?>>
					<summary><?php echo esc_html( $secrets_storage['can_provision'] ? __( 'Custom setup instructions', 'ran-booster' ) : __( 'Manual setup instructions', 'ran-booster' ) ); ?></summary>
					<div class="ran-booster-disclosure__body" data-ran-booster-ui-disclosure-content>
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
				</div>
			<?php } ?>

			<?php if ( array() !== $storage_discarded_candidates ) { ?>
				<div class="ran-booster-onboarding__storage-discarded" data-ran-booster-ui-disclosure="settings-storage" data-ran-booster-ui-disclosure-density="compact">
				<details class="ran-booster-onboarding__storage-discarded ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback>
					<summary><?php esc_html_e( 'Why automatic setup is unavailable', 'ran-booster' ); ?></summary>
					<div class="ran-booster-disclosure__body" data-ran-booster-ui-disclosure-content>
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
				</div>
			<?php } ?>

			<?php if ( $has_storage_details ) { ?>
				<dl class="ran-booster-onboarding__storage-facts">
					<div class="ran-booster-onboarding__storage-fact--inline"><dt><?php esc_html_e( 'Configuration constant', 'ran-booster' ); ?></dt><dd>
					<?php
					if ( null !== $storage_constant ) {
						?>
						<code class="ran-booster-onboarding__storage-inline"><?php echo esc_html( $storage_constant ); ?></code>
						<?php
					} else {
						?>
						<code class="ran-booster-onboarding__storage-inline"><?php esc_html_e( 'Not configured', 'ran-booster' ); ?></code>
						<?php
					}
					?>
					</dd></div>
					<?php if ( null !== $secrets_storage['candidate_path'] ) { ?>
						<?php if ( is_string( $storage_path_source ) && isset( $storage_path_source_labels[ $storage_path_source ] ) ) { ?>
							<div class="ran-booster-onboarding__storage-fact--inline">
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
							if ( 'path_configured' === $storage_status ) {
								?>
								<p class="description ran-booster-onboarding__storage-facts-note"><?php esc_html_e( 'The encrypted file and its separate database key are created when you save the first credential.', 'ran-booster' ); ?></p>
								<?php
							} elseif ( 'storage_healthy' === $storage_status ) {
								?>
								<p class="description ran-booster-onboarding__storage-facts-note"><?php esc_html_e( 'Credentials are encrypted in this file; the key is stored separately.', 'ran-booster' ); ?></p>
								<?php
							}
							?>
							</dd>
						</div>

					<?php } ?>
				</dl>
			<?php } ?>
			<p class="ran-booster-onboarding__storage-documentation">
				<a href="<?php echo esc_url( $credential_storage_documentation_url ); ?>"><?php esc_html_e( 'Learn how Booster manages credentials and keys', 'ran-booster' ); ?></a>
					<span aria-hidden="true"> · </span><a href="<?php echo esc_url( str_replace( '#ran-booster-credential-storage', '#ran-booster-manual-storage-override', $credential_storage_documentation_url ) ); ?>"><?php esc_html_e( 'Manual storage override', 'ran-booster' ); ?></a>
			</p>
		<?php
		$card_body        = (string) ob_get_clean();
		$card_id          = 'ran-booster-storage';
		$card_modifier    = 'ran-booster-settings__card--storage';
		$card_title       = esc_html__( 'Secure credential storage', 'ran-booster' );
		$card_description = esc_html( $secrets_storage['message'] );
		$card_badge       = \RAN\Admin\Component\StatusBadge::for_intent( (string) $storage_status_label, (string) ( $storage_status_intents[ $storage_status ] ?? 'none' ), array( 'data-ran-booster-storage-status' => (string) $storage_status ) );
		$card_label       = __( 'Secure credential storage', 'ran-booster' );
		$card_open        = $storage_card_open;
		$card_body_class  = '';
		$card_attributes  = array( 'data-ran-booster-storage-reason' => $storage_reason_code );
		require __DIR__ . '/settings-card.php';
	}
	?>
