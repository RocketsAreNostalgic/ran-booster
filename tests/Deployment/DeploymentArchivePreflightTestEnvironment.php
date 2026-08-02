<?php

declare(strict_types=1);

namespace Tests\Deployment;

final class DeploymentArchivePreflightTestEnvironment {

	public static function temporaryRoot(): string {
		return sys_get_temp_dir() . '/ran-booster-archive-preflight';
	}

	public static function pluginRoot(): string {
		return self::temporaryRoot() . '/plugins';
	}

	public static function upgradeRoot(): string {
		return self::temporaryRoot() . '/upgrade';
	}

	public static function themeRoot(): string {
		return self::temporaryRoot() . '/themes';
	}
}
