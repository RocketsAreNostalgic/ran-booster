<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

/** Provider-neutral credential, access, rate-limit, or transport read failure. */
final class RepositoryReleaseReadUnavailable extends RuntimeException {
}
