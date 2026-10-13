<?php

declare(strict_types=1);

namespace RAN\Activity;

interface ActivityOperation extends ActivityProgress {

	public function complete( string $status, string $reason, string $message = '', string $remediation = '' ): void;
}
