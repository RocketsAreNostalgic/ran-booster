<?php

declare(strict_types=1);

namespace Tests\Admin;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

final class PackagePagePresenterTest extends TestCase {

	public function testPluginConfigurationPreservesPluginRouting(): void {
		$config = PackagePagePresenter::plugin();

		self::assertSame( 'plugin', $config->getType() );
		self::assertSame( 'Plugin', $config->getSingularLabel() );
		self::assertSame( 'Plugins', $config->getPluralLabel() );
		self::assertSame( 'file', $config->getIdentifierField() );
		self::assertSame( 'ran-booster-plugins', $config->getPageSlug() );
		self::assertSame( 'ran-booster-plugins-create', $config->getCreatePageSlug() );
		self::assertSame( 'install-plugin', $config->getAction( 'install' ) );
		self::assertSame( 'update-plugin', $config->getAction( 'update' ) );
		self::assertSame( 'unlink-plugin', $config->getAction( 'unlink' ) );
		self::assertSame( 'unlink-delete-plugin', $config->getAction( 'unlink-delete' ) );
		self::assertSame( 'bulk-plugin', $config->getAction( 'bulk' ) );
	}

	public function testThemeConfigurationPreservesThemeRouting(): void {
		$config = PackagePagePresenter::theme();

		self::assertSame( 'theme', $config->getType() );
		self::assertSame( 'Theme', $config->getSingularLabel() );
		self::assertSame( 'Themes', $config->getPluralLabel() );
		self::assertSame( 'stylesheet', $config->getIdentifierField() );
		self::assertSame( 'ran-booster-themes', $config->getPageSlug() );
		self::assertSame( 'ran-booster-themes-create', $config->getCreatePageSlug() );
		self::assertSame( 'edit-theme', $config->getAction( 'edit' ) );
		self::assertSame( 'unlink-theme', $config->getAction( 'unlink' ) );
		self::assertSame( 'unlink-delete-theme', $config->getAction( 'unlink-delete' ) );
		self::assertSame( 'bulk-theme', $config->getAction( 'bulk' ) );
	}

	public function testUnsupportedActionsAreRejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PackagePagePresenter::plugin()->getAction( 'publish' );
	}
}
