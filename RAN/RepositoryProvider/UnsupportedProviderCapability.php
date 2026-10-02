<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use LogicException;

final class UnsupportedProviderCapability extends LogicException {

	public static function unknown_contract(): self {
		return new self( 'Unknown repository provider capability.' );
	}

	public static function for_provider(): self {
		return new self( 'Repository provider does not support the requested capability.' );
	}
}
