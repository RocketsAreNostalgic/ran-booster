<?php

/** @var list<array<string, mixed>> $extensions */
/** @var string $plugins_url */

defined( 'ABSPATH' ) || exit;

$extension_state_labels = array(
	'Not installed'       => __( 'Not installed', 'ran-booster' ),
	'Incompatible'        => __( 'Incompatible', 'ran-booster' ),
	'Active'              => __( 'Active', 'ran-booster' ),
	'Installed, inactive' => __( 'Installed, inactive', 'ran-booster' ),
);
?>
<section class="ran-booster-page-shell ran-booster-extensions plugin-install-php" aria-labelledby="ran-booster-extensions-heading">
	<header class="ran-booster-page-shell__header ran-booster-extensions__header">
		<p class="ran-booster-eyebrow"><?php esc_html_e( 'Add-ons', 'ran-booster' ); ?></p>
		<h2 id="ran-booster-extensions-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Extensions', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Add focused capabilities to Booster.', 'ran-booster' ); ?></p>
	</header>
	<div class="ran-booster-page-shell__body ran-booster-extensions__body">
		<div id="the-list" class="plugin-group ran-booster-extensions__grid">
		<?php foreach ( $extensions as $extension ) : ?>
			<?php
			$details_id          = 'ran-booster-extension-details-' . $extension['id'];
			$details_url         = '#TB_inline?width=772&height=600&inlineId=' . $details_id;
			$availability_label  = $extension['availability'];
			$state_label         = $extension_state_labels[ $extension['state'] ] ?? $extension['state'];
			$compatibility_label = $extension['compatible'] ? __( 'Compatible with your version of Booster', 'ran-booster' ) : __( 'Requires a different version of Booster', 'ran-booster' );
			$more_details_label   = sprintf(
				/* translators: %s: Extension name. */
				__( 'More details about %s', 'ran-booster' ),
				$extension['name']
			);
			?>
			<div class="plugin-card plugin-card-<?php echo esc_attr( $extension['id'] ); ?> ran-booster-extension-card" data-extension="<?php echo esc_attr( $extension['id'] ); ?>">
				<div class="plugin-card-top">
					<div class="name column-name">
						<h3>
							<a class="thickbox ran-booster-extension-details-link" href="<?php echo esc_url( $details_url ); ?>" data-title="<?php echo esc_attr( $extension['name'] ); ?>" aria-label="<?php echo esc_attr( $more_details_label ); ?>">
								<?php echo esc_html( $extension['name'] ); ?>
								<img class="plugin-icon" src="<?php echo esc_url( $extension['image_url'] ); ?>" alt="">
							</a>
						</h3>
					</div>
					<div class="action-links">
						<ul class="plugin-action-buttons">
								<li>
								<?php if ( 'Active' === $extension['state'] ) : ?>
									<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Active', 'ran-booster' ); ?></button>
								<?php elseif ( 'Not installed' !== $extension['state'] ) : ?>
									<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Inactive', 'ran-booster' ); ?></button>
								<?php else : ?>
									<button type="button" class="button button-disabled" disabled aria-disabled="true"><?php esc_html_e( 'Install', 'ran-booster' ); ?></button>
								<?php endif; ?>
								</li>
								<li>
									<a class="thickbox ran-booster-extension-details-link" href="<?php echo esc_url( $details_url ); ?>" data-title="<?php echo esc_attr( $extension['name'] ); ?>" aria-label="<?php echo esc_attr( $more_details_label ); ?>"><?php esc_html_e( 'More Details', 'ran-booster' ); ?></a>
								<?php if ( 'Active' !== $extension['state'] && 'Not installed' !== $extension['state'] ) : ?>
									<br><a href="<?php echo esc_url( $plugins_url ); ?>"><?php esc_html_e( 'Open Plugins', 'ran-booster' ); ?></a>
								<?php endif; ?>
								</li>
						</ul>
					</div>
					<div class="desc column-description">
						<p><?php echo esc_html( $extension['description'] ); ?></p>
						<p class="authors">
							<cite>
								<?php esc_html_e( 'By', 'ran-booster' ); ?>
								<a href="https://github.com/RocketsAreNostalgic">Rockets Are Nostalgic</a>
								<span aria-hidden="true"> · </span>
								<a href="<?php echo esc_url( $extension['support_url'] ); ?>"><?php esc_html_e( 'Support', 'ran-booster' ); ?></a>
							</cite>
						</p>
					</div>
				</div>
				<div class="plugin-card-bottom">
					<div class="vers column-rating ran-booster-extension-card__metadata">
						<span class="ran-booster-extension-card__badge"><?php echo esc_html( $availability_label ); ?></span>
						<span class="ran-booster-extension-card__badge"><?php esc_html_e( 'Beta', 'ran-booster' ); ?></span>
						<span class="ran-booster-badge ran-booster-badge--<?php echo esc_attr( $extension['state_kind'] ); ?>"><?php echo esc_html( $state_label ); ?></span>
					</div>
					<div class="column-compatibility">
					<?php if ( $extension['compatible'] ) : ?>
						<span class="compatibility-compatible"><strong><?php esc_html_e( 'Compatible', 'ran-booster' ); ?></strong> <?php esc_html_e( 'with your version of Booster', 'ran-booster' ); ?></span>
					<?php else : ?>
						<span class="compatibility-incompatible"><?php echo esc_html( $compatibility_label ); ?></span>
					<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php foreach ( $extensions as $extension ) : ?>
			<?php
			$details_id          = 'ran-booster-extension-details-' . $extension['id'];
			$details_title_id     = $details_id . '-title';
			$availability_label  = $extension['availability'];
			$state_label         = $extension_state_labels[ $extension['state'] ] ?? $extension['state'];
			$compatibility_label = $extension['compatible'] ? __( 'Compatible with your version of Booster', 'ran-booster' ) : __( 'Requires a different version of Booster', 'ran-booster' );
			?>
			<div id="<?php echo esc_attr( $details_id ); ?>" class="hidden">
				<article class="ran-booster-extension-details" aria-labelledby="<?php echo esc_attr( $details_title_id ); ?>">
					<header class="ran-booster-extension-details__header">
						<img src="<?php echo esc_url( $extension['image_url'] ); ?>" alt="">
						<div>
							<h2 id="<?php echo esc_attr( $details_title_id ); ?>"><?php echo esc_html( $extension['name'] ); ?></h2>
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
								<dd><?php echo esc_html( $availability_label ); ?></dd>
								<dt><?php esc_html_e( 'Maturity', 'ran-booster' ); ?></dt>
								<dd><?php esc_html_e( 'Beta', 'ran-booster' ); ?></dd>
								<dt><?php esc_html_e( 'Status', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( $state_label ); ?></dd>
								<dt><?php esc_html_e( 'Compatibility', 'ran-booster' ); ?></dt>
								<dd class="ran-booster-extension-details__compatibility<?php echo $extension['compatible'] ? '' : ' ran-booster-extension-details__compatibility--incompatible'; ?>">
									<span aria-hidden="true"><?php echo $extension['compatible'] ? '✓' : '×'; ?></span>
									<?php echo esc_html( $compatibility_label ); ?>
								</dd>
							</dl>
							<p><a href="<?php echo esc_url( $extension['docs_url'] ); ?>"><?php esc_html_e( 'Documentation', 'ran-booster' ); ?></a></p>
							<p><a href="<?php echo esc_url( $extension['support_url'] ); ?>"><?php esc_html_e( 'Support', 'ran-booster' ); ?></a></p>
						</aside>
					</div>
				</article>
			</div>
		<?php endforeach; ?>
	</div>
</section>
