<?php

declare(strict_types=1);

namespace RAN\WordPress;

use RuntimeException;

/** @internal Carries the release-source invariant across the persistence boundary. */
final class ManagedReleaseSubdirectoryNotSupported extends RuntimeException {
}
