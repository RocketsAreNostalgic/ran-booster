<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/ReleaseManagement/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/ReleaseManagement/GitHub/Support/GitHubReleaseWorkflowWordPressFunctions.php';

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryDetailRenderer;
use RAN\Admin\Component\RepositoryTableRenderer;
use RAN\Admin\Component\StatusBadge;
use RAN\Admin\PackagePagePresenter;
use RAN\Admin\ProviderRepositoryRowsNormalizer;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowDisplay;
use ReflectionMethod;

/**
 * StatusBadge::INTENTS is the one list of status tones. A tone any producer
 * emits, any validator accepts or any renderer draws derives from it, so a tone
 * accepted in one place cannot be rejected, fatally, in another.
 */
final class StatusToneVocabularyTest extends TestCase {

	/** @return array<string,array{string}> */
	public static function tones(): array {
		$cases = array();
		foreach ( array_keys( StatusBadge::INTENTS ) as $tone ) {
			$cases[ $tone ] = array( $tone );
		}

		return $cases;
	}

	#[DataProvider( 'tones' )]
	public function test_every_tone_maps_to_an_intent_the_badge_stylesheet_supports( string $tone ): void {
		self::assertContains( StatusBadge::INTENTS[ $tone ], StatusBadge::SUPPORTED_INTENTS );
		self::assertSame( StatusBadge::INTENTS[ $tone ], StatusBadge::intent_for( $tone ) );
	}

	#[DataProvider( 'tones' )]
	public function test_the_repository_normaliser_accepts_every_tone_in_details_and_badges( string $tone ): void {
		$normalizer = new ProviderRepositoryRowsNormalizer();
		$details    = new ReflectionMethod( $normalizer, 'assert_details' );
		$badges     = new ReflectionMethod( $normalizer, 'badges' );

		$details->invoke(
			$normalizer,
			array(
				array(
					'key'   => 'tone',
					'label' => 'Tone',
					'value' => 'Value',
					'tone'  => $tone,
				),
			)
		);
		$result = $badges->invoke(
			$normalizer,
			array(
				array(
					'label' => 'Badge',
					'tone'  => $tone,
				),
			)
		);
		self::assertIsArray( $result );
		self::assertSame( $tone, $result[0]['tone'] );
	}

	public function test_the_repository_normaliser_still_rejects_a_tone_outside_the_vocabulary(): void {
		$normalizer = new ProviderRepositoryRowsNormalizer();
		$this->expectException( LogicException::class );
		( new ReflectionMethod( $normalizer, 'badges' ) )->invoke(
			$normalizer,
			array(
				array(
					'label' => 'Badge',
					'tone'  => 'future',
				),
			)
		);
	}

	#[DataProvider( 'tones' )]
	public function test_the_package_page_presenter_accepts_every_tone( string $tone ): void {
		$presenter = PackagePagePresenter::plugin();
		$rows      = ( new ReflectionMethod( $presenter, 'normalize_extension_rows' ) )->invoke(
			$presenter,
			array(
				'p' => array(
					'badges' => array(
						array(
							'label' => 'Badge',
							'tone'  => $tone,
						),
					),
				),
			),
			array( 'p' => true ),
			array( 'p' => true )
		);
		self::assertIsArray( $rows );
		self::assertSame( $tone, $rows['p']['badges'][0]['tone'] );
	}

	#[DataProvider( 'tones' )]
	public function test_every_renderer_draws_the_intent_the_vocabulary_names( string $tone ): void {
		$expected = StatusBadge::detail_intent( $tone );
		$marker   = 'data-ran-booster-badge-intent="' . $expected . '"';

		$detail = new RepositoryDetailRenderer();
		ob_start();
		( new ReflectionMethod( $detail, 'render_detail_value' ) )->invoke(
			$detail,
			array(
				'value' => 'Value',
				'tone'  => $tone,
			)
		);
		self::assertStringContainsString( $marker, (string) ob_get_clean() );

		ob_start();
		( new RepositoryTableRenderer() )->render(
			'heading',
			array(
				array(
					'repository' => 'owner/repository',
					'historical' => true,
					'details'    => array(
						array(
							'label' => 'Fact',
							'value' => 'Value',
							'tone'  => $tone,
						),
					),
				),
			)
		);
		self::assertStringContainsString( $marker, (string) ob_get_clean() );

		self::assertStringContainsString(
			$marker,
			( new ReleaseWorkflowDisplay() )->repository_section(
				array(
					'show_automation' => true,
					'automation'      => array(
						'tone'  => $tone,
						'label' => 'Value',
					),
				)
			)
		);
		self::assertStringContainsString( 'data-ran-booster-badge-intent="' . StatusBadge::intent_for( $tone ) . '"', StatusBadge::markup( 'Value', $tone ) );
	}

	public function test_only_a_pending_detail_differs_from_the_standalone_badge(): void {
		foreach ( array_keys( StatusBadge::INTENTS ) as $tone ) {
			if ( 'pending' === $tone ) {
				self::assertSame( 'none', StatusBadge::detail_intent( $tone ) );
				self::assertSame( 'informational', StatusBadge::intent_for( $tone ) );
				continue;
			}
			self::assertSame( StatusBadge::intent_for( $tone ), StatusBadge::detail_intent( $tone ), 'The tone "' . $tone . '" must not have a detail-specific intent.' );
		}
		self::assertSame( 'none', StatusBadge::intent_for( 'future' ) );
		self::assertSame( 'none', StatusBadge::detail_intent( 'future' ) );
	}

	public function test_the_script_badge_intent_list_matches_the_stylesheet_intents(): void {
		$script = $this->read( 'assets/ran-booster-cards.js' );
		if ( 1 !== preg_match( '/const badgeIntents = \[([^\]]*)\]/', $script, $match ) ) {
			self::fail( 'The script badge intent list was not found.' );
		}
		self::assertSame( StatusBadge::SUPPORTED_INTENTS, array_map( static fn ( string $item ): string => trim( $item, " '\"" ), explode( ',', $match[1] ) ) );
	}

	public function test_no_presenter_or_view_carries_the_retired_badge_class_vocabulary(): void {
		$files = array_merge(
			$this->files( '/RAN/Admin/*.php' ),
			$this->files( '/RAN/Admin/*/*.php' ),
			$this->files( '/views/*.php' ),
			$this->files( '/views/*/*.php' )
		);
		self::assertNotEmpty( $files );
		foreach ( $files as $file ) {
			self::assertStringNotContainsString( 'ran-booster-badge--', $this->read( substr( $file, strlen( dirname( __DIR__, 2 ) ) + 1 ) ), $file );
		}
	}

	/** @return list<string> */
	private function files( string $pattern ): array {
		$found = glob( dirname( __DIR__, 2 ) . $pattern );

		return false === $found ? array() : $found;
	}

	private function read( string $path ): string {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		if ( false === $source ) {
			self::fail( 'The source could not be read: ' . $path );
		}

		return $source;
	}
}
