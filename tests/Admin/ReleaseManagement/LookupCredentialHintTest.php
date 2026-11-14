<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';
require_once __DIR__ . '/../CredentialExpiryWordPressFunctions.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RAN\Admin\AdminTabRegistry;
use RAN\Admin\GitHubVisibility;
use RAN\Admin\ProviderTabLinks;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use RAN\Tests\Admin\ReleaseManagement\Support\ReleaseTrackingFacadeDouble;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;

/** The anonymous rate-limit hint reaches every release lookup surface once, with one wording and a safe link. */
final class LookupCredentialHintTest extends TestCase {
	private const TEXT = 'A dedicated lookup credential helps avoid rate limits.';
	private const LINK = 'Choose a lookup credential';
	private const CARD = 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=gh#ran-booster-public-lookup-profile-region';

	#[Before]
	public function reset_word_press(): void {
		ReleaseManagementFixture::reset_word_press();
	}

	private function links( string $registered = 'gh' ): ProviderTabLinks {
		return new ProviderTabLinks(
			static fn (): AdminTabRegistry => new AdminTabRegistry( new ProviderRegistry( array( new ExternalFixtureProvider( $registered ) ) ) ),
			static fn (): ?GitHubVisibility => null
		);
	}

	public function test_the_shared_hint_links_to_the_current_providers_lookup_card(): void {
		self::assertSame(
			array(
				'text' => self::TEXT,
				'link' => self::LINK,
				'url'  => self::CARD,
			),
			ProviderTabLinks::lookup_hint( $this->links(), 'https://example.test/wp-admin/admin.php', 'gh' )
		);
	}

	public function test_the_hint_link_falls_back_to_overview_without_the_card_anchor_when_the_provider_tab_is_unavailable(): void {
		$hint = ProviderTabLinks::lookup_hint( $this->links( 'bb' ), 'https://example.test/wp-admin/admin.php', 'gh' );

		self::assertSame( 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview', $hint['url'] );
		self::assertSame( self::TEXT, $hint['text'] );
	}

	public function test_the_release_track_card_listing_carries_the_hint_for_an_anonymous_rate_limit_only(): void {
		$tracking                                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->anonymous_rate_limit_provider = 'gh';
		$controls                                = ReleaseManagementFixture::controls( $tracking, links: $this->links() );

		$outcome = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request() );

		self::assertSame( 'unable_to_check', $outcome['code'] );
		self::assertFalse( $outcome['successful'] );
		self::assertSame(
			array(
				'lookup_hint' => array(
					'text' => self::TEXT,
					'link' => self::LINK,
					'url'  => self::CARD,
				),
			),
			$outcome['data']
		);

		// An unrelated outage, or a lookup or package credential already in use, reports no provider.
		$tracking->anonymous_rate_limit_provider = null;
		$outcome                                 = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request() );

		self::assertSame( 'unable_to_check', $outcome['code'] );
		self::assertSame( array(), $outcome['data'] );
	}

	public function test_the_release_track_card_hint_follows_a_hidden_provider_tab_to_overview(): void {
		$tracking                                = new ReleaseTrackingFacadeDouble( ReleaseManagementFixture::status( 'release_asset' ) );
		$tracking->anonymous_rate_limit_provider = 'gh';
		$controls                                = ReleaseManagementFixture::controls( $tracking, links: $this->links( 'bb' ) );

		$outcome = $controls->process_managed_browser_request( 'list_candidates', $this->managed_request() );

		self::assertSame( 'https://example.test/wp-admin/admin.php?page=ran-booster&tab=overview', $outcome['data']['lookup_hint']['url'] );
	}

	public function test_the_add_releases_listing_carries_the_hint_for_an_anonymous_rate_limit_only(): void {
		$result   = ProspectiveReleaseResult::failure( 'unable_to_check', array( 'rate_limited_provider' => 'gh' ) );
		$controls = ReleaseManagementFixture::controls(
			read_candidates: static function () use ( &$result ): ProspectiveReleaseResult {
				return $result;
			},
			links: $this->links()
		);

		$outcome = $controls->process_prospective_request( 'list_candidates', $this->prospective_request() );

		self::assertSame( 'unable_to_check', $outcome['code'] );
		self::assertSame( self::CARD, $outcome['data']['lookup_hint']['url'] );
		self::assertSame( self::TEXT, $outcome['data']['lookup_hint']['text'] );
		self::assertArrayNotHasKey( 'rate_limited_provider', $outcome['data'] );

		// No marker: an unrelated outage or a credential already in use.
		$result  = ProspectiveReleaseResult::failure( 'unable_to_check' );
		$outcome = $controls->process_prospective_request( 'list_candidates', $this->prospective_request() );
		self::assertSame( 'unable_to_check', $outcome['code'] );
		self::assertSame( array(), $outcome['data'] );

		// A marker is honoured only for an unable-to-check failure and a well-formed provider code.
		foreach (
			array(
				ProspectiveReleaseResult::failure( 'no_releases', array( 'rate_limited_provider' => 'gh' ) ),
				ProspectiveReleaseResult::failure( 'unable_to_check', array( 'rate_limited_provider' => 'GH <script>' ) ),
				ProspectiveReleaseResult::failure( 'unable_to_check', array( 'rate_limited_provider' => 7 ) ),
			) as $result
		) {
			$outcome = $controls->process_prospective_request( 'list_candidates', $this->prospective_request() );
			self::assertSame( array(), $outcome['data'] );
		}
	}

	/** @return array<string,string> */
	private function managed_request(): array {
		return array(
			'expected_type'            => 'plugin',
			'expected_identifier'      => 'example/example.php',
			'expected_source_revision' => '3',
			'release_channel'          => 'stable',
			'_wpnonce'                 => 'nonce-for-release-tracking-list_candidates-plugin-example/example.php-3-stable',
		);
	}

	/** @return array<string,mixed> */
	private function prospective_request(): array {
		return array(
			'expected_type'   => 'plugin',
			'_wpnonce'        => 'nonce-for-prospective-release-list_candidates-plugin',
			'release_channel' => 'stable',
			'ran_booster'     => array(
				'provider'   => 'gh',
				'repository' => 'workspace/example',
			),
		);
	}
}
