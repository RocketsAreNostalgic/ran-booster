<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\ExternalLinkMarker;
use RAN\Admin\Component\RepositoryTableRenderer;

final class ExternalLinkMarkerTest extends TestCase {
	public function test_marker_is_a_decorative_arrow_then_the_new_tab_hint(): void {
		self::assertSame( '<span aria-hidden="true">↗</span><span class="screen-reader-text"> (opens in a new tab)</span>', ExternalLinkMarker::markup() );
	}

	public function test_every_new_tab_text_link_in_admin_sources_ends_with_exactly_one_marker(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			(array) glob( $root . '/views/*.php' ),
			(array) glob( $root . '/views/*/*.php' ),
			(array) glob( $root . '/views/*/*/*.php' ),
			(array) glob( $root . '/RAN/Admin/*.php' ),
			(array) glob( $root . '/RAN/Admin/*/*.php' ),
			(array) glob( $root . '/RAN/Admin/*/views/*.php' )
		);
		self::assertNotEmpty( $files );
		$checked = 0;
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
			if ( 1 > preg_match_all( '/<a\b(?:(?!<\/a>).)*?target="_blank".*?<\/a>/s', $source, $matches ) ) {
				continue;
			}
			foreach ( $matches[0] as $anchor ) {
				// Anchors drawn as Buttons are commands, not text links.
				if ( str_contains( $anchor, 'ButtonClasses::' ) ) {
					continue;
				}
				++$checked;
				self::assertSame(
					1,
					substr_count( $anchor, 'ExternalLinkMarker::' ),
					basename( $file ) . ' has a new-tab text link without exactly one shared marker: ' . substr( $anchor, 0, 120 )
				);
			}
		}
		self::assertGreaterThan( 10, $checked );
	}

	public function test_repository_name_link_uses_the_shared_marker_once(): void {
		ob_start();
		( new RepositoryTableRenderer() )->render(
			'managed-repositories-heading',
			array(
				array(
					'repository'     => 'owner/repository',
					'repository_url' => 'https://github.com/owner/repository',
					'details'        => array(),
				),
			)
		);
		$html = (string) ob_get_clean();

		if ( 1 !== preg_match( '/<a class="ran-booster-repository-record__name"[^>]*>(.*?)<\/a>/s', $html, $m ) ) {
			self::fail( 'The repository name link is missing.' );
		}
		self::assertSame( 1, substr_count( $m[1], '↗' ) );
		self::assertSame( 1, substr_count( $m[1], '(opens in a new tab)' ) );
	}
}
