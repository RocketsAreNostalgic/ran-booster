<?php

declare(strict_types=1);

namespace RAN\Portability;

use InvalidArgumentException;

/** The selected packages contain one or more sources that Blueprint V1 cannot represent. */
final class UnsupportedBlueprintPackages extends InvalidArgumentException {

	/** @param non-empty-list<BlueprintExportPackageFailure> $failures */
	public function __construct( public readonly array $failures ) {
		parent::__construct( 'The selected packages are unavailable to the current Blueprint format.' );
	}
}
