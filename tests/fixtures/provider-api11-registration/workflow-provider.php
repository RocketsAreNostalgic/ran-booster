<?php

declare(strict_types=1);

// Loading this retired facet on API 12 must fail; the registration guard prevents it.
abstract class RANBoosterApiElevenWorkflowProvider implements \RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2 {}
