<?php

declare(strict_types=1);

namespace RAN\Admin;

require_once __DIR__ . '/BackgroundDeploymentFailureWordPressFunctions.php';

function wp_get_environment_type(): string {
	return (string) ( $GLOBALS['ran_booster_development_detector_environment_type'] ?? 'production' );
}

function wp_is_development_mode( string $mode ): bool {
	return in_array( $mode, $GLOBALS['ran_booster_development_detector_modes'] ?? array(), true );
}
