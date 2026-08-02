<?php

declare(strict_types=1);

namespace RAN\Storage;

enum PackageMutationStatus: string {

	case CHANGED   = 'changed';
	case UNCHANGED = 'unchanged';
	case CONFLICT  = 'conflict';
	case FAILED    = 'failed';
}
