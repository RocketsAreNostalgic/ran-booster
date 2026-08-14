<?php

/** @var list<array<string, mixed>> $extensions */
/** @var string $pluginsUrl */

defined( 'ABSPATH' ) || exit;
?>
<section class="ran-booster-page-shell ran-booster-extensions" aria-labelledby="ran-booster-extensions-heading">
	<header class="ran-booster-page-shell__header ran-booster-extensions__header">
		<p class="ran-booster-eyebrow"><?php esc_html_e( 'Add-ons', 'ran-booster' ); ?></p>
		<h2 id="ran-booster-extensions-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Extensions', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Add focused capabilities to Booster. Every extension is currently in beta.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-page-shell__body ran-booster-extensions__body">
		<div class="ran-booster-extensions__grid">
		<?php foreach ( $extensions as $extension ) : ?>
			<?php
			$detailsId          = 'ran-booster-extension-details-' . $extension['id'];
			$detailsUrl         = '#TB_inline?width=772&height=600&inlineId=' . $detailsId;
			$availabilityLabel  = 'Subscriber' === $extension['availability'] ? __( 'Sponsor', 'ran-booster' ) : $extension['availability'];
			$compatibilityLabel = $extension['compatible'] ? __( 'Compatible with your version of Booster', 'ran-booster' ) : __( 'Requires a different version of Booster', 'ran-booster' );
			$moreDetailsLabel   = sprintf(
				/* translators: %s: Extension name. */
				__( 'More details about %s', 'ran-booster' ),
				$extension['name']
			);
			?>
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
							<?php elseif ( 'Not installed' !== $extension['state'] ) : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php echo esc_html( $extension['state'] ); ?></button>
							<?php elseif ( 'Subscriber' === $extension['availability'] ) : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Sponsor install', 'ran-booster' ); ?></button>
							<?php else : ?>
								<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Install unavailable', 'ran-booster' ); ?></button>
							<?php endif; ?>
							<a class="thickbox ran-booster-extension-details-link" href="<?php echo esc_url( $detailsUrl ); ?>" data-title="<?php echo esc_attr( $extension['name'] ); ?>" aria-label="<?php echo esc_attr( $moreDetailsLabel ); ?>"><?php esc_html_e( 'More Details', 'ran-booster' ); ?></a>
							<?php if ( 'Active' !== $extension['state'] && 'Not installed' !== $extension['state'] ) : ?>
								<a href="<?php echo esc_url( $pluginsUrl ); ?>"><?php esc_html_e( 'Open Plugins', 'ran-booster' ); ?></a>
							<?php elseif ( 'Not installed' === $extension['state'] && 'Subscriber' === $extension['availability'] ) : ?>
								<a href="https://github.com/sponsors/RocketsAreNostalgic"><?php esc_html_e( 'Get access', 'ran-booster' ); ?></a>
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
						<span class="ran-booster-extension-card__badge"><?php echo esc_html( $availabilityLabel ); ?></span>
						<span class="ran-booster-extension-card__badge"><?php esc_html_e( 'Beta', 'ran-booster' ); ?></span>
						<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $extension['state_kind'] ); ?>"><?php echo esc_html( $extension['state'] ); ?></span>
					</div>
					<div class="ran-booster-extension-card__compatibility<?php echo $extension['compatible'] ? '' : ' ran-booster-extension-card__compatibility--incompatible'; ?>">
						<span aria-hidden="true"><?php echo $extension['compatible'] ? '✓' : '×'; ?></span>
						<span><?php echo esc_html( $compatibilityLabel ); ?></span>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
		<?php foreach ( $extensions as $extension ) : ?>
			<?php
			$detailsId          = 'ran-booster-extension-details-' . $extension['id'];
			$detailsTitleId     = $detailsId . '-title';
			$availabilityLabel  = 'Subscriber' === $extension['availability'] ? __( 'Sponsor', 'ran-booster' ) : $extension['availability'];
			$compatibilityLabel = $extension['compatible'] ? __( 'Compatible with your version of Booster', 'ran-booster' ) : __( 'Requires a different version of Booster', 'ran-booster' );
			?>
			<div id="<?php echo esc_attr( $detailsId ); ?>" class="hidden">
				<article class="ran-booster-extension-details" aria-labelledby="<?php echo esc_attr( $detailsTitleId ); ?>">
					<header class="ran-booster-extension-details__header">
						<img src="<?php echo esc_url( $extension['image_url'] ); ?>" alt="">
						<div>
							<h2 id="<?php echo esc_attr( $detailsTitleId ); ?>"><?php echo esc_html( $extension['name'] ); ?></h2>
							<p><?php echo esc_html( $extension['description'] ); ?></p>
						</div>
					</header>
					<div class="ran-booster-extension-details__tabs" aria-hidden="true">
						<span><?php esc_html_e( 'Details', 'ran-booster' ); ?></span>
					</div>
					<div class="ran-booster-extension-details__content">
						<div class="ran-booster-extension-details__main">
							<h3><?php esc_html_e( 'About this extension', 'ran-booster' ); ?></h3>
							<p><?php echo esc_html( $extension['details'] ); ?></p>
							<h3><?php esc_html_e( 'What it adds', 'ran-booster' ); ?></h3>
							<ul>
							<?php foreach ( $extension['features'] as $feature ) : ?>
								<li><?php echo esc_html( $feature ); ?></li>
							<?php endforeach; ?>
							</ul>
							<h3><?php esc_html_e( 'Before you install', 'ran-booster' ); ?></h3>
							<ul>
							<?php foreach ( $extension['requirements'] as $requirement ) : ?>
								<li><?php echo esc_html( $requirement ); ?></li>
							<?php endforeach; ?>
							</ul>
						</div>
						<aside class="ran-booster-extension-details__sidebar">
							<h3><?php esc_html_e( 'Extension details', 'ran-booster' ); ?></h3>
							<dl>
								<dt><?php esc_html_e( 'Availability', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( $availabilityLabel ); ?></dd>
								<dt><?php esc_html_e( 'Maturity', 'ran-booster' ); ?></dt>
								<dd><?php esc_html_e( 'Beta', 'ran-booster' ); ?></dd>
								<dt><?php esc_html_e( 'Status', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( $extension['state'] ); ?></dd>
								<dt><?php esc_html_e( 'Compatibility', 'ran-booster' ); ?></dt>
								<dd class="ran-booster-extension-details__compatibility<?php echo $extension['compatible'] ? '' : ' ran-booster-extension-details__compatibility--incompatible'; ?>">
									<span aria-hidden="true"><?php echo $extension['compatible'] ? '✓' : '×'; ?></span>
									<?php echo esc_html( $compatibilityLabel ); ?>
								</dd>
							</dl>
							<p><a href="<?php echo esc_url( $extension['docs_url'] ); ?>"><?php esc_html_e( 'Documentation', 'ran-booster' ); ?></a></p>
							<p><a href="<?php echo esc_url( $extension['support_url'] ); ?>"><?php esc_html_e( 'Support', 'ran-booster' ); ?></a></p>
						</aside>
					</div>
				</article>
			</div>
		<?php endforeach; ?>
		<p class="description ran-booster-extensions__note"><?php esc_html_e( 'Free downloads will be enabled after their public beta releases are ready. Sponsor packages are delivered manually during beta.', 'ran-booster' ); ?></p>
	</div>
</section>
