<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\NonceField;

final class NonceFieldTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_it_prints_the_nonce_and_referer_fields_without_an_id(): void {
		require_once __DIR__ . '/NonceFieldWordPressFunctions.php';

		ob_start();
		NonceField::print( 'ran-booster-save-secrets' );
		NonceField::print( 'ran-booster-save-secrets' );
		$html = (string) ob_get_clean();

		self::assertSame( 0, substr_count( $html, 'id=' ) );
		self::assertSame( 2, substr_count( $html, '<input type="hidden" name="_wpnonce" value="nonce-for-ran-booster-save-secrets" />' ) );
		self::assertSame( 2, substr_count( $html, '<input type="hidden" name="_wp_http_referer" value="/wp-admin/admin.php" />' ) );
	}

	public function test_views_print_nonces_through_the_id_free_helper(): void {
		$root = dirname( __DIR__, 2 ) . '/views';
		$iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$bare = array();
		foreach ( $iter as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads repository source in a contract test.
			if ( 1 === preg_match( '/(?<![\w\\\\:>])wp_nonce_field\(\s*[^,)]*(?:\([^)]*\))?[^,)]*\)/', $source ) ) {
				$bare[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		// Only the Transporter export field names its input "nonce"; every other nonce uses the helper, whose default name repeated its id.
		self::assertSame( array(), $bare );
	}
}
