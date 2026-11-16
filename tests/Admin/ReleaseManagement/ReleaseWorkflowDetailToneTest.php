<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\StatusBadge;

/**
 * The repository detail normaliser rejects any tone outside the status badge
 * vocabulary with a fatal error, so every tone a detail producer can assign must
 * belong to StatusBadge::INTENTS, the single source of tones.
 */
final class ReleaseWorkflowDetailToneTest extends TestCase {

	public function test_every_tone_the_repository_workflow_detail_can_assign_is_accepted_and_drawable(): void {
		$accepted = $this->accepted_tones();
		$source   = $this->source( 'RAN/Admin/ReleaseManagement/ReleaseWorkflowPresenter.php' );
		if ( 1 !== preg_match( '/private function repository_release_automation_projection\(.*?\n\t\}\n/s', $source, $method ) ) {
			self::fail( 'The repository workflow projection method was not found.' );
		}
		self::assertGreaterThan( 0, preg_match_all( '/\$tone\s*=\s*\'([a-z]+)\';/', $method[0], $assigned ) );
		foreach ( $assigned[1] as $tone ) {
			self::assertContains( $tone, $accepted, 'The detail tone "' . $tone . '" is not in the status badge vocabulary.' );
		}
	}

	public function test_the_info_tone_is_accepted_and_draws_the_informational_blue_badge(): void {
		self::assertContains( 'info', $this->accepted_tones() );
		self::assertSame( 'informational', StatusBadge::INTENTS['info'] );
		self::assertStringContainsString( 'is-informational-intent', StatusBadge::markup( 'Existing workflow found', 'info' ) );
	}

	public function test_every_literal_tone_in_the_detail_producers_is_accepted(): void {
		$accepted  = $this->accepted_tones();
		$producers = array(
			'RAN/Admin/ProviderRepositoryRowsNormalizer.php',
			'RAN/Admin/Component/RepositoryDetailRenderer.php',
			'RAN/Admin/WebhookManagement/Display/WebhookDisplayModel.php',
			'RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php',
			'RAN/Admin/ReleaseManagement/ReleaseWorkflowPresenter.php',
		);
		$checked   = 0;
		foreach ( $producers as $path ) {
			$source = $this->source( $path );
			// Direct literals (`$tone = 'ok';`, `'tone' => 'ok'`) and the literal branches of a ternary (`? 'ok' : 'pending'`).
			// Hyphenated values such as ran-booster-badge--info are CSS classes in a separate vocabulary and are not matched.
			$patterns = array(
				'/(?:\'tone\'\s*=>|\$\w*tone\s*=)\s*\'([a-z]+)\'\s*[;,)]/',
				'/(?:\'tone\'\s*=>|\$\w*tone\s*=)[^;,]*?\?\s*\'([a-z]+)\'\s*:/',
				'/(?:\'tone\'\s*=>|\$\w*tone\s*=)[^;,]*?:\s*\'([a-z]+)\'\s*[;,)]/',
			);
			foreach ( $patterns as $pattern ) {
				$found = preg_match_all( $pattern, $source, $literals );
				self::assertIsInt( $found );
				foreach ( $literals[1] as $tone ) {
					++$checked;
					self::assertContains( $tone, $accepted, 'The tone "' . $tone . '" in ' . $path . ' is not in the status badge vocabulary.' );
				}
			}
		}
		self::assertGreaterThan( 10, $checked );
	}

	/** @return list<string> */
	private function accepted_tones(): array {
		return array_keys( StatusBadge::INTENTS );
	}

	private function source( string $path ): string {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		if ( false === $source ) {
			self::fail( 'The source could not be read: ' . $path );
		}

		return $source;
	}
}
