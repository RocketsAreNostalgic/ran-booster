<?php

declare(strict_types=1);

final class RAN_Booster_WorkflowV3FeatureDetectionProvider {}

if ( interface_exists( 'RAN\\RepositoryProvider\\RepositoryReleaseWorkflowManagementV3' ) ) {
	require __DIR__ . '/workflow-provider-v3.php';
}
