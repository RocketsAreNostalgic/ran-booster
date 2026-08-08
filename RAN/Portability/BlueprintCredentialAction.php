<?php

declare(strict_types=1);

namespace RAN\Portability;

/** Closed request-local choices for one credential carried by a Blueprint. */
enum BlueprintCredentialAction: string {
	case IMPORT = 'import';
	case TARGET = 'target';
	case LEAVE  = 'leave';
}
