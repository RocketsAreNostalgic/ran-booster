<?php

declare(strict_types=1);

namespace RAN\Deployment;

use RuntimeException;

/** Internal signal for a proven pre-mutation install no-op. */
final class ExistingManagedDestination extends RuntimeException {
}
