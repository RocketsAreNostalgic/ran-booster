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

	public function test_normaliser_inserts_the_arrow_before_an_existing_hint_without_duplicating_it(): void {
		$html = '<a href="https://e.test" target="_blank" rel="noopener noreferrer">Docs<span class="screen-reader-text"> (opens in a new tab)</span></a>';

		self::assertSame(
			'<a href="https://e.test" target="_blank" rel="noopener noreferrer">Docs <span aria-hidden="true">↗</span><span class="screen-reader-text"> (opens in a new tab)</span></a>',
			ExternalLinkMarker::normalize_html( $html )
		);
	}

	public function test_normaliser_appends_the_full_marker_when_a_new_tab_link_has_neither(): void {
		self::assertSame(
			'<a href="https://e.test" target=\'_blank\'>Docs ' . ExternalLinkMarker::markup() . '</a>',
			ExternalLinkMarker::normalize_html( '<a href="https://e.test" target=\'_blank\'>Docs</a>' )
		);
	}

	public function test_normaliser_is_idempotent_and_leaves_marked_links_alone(): void {
		$marked = '<a href="https://e.test" target="_blank">Docs ' . ExternalLinkMarker::markup() . '</a>';
		$once   = ExternalLinkMarker::normalize_html( '<a href="https://e.test" target="_blank">Docs</a>' );

		self::assertSame( $marked, ExternalLinkMarker::normalize_html( $marked ) );
		self::assertSame( $once, ExternalLinkMarker::normalize_html( $once ) );
		$hint_only = ExternalLinkMarker::normalize_html( '<a target="_blank" href="x">Docs<span class="screen-reader-text"> (opens in a new tab)</span></a>' );
		self::assertSame( $hint_only, ExternalLinkMarker::normalize_html( $hint_only ) );
	}

	public function test_normaliser_leaves_same_tab_and_button_anchors_untouched(): void {
		$html = '<p><a href="#x">Same tab</a> <a class="ran-wp-ui-button button" href="https://e.test" target="_blank">Open</a> <a href="https://e.test" data-target="_blank">Data</a></p>';

		self::assertSame( $html, ExternalLinkMarker::normalize_html( $html ) );
	}

	public function test_normaliser_marks_each_of_several_anchors(): void {
		$html = ExternalLinkMarker::normalize_html( '<a target="_blank" href="a">A</a> and <a href="b" target="_blank">B<span class="screen-reader-text"> (opens in a new tab)</span></a>' );

		self::assertSame( 2, substr_count( $html, '↗' ) );
		self::assertSame( 2, substr_count( $html, '(opens in a new tab)' ) );
	}

	public function test_normaliser_keeps_malformed_and_nested_input_intact(): void {
		self::assertSame( '<p>No anchors &amp; <b>bold</p>', ExternalLinkMarker::normalize_html( '<p>No anchors &amp; <b>bold</p>' ) );
		self::assertSame( '<a href="x" target="_blank">Unclosed', ExternalLinkMarker::normalize_html( '<a href="x" target="_blank">Unclosed' ) );
		self::assertSame( '', ExternalLinkMarker::normalize_html( '' ) );

		$nested = ExternalLinkMarker::normalize_html( '<a href="o" target="_blank">Outer <a href="i" target="_blank">Inner</a>' );
		self::assertSame( 1, substr_count( $nested, '↗' ) );
		self::assertStringStartsWith( '<a href="o" target="_blank">Outer <a href="i" target="_blank">Inner ', $nested );
	}

	public function test_every_new_tab_text_link_in_admin_sources_ends_with_exactly_one_marker(): void {
		$root  = dirname( __DIR__, 2 );
		$files = array_merge(
			self::files( $root . '/views/*.php' ),
			self::files( $root . '/views/*/*.php' ),
			self::files( $root . '/views/*/*/*.php' ),
			self::files( $root . '/RAN/Admin/*.php' ),
			self::files( $root . '/RAN/Admin/*/*.php' ),
			self::files( $root . '/RAN/Admin/*/views/*.php' )
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

	/** @return list<string> */
	private static function files( string $pattern ): array {
		$found = glob( $pattern );

		return false === $found ? array() : $found;
	}
}
