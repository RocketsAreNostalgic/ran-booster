<?php

declare( strict_types = 1 );

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
