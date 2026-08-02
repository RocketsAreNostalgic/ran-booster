<?php

declare(strict_types=1);

namespace RAN\Admin;

enum AdminTabKind: string {

	case PROVIDER = 'provider';
	case PAGE     = 'page';
}
