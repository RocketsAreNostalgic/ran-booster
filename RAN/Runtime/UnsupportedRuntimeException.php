<?php

declare(strict_types=1);

namespace RAN\Runtime;

final class UnsupportedRuntimeException extends \RuntimeException {

	public const ERROR_CODE = 'runtime_unsupported';

	public static function multisite(): self {
		return new self( 'RAN Booster managed operations are unavailable on WordPress Multisite.' );
	}
}
