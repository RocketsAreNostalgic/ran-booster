<?php

declare(strict_types=1);

namespace RAN;

use InvalidArgumentException;

/** A submitted package subdirectory is not a safe repository-relative path. */
final class InvalidPackageSubdirectory extends InvalidArgumentException {
}
