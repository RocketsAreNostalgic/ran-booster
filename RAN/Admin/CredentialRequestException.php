<?php

declare(strict_types=1);

namespace RAN\Admin;

use RuntimeException;

/**
 * A validation message that is safe to return to a WordPress administrator.
 */
final class CredentialRequestException extends RuntimeException {
}
