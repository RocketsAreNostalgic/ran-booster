<?php
/**
 * Optional-first RAN admin shell.
 *
 * Expects a local `$ran_admin_shell` array supplied by the consumer.
 *
 * @package RAN_Admin_Shell
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $ran_admin_shell ) || ! is_array( $ran_admin_shell ) ) {
	return;
}

$ran_admin_shell_name = isset( $ran_admin_shell['name'] ) && is_scalar( $ran_admin_shell['name'] )
	? trim( (string) $ran_admin_shell['name'] )
	: '';

if ( '' === $ran_admin_shell_name ) {
	return;
}

$ran_admin_shell_home_url   = isset( $ran_admin_shell['home_url'] ) && is_scalar( $ran_admin_shell['home_url'] ) ? trim( (string) $ran_admin_shell['home_url'] ) : '';
$ran_admin_shell_strapline  = isset( $ran_admin_shell['strapline'] ) && is_scalar( $ran_admin_shell['strapline'] ) ? trim( (string) $ran_admin_shell['strapline'] ) : '';
$ran_admin_shell_version    = isset( $ran_admin_shell['version'] ) && is_scalar( $ran_admin_shell['version'] ) ? trim( (string) $ran_admin_shell['version'] ) : '';
$ran_admin_shell_logo       = isset( $ran_admin_shell['logo'] ) && is_array( $ran_admin_shell['logo'] ) ? $ran_admin_shell['logo'] : array();
$ran_admin_shell_background = isset( $ran_admin_shell['background'] ) && is_array( $ran_admin_shell['background'] ) ? $ran_admin_shell['background'] : array();
$ran_admin_shell_navigation = isset( $ran_admin_shell['navigation'] ) && is_array( $ran_admin_shell['navigation'] ) ? $ran_admin_shell['navigation'] : array();
$ran_admin_shell_actions    = isset( $ran_admin_shell['actions'] ) && is_array( $ran_admin_shell['actions'] ) ? $ran_admin_shell['actions'] : array();

$ran_admin_shell_valid_image = static function ( $image ) {
	return is_array( $image )
		&& isset( $image['url'], $image['width'], $image['height'] )
		&& is_scalar( $image['url'] )
		&& '' !== trim( (string) $image['url'] )
		&& is_numeric( $image['width'] )
		&& is_numeric( $image['height'] )
		&& 0 < (int) $image['width']
		&& 0 < (int) $image['height'];
};

$ran_admin_shell_has_logo       = $ran_admin_shell_valid_image( $ran_admin_shell_logo );
$ran_admin_shell_has_background = $ran_admin_shell_valid_image( $ran_admin_shell_background );
$ran_admin_shell_navigation     = array_values(
	array_filter(
		$ran_admin_shell_navigation,
		static function ( $item ) {
			return is_array( $item )
				&& isset( $item['label'], $item['url'] )
				&& is_scalar( $item['label'] )
				&& is_scalar( $item['url'] )
				&& '' !== trim( (string) $item['label'] )
				&& '' !== trim( (string) $item['url'] );
		}
	)
);
$ran_admin_shell_actions        = array_values(
	array_filter(
		$ran_admin_shell_actions,
		static function ( $item ) {
			return is_array( $item )
				&& isset( $item['label'], $item['url'] )
				&& is_scalar( $item['label'] )
				&& is_scalar( $item['url'] )
				&& '' !== trim( (string) $item['label'] )
				&& '' !== trim( (string) $item['url'] );
		}
	)
);
?>
<header class="ran-admin-shell<?php echo '' === $ran_admin_shell_strapline ? ' ran-admin-shell--name-only' : ''; ?>">
	<?php if ( $ran_admin_shell_has_background ) : ?>
		<img class="ran-admin-shell__background" src="<?php echo esc_url( (string) $ran_admin_shell_background['url'] ); ?>" width="<?php echo esc_attr( (string) (int) $ran_admin_shell_background['width'] ); ?>" height="<?php echo esc_attr( (string) (int) $ran_admin_shell_background['height'] ); ?>" alt="" aria-hidden="true" />
	<?php endif; ?>
	<div class="ran-admin-shell__inner">
		<div class="ran-admin-shell__identity">
			<?php if ( $ran_admin_shell_has_logo ) : ?>
				<img class="ran-admin-shell__logo" src="<?php echo esc_url( (string) $ran_admin_shell_logo['url'] ); ?>" width="<?php echo esc_attr( (string) (int) $ran_admin_shell_logo['width'] ); ?>" height="<?php echo esc_attr( (string) (int) $ran_admin_shell_logo['height'] ); ?>" alt="" aria-hidden="true" />
			<?php endif; ?>
			<div class="ran-admin-shell__copy">
				<h1 class="ran-admin-shell__title">
					<?php if ( '' !== $ran_admin_shell_home_url ) : ?>
						<a href="<?php echo esc_url( $ran_admin_shell_home_url ); ?>"><?php echo esc_html( $ran_admin_shell_name ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $ran_admin_shell_name ); ?>
					<?php endif; ?>
				</h1>
				<?php if ( '' !== $ran_admin_shell_strapline ) : ?>
					<p class="ran-admin-shell__strapline"><?php echo esc_html( $ran_admin_shell_strapline ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $ran_admin_shell_version ) : ?>
				<span class="ran-admin-shell__version"><?php echo esc_html( $ran_admin_shell_version ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( $ran_admin_shell_actions ) : ?>
			<div class="ran-admin-shell__actions">
				<?php foreach ( $ran_admin_shell_actions as $ran_admin_shell_action ) : ?>
					<a class="button" href="<?php echo esc_url( (string) $ran_admin_shell_action['url'] ); ?>"><?php echo esc_html( trim( (string) $ran_admin_shell_action['label'] ) ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php if ( $ran_admin_shell_navigation ) : ?>
		<nav class="ran-admin-shell__navigation" aria-label="<?php echo esc_attr( isset( $ran_admin_shell['navigation_label'] ) && is_scalar( $ran_admin_shell['navigation_label'] ) ? trim( (string) $ran_admin_shell['navigation_label'] ) : 'Plugin sections' ); ?>">
			<?php $ran_admin_shell_current_rendered = false; ?>
			<?php foreach ( $ran_admin_shell_navigation as $ran_admin_shell_item ) : ?>
				<?php $ran_admin_shell_is_current = ! $ran_admin_shell_current_rendered && ! empty( $ran_admin_shell_item['current'] ); ?>
				<a href="<?php echo esc_url( (string) $ran_admin_shell_item['url'] ); ?>"<?php echo $ran_admin_shell_is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( trim( (string) $ran_admin_shell_item['label'] ) ); ?></a>
				<?php $ran_admin_shell_current_rendered = $ran_admin_shell_current_rendered || $ran_admin_shell_is_current; ?>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>
</header>
