<?php

/** @var list<array<string, mixed>> $extensions */
/** @var string $pluginsUrl */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ran-booster-admin ran-booster-extensions">
	<header class="ran-booster-extensions__header">
		<h1><?php esc_html_e( 'RAN Booster Extensions', 'ran-booster' ); ?></h1>
		<p><?php esc_html_e( 'Add focused capabilities to Booster. Every extension is currently in beta.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-extensions__grid">
		<?php foreach ( $extensions as $extension ) : ?>
			<article class="plugin-card ran-booster-extension-card" data-extension="<?php echo esc_attr( $extension['id'] ); ?>">
				<div class="plugin-card-top">
					<img class="plugin-icon" src="<?php echo esc_url( $extension['image_url'] ); ?>" alt="">
					<div class="ran-booster-extension-card__body">
						<div class="name column-name">
							<h2><a href="<?php echo esc_url( $extension['docs_url'] ); ?>"><?php echo esc_html( $extension['name'] ); ?></a></h2>
						</div>
						<div class="action-links">
							<?php if ( 'Active' === $extension['state'] ) : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Active', 'ran-booster' ); ?></button>
								<a href="<?php echo esc_url( $extension['docs_url'] ); ?>"><?php esc_html_e( 'More Details', 'ran-booster' ); ?></a>
							<?php elseif ( 'Not installed' !== $extension['state'] ) : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php echo esc_html( $extension['state'] ); ?></button>
								<a href="<?php echo esc_url( $pluginsUrl ); ?>"><?php esc_html_e( 'Open Plugins', 'ran-booster' ); ?></a>
							<?php elseif ( 'Subscriber' === $extension['availability'] ) : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Subscriber install', 'ran-booster' ); ?></button>
								<a href="https://github.com/sponsors/RocketsAreNostalgic"><?php esc_html_e( 'Get access', 'ran-booster' ); ?></a>
							<?php else : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Install unavailable', 'ran-booster' ); ?></button>
								<a href="<?php echo esc_url( $extension['docs_url'] ); ?>"><?php esc_html_e( 'More Details', 'ran-booster' ); ?></a>
							<?php endif; ?>
						</div>
						<div class="desc column-description">
							<p><?php echo esc_html( $extension['description'] ); ?></p>
						</div>
						<p class="ran-booster-extension-card__byline">
							<?php esc_html_e( 'By', 'ran-booster' ); ?>
							<a href="https://github.com/RocketsAreNostalgic">Rockets Are Nostalgic</a>
							<span aria-hidden="true"> · </span>
							<a href="<?php echo esc_url( $extension['support_url'] ); ?>"><?php esc_html_e( 'Support', 'ran-booster' ); ?></a>
						</p>
					</div>
				</div>
				<div class="plugin-card-bottom">
					<div class="ran-booster-extension-card__metadata">
						<span class="ran-booster-extension-card__badge"><?php echo esc_html( $extension['availability'] ); ?></span>
						<span class="ran-booster-extension-card__badge"><?php esc_html_e( 'Beta', 'ran-booster' ); ?></span>
						<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $extension['state_kind'] ); ?>"><?php echo esc_html( $extension['state'] ); ?></span>
					</div>
					<div class="ran-booster-extension-card__compatibility<?php echo $extension['compatible'] ? '' : ' ran-booster-extension-card__compatibility--incompatible'; ?>">
						<span aria-hidden="true"><?php echo $extension['compatible'] ? '✓' : '×'; ?></span>
						<span><?php echo esc_html( $extension['compatible'] ? __( 'Compatible with your version of Booster', 'ran-booster' ) : __( 'Requires a different version of Booster', 'ran-booster' ) ); ?></span>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
	<p class="description ran-booster-extensions__note"><?php esc_html_e( 'Free downloads will be enabled after their public beta releases are ready. Subscriber packages are delivered manually during beta.', 'ran-booster' ); ?></p>
</div>
