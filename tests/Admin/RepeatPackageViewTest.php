<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\PackagePagePresenter;
use RAN\ManagedRepository;
use RAN\PackageSource;

final class RepeatPackageViewTest extends TestCase {

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$_POST = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'], $GLOBALS['ran_booster_dashboard_test_multisite'] );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'], $GLOBALS['ran_booster_dashboard_test_multisite'] );
	}

	/** @return list<array{PackagePagePresenter, bool, bool}> */
	public static function create_view_matrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), true, true ),
			array( PackagePagePresenter::theme(), false, false ),
		);
	}

	#[DataProvider( 'create_view_matrix' )]
	public function test_create_view_keeps_primary_install_and_adds_explicit_repeat_action(
		PackagePagePresenter $package_view,
		bool $explicit_provider,
		bool $open_repository_picker
	): void {
		$package_provider_settings = $this->provider_settings( true );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/create.php';
		$html = (string) ob_get_clean();
		$form = $this->form_by_id( $html, 'ran-booster-package-create-form' );

		self::assertStringContainsString( 'data-ran-booster-native-submit', $form );
		self::assertStringContainsString( 'data-ran-booster-package-create="1"', $html );
		self::assertStringContainsString(
			'data-ran-booster-explicit-provider="' . ( $explicit_provider ? '1' : '0' ) . '"',
			$html
		);
		self::assertStringContainsString(
			'data-ran-booster-open-picker="' . ( $open_repository_picker ? '1' : '0' ) . '"',
			$html
		);
		self::assertStringContainsString(
			'name="ran_booster[action]" value="' . $package_view->get_action( 'install' ) . '"',
			$html
		);
		self::assertStringNotContainsString( 'ran-booster-page-shell', $html );
		self::assertStringContainsString( 'Back to Managed ' . $package_view->get_plural_label(), $html );
		self::assertStringContainsString(
			'<h2 id="ran-booster-package-create-heading" class="ran-booster-package-settings__heading">Install New ' . $package_view->get_singular_label() . '</h2>',
			$html
		);
		self::assertStringContainsString( 'class="ran-booster-package-settings__intro"', $html );
		self::assertStringContainsString( 'ran-booster-package-settings--create', $html );
		self::assertStringContainsString( 'ran-booster-settings-section', $html );
		self::assertStringContainsString( '>Repository configuration</h3>', $html );
		self::assertStringContainsString( 'ran-booster-settings-fields', $html );
		self::assertStringNotContainsString( 'class="form-table"', $html );
		$repository_position = strpos( $html, 'id="ran-booster-package-configuration-heading"' );
		$advanced_position   = strpos( $html, '<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings"' );
		$operation_position  = strpos( $html, 'id="ran-booster-package-operation-heading"' );
		$automation_position = strpos( $html, 'name="ran_booster[deployment_policy]"' );
		$link_position       = strpos( $html, 'name="ran_booster[dry-run]"' );
		self::assertIsInt( $repository_position );
		self::assertIsInt( $advanced_position );
		self::assertIsInt( $operation_position );
		self::assertIsInt( $automation_position );
		self::assertIsInt( $link_position );
		self::assertTrue( $repository_position < $advanced_position );
		self::assertTrue( $advanced_position < $operation_position );
		self::assertTrue( $operation_position < $automation_position );
		self::assertTrue( $automation_position < $link_position );
		self::assertSame(
			array( 'Repository configuration', 'Advanced settings', 'Update source', 'Package operation' ),
			$this->h3_headings( $html ),
			$package_view->get_type()
		);
		self::assertStringNotContainsString(
			'<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings" data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings open',
			$html
		);
		self::assertStringContainsString( 'Branch · provider default', $html );
		self::assertStringContainsString( 'class="ran-booster-package-source-shell" data-ran-booster-source-controls', $html );
		self::assertStringContainsString( 'class="regular-text ran-booster-repository-input"', $html );
		self::assertMatchesRegularExpression(
			'/class="regular-text ran-booster-repository-input"[^>]+required/',
			$html
		);
		self::assertStringContainsString( 'class="ran-booster-source-choices"', $html );
		self::assertStringNotContainsString( 'role="tab"', $html );
		self::assertStringContainsString( 'data-ran-booster-source-choice="branch"', $html );
		self::assertStringContainsString( 'data-ran-booster-source-pane="branch"', $html );
		self::assertStringContainsString( 'ran-booster-source-choice__radio', $html );
		self::assertStringNotContainsString( 'ran-booster-source-choice--navigation', $html );
		self::assertStringContainsString( 'id="ran-booster-package-configuration-heading"', $form );
		self::assertStringContainsString( 'id="ran-booster-advanced-source-settings"', $form );
		self::assertStringContainsString( 'id="ran-booster-package-operation-heading"', $form );
		self::assertMatchesRegularExpression( '/<button[^>]*data-ran-booster-source-choice="branch"/', $form );
		self::assertStringContainsString( 'Choose a repository before configuring its update source.', $html );
		self::assertStringContainsString( 'Choose an update source. Selecting it does not install the package.', $html );
		self::assertStringNotContainsString( 'name="ran_booster[check_repository_branch_after_save]"', $html );
		self::assertStringNotContainsString( 'Save settings and check', $html );
		self::assertMatchesRegularExpression(
			'/name="ran_booster\\[install_another\\]" value="1"\\s*>Install and add another<\\/button>/',
			$html
		);
		self::assertLessThan(
			strpos( $html, 'name="ran_booster[install_another]"' ),
			strpos( $html, 'Install ' . $package_view->get_type() )
		);
		self::assertSame( 1, substr_count( $html, 'name="ran_booster[install_another]"' ) );
	}

	#[DataProvider( 'create_view_matrix' )]
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The included production view reads these parameters as local template variables.
	public function test_create_view_does_not_trigger_warnings_when_promoted_to_exceptions(
		PackagePagePresenter $package_view,
		bool $explicit_provider,
		bool $open_repository_picker
	): void {
		$package_provider_settings = $this->provider_settings( true );
		$buffer_level              = ob_get_level();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test-only handler promotes render warnings to exceptions.
		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);

		try {
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/create.php';
			$html = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}

			restore_error_handler();
		}

		self::assertStringContainsString( 'ran-booster-package-settings--create', $html );
		self::assertStringNotContainsString( 'name="ran_booster[check_repository_branch_after_save]"', $html );
	}

	/** @return list<array{PackagePagePresenter, string, bool}> */
	public static function managed_package_context_matrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), 'example/example.php', false ),
			array( PackagePagePresenter::theme(), 'example-theme', true ),
		);
	}

	#[DataProvider( 'managed_package_context_matrix' )]
	public function test_signed_repeat_context_offers_management_and_another_install(
		PackagePagePresenter $package_view,
		string $managed_package_identifier,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$package_provider_settings                       = $this->provider_settings( true );
		$explicit_provider                               = true;
		$open_repository_picker                          = true;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/create.php';
		$html = (string) ob_get_clean();
		$form = $this->form_by_id( $html, 'ran-booster-package-create-form' );

		$base_url   = $multisite ? 'https://example.test/wp-admin/network/admin.php' : 'https://example.test/wp-admin/admin.php';
		$manage_url = $base_url
			. '?page=' . $package_view->get_page_slug()
			. '&amp;package=' . rawurlencode( $managed_package_identifier );

		self::assertStringContainsString(
			'<a class="button button-primary" href="' . $manage_url . '">Manage ' . $package_view->get_type() . '</a>',
			$form
		);
		self::assertMatchesRegularExpression(
			'/name="ran_booster\[install_another\]" value="1"\s*>Install another ' . $package_view->get_type() . '<\/button>/',
			$form
		);
		self::assertStringNotContainsString( '>Install and add another</button>', $form );
		self::assertStringNotContainsString( '<button type="submit" class="button button-primary"', $form );
		self::assertSame( 1, substr_count( $form, 'name="ran_booster[install_another]"' ) );
	}

	/** @return list<array{PackagePagePresenter, bool, bool}> */
	public static function edit_view_matrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), true, false ),
			array( PackagePagePresenter::theme(), false, false ),
			array( PackagePagePresenter::plugin(), false, true ),
			array( PackagePagePresenter::plugin(), false, false ),
			array( PackagePagePresenter::theme(), true, true ),
		);
	}

	#[DataProvider( 'edit_view_matrix' )]
	public function test_edit_view_offers_matching_install_another_route_even_when_provider_unavailable(
		PackagePagePresenter $package_view,
		bool $provider_available,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$package                   = $this->package( $package_view );
		$package_provider_settings = $this->provider_settings( $provider_available );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();

		$base_url             = $multisite ? 'https://example.test/wp-admin/network/admin.php' : 'https://example.test/wp-admin/admin.php';
		$expected_install_url = $base_url
			. '?page=' . $package_view->get_create_page_slug()
			. '&amp;provider=gh&amp;open_picker=1';
		$expected_back_url    = $base_url . '?page=' . $package_view->get_page_slug();
		$install_another_link = '<a class="button" href="' . $expected_install_url . '">Install another ' . $package_view->get_type() . '</a>';
		$back_link            = '<a class="button" href="' . $expected_back_url . '">Back to Managed ' . $package_view->get_plural_label() . '</a>';

		self::assertStringNotContainsString( 'ran-booster-package-settings__install-another', $html );
		self::assertStringNotContainsString( '>Cancel</a>', $html );
		self::assertStringNotContainsString( 'class="ran-booster-package-settings__intro"', $html );
		self::assertStringNotContainsString( 'WordPress disabled', $html );
		self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Branch · main</p>', $html );
		$save_actions = $this->action_group_by_class( $html, 'ran-booster-package-settings__save-actions' );
		self::assertStringContainsString( $install_another_link, $save_actions );
		self::assertStringContainsString( $back_link, $save_actions );
		$save_position            = strpos( $save_actions, 'data-ran-booster-package-settings-save' );
		$install_another_position = strpos( $save_actions, $install_another_link );
		$back_position            = strpos( $save_actions, $back_link );
		self::assertIsInt( $save_position );
		self::assertIsInt( $install_another_position );
		self::assertIsInt( $back_position );
		self::assertLessThan( $install_another_position, $save_position );
		self::assertLessThan( $back_position, $install_another_position );
		if ( ! $provider_available ) {
			self::assertStringContainsString( '<strong>Provider unavailable.</strong>', $html );
			self::assertMatchesRegularExpression(
				'/<p class="ran-booster-package-summary__meta">\s*<code>owner\/example<\/code>/',
				$html,
				$package_view->get_type()
			);
		} else {
			self::assertStringContainsString(
				'<a href="https://github.com/owner/example" class="ran-booster-repository-link"',
				$html
			);
			$advanced_position     = strpos( $html, '<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings"' );
			$advanced_end          = strpos( $html, '</details>', $advanced_position );
			$readiness_position    = strpos( $html, 'id="ran-booster-branch-readiness"', $advanced_position );
			$automation_position   = strpos( $html, 'name="ran_booster[deployment_policy]"' );
			$operation_position    = strpos( $html, 'class="ran-booster-settings-section ran-booster-package-operation-settings"' );
			$actions_position      = strpos( $html, 'class="ran-booster-package-operation-settings__actions"', $operation_position );
			$reinstall_position    = strpos( $html, 'data-ran-booster-settings-reinstall', $actions_position );
			$operation_end         = strpos( $html, '</section>', $operation_position );
			$save_actions_position = strpos( $html, 'class="ran-booster-settings-actions ran-booster-package-settings__save-actions"', $operation_end );
			self::assertIsInt( $advanced_position );
			self::assertIsInt( $advanced_end );
			self::assertIsInt( $readiness_position );
			self::assertIsInt( $automation_position );
			self::assertIsInt( $operation_position );
			self::assertIsInt( $actions_position );
			self::assertIsInt( $reinstall_position );
			self::assertIsInt( $operation_end );
			self::assertIsInt( $save_actions_position );
			self::assertLessThan( $automation_position, $advanced_position );
			self::assertTrue( $advanced_position < $readiness_position );
			self::assertTrue( $readiness_position < $advanced_end );
			self::assertTrue( $operation_position < $actions_position );
			self::assertTrue( $actions_position < $reinstall_position );
			self::assertTrue( $reinstall_position < $operation_end );
			self::assertTrue( $operation_end < $save_actions_position );
			self::assertStringContainsString(
				'Save ' . $package_view->get_type() . ' settings',
				$html,
				$package_view->get_type()
			);
			self::assertStringNotContainsString( 'id="ran-booster-package-reinstall-heading"', $html );
			self::assertStringContainsString( 'id="ran-booster-advanced-source-settings"', $html );
			self::assertStringContainsString( 'Deploy the saved branch manually or on a signed push.', $html );
			self::assertStringContainsString(
				'id="ran-booster-package-edit-form" action="" method="POST" data-ran-booster-package-mutation',
				$html
			);
			$edit_form = $this->form_by_id( $html, 'ran-booster-package-edit-form' );
			self::assertStringContainsString( 'id="ran-booster-package-configuration-heading"', $edit_form );
			self::assertStringNotContainsString( 'id="ran-booster-advanced-source-settings"', $edit_form );
			self::assertStringNotContainsString( 'id="ran-booster-package-operation-heading"', $edit_form );
			self::assertSame(
				array( 'Repository configuration', 'Advanced settings', 'Update source', 'Package operation', 'Danger zone' ),
				$this->h3_headings( $html ),
				$package_view->get_type()
			);
		}

		$danger_zone = $this->danger_zone( $html );
		$type        = $package_view->get_type();
		self::assertStringStartsWith( '<details id="ran-booster-package-danger-zone"', $danger_zone );
		self::assertStringNotContainsString( 'data-ran-booster-package-disclosure open', $danger_zone );
		self::assertLessThan( strpos( $danger_zone, '<form' ), strpos( $danger_zone, '<summary>' ) );
		self::assertSame( 2, substr_count( $danger_zone, 'data-ran-booster-confirmed-package-removal' ) );
		self::assertStringContainsString(
			'name="ran_booster[action]" value="' . $package_view->get_action( 'unlink' ) . '"',
			$danger_zone
		);
		self::assertStringContainsString(
			'name="ran_booster[action]" value="' . $package_view->get_action( 'unlink-delete' ) . '"',
			$danger_zone
		);
		self::assertStringContainsString(
			'name="_wpnonce" value="' . $package_view->get_action( 'unlink' ) . '"',
			$danger_zone
		);
		self::assertStringContainsString(
			'name="_wpnonce" value="' . $package_view->get_action( 'unlink-delete' ) . '"',
			$danger_zone
		);
		self::assertSame( 2, substr_count( $danger_zone, 'name="ran_booster[expected_source_revision]" value="1"' ) );
		self::assertSame( 2, substr_count( $danger_zone, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 2, substr_count( $danger_zone, 'disabled data-ran-booster-package-removal-submit' ) );
		self::assertStringContainsString(
			'name="ran_booster[' . $package_view->get_identifier_field() . ']" value="' . $package->get_identifier() . '"',
			$danger_zone
		);
		self::assertMatchesRegularExpression( '/Unlink ' . preg_quote( $type, '/' ) . '\\s*<\\/button>/', $danger_zone );
		self::assertMatchesRegularExpression( '/Unlink and delete ' . preg_quote( $type, '/' ) . '\\s*<\\/button>/', $danger_zone );
		self::assertStringContainsString(
			'plugin' === $type
				? 'Settings may be permanently removed, while incomplete cleanup may leave incompatible data. This is not a rollback.'
				: 'Active, parent and depended-on themes are protected.',
			$danger_zone
		);
	}

	public function test_unavailable_package_source_keeps_navigation_actions_without_save(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package                   = $this->package( $package_view );
			$package_provider_settings = $this->provider_settings( true );
			$package_source            = array( 'unavailable' => true );

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			$base_url             = 'https://example.test/wp-admin/admin.php';
			$install_another_link = '<a class="button" href="' . $base_url . '?page=' . $package_view->get_create_page_slug() . '&amp;provider=gh&amp;open_picker=1">Install another ' . $package_view->get_type() . '</a>';
			$back_link            = '<a class="button" href="' . $base_url . '?page=' . $package_view->get_page_slug() . '">Back to Managed ' . $package_view->get_plural_label() . '</a>';
			$actions              = $this->action_group_by_class( $html, 'ran-booster-settings-actions' );

			self::assertStringNotContainsString( 'data-ran-booster-package-settings-save', $html, $package_view->get_type() );
			self::assertStringContainsString( $install_another_link, $actions, $package_view->get_type() );
			self::assertStringContainsString( $back_link, $actions, $package_view->get_type() );
			self::assertLessThan( strpos( $actions, $back_link ), strpos( $actions, $install_another_link ), $package_view->get_type() );
		}
	}

	public function test_submitted_removal_actions_reopen_danger_zone_for_native_failures(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			foreach ( array( 'unlink', 'unlink-delete' ) as $action ) {
				$package                   = $this->package( $package_view );
				$package_provider_settings = $this->provider_settings( true );
				$_POST['ran_booster']      = array( 'action' => $package_view->get_action( $action ) );

				ob_start();
				require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
				$html = (string) ob_get_clean();

				self::assertStringContainsString(
					'data-ran-booster-package-disclosure open',
					$this->danger_zone( $html ),
					$package_view->get_type() . ' ' . $action
				);
			}

			$_POST['ran_booster'] = array( 'action' => $package_view->get_action( 'edit' ) );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			self::assertStringNotContainsString(
				'data-ran-booster-package-disclosure open',
				$this->danger_zone( $html ),
				$package_view->get_type()
			);
		}
	}

	public function test_explicit_source_view_opens_stable_advanced_disclosure_for_plugins_and_themes(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package                   = $this->package( $package_view );
			$package_provider_settings = $this->provider_settings( true );
			$package_source            = array( 'advanced_open' => true );

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			self::assertStringContainsString(
				'<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings" data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings open',
				$html,
				$package_view->get_type()
			);
		}
	}

	public function test_edit_and_reinstall_snapshots_stay_authoritative_while_attempted_values_are_retained(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package                   = $this->package( $package_view );
			$package_provider_settings = $this->provider_settings( true );
			$_POST['ran_booster']      = array(
				'provider'                            => 'gh',
				'repository'                          => 'owner/attempted',
				'branch'                              => 'attempted-branch',
				'subdirectory'                        => 'attempted/path',
				'deployment_policy'                   => 'automatic',
				'provider_repository_id'              => 'attempted-id',
				'provider_repository_identity_source' => 'manual',
			);

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			$edit_form = $this->form_by_id( $html, 'ran-booster-package-edit-form' );
			self::assertStringContainsString( 'name="ran_booster[expected_provider]" value="gh"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_provider_repository_id]" value="provider-id"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_repository]" value="owner/example"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_branch]" value="main"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_credential_id]" value=""', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_subdirectory]" value=""', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_private]" value="0"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_package_slug]" value="example"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_deployment_policy]" value="manual"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_source]" value="branch"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[expected_source_revision]" value="1"', $edit_form );
			self::assertStringContainsString( 'name="_ran_booster_reinstall_nonce"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[reinstall_after_save]"', $html );
			self::assertStringContainsString( 'form="ran-booster-package-edit-form"', $html );
			self::assertStringNotContainsString( 'ran-booster-package-settings__reinstall-form', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[repository\]"[^>]*value="owner\/attempted"/', $edit_form );
			self::assertMatchesRegularExpression( '/name="ran_booster\[branch\]"[^>]*value="attempted-branch"/', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[subdirectory\]"[^>]*value="attempted\/path"/', $html );
			self::assertMatchesRegularExpression( '/<option value="automatic"[^>]*selected="selected"/', $html );
			self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Branch · main</p>', $html );
			self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Manual</p>', $html );
		}
	}

	public function test_edit_source_choices_use_in_place_navigation_with_anchored_fallback(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$identifier_value       = 'plugin' === $package_view->get_type() ? 'example/example.php' : 'example-theme';
			$package_source_mode    = 'edit';
			$package_source_view    = 'branch';
			$package_current_source = 'release_asset';
			$package_source_choices = array();
			foreach (
				array(
					'branch'        => 'Branch',
					'release_asset' => 'Releases',
				) as $source_key => $heading
			) {
				$package_source_choices[ $source_key ] = array(
					'heading'           => $heading,
					'description'       => $heading . ' description',
					'meta'              => $heading . ' meta',
					'url'               => 'https://example.test/wp-admin/admin.php?page=' . $package_view->get_page_slug() . '&source_view=' . $source_key,
					'disabled'          => false,
					'client_hydratable' => false,
				);
			}

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
			$html = (string) ob_get_clean();

			self::assertSame( 1, substr_count( $html, '#ran-booster-advanced-source-settings" hx-get=' ), $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none"' ), $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'hx-push-url="true" hx-history="false" hx-sync="closest [data-ran-booster-source-controls]:replace"' ), $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error"' ), $package_view->get_type() );
			self::assertStringNotContainsString( 'https://example.test', $html, $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'hx-get="/wp-admin/admin.php?' ), $package_view->get_type() );
			self::assertStringContainsString( '>Branch</strong>', $html, $package_view->get_type() );
			self::assertStringContainsString( '>Releases</strong>', $html, $package_view->get_type() );
			self::assertStringContainsString( '<legend class="screen-reader-text">Update source</legend>', $html, $package_view->get_type() );
			self::assertStringContainsString( '<h3 id="ran-booster-package-source-heading" class="ran-booster-section__title">Update source</h3>', $html, $package_view->get_type() );
			self::assertStringContainsString( 'ran-booster-package-source--navigation', $html, $package_view->get_type() );
			self::assertStringContainsString( 'ran-booster-source-choices--navigation nav-tab-wrapper wp-clearfix', $html, $package_view->get_type() );
			self::assertStringContainsString( ' nav-tab ', $html, $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'nav-tab-active' ), $package_view->get_type() );
			self::assertStringContainsString( 'role="navigation" aria-label="Update source settings"', $html, $package_view->get_type() );
			self::assertStringContainsString( 'Viewing settings does not change the update source.', $html, $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'ran-booster-source-choice__current-source' ), $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, '>Active</span>' ), $package_view->get_type() );
			self::assertMatchesRegularExpression(
				'/<span[^>]*class="ran-booster-source-choice__current-source"[^>]*>Active<\\/span>/',
				$html,
				$package_view->get_type()
			);
			self::assertStringContainsString( 'ran-booster-source-choice__content', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'Current source', $html, $package_view->get_type() );
			self::assertMatchesRegularExpression(
				'/data-ran-booster-source-choice="release_asset"[^>]*>[\\s\\S]*?ran-booster-source-choice__current-source[^>]*>Active<\\/span>/',
				$html,
				$package_view->get_type()
			);
			self::assertStringNotContainsString( 'Branch description', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'Releases meta', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'ran-booster-source-choice__radio', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'ran-booster-source-choice__navigation-cue', $html, $package_view->get_type() );
			self::assertMatchesRegularExpression(
				'/<span[^>]*aria-current="page"[^>]*data-ran-booster-source-choice="branch"|<span[^>]*data-ran-booster-source-choice="branch"[^>]*aria-current="page"/',
				$html,
				$package_view->get_type()
			);
		}
	}

	public function test_disabled_source_choice_remains_readable_focusable_and_explains_itself(): void {
		$package_view           = PackagePagePresenter::plugin();
		$package_source_mode    = 'create';
		$package_source_view    = 'branch';
		$package_source_choices = array(
			'branch'        => array(
				'heading'           => 'Branch',
				'description'       => 'Deploy the configured branch.',
				'meta'              => 'Available',
				'url'               => '',
				'disabled'          => false,
				'client_hydratable' => false,
			),
			'release_asset' => array(
				'heading'           => 'Published releases',
				'description'       => 'Published releases require the repository root.',
				'meta'              => 'Repository root required',
				'url'               => '',
				'disabled'          => true,
				'client_hydratable' => false,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
		$html = (string) ob_get_clean();

		self::assertMatchesRegularExpression(
			'/data-ran-booster-source-choice="release_asset"[^>]*aria-disabled="true"|aria-disabled="true"[^>]*data-ran-booster-source-choice="release_asset"/',
			$html
		);
		self::assertStringContainsString( 'title="Published releases require the repository root."', $html );
		self::assertStringNotContainsString( ' disabled=', $html );
	}

	/** @return list<array{PackagePagePresenter, PackageSource, bool}> */
	public static function branch_view_source_matrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), PackageSource::BRANCH, false ),
			array( PackagePagePresenter::theme(), PackageSource::BRANCH, false ),
			array( PackagePagePresenter::plugin(), PackageSource::RELEASE_ASSET, true ),
			array( PackagePagePresenter::theme(), PackageSource::RELEASE_ASSET, true ),
		);
	}

	#[DataProvider( 'branch_view_source_matrix' )]
	public function test_branch_view_keeps_its_stable_shell_and_advanced_sections_for_every_current_source(
		PackagePagePresenter $package_view,
		PackageSource $current_source,
		bool $branch_settings_inactive
	): void {
			$package = $this->package( $package_view );
			$package->set_source( $current_source, 2 );

			$package_provider_settings = $this->provider_settings( true );
			$package_source            = array(
				'current'           => $current_source->value,
				'selected'          => PackageSource::BRANCH->value,
				'unavailable'       => false,
				'advanced_sections' => array( '<div class="ran-booster-release-return">Return action</div>' ),
			);

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			if ( $branch_settings_inactive ) {
				self::assertStringNotContainsString(
					'data-ran-booster-settings-reinstall',
					$html,
					$package_view->get_type()
				);
			}
			self::assertMatchesRegularExpression(
				'/data-ran-booster-branch-fields\s*>/',
				$html,
				$package_view->get_type()
			);
			self::assertSame(
				$branch_settings_inactive,
				// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Already literal-first; the sniff scans across the preceding assertion argument.
				1 === preg_match( '/id="ran-booster-repository-branch"[^>]*disabled="disabled"/', $html ),
				$package_view->get_type()
			);
			self::assertSame(
				$branch_settings_inactive,
				// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Already literal-first; the sniff scans across the preceding assertion argument.
				1 === preg_match( '/id="ran-booster-repository-subdirectory"[^>]*disabled="disabled"/', $html ),
				$package_view->get_type()
			);
			self::assertSame( 1, substr_count( $html, '<h4 id="ran-booster-branch-readiness-heading">Branch readiness</h4>' ), $package_view->get_type() );
			self::assertStringContainsString( 'aria-labelledby="ran-booster-branch-readiness-heading"', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'Published releases remain the package source and settings are retained until returning.', $html, $package_view->get_type() );
			self::assertStringContainsString( 'class="screen-reader-text">' . ( $branch_settings_inactive ? 'Inactive Branch deployment settings' : 'Branch deployment settings' ) . '</legend>', $html, $package_view->get_type() );
			self::assertSame(
				$branch_settings_inactive,
				// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Already literal-first; the sniff scans across the preceding assertion argument.
				1 === preg_match( '/ran-booster-branch-settings is-inactive[^>]*disabled="disabled"[^>]*aria-disabled="true"/', $html ),
				$package_view->get_type()
			);
			self::assertStringContainsString( 'id="ran-booster-branch-readiness"', $html, $package_view->get_type() );
			$branch_pane_position   = strpos( $html, 'id="ran-booster-source-pane-branch"' );
			$return_position        = strpos( $html, 'class="ran-booster-release-return"' );
			$branch_fields_position = strpos( $html, '<fieldset class="ran-booster-branch-settings' );
			$readiness_position     = strpos( $html, 'id="ran-booster-branch-readiness"' );
			self::assertIsInt( $branch_pane_position, $package_view->get_type() );
			self::assertIsInt( $return_position, $package_view->get_type() );
			self::assertIsInt( $branch_fields_position, $package_view->get_type() );
			self::assertIsInt( $readiness_position, $package_view->get_type() );
			self::assertTrue( $branch_pane_position < $return_position, $package_view->get_type() );
			self::assertTrue( $return_position < $branch_fields_position, $package_view->get_type() );
			self::assertTrue( $branch_fields_position < $readiness_position, $package_view->get_type() );
	}

	/** @return array{default_provider: string, providers: list<array<string, mixed>>} */
	private function provider_settings( bool $available ): array {
		return array(
			'default_provider' => 'gh',
			'providers'        => array(
				array(
					'code'                  => 'gh',
					'label'                 => 'GitHub',
					'owner_label'           => 'Owner',
					'repository_url_base'   => $available ? 'https://github.com/' : '',
					'available'             => $available,
					'browse'                => $available,
					'deploy'                => $available,
					'webhooks'              => $available,
					'default_credential_id' => '',
					'credential_profiles'   => array(),
				),
			),
		);
	}

	private function package( PackagePagePresenter $package_view ): RepeatPackageViewPackage {
		$identifier = 'plugin' === $package_view->get_type() ? 'example/example.php' : 'example-theme';
		$package    = new RepeatPackageViewPackage( $identifier );
		$package->set_repository( new ManagedRepository( 'gh', 'owner/example', 'provider-id', 'main' ) );

		return $package;
	}

	private function danger_zone( string $html ): string {
		$start = strpos( $html, '<details id="ran-booster-package-danger-zone" class="ran-booster-settings-disclosure ran-booster-package-danger-zone"' );
		self::assertNotFalse( $start, 'The package settings page should include the danger zone.' );

		return substr( $html, $start );
	}

	private function form_by_id( string $html, string $id ): string {
		self::assertMatchesRegularExpression( '/<form\s+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html );
		preg_match( '/<form\s+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html, $matches );

		return $matches[0];
	}


	private function action_group_by_class( string $html, string $css_class ): string {
		self::assertMatchesRegularExpression( '/<div[^>]*class="[^"]*' . preg_quote( $css_class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/s', $html );
		preg_match( '/<div[^>]*class="[^"]*' . preg_quote( $css_class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/s', $html, $matches );

		return $matches[0];
	}

	/** @return list<string> */
	private function h3_headings( string $html ): array {
		preg_match_all( '/<h3[^>]*>(.*?)<\/h3>/s', $html, $matches );

		return array_map(
			static fn ( string $heading ): string => trim( wp_strip_all_tags( $heading, true ) ),
			$matches[1]
		);
	}
}

// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused package fake stays beside shared view coverage.
final class RepeatPackageViewPackage extends AbstractPackage {
	public string $name;

	public function __construct( private readonly string $identifier = '' ) {
		$this->name = 'Example package';
	}

	public function get_identifier(): mixed {
		return $this->identifier;
	}

	protected function runtime_slug(): string {
		return 'example';
	}
}
