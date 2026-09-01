<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RuntimeException;

/** @internal Carries an unavailable repository source relationship across the persistence boundary. */
final class ManagedReleaseRepositorySourceUnavailable extends RuntimeException {
}
