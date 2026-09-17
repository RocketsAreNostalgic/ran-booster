<?php

declare(strict_types=1);

namespace RAN\BoosterGitHubProvider\V1\ReleaseDeployments\WorkflowAssistance;

function get_option( string $option, mixed $fallback = false ): mixed {
	return $GLOBALS['ran_booster_release_deployments_test_options'][ $option ] ?? $fallback;
}
