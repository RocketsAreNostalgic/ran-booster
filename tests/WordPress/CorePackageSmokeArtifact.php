<?php

declare(strict_types=1);

namespace RAN\Tests\WordPress;

use RAN\Deployment\PreparedArtifact;
use RAN\WPBranchUpdater\V1\Contract\PreparedPackageArtifact;

/** Reuse the smoke's immutable ZIP fixtures at the real Branch executor boundary. */
final class CorePackageSmokeArtifact implements PreparedPackageArtifact {

	public function __construct( private readonly PreparedArtifact $artifact ) {}

	public function get_path(): string {
		return $this->artifact->get_path();
	}

	public function get_expected_version(): string {
		return $this->artifact->get_expected_version();
	}

	public function assert_unchanged(): void {
		$this->artifact->assert_unchanged();
	}
}
