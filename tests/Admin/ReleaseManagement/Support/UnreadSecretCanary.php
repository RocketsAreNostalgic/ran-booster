<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement\Support;

use RuntimeException;

final readonly class UnreadSecretCanary {
	public function __toString(): string {
		throw new RuntimeException( 'Credential-bearing repository fields were traversed before local authority.' );
	}
}
