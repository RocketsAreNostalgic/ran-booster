<?php

declare(strict_types=1);

namespace RAN\Storage;

enum PackageStorageOperation: string {

	case QUERY  = 'query';
	case INSERT = 'insert';
	case UPDATE = 'update';
	case DELETE = 'delete';
}
