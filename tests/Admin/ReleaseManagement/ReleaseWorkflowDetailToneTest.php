<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

use PHPUnit\Framework\TestCase;

/**
 * The repository detail normaliser rejects any tone outside its five-value
 * vocabulary with a fatal error, so every tone the release workflow projection
 * can assign must belong to it.
 */
final class ReleaseWorkflowDetailToneTest extends TestCase {

	private const TONES = array( 'neutral', 'ok', 'pending', 'warning', 'error' );

	public function test_every_tone_the_repository_workflow_detail_can_assign_is_a_valid_detail_tone(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/RAN/Admin/ReleaseManagement/ReleaseWorkflowPresenter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		if ( false === $source ) {
			self::fail( 'The presenter source could not be read.' );
		}
		if ( 1 !== preg_match( '/private function repository_release_automation_projection\(.*?\n\t\}\n/s', $source, $method ) ) {
			self::fail( 'The repository workflow projection method was not found.' );
		}
		self::assertGreaterThan( 0, preg_match_all( '/\$tone\s*=\s*\'([a-z]+)\';/', $method[0], $assigned ) );
		foreach ( $assigned[1] as $tone ) {
			self::assertContains( $tone, self::TONES, 'The detail tone "' . $tone . '" is not accepted by the repository details normaliser.' );
		}
	}
}
