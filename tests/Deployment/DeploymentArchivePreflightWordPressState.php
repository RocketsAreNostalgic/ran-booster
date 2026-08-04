<?php

declare(strict_types=1);

namespace RAN\Deployment;

final class DeploymentArchivePreflightWordPressState {

	public static string $source = '';
	public static int $status    = 200;
	public static int $requests  = 0;
	public static bool $wpError  = false;
	/** @var list<array{status?: int, wp_error?: bool, headers?: array<string, string>}> */
	public static array $responses = array();
	/** @var array<string, string> */
	public static array $headers = array();
	/** @var array<string, mixed> */
	public static array $arguments         = array();
	public static bool $multisite          = false;
	public static bool $fileMods           = true;
	public static string $filesystemMethod = 'direct';
	public static float $freeSpace         = 1073741824.0;
	/** @var array<string, float|false> */
	public static array $freeSpaceByDirectory = array();
	public static string $wordpressVersion    = '7.0.1';

	public static function reset(): void {
		self::$source               = '';
		self::$status               = 200;
		self::$requests             = 0;
		self::$wpError              = false;
		self::$responses            = array();
		self::$headers              = array();
		self::$arguments            = array();
		self::$multisite            = false;
		self::$fileMods             = true;
		self::$filesystemMethod     = 'direct';
		self::$freeSpace            = 1073741824.0;
		self::$freeSpaceByDirectory = array();
		self::$wordpressVersion     = '7.0.1';
	}
}
