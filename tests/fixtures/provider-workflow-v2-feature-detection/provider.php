<?php

declare(strict_types=1);

final class RANBoosterWorkflowV2FeatureDetectionProvider {}

if ( interface_exists( 'RAN\\RepositoryProvider\\RepositoryReleaseWorkflowManagementV2' ) ) {
	require __DIR__ . '/workflow-provider-v2.php';
}
