<?php

declare(strict_types=1);

namespace Tests\Admin;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused package fake stays beside shared view coverage.

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AbstractPackage;
use RAN\Admin\PackagePagePresenter;
use RAN\ManagedRepository;
use RAN\PackageSource;

final class RepeatPackageViewTest extends TestCase {

	protected function setUp(): void {
		$_POST = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'], $GLOBALS['ran_booster_dashboard_test_multisite'] );
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['ran_booster_package_view_multisite'], $GLOBALS['ran_booster_dashboard_test_multisite'] );
	}

	/** @return list<array{PackagePagePresenter, bool, bool}> */
	public static function createViewMatrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), true, true ),
			array( PackagePagePresenter::theme(), false, false ),
		);
	}

	#[DataProvider( 'createViewMatrix' )]
	public function testCreateViewKeepsPrimaryInstallAndAddsExplicitRepeatAction(
		PackagePagePresenter $packageView,
		bool $explicitProvider,
		bool $openRepositoryPicker
	): void {
		$packageProviderSettings = $this->providerSettings( true );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/create.php';
		$html = (string) ob_get_clean();
		$form = $this->formById( $html, 'ran-booster-package-create-form' );

		self::assertStringContainsString( 'data-ran-booster-native-submit', $form );
		self::assertStringContainsString( 'data-ran-booster-package-create="1"', $html );
		self::assertStringContainsString(
			'data-ran-booster-explicit-provider="' . ( $explicitProvider ? '1' : '0' ) . '"',
			$html
		);
		self::assertStringContainsString(
			'data-ran-booster-open-picker="' . ( $openRepositoryPicker ? '1' : '0' ) . '"',
			$html
		);
		self::assertStringContainsString(
			'name="ran_booster[action]" value="' . $packageView->getAction( 'install' ) . '"',
			$html
		);
		self::assertStringNotContainsString( 'ran-booster-page-shell', $html );
		self::assertStringContainsString( 'Back to Managed ' . $packageView->getPluralLabel(), $html );
		self::assertStringContainsString(
			'<h2 id="ran-booster-package-create-heading" class="ran-booster-package-settings__heading">Install New ' . $packageView->getSingularLabel() . '</h2>',
			$html
		);
		self::assertStringContainsString( 'class="ran-booster-package-settings__intro"', $html );
		self::assertStringContainsString( 'ran-booster-package-settings--create', $html );
		self::assertStringContainsString( 'ran-booster-settings-section', $html );
		self::assertStringContainsString( '>Repository configuration</h3>', $html );
		self::assertStringContainsString( 'ran-booster-settings-fields', $html );
		self::assertStringNotContainsString( 'class="form-table"', $html );
		$repositoryPosition = strpos( $html, 'id="ran-booster-package-configuration-heading"' );
		$advancedPosition   = strpos( $html, '<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings"' );
		$operationPosition  = strpos( $html, 'id="ran-booster-package-operation-heading"' );
		$automationPosition = strpos( $html, 'name="ran_booster[deployment_policy]"' );
		$linkPosition       = strpos( $html, 'name="ran_booster[dry-run]"' );
		self::assertIsInt( $repositoryPosition );
		self::assertIsInt( $advancedPosition );
		self::assertIsInt( $operationPosition );
		self::assertIsInt( $automationPosition );
		self::assertIsInt( $linkPosition );
		self::assertTrue( $repositoryPosition < $advancedPosition );
		self::assertTrue( $advancedPosition < $operationPosition );
		self::assertTrue( $operationPosition < $automationPosition );
		self::assertTrue( $automationPosition < $linkPosition );
		self::assertSame(
			array( 'Repository configuration', 'Advanced settings', 'Package source', 'Package operation' ),
			$this->h3Headings( $html ),
			$packageView->getType()
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
		self::assertStringContainsString( 'id="ran-booster-package-configuration-heading"', $form );
		self::assertStringContainsString( 'id="ran-booster-advanced-source-settings"', $form );
		self::assertStringContainsString( 'id="ran-booster-package-operation-heading"', $form );
		self::assertMatchesRegularExpression( '/<button[^>]*data-ran-booster-source-choice="branch"/', $form );
		self::assertStringContainsString( 'Choose or enter a repository above before configuring its package source.', $html );
		self::assertMatchesRegularExpression(
			'/name="ran_booster\\[install_another\\]" value="1"\\s*>Install and add another<\\/button>/',
			$html
		);
		self::assertLessThan(
			strpos( $html, 'name="ran_booster[install_another]"' ),
			strpos( $html, 'Install ' . $packageView->getType() )
		);
		self::assertSame( 1, substr_count( $html, 'name="ran_booster[install_another]"' ) );
	}

	/** @return list<array{PackagePagePresenter, string, bool}> */
	public static function managedPackageContextMatrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), 'example/example.php', false ),
			array( PackagePagePresenter::theme(), 'example-theme', true ),
		);
	}

	#[DataProvider( 'managedPackageContextMatrix' )]
	public function testSignedRepeatContextOffersManagementAndAnotherInstall(
		PackagePagePresenter $packageView,
		string $managedPackageIdentifier,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$packageProviderSettings                         = $this->providerSettings( true );
		$explicitProvider                                = true;
		$openRepositoryPicker                            = true;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/create.php';
		$html = (string) ob_get_clean();
		$form = $this->formById( $html, 'ran-booster-package-create-form' );

		$baseUrl   = $multisite ? 'https://example.test/wp-admin/network/admin.php' : 'https://example.test/wp-admin/admin.php';
		$manageUrl = $baseUrl
			. '?page=' . $packageView->getPageSlug()
			. '&amp;package=' . rawurlencode( $managedPackageIdentifier );

		self::assertStringContainsString(
			'<a class="button button-primary" href="' . $manageUrl . '">Manage ' . $packageView->getType() . '</a>',
			$form
		);
		self::assertMatchesRegularExpression(
			'/name="ran_booster\[install_another\]" value="1"\s*>Install another ' . $packageView->getType() . '<\/button>/',
			$form
		);
		self::assertStringNotContainsString( '>Install and add another</button>', $form );
		self::assertStringNotContainsString( '<button type="submit" class="button button-primary"', $form );
		self::assertSame( 1, substr_count( $form, 'name="ran_booster[install_another]"' ) );
	}

	/** @return list<array{PackagePagePresenter, bool, bool}> */
	public static function editViewMatrix(): array {
		return array(
			array( PackagePagePresenter::plugin(), true, false ),
			array( PackagePagePresenter::theme(), false, false ),
			array( PackagePagePresenter::plugin(), false, true ),
			array( PackagePagePresenter::plugin(), false, false ),
			array( PackagePagePresenter::theme(), true, true ),
		);
	}

	#[DataProvider( 'editViewMatrix' )]
	public function testEditViewOffersMatchingInstallAnotherRouteEvenWhenProviderUnavailable(
		PackagePagePresenter $packageView,
		bool $providerAvailable,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$package                 = $this->package( $packageView );
		$packageProviderSettings = $this->providerSettings( $providerAvailable );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();

		$baseUrl            = $multisite ? 'https://example.test/wp-admin/network/admin.php' : 'https://example.test/wp-admin/admin.php';
		$expectedInstallUrl = $baseUrl
			. '?page=' . $packageView->getCreatePageSlug()
			. '&amp;provider=gh&amp;open_picker=1';
		$expectedBackUrl    = $baseUrl . '?page=' . $packageView->getPageSlug();
		$installAnotherLink = '<a class="button" href="' . $expectedInstallUrl . '">Install another ' . $packageView->getType() . '</a>';
		$backLink           = '<a class="button" href="' . $expectedBackUrl . '">Back to Managed ' . $packageView->getPluralLabel() . '</a>';

		self::assertStringNotContainsString( 'ran-booster-package-settings__install-another', $html );
		self::assertStringNotContainsString( '>Cancel</a>', $html );
		self::assertStringNotContainsString( 'class="ran-booster-package-settings__intro"', $html );
		self::assertStringNotContainsString( 'WordPress disabled', $html );
		self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Branch · main</p>', $html );
		$saveActions = $this->actionGroupByClass( $html, 'ran-booster-package-settings__save-actions' );
		self::assertStringContainsString( $installAnotherLink, $saveActions );
		self::assertStringContainsString( $backLink, $saveActions );
		$savePosition           = strpos( $saveActions, 'data-ran-booster-package-settings-save' );
		$installAnotherPosition = strpos( $saveActions, $installAnotherLink );
		$backPosition           = strpos( $saveActions, $backLink );
		self::assertIsInt( $savePosition );
		self::assertIsInt( $installAnotherPosition );
		self::assertIsInt( $backPosition );
		self::assertLessThan( $installAnotherPosition, $savePosition );
		self::assertLessThan( $backPosition, $installAnotherPosition );
		if ( ! $providerAvailable ) {
			self::assertStringContainsString( '<strong>Provider unavailable.</strong>', $html );
			self::assertMatchesRegularExpression(
				'/<p class="ran-booster-package-summary__meta">\s*<code>owner\/example<\/code>/',
				$html,
				$packageView->getType()
			);
		} else {
			self::assertStringContainsString(
				'<a href="https://github.com/owner/example" class="ran-booster-repository-link"',
				$html
			);
			$advancedPosition    = strpos( $html, '<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings"' );
			$advancedEnd         = strpos( $html, '</details>', $advancedPosition );
			$readinessPosition   = strpos( $html, 'id="ran-booster-branch-readiness"', $advancedPosition );
			$automationPosition  = strpos( $html, 'name="ran_booster[deployment_policy]"' );
			$operationPosition   = strpos( $html, 'class="ran-booster-settings-section ran-booster-package-operation-settings"' );
			$actionsPosition     = strpos( $html, 'class="ran-booster-package-operation-settings__actions"', $operationPosition );
			$reinstallPosition   = strpos( $html, 'data-ran-booster-settings-reinstall', $actionsPosition );
			$operationEnd        = strpos( $html, '</section>', $operationPosition );
			$saveActionsPosition = strpos( $html, 'class="ran-booster-settings-actions ran-booster-package-settings__save-actions"', $operationEnd );
			self::assertIsInt( $advancedPosition );
			self::assertIsInt( $advancedEnd );
			self::assertIsInt( $readinessPosition );
			self::assertIsInt( $automationPosition );
			self::assertIsInt( $operationPosition );
			self::assertIsInt( $actionsPosition );
			self::assertIsInt( $reinstallPosition );
			self::assertIsInt( $operationEnd );
			self::assertIsInt( $saveActionsPosition );
			self::assertLessThan( $automationPosition, $advancedPosition );
			self::assertTrue( $advancedPosition < $readinessPosition );
			self::assertTrue( $readinessPosition < $advancedEnd );
			self::assertTrue( $operationPosition < $actionsPosition );
			self::assertTrue( $actionsPosition < $reinstallPosition );
			self::assertTrue( $reinstallPosition < $operationEnd );
			self::assertTrue( $operationEnd < $saveActionsPosition );
			self::assertStringContainsString(
				'Save ' . $packageView->getType() . ' settings',
				$html,
				$packageView->getType()
			);
			self::assertStringNotContainsString( 'id="ran-booster-package-reinstall-heading"', $html );
			self::assertStringContainsString( 'id="ran-booster-advanced-source-settings"', $html );
			self::assertStringContainsString(
				'id="ran-booster-package-edit-form" action="" method="POST" data-ran-booster-package-mutation',
				$html
			);
			$editForm = $this->formById( $html, 'ran-booster-package-edit-form' );
			self::assertStringContainsString( 'id="ran-booster-package-configuration-heading"', $editForm );
			self::assertStringNotContainsString( 'id="ran-booster-advanced-source-settings"', $editForm );
			self::assertStringNotContainsString( 'id="ran-booster-package-operation-heading"', $editForm );
			self::assertSame(
				array( 'Repository configuration', 'Advanced settings', 'Package source', 'Branch and webhook setup', 'Package operation', 'Danger zone' ),
				$this->h3Headings( $html ),
				$packageView->getType()
			);
		}

		$dangerZone = $this->dangerZone( $html );
		$type       = $packageView->getType();
		self::assertStringStartsWith( '<details id="ran-booster-package-danger-zone"', $dangerZone );
		self::assertStringNotContainsString( 'data-ran-booster-package-disclosure open', $dangerZone );
		self::assertLessThan( strpos( $dangerZone, '<form' ), strpos( $dangerZone, '<summary>' ) );
		self::assertSame( 2, substr_count( $dangerZone, 'data-ran-booster-confirmed-package-removal' ) );
		self::assertStringContainsString(
			'name="ran_booster[action]" value="' . $packageView->getAction( 'unlink' ) . '"',
			$dangerZone
		);
		self::assertStringContainsString(
			'name="ran_booster[action]" value="' . $packageView->getAction( 'unlink-delete' ) . '"',
			$dangerZone
		);
		self::assertStringContainsString(
			'name="_wpnonce" value="' . $packageView->getAction( 'unlink' ) . '"',
			$dangerZone
		);
		self::assertStringContainsString(
			'name="_wpnonce" value="' . $packageView->getAction( 'unlink-delete' ) . '"',
			$dangerZone
		);
		self::assertSame( 2, substr_count( $dangerZone, 'name="ran_booster[expected_source_revision]" value="1"' ) );
		self::assertSame( 2, substr_count( $dangerZone, 'name="ran_booster[confirm_package_removal]" value="1" required' ) );
		self::assertSame( 2, substr_count( $dangerZone, 'disabled data-ran-booster-package-removal-submit' ) );
		self::assertStringContainsString(
			'name="ran_booster[' . $packageView->getIdentifierField() . ']" value="' . $package->getIdentifier() . '"',
			$dangerZone
		);
		self::assertMatchesRegularExpression( '/Unlink ' . preg_quote( $type, '/' ) . '\\s*<\\/button>/', $dangerZone );
		self::assertMatchesRegularExpression( '/Unlink and delete ' . preg_quote( $type, '/' ) . '\\s*<\\/button>/', $dangerZone );
		self::assertStringContainsString(
			'plugin' === $type
				? 'Settings may be permanently removed, while incomplete cleanup may leave incompatible data. This is not a rollback.'
				: 'Active, parent and depended-on themes are protected.',
			$dangerZone
		);
	}

	public function testUnavailablePackageSourceKeepsNavigationActionsWithoutSave(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $packageView ) {
			$package                 = $this->package( $packageView );
			$packageProviderSettings = $this->providerSettings( true );
			$packageSource           = array( 'unavailable' => true );

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			$baseUrl            = 'https://example.test/wp-admin/admin.php';
			$installAnotherLink = '<a class="button" href="' . $baseUrl . '?page=' . $packageView->getCreatePageSlug() . '&amp;provider=gh&amp;open_picker=1">Install another ' . $packageView->getType() . '</a>';
			$backLink           = '<a class="button" href="' . $baseUrl . '?page=' . $packageView->getPageSlug() . '">Back to Managed ' . $packageView->getPluralLabel() . '</a>';
			$actions            = $this->actionGroupByClass( $html, 'ran-booster-settings-actions' );

			self::assertStringNotContainsString( 'data-ran-booster-package-settings-save', $html, $packageView->getType() );
			self::assertStringContainsString( $installAnotherLink, $actions, $packageView->getType() );
			self::assertStringContainsString( $backLink, $actions, $packageView->getType() );
			self::assertLessThan( strpos( $actions, $backLink ), strpos( $actions, $installAnotherLink ), $packageView->getType() );
		}
	}

	public function testSubmittedRemovalActionsReopenDangerZoneForNativeFailures(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $packageView ) {
			foreach ( array( 'unlink', 'unlink-delete' ) as $action ) {
				$package                 = $this->package( $packageView );
				$packageProviderSettings = $this->providerSettings( true );
				$_POST['ran_booster']    = array( 'action' => $packageView->getAction( $action ) );

				ob_start();
				require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
				$html = (string) ob_get_clean();

				self::assertStringContainsString(
					'data-ran-booster-package-disclosure open',
					$this->dangerZone( $html ),
					$packageView->getType() . ' ' . $action
				);
			}

			$_POST['ran_booster'] = array( 'action' => $packageView->getAction( 'edit' ) );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			self::assertStringNotContainsString(
				'data-ran-booster-package-disclosure open',
				$this->dangerZone( $html ),
				$packageView->getType()
			);
		}
	}

	public function testExplicitSourceViewOpensStableAdvancedDisclosureForPluginsAndThemes(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $packageView ) {
			$package                 = $this->package( $packageView );
			$packageProviderSettings = $this->providerSettings( true );
			$packageSource           = array( 'advanced_open' => true );

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			self::assertStringContainsString(
				'<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings" data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings open',
				$html,
				$packageView->getType()
			);
		}
	}

	public function testEditAndReinstallSnapshotsStayAuthoritativeWhileAttemptedValuesAreRetained(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $packageView ) {
			$package                 = $this->package( $packageView );
			$packageProviderSettings = $this->providerSettings( true );
			$_POST['ran_booster']    = array(
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

			$editForm = $this->formById( $html, 'ran-booster-package-edit-form' );
			self::assertStringContainsString( 'name="ran_booster[expected_provider]" value="gh"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_provider_repository_id]" value="provider-id"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_repository]" value="owner/example"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_branch]" value="main"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_credential_id]" value=""', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_subdirectory]" value=""', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_private]" value="0"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_package_slug]" value="example"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_deployment_policy]" value="manual"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_source]" value="branch"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[expected_source_revision]" value="1"', $editForm );
			self::assertStringContainsString( 'name="_ran_booster_reinstall_nonce"', $editForm );
			self::assertStringContainsString( 'name="ran_booster[reinstall_after_save]"', $html );
			self::assertStringContainsString( 'form="ran-booster-package-edit-form"', $html );
			self::assertStringNotContainsString( 'ran-booster-package-settings__reinstall-form', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[repository\]"[^>]*value="owner\/attempted"/', $editForm );
			self::assertMatchesRegularExpression( '/name="ran_booster\[branch\]"[^>]*value="attempted-branch"/', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[subdirectory\]"[^>]*value="attempted\/path"/', $html );
			self::assertMatchesRegularExpression( '/<option value="automatic"[^>]*selected="selected"/', $html );
			self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Branch · main</p>', $html );
			self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Manual</p>', $html );
		}
	}

	public function testEditSourceChoicesUseInPlaceNavigationWithAnchoredFallback(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $packageView ) {
			$identifierValue      = 'plugin' === $packageView->getType() ? 'example/example.php' : 'example-theme';
			$packageSourceMode    = 'edit';
			$packageSourceView    = 'branch';
			$packageSourceChoices = array();
			foreach (
				array(
					'branch'        => 'Branch',
					'release_asset' => 'Published releases',
				) as $sourceKey => $heading
			) {
				$packageSourceChoices[ $sourceKey ] = array(
					'heading'           => $heading,
					'description'       => $heading . ' description',
					'meta'              => $heading . ' meta',
					'url'               => 'https://example.test/wp-admin/admin.php?page=' . $packageView->getPageSlug() . '&source_view=' . $sourceKey,
					'disabled'          => false,
					'client_hydratable' => false,
				);
			}

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
			$html = (string) ob_get_clean();

			self::assertSame( 2, substr_count( $html, '#ran-booster-advanced-source-settings" hx-get=' ), $packageView->getType() );
			self::assertSame( 2, substr_count( $html, 'hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none"' ), $packageView->getType() );
			self::assertSame( 2, substr_count( $html, 'hx-push-url="true" hx-history="false" hx-sync="closest [data-ran-booster-source-controls]:replace"' ), $packageView->getType() );
			self::assertSame( 2, substr_count( $html, 'data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error"' ), $packageView->getType() );
			self::assertStringNotContainsString( 'https://example.test', $html, $packageView->getType() );
			self::assertSame( 2, substr_count( $html, 'hx-get="/wp-admin/admin.php?' ), $packageView->getType() );
		}
	}

	public function testDisabledSourceChoiceRemainsReadableFocusableAndExplainsItself(): void {
		$packageView          = PackagePagePresenter::plugin();
		$packageSourceMode    = 'create';
		$packageSourceView    = 'branch';
		$packageSourceChoices = array(
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

	public function testReleaseManagedBranchViewShowsRetainedTargetWithoutBranchOperations(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $packageView ) {
			$package = $this->package( $packageView );
			$package->setSource( PackageSource::RELEASE_ASSET, 2 );

			$packageProviderSettings = $this->providerSettings( true );
			$packageSource           = array(
				'current'     => PackageSource::RELEASE_ASSET->value,
				'selected'    => PackageSource::BRANCH->value,
				'unavailable' => false,
			);

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			self::assertStringNotContainsString(
				'data-ran-booster-settings-reinstall',
				$html,
				$packageView->getType()
			);
			self::assertMatchesRegularExpression(
				'/data-ran-booster-branch-fields\s*>/',
				$html,
				$packageView->getType()
			);
			self::assertMatchesRegularExpression(
				'/id="ran-booster-repository-branch"[^>]*disabled="disabled"/',
				$html,
				$packageView->getType()
			);
			self::assertMatchesRegularExpression(
				'/id="ran-booster-repository-subdirectory"[^>]*disabled="disabled"/',
				$html,
				$packageView->getType()
			);
			self::assertStringContainsString(
				'Published releases remain the current source.',
				$html,
				$packageView->getType()
			);
			self::assertStringNotContainsString( 'id="ran-booster-branch-readiness"', $html, $packageView->getType() );
		}
	}

	/** @return array{default_provider: string, providers: list<array<string, mixed>>} */
	private function providerSettings( bool $available ): array {
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

	private function package( PackagePagePresenter $packageView ): RepeatPackageViewPackage {
		$identifier = 'plugin' === $packageView->getType() ? 'example/example.php' : 'example-theme';
		$package    = new RepeatPackageViewPackage( $identifier );
		$package->setRepository( new ManagedRepository( 'gh', 'owner/example', 'provider-id', 'main' ) );

		return $package;
	}

	private function dangerZone( string $html ): string {
		$start = strpos( $html, '<details id="ran-booster-package-danger-zone" class="ran-booster-settings-disclosure ran-booster-package-danger-zone"' );
		self::assertNotFalse( $start, 'The package settings page should include the danger zone.' );

		return substr( $html, $start );
	}

	private function formById( string $html, string $id ): string {
		self::assertMatchesRegularExpression( '/<form\s+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html );
		preg_match( '/<form\s+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html, $matches );

		return $matches[0];
	}

	private function formByClass( string $html, string $class ): string {
		self::assertMatchesRegularExpression( '/<form[^>]*class="[^"]*' . preg_quote( $class, '/' ) . '[^"]*".*?<\/form>/s', $html );
		preg_match( '/<form[^>]*class="[^"]*' . preg_quote( $class, '/' ) . '[^"]*".*?<\/form>/s', $html, $matches );

		return $matches[0];
	}

	private function actionGroupByClass( string $html, string $class ): string {
		self::assertMatchesRegularExpression( '/<div[^>]*class="[^"]*' . preg_quote( $class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/s', $html );
		preg_match( '/<div[^>]*class="[^"]*' . preg_quote( $class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/s', $html, $matches );

		return $matches[0];
	}

	/** @return list<string> */
	private function h3Headings( string $html ): array {
		preg_match_all( '/<h3[^>]*>(.*?)<\/h3>/s', $html, $matches );

		return array_map(
			static fn ( string $heading ): string => trim( wp_strip_all_tags( $heading, true ) ),
			$matches[1]
		);
	}
}

final class RepeatPackageViewPackage extends AbstractPackage {
	public string $name;

	public function __construct( private readonly string $identifier ) {
		$this->name = 'Example package';
	}

	public function getIdentifier(): mixed {
		return $this->identifier;
	}

	protected function runtimeSlug(): string {
		return 'example';
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
