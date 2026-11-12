<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowDisplay;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowRequestController;

/**
 * The release workflow keeps its own lists of the preflight reasons it can display. A reason Core emits that
 * a list lacks degrades to preflight_contract_unavailable, so the lists must follow ReleaseTrackingPreflight.
 */
final class ReleaseWorkflowReasonCodesTest extends TestCase {

	public function test_the_controller_accepts_every_preflight_reason_it_can_receive(): void {
		$accepted = ( new \ReflectionClassConstant( ReleaseWorkflowRequestController::class, 'FAILURE_DIAGNOSTIC_CODES' ) )->getValue();
		self::assertIsArray( $accepted );

		foreach ( ReleaseTrackingPreflight::reason_codes() as $reason ) {
			self::assertContains( $reason, $accepted, $reason . ' is missing from the workflow controller list.' );
		}
	}

	public function test_the_display_accepts_every_preflight_reason(): void {
		$display = new ReleaseWorkflowDisplay();
		$method  = new \ReflectionMethod( $display, 'valid_diagnostic_code' );

		foreach ( ReleaseTrackingPreflight::reason_codes() as $reason ) {
			self::assertTrue( $method->invoke( $display, $reason ), $reason . ' is missing from the workflow display list.' );
		}
	}
}
