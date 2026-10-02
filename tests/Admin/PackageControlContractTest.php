<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Executable inventory for the shared plugin/theme package administration surface. */
final class PackageControlContractTest extends TestCase {

	/** @return iterable<string, array{string, string, string, string, string, string}> */
	public static function control_provider(): iterable {
		foreach ( self::controls() as $name => $control ) {
			yield $name => $control;
		}
	}

	#[DataProvider( 'control_provider' )]
	public function test_every_control_has_an_explicit_interaction_contract(
		string $surface,
		string $transport,
		string $authority,
		string $success,
		string $failure,
		string $destination
	): void {
		self::assertContains( $surface, array( 'create', 'edit', 'index' ) );
		self::assertContains( $transport, array( 'mutation-post', 'readonly-htmx-get', 'native-get', 'native-link', 'local-action' ) );
		self::assertContains( $authority, array( 'core', 'wordpress', 'provider' ) );
		self::assertNotSame( '', $success );
		self::assertNotSame( '', $failure );
		self::assertNotSame( '', $destination );
	}

	public function test_current_templates_expose_every_inventoried_transport(): void {
		$create    = $this->view( 'create.php' );
		$edit      = $this->view( 'edit.php' );
		$index     = $this->view( 'index.php' );
		$danger    = $this->view( 'danger-zone.php' );
		$reinstall = $this->view( 'reinstall.php' );
		$source    = $this->view( 'source-choices.php' );
		$readiness = $this->view( 'branch-readiness.php' );

		self::assertStringContainsString( 'ran_booster[install_another]', $create );
		self::assertStringContainsString( 'data-ran-booster-native-submit', $create );
		self::assertStringContainsString( 'data-ran-booster-package-mutation', $edit );
		self::assertStringContainsString( 'data-ran-booster-package-mutation', $index );
		self::assertSame( 2, substr_count( $danger, 'data-ran-booster-package-mutation' ) );
		self::assertSame( 2, substr_count( $danger, 'data-ran-booster-native-submit' ) );
		self::assertStringContainsString( 'data-ran-booster-package-mutation', $reinstall );
		$associated_settings_include = 'hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"';
		self::assertStringContainsString( $associated_settings_include, $edit );
		self::assertStringContainsString( $associated_settings_include, $reinstall );
		self::assertStringContainsString( $associated_settings_include, $readiness );
		self::assertStringContainsString( 'hx-get=', $source );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $source );
		self::assertStringContainsString( 'data-ran-booster-error-target="#ran-booster-package-mutation-error"', $source );
		self::assertStringContainsString( 'type="button"', $source );
		self::assertStringContainsString( "'heading'           => __( 'Releases', 'ran-booster' )", $source );
		self::assertStringContainsString( 'Provider capability required', $source );
		self::assertStringNotContainsString( 'Subscriber', $source );
		self::assertStringNotContainsString( 'Release Deployments', $source );
		self::assertStringContainsString( 'form="ran-booster-package-edit-form"', $readiness );
		self::assertStringContainsString( 'hx-post=', $readiness );
		self::assertStringContainsString( 'hx-push-url=', $readiness );
		self::assertStringContainsString( 'data-ran-booster-enhanced-mutation', $readiness );
		self::assertStringContainsString( 'name="ran_booster[check_repository_branch_after_save]"', $readiness );
	}

	public function test_plugin_and_theme_use_one_shared_template_set(): void {
		foreach ( array( 'create.php', 'edit.php', 'index.php', 'danger-zone.php', 'source-choices.php' ) as $view ) {
			$source = $this->view( $view );
			self::assertStringContainsString( '$package_view', $source, $view );
			self::assertStringNotContainsString( 'PackagePagePresenter::plugin()', $source, $view );
			self::assertStringNotContainsString( 'PackagePagePresenter::theme()', $source, $view );
		}

		$reinstall = $this->view( 'reinstall.php' );
		self::assertStringNotContainsString( 'PackagePagePresenter::plugin()', $reinstall );
		self::assertStringNotContainsString( 'PackagePagePresenter::theme()', $reinstall );
	}

	/** @return array<string, array{string, string, string, string, string, string}> */
	private static function controls(): array {
		return array(
			'create source choice'      => array( 'create', 'local-action', 'core', 'selected pane', 'inline validation', 'current create route' ),
			'install'                   => array( 'create', 'mutation-post', 'core', 'signed package PRG', 'local package form', 'saved package settings' ),
			'install and add another'   => array( 'create', 'mutation-post', 'core', 'signed package PRG', 'local package form', 'canonical create route' ),
			'create back'               => array( 'create', 'native-link', 'core', 'navigation', 'WordPress page', 'package index' ),
			'save settings'             => array( 'edit', 'mutation-post', 'core', 'signed package PRG', 'local package form', 'saved package settings' ),
			'reinstall'                 => array( 'edit', 'mutation-post', 'core', 'signed package PRG', 'local package form', 'saved package settings' ),
			'unlink'                    => array( 'edit', 'mutation-post', 'core', 'signed package PRG', 'local package form', 'package index' ),
			'unlink and delete'         => array( 'edit', 'mutation-post', 'core', 'signed package PRG', 'local package form', 'package index' ),
			'saved source navigation'   => array( 'edit', 'readonly-htmx-get', 'core', 'selected pane', 'unchanged page', 'canonical source view' ),
			'branch readiness'          => array( 'edit', 'mutation-post', 'core', 'green repository row', 'saved form or repository warning', 'canonical branch view' ),
			'published release actions' => array( 'edit', 'mutation-post', 'core', 'Core PRG', 'local source panel', 'canonical release view' ),
			'repository provider links' => array( 'edit', 'native-link', 'provider', 'navigation', 'provider page', 'provider settings' ),
			'activate or enable'        => array( 'edit', 'native-link', 'wordpress', 'WordPress action', 'WordPress page', 'saved package settings' ),
			'filter and search'         => array( 'index', 'native-get', 'core', 'filtered list', 'package index', 'canonical filtered index' ),
			'bulk apply'                => array( 'index', 'mutation-post', 'core', 'signed bulk PRG', 'package index', 'canonical filtered index' ),
			'row reinstall'             => array( 'index', 'mutation-post', 'core', 'signed package PRG', 'package index', 'canonical filtered index' ),
			'edit settings'             => array( 'index', 'native-link', 'core', 'navigation', 'package index', 'saved package settings' ),
			'deployment details'        => array( 'index', 'native-link', 'core', 'navigation', 'package index', 'deployment activity' ),
		);
	}

	private function view( string $name ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Focused local source contract.
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/views/packages/' . $name );
	}
}
