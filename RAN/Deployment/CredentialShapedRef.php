<?php

declare(strict_types=1);

namespace RAN\Deployment;

use InvalidArgumentException;

/** A configured ref cannot enter the secret-free execution record. */
final class CredentialShapedRef extends InvalidArgumentException {

	public function __construct() {
		parent::__construct( 'The configured ref resembles a credential.' );
	}
}
