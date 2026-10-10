<?php

declare(strict_types=1);

namespace RAN\Admin;

interface PackageDisplayProjection {
	public function type(): string;

	public function identifier(): string;

	public function display_name(): string;

	public function source(): string;

	public function settings_url(): string;

	public function source_revision(): int;
}
