<?php

declare(strict_types=1);

namespace RAN\WordPress;

/**
 * Safe, closed reasons why WordPress did not complete one package operation.
 */
enum CorePackageExecutionFailure: string {
	case INVALID_REQUEST     = 'invalid_request';
	case RUNTIME_UNSUPPORTED = 'runtime_unsupported';
	case WORDPRESS_REFUSED   = 'wordpress_refused';
	case WORDPRESS_FAILED    = 'wordpress_failed';
	case WORDPRESS_RESTORED  = 'wordpress_restored';
	case WORDPRESS_UNCERTAIN = 'wordpress_uncertain';
	case OPERATION_MISMATCH  = 'operation_mismatch';
}
