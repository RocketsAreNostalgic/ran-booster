<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var list<array{heading:?string,body:string}> $sections */
foreach ( $sections as $section ) :
	if ( null !== $section['heading'] ) :
		?>
		<h3><?php echo esc_html( $section['heading'] ); ?></h3>
		<?php
	endif;
	?>
	<p><?php echo esc_html( $section['body'] ); ?></p>
	<?php
endforeach;
