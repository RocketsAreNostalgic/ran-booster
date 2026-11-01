<?php

declare(strict_types=1);

namespace RAN\Tests\Troubleshooting;

final class DiagnosticRunMemory {
	public ?string $raw        = null;
	public bool $refuse        = false;
	public bool $broken        = false;
	public int $writes         = 0;
	public ?\Closure $conflict = null;
}
