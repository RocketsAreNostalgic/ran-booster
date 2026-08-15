<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderRepositoryCompositionRenderer;

final class ProviderRepositoryCompositionRendererTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_view_filters'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_view_filters'] );
	}

	public function testHistoricalRowsCanRenderWithoutAnyCurrentCoreRepository(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_rows'][] =
			static function ( array $rows, string $providerCode, array $projections ): array {
				self::assertSame( array(), $rows );
				self::assertSame( 'gh', $providerCode );
				self::assertSame( array(), $projections );

				$rows['fixture:historical:abc123'] = array(
					'provider_code'  => 'gh',
					'provider_label' => 'GitHub',
					'repository_id'  => 'old-42',
					'repository'     => 'owner/historical',
					'historical'     => true,
					'statuses'       => array(
						array(
							'label' => 'No longer managed',
							'tone'  => 'warning',
						),
					),
					'actions'        => array(),
					'details'        => array(),
				);

				return $rows;
			};

		$rows = ( new ProviderRepositoryCompositionRenderer() )->rows(
			array(),
			'gh',
			array(),
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh'
		);

		self::assertArrayHasKey( 'fixture:historical:abc123', $rows );
		self::assertTrue( $rows['fixture:historical:abc123']['historical'] );
	}

	public function testAssistanceAvailabilityIsIndependentOfRepositoryEligibility(): void {
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_assistance_active'][] =
			static fn ( bool $active, string $providerCode ): bool => $active || 'gh' === $providerCode;

		$renderer = new ProviderRepositoryCompositionRenderer();

		self::assertTrue( $renderer->assistanceActive( 'gh' ) );
		self::assertFalse( $renderer->assistanceActive( 'bb' ) );
	}

	public function testProviderPresentationBuildsTheDormantPromotionalAction(): void {
		$renderer     = new ProviderRepositoryCompositionRenderer();
		$presentation = $renderer->assistancePresentation( $this->assistancePresentation() );
		self::assertNotNull( $presentation );

		$actions = $renderer->dormantAssistanceAction(
			$presentation,
			'owner/repository',
			array( 'promotion-description', 'readiness-reason' )
		);

		self::assertSame(
			array(
				'key'           => 'core:webhook-management',
				'label'         => 'Assisted Hooks',
				'type'          => 'link',
				'url'           => '',
				'hidden'        => array(),
				'disabled'      => true,
				'external'      => false,
				'described_by'  => 'promotion-description readiness-reason',
				'screen_reader' => 'owner/repository',
			),
			$actions['core:webhook-management']
		);
	}

	public function testAssistanceNoteChangesCopyOnlyWhenTheAddOnReportsActive(): void {
		$renderer     = new ProviderRepositoryCompositionRenderer();
		$presentation = $renderer->assistancePresentation( $this->assistancePresentation() );
		self::assertNotNull( $presentation );

		ob_start();
		$renderer->renderAssistanceNote( $presentation, 'gh', 'promotion-description' );
		$inactive = (string) ob_get_clean();

		self::assertStringContainsString( 'Assisted Hooks add-on not active.', $inactive );
		self::assertStringNotContainsString( 'Assisted Hooks is active.', $inactive );

		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_assistance_active'][] =
			static fn ( bool $active ): bool => true;

		ob_start();
		$renderer->renderAssistanceNote( $presentation, 'gh', 'promotion-description' );
		$active = (string) ob_get_clean();

		self::assertStringContainsString( 'Assisted Hooks is active.', $active );
		self::assertStringNotContainsString( 'Assisted Hooks add-on not active.', $active );
	}

	public function testInvalidOrUnreservedAssistancePresentationFailsClosed(): void {
		$renderer = new ProviderRepositoryCompositionRenderer();

		self::assertNull( $renderer->assistancePresentation( null ) );
		self::assertNull( $renderer->assistancePresentation( array( 'action_key' => 'core:other' ) ) );
		self::assertNull(
			$renderer->assistancePresentation(
				array_replace( $this->assistancePresentation(), array( 'action_key' => 'addon:other' ) )
			)
		);
	}

	public function testAnUnprojectedRowCannotHaveItsAssistanceActionActivated(): void {
		$baseRows = array(
			'release-row' => array(
				'key'        => 'release-row',
				'source_key' => 'release_asset',
				'actions'    => array(
					'core:webhook-management' => array(
						'key'           => 'core:webhook-management',
						'label'         => 'Assisted Hooks',
						'type'          => 'link',
						'url'           => '',
						'hidden'        => array(),
						'disabled'      => true,
						'external'      => false,
						'described_by'  => 'release-source-reason',
						'screen_reader' => 'owner/release',
					),
				),
			),
		);
		$GLOBALS['ran_booster_admin_view_filters']['ran_booster_admin_provider_repository_rows'][] =
			static function ( array $rows ): array {
				$rows['release-row']['actions']['core:webhook-management']['url']          = 'https://example.test/unsafe-assistance';
				$rows['release-row']['actions']['core:webhook-management']['disabled']     = false;
				$rows['release-row']['actions']['core:webhook-management']['described_by'] = '';
				$rows['release-row']['details'][] = array(
					'label' => 'Recorded hook profile',
					'value' => 'Previous profile',
				);

				return $rows;
			};

		$rows = ( new ProviderRepositoryCompositionRenderer() )->rows(
			$baseRows,
			'gh',
			array(),
			'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh'
		);

		self::assertTrue( $rows['release-row']['actions']['core:webhook-management']['disabled'] );
		self::assertSame( '', $rows['release-row']['actions']['core:webhook-management']['url'] );
		self::assertSame( 'release-source-reason', $rows['release-row']['actions']['core:webhook-management']['described_by'] );
		self::assertSame( 'Previous profile', $rows['release-row']['details'][0]['value'] );
	}

	/** @return array<string, string> */
	private function assistancePresentation(): array {
		return array(
			'action_key'           => 'core:webhook-management',
			'action_label'         => 'Assisted Hooks',
			'inactive_heading'     => 'Assisted Hooks add-on not active.',
			'inactive_description' => 'Activate the compatible add-on.',
			'active_heading'       => 'Assisted Hooks is active.',
			'active_description'   => 'Repository actions are available.',
		);
	}
}
