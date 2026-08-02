<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

enum RepositoryBrowseMode: string {

	case PUBLIC_OWNER = 'public_owner';
	case ACCESSIBLE   = 'accessible';
}
