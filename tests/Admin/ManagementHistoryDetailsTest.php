<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/WebhookManagement/Display/WebhookDisplayModelWordPressFunctions.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\RepositoryDetailRenderer;
use RAN\Admin\Component\RepositoryTableRenderer;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use ReflectionClass;
use ReflectionMethod;

/**
 * The management history Card and the repository list share one detail projection (short
 * state values are Badges, data stays plain text or time); the Integration status Card shows only usage.
 */
final class ManagementHistoryDetailsTest extends TestCase {
	private const LABEL_CLASS = 'class="ran-booster-repository-detail__fact-label"';

	/** Rows that only the Management history card shows. */
	private const HISTORY_LABELS = array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked', 'Release workflow' );

	public function test_no_record_state_renders_every_short_state_value_as_a_badge(): void {
		$html = $this->history( $this->details( 'not_configured', null ) );

		foreach ( array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked' ) as $label ) {
			self::assertSame( 1, preg_match( $this->row_pattern( $label, 'badge' ), $html ), $label . ' must be a Badge.' );
		}
		self::assertSame( 1, preg_match( $this->row_pattern( 'Last checked', 'badge', 'neutral', 'Never' ), $html ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Observation', 'badge', 'neutral', 'None' ), $html ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Management credential', 'badge', 'neutral', 'None' ), $html ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Recorded signing secret', 'badge', 'neutral', 'None' ), $html ) );
		self::assertSame( 1, substr_count( $html, 'Managed hook not yet set' ) );
		foreach ( array( 'No historical observation', 'Webhook has not been managed yet' ) as $sentence ) {
			self::assertStringNotContainsString( $sentence, $html );
		}
		self::assertStringNotContainsString( '<time', $html );
	}

	public function test_recorded_and_drift_states_keep_badges_and_render_data_as_plain_text_or_time(): void {
		foreach ( array( 'configured', 'profile_revision_stale', 'configuration_drift' ) as $state ) {
			$html = $this->history( $this->details( $state, $this->record() ) );

			self::assertSame( 1, preg_match( $this->row_pattern( 'Recorded hook status', 'badge' ), $html ), $state );
			self::assertSame( 1, preg_match( $this->row_pattern( 'Observation', 'badge', 'neutral', 'Historical only' ), $html ), $state );
			self::assertSame( 1, preg_match( $this->row_pattern( 'Management credential', 'plain' ), $html ), $state );
			self::assertSame( 1, preg_match( $this->row_pattern( 'Recorded signing secret', 'plain' ), $html ), $state );
			self::assertSame( 1, preg_match( $this->row_pattern( 'Last checked', 'time' ), $html ), $state );
			self::assertStringContainsString( '<time datetime="2026-08-20T01:02:03Z">2026-08-20T01:02:03Z</time>', $html );
			self::assertStringNotContainsString( '>Never<', $html );
			self::assertStringNotContainsString( 'not live readiness or a signed delivery', $html );
		}
		self::assertSame( 1, preg_match( $this->row_pattern( 'Current local warning', 'badge' ), $this->history( $this->details( 'configuration_drift', $this->record() ) ) ) );
	}

	public function test_a_single_release_workflow_row_is_labelled_status_under_its_group_heading(): void {
		$workflow = array(
			'key'      => 'core:release-workflow-abc',
			'label'    => 'Release workflow',
			'value'    => 'Unavailable',
			'tone'     => 'warning',
			'category' => 'release_workflow',
		);
		$html     = $this->history( array_merge( $this->details( 'not_configured', null ), array( $workflow ) ) );

		self::assertStringContainsString( '<h4 class="ran-booster-repository-detail__group-heading">Webhook</h4>', $html );
		self::assertStringContainsString( '<h4 class="ran-booster-repository-detail__group-heading">Release workflow</h4>', $html );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Status', 'badge', 'warning', 'Unavailable' ), $html ) );
		self::assertSame( 0, preg_match( '#<dt[^>]*>Release workflow</dt>#', $html ) );

		$multiple = array_merge( $workflow, array( 'label' => 'Release workflow — owner/plugin.php' ) );
		$html     = $this->history( array_merge( $this->details( 'not_configured', null ), array( $multiple ) ) );
		self::assertSame( 1, preg_match( '#<dt[^>]*>Release workflow — owner/plugin\.php</dt>#', $html ) );
		self::assertSame( 0, preg_match( '#<dt[^>]*>Status</dt>#', $html ) );
	}

	public function test_the_status_card_renders_only_the_usage_rows_and_none_of_the_history_rows(): void {
		$workflow = array(
			'key'      => 'core:release-workflow-abc',
			'label'    => 'Release workflow',
			'value'    => 'Unavailable',
			'tone'     => 'warning',
			'category' => 'release_workflow',
		);
		foreach ( array( array( 'not_configured', null ), array( 'configuration_drift', $this->record() ) ) as $case ) {
			$card = $this->integration( array_merge( $this->details( $case[0], $case[1] ), array( $workflow ) ), 1, 1 );

			self::assertSame( array( 'Branch demand', 'Recorded release usage' ), $this->labels( $card ) );
			foreach ( self::HISTORY_LABELS as $label ) {
				self::assertStringNotContainsString( $label, $card );
			}
			self::assertStringNotContainsString( 'group-heading', $card );
		}
	}

	public function test_the_status_card_adds_package_sources_only_when_the_inventory_is_incomplete(): void {
		$card = $this->integration( array(), 0, 0, 1 );

		self::assertSame( array( 'Package sources' ), $this->labels( $card ) );
		self::assertStringContainsString( 'Exact counts are unavailable while package inventory is incomplete.', $card );
		self::assertSame( array( 'Branch demand', 'Recorded release usage' ), $this->labels( $this->integration( array(), 1, 0 ) ) );
	}

	public function test_the_sidebar_history_card_renders_the_six_rows_on_every_tab_and_no_card_repeats_a_label(): void {
		$workflow = array(
			'key'      => 'core:release-workflow-abc',
			'label'    => 'Release workflow',
			'value'    => 'Unavailable',
			'tone'     => 'warning',
			'category' => 'release_workflow',
		);
		$details  = array_merge( $this->details( 'configuration_drift', $this->record() ), array( $workflow ) );
		foreach ( array( 'status', 'branch', 'releases' ) as $view ) {
			$html = $this->render( $details, $view );
			if ( 1 !== preg_match( '#ran-booster-repository-activity-heading.*?</section>#s', $html, $m ) ) {
				self::fail( 'The Management history card was not rendered on ' . $view . '.' );
			}
			$history_labels = $this->labels( $m[0] );
			foreach ( array( 'Recorded hook status', 'Observation', 'Management credential', 'Recorded signing secret', 'Last checked', 'Status' ) as $label ) {
				self::assertContains( $label, $history_labels, $view . ': ' . $label );
			}
			self::assertSame( $history_labels, array_values( array_unique( $history_labels ) ), $view . ' repeats a row.' );
			if ( 'status' === $view ) {
				self::assertSame( array(), array_intersect( $history_labels, $this->labels( $this->integration( $details ) ) ) );
			}
			self::assertSame( 1, substr_count( $html, 'Recorded hook status' ), $view . ' shows the hook status twice.' );
		}
	}

	public function test_usage_rows_are_badges_in_every_state(): void {
		$one  = $this->integration( array(), 1, 0 );
		$many = $this->integration( array(), 2, 3 );
		$none = $this->integration( array(), 0, 0 );

		self::assertSame( 1, preg_match( $this->row_pattern( 'Branch demand', 'badge', 'ok', '1 package' ), $one ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Recorded release usage', 'badge', 'neutral', 'None' ), $one ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Branch demand', 'badge', 'ok', '2 packages' ), $many ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Recorded release usage', 'badge', 'ok', '3 packages' ), $many ) );
		self::assertSame( 1, preg_match( $this->row_pattern( 'Branch demand', 'badge', 'neutral', 'None' ), $none ) );
	}

	public function test_the_repository_list_renders_the_same_short_values_as_badges(): void {
		foreach ( array( array( 'not_configured', null ), array( 'configuration_drift', $this->record() ) ) as $case ) {
			ob_start();
			( new RepositoryTableRenderer() )->render(
				'managed-repositories-heading',
				array(
					array(
						'provider_label' => 'GitHub',
						'repository'     => 'owner/repository',
						'historical'     => true,
						'details'        => $this->details( $case[0], $case[1] ),
						'actions'        => array(),
					),
				)
			);
			$html = (string) ob_get_clean();

			foreach ( array( 'Recorded hook status', 'Observation' ) as $label ) {
				self::assertSame( 1, preg_match( '#<strong>' . preg_quote( $label, '#' ) . '</strong>\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge #', $html ), $label );
			}
			if ( null === $case[1] ) {
				foreach ( array( 'Management credential', 'Recorded signing secret', 'Last checked' ) as $label ) {
					self::assertSame( 1, preg_match( '#<strong>' . preg_quote( $label, '#' ) . '</strong>\s*<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent"[^>]*>(None|Never)</span>#', $html ), $label );
				}
				self::assertStringNotContainsString( 'No historical observation', $html );
			} else {
				self::assertStringContainsString( '<time datetime="2026-08-20T01:02:03Z">', $html );
			}
		}
	}

	public function test_the_history_css_is_scoped_to_the_record_cards(): void {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/ran-booster/30-provider-cards.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a stylesheet in a test.
		self::assertIsString( $css );
		$rows = array(
			'\.ran-booster-repository-detail__group-heading \{' => array( 'font-size: var(--ran-booster-font-size-lg);', 'font-weight: var(--ran-booster-font-weight-semibold);', 'margin-block: 0 var(--ran-booster-card-heading-gap);' ),
			'\.ran-booster-repository-detail__fact-label \{' => array( 'color: var(--ran-booster-text-muted);', 'font-size: var(--ran-booster-font-size-md);', 'font-weight: 400;' ),
			'\.ran-booster-repository-detail__facts \{'    => array( 'gap: var(--ran-booster-space-16);' ),
			'> div \{'                                     => array( 'gap: var(--ran-booster-space-4);' ),
			'\+ \.ran-booster-repository-detail__group \{' => array( 'margin-block-start: var(--ran-booster-space-24);', 'padding-block-start: var(--ran-booster-space-24);' ),
		);
		foreach ( $rows as $selector => $declarations ) {
			if ( 1 !== preg_match( '/\.ran-booster-admin\n\t\.ran-booster-repository-detail__(?:history|record)\n(?:\t[^\n{]*\n)*?\t?' . $selector . '([^}]*)\}/', $css, $m ) ) {
				self::fail( 'Scoped rule ' . stripslashes( $selector ) . ' is missing.' );
			}
			foreach ( $declarations as $declaration ) {
				self::assertStringContainsString( $declaration, $m[1] );
			}
		}
		self::assertSame( 1, preg_match( '/\.ran-booster-admin \.ran-booster-repository-detail__record \{\n\tcontainer-type: inline-size;\n\}/', $css ), 'The record card must be an inline-size container.' );
		if ( 1 !== preg_match( '/@container \(min-width: 400px\) \{\n\t\.ran-booster-admin\n\t\t\.ran-booster-repository-detail__record\n\t\t\.ran-booster-repository-detail__facts\n\t\t> div \{([^}]*)\}/', $css, $inline ) ) {
			self::fail( 'The container rule for record card facts is missing.' );
		}
		self::assertStringContainsString( 'grid-template-columns: minmax(140px, 0.4fr) minmax(0, 1fr);', $inline[1] );
		self::assertStringContainsString( 'align-items: center;', $inline[1] );
		self::assertStringNotContainsString( 'repository-detail__summary', $css );
		// Every new selector carries the record or history scope.
		self::assertSame( 0, preg_match( '/\n\.ran-booster-admin \.ran-booster-repository-detail__(group-heading|fact-label)/', $css ) );
	}

	/** @return list<string> Row labels of one card, in order. */
	private function labels( string $html ): array {
		preg_match_all( '#<dt class="ran-booster-repository-detail__fact-label">([^<]+)</dt>#', $html, $m );

		return array_values( array_map( static fn ( string $label ): string => html_entity_decode( $label ), $m[1] ) );
	}

	/** @return list<array<string, mixed>> */
	private function details( string $state, ?InstallationRecord $record ): array {
		$method = new ReflectionMethod( WebhookDisplayModel::class, 'history_details' );
		$model  = ( new ReflectionClass( WebhookDisplayModel::class ) )->newInstanceWithoutConstructor();
		$result = $method->invoke( $model, $state, $record );
		self::assertIsArray( $result );

		return array_values( $result );
	}

	private function record(): InstallationRecord {
		return new InstallationRecord( 'gh', 'repo-42', 'owner/example', '77', 'credential_1', 'wh_0123456789abcdef01234567', 'repository', 1, 'created', 'https://hooks.example.test/webhook', 'configured', '2026-08-19T23:01:02Z', '2026-08-20T01:02:03Z' );
	}

	/** @param list<array<string, mixed>> $details */
	private function history( array $details ): string {
		return $this->render( $details, 'branch' );
	}

	/** @param list<array<string, mixed>> $details */
	private function integration( array $details, int $branch = 1, int $release = 0, int $omitted = 0 ): string {
		$html = $this->render( $details, 'status', $branch, $release, $omitted );
		if ( 1 !== preg_match( '#ran-booster-repository-integration-summary-heading.*?</section>#s', $html, $m ) ) {
			self::fail( 'The Integration status card was not rendered.' );
		}

		return $m[0];
	}

	/** @param list<array<string, mixed>> $details */
	private function render( array $details, string $view, int $branch = 1, int $release = 0, int $omitted = 0 ): string {
		$packages = array();
		for ( $i = 0; $i < $branch; $i++ ) {
			$packages[] = $this->package( 'branch', 'branch-' . $i );
		}
		for ( $i = 0; $i < $release; $i++ ) {
			$packages[] = $this->package( 'release_asset', 'release-' . $i );
		}
		$urls = array(
			'status'   => 'https://example.test/repository?repository_view=status',
			'branch'   => 'https://example.test/repository?repository_view=branch',
			'releases' => 'https://example.test/repository?repository_view=releases',
		);
		ob_start();
		( new RepositoryDetailRenderer() )->render(
			array(
				'repository'                => 'owner/example',
				'source_label'              => 'Branch',
				'source_key'                => 'branch',
				'package_summaries'         => $packages,
				'package_summaries_omitted' => $omitted,
				'details'                   => $details,
				'actions'                   => array(),
			),
			'GitHub',
			'https://example.test/repositories',
			'https://example.test/activity',
			true,
			'Receiver ready.',
			$view,
			$urls,
			array_map( static fn ( string $url ): string => $url . '&ran_booster_provider_fragment=1', $urls ),
			null,
			null
		);

		return (string) ob_get_clean();
	}

	/** @return array<string, mixed> */
	private function package( string $source, string $identifier ): array {
		return array(
			'type'              => 'plugin',
			'identifier'        => $identifier . '/plugin.php',
			'display_name'      => $identifier,
			'settings_url'      => 'https://example.test/' . $identifier,
			'source'            => $source,
			'source_revision'   => 1,
			'branch'            => 'branch' === $source ? 'main' : '',
			'subdirectory'      => '',
			'deployment_policy' => 'manual',
		);
	}

	/** Pattern for one fact row whose value is a Badge, plain text or a time element. */
	private function row_pattern( string $label, string $kind, string $tone = '', string $text = '' ): string {
		$intent = array(
			'neutral' => 'none',
			'ok'      => 'stable',
			'warning' => 'medium',
		)[ $tone ] ?? '';
		$badge  = '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . ( '' === $intent ? '[a-z]+' : $intent ) . '-intent"[^>]*>' . ( '' === $text ? '[^<]+' : preg_quote( $text, '#' ) ) . '</span>';
		$value  = match ( $kind ) {
			'badge' => '\s*' . $badge . '\s*',
			'time'  => '\s*<time datetime="[^"]+">[^<]+</time>\s*',
			default => '[^<]+',
		};

		return '#<dt ' . preg_quote( self::LABEL_CLASS, '#' ) . '>' . preg_quote( $label, '#' ) . '</dt><dd>' . $value . '</dd>#';
	}
}
