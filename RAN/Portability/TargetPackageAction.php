<?php

declare(strict_types=1);

namespace RAN\Portability;

enum TargetPackageAction: string {
	case INSTALL   = 'install';
	case ADOPT     = 'adopt';
	case MANAGED   = 'managed';
	case PROTECTED = 'protected';
	case BLOCKED   = 'blocked';
}
