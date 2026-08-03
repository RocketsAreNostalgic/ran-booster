<?php

defined( 'ABSPATH' ) || exit;

// This Core-owned region is the only provider-settings fragment returned by
// the initial HTMX mutation contract. It intentionally contains no secret
// material: credential profiles are display-safe metadata supplied by the
// provider settings presenter.

$publicLookupProfileError = isset( $publicLookupProfileError ) && is_string( $publicLookupProfileError )
	? $publicLookupProfileError
	: null;
$publicLookupProfile      = isset( $publicLookupProfile ) && is_array( $publicLookupProfile )
	? $publicLookupProfile
	: ( isset( $public_lookup_profile ) && is_array( $public_lookup_profile )
		? $public_lookup_profile
		: array(
			'configured_id' => '',
			'stale'         => false,
		) );
?>
<section id="ran-booster-public-lookup-profile-region" class="ran-booster-provider-section" data-ran-booster-admin-mutation-region="public-lookup-profile" aria-labelledby="ran-booster-public-lookup-heading">
	<header class="ran-booster-provider-section__header">
		<h3 id="ran-booster-public-lookup-heading" class="ran-booster-section__title"><?php esc_html_e( 'Public repository lookup', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php esc_html_e( 'Choose Anonymous, or use a dedicated saved credential to reduce provider rate-limit interruptions.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-provider-section__body">
		<div class="ran-booster-public-lookup-profile__panel ran-booster-panel">
			<div id="ran-booster-public-lookup-profile-error" class="notice notice-error inline" data-ran-booster-admin-mutation-error role="alert" tabindex="-1" <?php echo null === $publicLookupProfileError ? 'hidden' : ''; ?>><p><?php echo null === $publicLookupProfileError ? '' : esc_html( $publicLookupProfileError ); ?></p></div>
			<div class="ran-booster-public-lookup-profile__layout">
				<form method="post" action="" class="ran-booster-public-lookup-profile__form" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-public-lookup-profile-error" hx-post="" hx-target="#ran-booster-public-lookup-profile-region" hx-swap="outerHTML transition:true show:none" hx-sync="this:drop">
					<?php wp_nonce_field( 'ran-booster-save-public-lookup-profile' ); ?>
					<input type="hidden" name="ran_booster[action]" value="save-public-lookup-profile">
					<input type="hidden" name="ran_booster[provider]" value="<?php echo esc_attr( $provider['code'] ); ?>">
					<label class="ran-booster-public-lookup-profile__label ran-booster-eyebrow ran-booster-eyebrow--compact" for="ran-booster-public-lookup-profile"><?php esc_html_e( 'Lookup credential', 'ran-booster' ); ?></label>
					<div class="ran-booster-public-lookup-profile__controls">
						<select id="ran-booster-public-lookup-profile" name="ran_booster[profile_id]">
							<option value="" <?php selected( '', $publicLookupProfile['configured_id'] ); ?>><?php esc_html_e( 'Anonymous', 'ran-booster' ); ?></option>
							<?php foreach ( $credential_profiles as $profile ) { ?>
								<?php if ( $profile['configured'] ) { ?>
									<option value="<?php echo esc_attr( $profile['id'] ); ?>" <?php selected( $profile['id'], $publicLookupProfile['configured_id'] ); ?>><?php echo esc_html( $profile['label'] . ' (' . $profile['id'] . ')' ); ?></option>
								<?php } ?>
							<?php } ?>
							<?php if ( $publicLookupProfile['stale'] ) { ?>
								<option value="<?php echo esc_attr( $publicLookupProfile['configured_id'] ); ?>" selected><?php echo esc_html( 'Missing profile (' . $publicLookupProfile['configured_id'] . ')' ); ?></option>
							<?php } ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Save', 'ran-booster' ); ?></button>
					</div>
				</form>
					<aside class="ran-booster-public-lookup-profile__guidance">
						<strong><?php esc_html_e( 'Credential guidance', 'ran-booster' ); ?></strong>
						<p><?php esc_html_e( 'Prefer a dedicated, expiring, least-privilege credential kept separate from credentials that can access private repositories or deployments.', 'ran-booster' ); ?></p>
						<p><?php esc_html_e( 'The active provider can read every credential saved under its provider code, not only the selected lookup profile. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></p>
					</aside>
			</div>
			<?php if ( $publicLookupProfile['stale'] ) { ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The configured public lookup profile is missing. Choose Anonymous or another saved profile, then save the preference.', 'ran-booster' ); ?></p></div>
			<?php } ?>
		</div>
	</div>
</section>
