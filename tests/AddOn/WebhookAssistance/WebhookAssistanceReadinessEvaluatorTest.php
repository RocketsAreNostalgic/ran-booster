<?php

declare(strict_types=1);

namespace RAN\Tests\AddOn\WebhookAssistance;

require_once __DIR__ . '/WebhookAssistanceWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceReadinessEvaluator;
use RAN\Secrets\SecretsFile;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;

final class WebhookAssistanceReadinessEvaluatorTest extends TestCase {

	/** @return iterable<string, array{string, bool}> */
	public static function callback_urls(): iterable {
		$path = '/wp-json/ran-booster/v1/webhooks/gh';
		yield 'public HTTPS host' => array( 'https://example.com' . $path, true );
		yield 'public tunnel host' => array( 'https://abc123.tunnel.example.net' . $path, true );
		yield 'explicit default port' => array( 'https://example.com:443' . $path, true );
		yield 'HTTP' => array( 'http://example.com' . $path, false );
		yield 'non-default port' => array( 'https://example.com:8443' . $path, false );
		yield 'localhost' => array( 'https://localhost' . $path, false );
		yield '*.localhost' => array( 'https://site.localhost' . $path, false );
		yield '*.local' => array( 'https://site.local' . $path, false );
		yield '*.test' => array( 'https://site.test' . $path, false );
		yield 'upper-case *.test' => array( 'https://SITE.TEST' . $path, false );
		yield '*.test with a trailing dot' => array( 'https://site.test.' . $path, false );
		yield 'private IP' => array( 'https://192.168.1.20' . $path, false );
		yield 'IPv6 loopback' => array( 'https://[::1]' . $path, false );
		yield 'test only in the path' => array( 'https://example.com/site.test' . $path, true );
	}

	#[DataProvider( 'callback_urls' )]
	public function test_the_callback_url_decides_reachability( string $callback_url, bool $reachable ): void {
		$evaluator = new WebhookAssistanceReadinessEvaluator(
			$this->createStub( PluginRepository::class ),
			$this->createStub( ThemeRepository::class ),
			$this->createStub( SecretsFile::class ),
			$this->createStub( Database::class ),
			static fn (): bool => true
		);

		$site = $evaluator->evaluate( 'gh', $callback_url )->to_array()['site'];

		self::assertSame( $reachable, ! in_array( 'callback_requires_public_https', $site['reason_codes'], true ) );
	}
}
