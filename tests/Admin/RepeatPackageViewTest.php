<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
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

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_query_double_preserves_nested_wordpress_query_semantics(): void {
		$url = add_query_arg(
			array(
				'return_s' => 'Rock%26x%3D1',
				'more'     => array( 'deep' => 'a%2Bb' ),
			),
			'https://example.test/wp-admin/admin.php?nested%5Ba%5D=a%26b&nested%5Bdeep%5D%5Bv%5D=one+two~&source=existing#view'
		);
		self::assertSame(
			'https://example.test/wp-admin/admin.php?nested%5Ba%5D=a%26b&nested%5Bdeep%5D%5Bv%5D=one+two%7E&source=existing&return_s=Rock%26x%3D1&more%5Bdeep%5D=a%2Bb#view',
			$url
		);
	}

	public function test_shell_breadcrumbs_render_for_create_and_edit_of_each_type(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			foreach ( array( 'create', 'edit' ) as $operation ) {
				$view                      = 'packages/' . $operation;
				$package                   = $this->package( $package_view );
				$package_provider_settings = $this->provider_settings( true );
				$explicit_provider         = false;
				$open_repository_picker    = false;
				$package_return_list       = array(
					's'        => 'release',
					'provider' => 'gh',
				);
				$package_return_args       = array(
					'return_type'     => $package_view->get_type(),
					'return_s'        => 'release',
					'return_provider' => 'gh',
				);
				$messages                  = array();
				$tabs                      = array();
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/base.php';
				$html = (string) ob_get_clean();
				self::assertStringContainsString( 'page=' . $package_view->get_page_slug() . '&amp;s=release&amp;provider=gh', $html );
				self::assertStringContainsString( '>' . $package_view->get_plural_label() . '</a>', $html );
				// The branch check changes its intent in place: one icon template, after the shell.
				self::assertSame( 1, substr_count( $html, 'id="ran-booster-notice-icons"' ) );
				self::assertStringEndsWith( '</template>', $html );
				$current = 'create' === $operation ? 'Install ' . $package_view->get_singular_label() : $package->name;
				self::assertStringContainsString( 'aria-current="page">' . $current . '</span>', $html );
				if ( 'edit' === $operation ) {
					self::assertStringContainsString( 'return_type=' . $package_view->get_type(), $html );
				}
			}
		}
	}

	public function test_reserved_characters_stay_inside_return_values_on_rendered_routes(): void {
		$search = 'Rock&provider=bb&page=other#frag+more';
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package                   = $this->package( $package_view );
			$package_provider_settings = $this->provider_settings( true );
			$package_return_list       = array(
				's'        => $search,
				'provider' => 'gh',
			);
			$package_return_args       = array(
				'return_type'     => $package_view->get_type(),
				'return_s'        => $search,
				'return_provider' => 'gh',
			);
			$messages                  = array();
			$tabs                      = array();
			$view                      = 'packages/edit';
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/base.php';
			$html              = (string) ob_get_clean();
			$packages          = array( $package );
			$package_providers = array();
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			$html                      .= (string) ob_get_clean();
			$managed_package_identifier = $package->get_identifier();
			$explicit_provider          = false;
			$open_repository_picker     = false;
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/create.php';
			$html .= (string) ob_get_clean();
			preg_match_all( '/(?:href|hx-post)="([^"]*(?:return_s|[&?]s)=([^"]*))"/', $html, $matches );
			self::assertNotEmpty( $matches[1] );
			foreach ( $matches[1] as $attribute ) {
				$url   = html_entity_decode( $attribute, ENT_QUOTES, 'UTF-8' );
				$parts = wp_parse_url( $url );
				self::assertIsArray( $parts );
				$query = array();
				wp_parse_str( $parts['query'] ?? '', $query );
				self::assertContains( $query['page'] ?? '', array( $package_view->get_page_slug(), $package_view->get_create_page_slug() ) );
				self::assertSame( $search, $query['return_s'] ?? $query['s'] ?? null );
				self::assertNotSame( 'bb', $query['provider'] ?? null );
				self::assertArrayNotHasKey( 'fragment', $parts );
			}
		}
	}

	public function test_filtered_list_row_settings_link_carries_the_same_context(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$packages            = array( $this->package( $package_view ) );
			$package_providers   = array();
			$package_return_args = array(
				'return_type'     => $package_view->get_type(),
				'return_s'        => 'release',
				'return_provider' => 'gh',
			);
			$messages            = array();
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'ran-wp-ui-badge is-high-intent" data-ran-booster-badge data-ran-booster-badge-intent="high"', $html );
			self::assertStringContainsString( '>Git provider unavailable</span>', $html );
			self::assertMatchesRegularExpression( '/href="[^"]*page=' . $package_view->get_page_slug() . '&amp;package=[^"]*&amp;return_type=' . $package_view->get_type() . '&amp;return_s=release&amp;return_provider=gh" class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline">Edit settings/', $html );
			$document = new \DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$document->loadHTML( $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$xpath = new \DOMXPath( $document );
			$type  = $package_view->get_type();
			$host  = '//*[@id="ran-booster-' . $type . '-collection" and @data-ran-booster-card="' . $type . 's"]';
			$this->assert_xpath_count( 1, $xpath, $host . '//*[contains(concat(" ", normalize-space(@class), " "), " ran-booster-package-collection__toolbar ")]' );
			$this->assert_xpath_count( 2, $xpath, $host . '//form[@method="get"]' );
			$this->assert_xpath_count( 1, $xpath, $host . '//form[@id="ran-booster-' . $type . '-bulk-form" and @method="POST"]' );
			$this->assert_xpath_count( 1, $xpath, $host . '//table[@data-package-type="' . $type . '"]//input[@form="ran-booster-' . $type . '-bulk-form" and @type="checkbox"]' );
			$this->assert_xpath_count( 0, $xpath, '//form//form' );
		}
	}

	public function test_list_row_links_have_a_unique_accessible_name_per_package(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$first        = $this->package( $package_view );
			$first->name  = 'First <b>package</b>';
			$second       = new RepeatPackageViewPackage( 'plugin' === $package_view->get_type() ? 'other/other.php' : 'other-theme' );
			$second->name = 'Second package';
			$second->set_repository( new ManagedRepository( 'gh', 'owner/other', 'provider-other', 'main' ) );
			$packages         = array( $first, $second );
			$package_activity = array( 'items' => array() );
			foreach ( $packages as $package ) {
				$package_activity['items'][ (string) $package->get_identifier() ] = array(
					'check' => array(
						'available'   => true,
						'latest'      => null,
						'observation' => null,
					),
				);
			}
			$package_providers   = array();
			$package_return_args = array( 'return_type' => $package_view->get_type() );
			$messages            = array();
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			$html = (string) ob_get_clean();

			foreach ( array( 'Edit settings', 'View activity' ) as $visible ) {
				$count = preg_match_all( '/<a\b[^>]*>\s*' . preg_quote( $visible, '/' ) . '<span class="screen-reader-text">(.*?)<\/span>\s*<\/a>/s', $html, $matches );
				if ( 2 !== $count ) {
					self::fail( $visible . ' links should each carry a package name; found ' . (string) $count . '.' );
				}
				$names = array();
				foreach ( $matches[1] as $suffix ) {
					$names[] = $visible . html_entity_decode( $suffix, ENT_QUOTES );
				}
				self::assertCount( 2, array_unique( $names ), $visible . ' names repeat across rows.' );
				self::assertContains( $visible . ': Second package', $names );
				self::assertContains( $visible . ': First <b>package</b>', $names );
			}
			self::assertStringNotContainsString( 'First <b>', $html );
		}
	}

	private function assert_xpath_count( int $expected, \DOMXPath $xpath, string $expression ): void {
		$nodes = $xpath->query( $expression );
		self::assertNotFalse( $nodes );
		self::assertCount( $expected, $nodes );
	}

	public function test_edit_links_keep_return_filter_separate_from_install_provider_and_hx_routes(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package = $this->package( $package_view );
			$package->set_repository( new ManagedRepository( 'bb', 'owner/example', 'provider-id', 'main' ) );
			$package_provider_settings = $this->provider_settings( true );
			$package_return_args       = array(
				'return_type'     => $package_view->get_type(),
				'return_s'        => 'release',
				'return_provider' => 'gh',
			);
			$package_return_list       = array(
				's'        => 'release',
				'provider' => 'gh',
			);
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'provider=bb&amp;open_picker=1&amp;return_type=' . $package_view->get_type() . '&amp;return_s=release&amp;return_provider=gh', $html );
			self::assertMatchesRegularExpression( '/hx-post="[^" ]*return_type=' . $package_view->get_type() . '&amp;return_s=release&amp;return_provider=gh"/', $html );
			// The list return context now belongs to the Shell breadcrumb parent, not to a button in this view.
			self::assertStringNotContainsString( 'page=' . $package_view->get_page_slug() . '&amp;s=release&amp;provider=gh', $html );
		}
	}

	public function test_failed_package_submission_marks_form_for_leave_warning(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			foreach ( array( 'create', 'edit' ) as $operation ) {
				$package                   = $this->package( $package_view );
				$package_provider_settings = $this->provider_settings( true );
				$explicit_provider         = false;
				$open_repository_picker    = false;
				$_POST['ran_booster']      = array( 'action' => $package_view->get_action( 'create' === $operation ? 'install' : 'edit' ) );
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/packages/' . $operation . '.php';
				$html = (string) ob_get_clean();
				self::assertStringContainsString( 'data-ran-booster-attempted', $this->form_by_id( $html, 'ran-booster-package-' . $operation . '-form' ) );
			}
		}
	}

	public function test_incomplete_advanced_summary_projection_uses_plain_summary(): void {
		foreach ( array( null, false, 17, array() ) as $heading ) {
			$package_advanced_summary = 'Branch fallback';
			$package_source           = array(
				'advanced_summary_projection' => array(
					'heading' => $heading,
					'badges'  => array( array( 'label' => 'Release badge' ) ),
					'status'  => 'Existing status',
				),
			);
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/advanced-source-settings.php';
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'Branch fallback', $html );
			self::assertStringNotContainsString( 'ran-booster-advanced-source-summary__heading', $html );
			self::assertStringNotContainsString( 'Release badge', $html );
		}
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
		self::assertStringNotContainsString( 'data-ran-booster-card="package-edit"', $html );

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
		self::assertStringNotContainsString( 'ran-booster-package-settings__back', $html );
		self::assertStringContainsString(
			'<h2 id="ran-booster-package-create-heading" class="ran-booster-package-settings__heading ran-booster-page-heading__title">Install New ' . $package_view->get_singular_label() . '</h2>',
			$html
		);
		self::assertStringNotContainsString( 'ran-booster-package-settings__intro', $html );
		self::assertStringNotContainsString( 'Identify the repository Booster should manage', $html );
		self::assertStringNotContainsString( 'Choose the repository and access Booster should use', $html );
		self::assertStringNotContainsString( 'Choose when Booster may update this package.', $html );
		self::assertStringContainsString( 'ran-booster-package-settings--create', $html );
		self::assertStringContainsString( 'ran-booster-settings-section', $html );
		self::assertStringContainsString( '>Repository configuration</h3>', $html );
		self::assertStringContainsString( 'ran-booster-settings-fields', $html );
		self::assertStringNotContainsString( 'class="form-table"', $html );
		$trust_guidance = 'Only install Git provider integrations you trust: an active Git provider can read its saved credentials. Booster does not authenticate a third-party publisher.';
		self::assertSame( 1, substr_count( $html, $trust_guidance ), 'The trust guidance renders once.' );
		self::assertStringContainsString( '<div class="notice notice-info inline ran-booster-settings-fields__notice ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p>' . $trust_guidance . '</p></div>', $html );
		$credential_position = strpos( $html, 'id="ran-booster-credential-id"' );
		$trust_position      = strpos( $html, $trust_guidance );
		$locator_position    = strpos( $html, 'name="ran_booster[repository]"' );
		self::assertIsInt( $credential_position );
		self::assertIsInt( $trust_position );
		self::assertIsInt( $locator_position );
		self::assertTrue( $credential_position < $trust_position, 'The trust Notice follows the access field.' );
		self::assertTrue( $trust_position < $locator_position, 'The trust Notice precedes the repository locator.' );
		$repository_position = strpos( $html, 'id="ran-booster-package-configuration-heading"' );
		$advanced_position   = strpos( $html, '<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings ran-booster-disclosure"' );
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
		$actions_position = strpos( $html, 'aria-label="Installation actions"' );
		self::assertIsInt( $actions_position );
		self::assertTrue( $link_position < $actions_position, 'The link choice sits directly above the action group.' );
		self::assertMatchesRegularExpression( '/<fieldset class="ran-booster-package-link-choice"/', $html );
		self::assertMatchesRegularExpression( '/name="ran_booster\[dry-run\]" aria-describedby="ran-booster-link-installed-help"/', $html );
		self::assertStringContainsString( 'id="ran-booster-link-installed-help"', $html );
		self::assertStringContainsString( 'Link an already installed ' . $package_view->get_type(), $html );
		self::assertStringNotContainsString( 'Link installed ' . $package_view->get_type(), $html );
		self::assertStringNotContainsString( 'ran-booster-link-installed-folder-help', $html );
		self::assertStringContainsString( 'without deploying it; its folder name must match the repository.', $html );
		self::assertStringNotContainsString( 'Let Booster manage an already installed', $html );
		self::assertStringNotContainsString( 'The installed folder name must match the repository package name.', $html );
		self::assertStringContainsString( 'Leave blank for the repository root.', $html );
		self::assertStringNotContainsString( 'lives below the repository root', $html );
		self::assertStringContainsString( 'Blank uses the default branch.', $html );
		self::assertStringNotContainsString( 'Leave blank to use the repository', $html );
		self::assertMatchesRegularExpression( '/class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-solid" aria-describedby="ran-booster-link-installed-help"/', $html );
		self::assertSame(
			array( 'Repository configuration', 'Advanced settings', 'Update source', 'Package operation' ),
			$this->h3_headings( $html ),
			$package_view->get_type()
		);
		self::assertStringNotContainsString(
			'<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings open',
			$html
		);
		self::assertStringContainsString( 'Branch · repository default', $html );
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
		self::assertStringNotContainsString( 'aria-controls=', $html );
		self::assertStringContainsString( 'ran-booster-source-choice__radio', $html );
		self::assertStringNotContainsString( 'ran-booster-source-choice--navigation', $html );
		self::assertStringContainsString( 'id="ran-booster-package-configuration-heading"', $form );
		self::assertStringContainsString( 'id="ran-booster-advanced-source-settings"', $form );
		self::assertStringContainsString( 'id="ran-booster-package-operation-heading"', $form );
		self::assertMatchesRegularExpression( '/<button[^>]*data-ran-booster-source-choice="branch"/', $form );
		self::assertStringContainsString( 'Choose a repository first.', $html );
		self::assertStringContainsString( 'Selecting it does not install the package.', $html );
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
	public function test_create_view_installation_actions_are_the_card_footer_band(
		PackagePagePresenter $package_view,
		bool $explicit_provider,
		bool $open_repository_picker
	): void {
		$package_provider_settings = $this->provider_settings( true );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/create.php';
		$html = (string) ob_get_clean();
		$form = $this->form_by_id( $html, 'ran-booster-package-create-form' );

		// The body carries the footed modifier and the band is the last visible child of the Card body.
		self::assertStringContainsString( '<div class="ran-booster-settings-section__body ran-booster-settings-section__body--footed">', $form );
		if ( 1 !== preg_match( '/<div class="ran-booster-card-footer ran-booster-package-install-actions" role="group" aria-label="Installation actions">(.*?)<\/div>\s*<\/div>\s*<\/div>\s*<\/section>\s*<\/form>/s', $form, $band ) ) {
			self::fail( 'The installation actions must be the last child of the Package operation body.' );
		}
		self::assertStringNotContainsString( 'ran-booster-settings-actions', $form );
		self::assertSame( 2, substr_count( $band[1], '<button type="submit"' ) );
		$primary   = strpos( $band[1], 'Install ' . $package_view->get_type() . '</button>' );
		$secondary = strpos( $band[1], 'Install and add another</button>' );
		self::assertIsInt( $primary );
		self::assertIsInt( $secondary );
		self::assertLessThan( $secondary, $primary, 'The primary action comes first.' );
		self::assertStringContainsString( 'data-ran-booster-busy-label="Installing…"', $band[1] );
		self::assertStringContainsString( 'name="ran_booster[install_another]" value="1"', $band[1] );

		// The wrapper that JS hides contains the band and the status element, inside the form.
		$wrapper = strpos( $form, 'data-ran-booster-branch-install-actions' );
		$status  = strpos( $form, 'data-ran-booster-submit-busy-status' );
		$footer  = strpos( $form, 'ran-booster-card-footer ran-booster-package-install-actions' );
		self::assertIsInt( $wrapper );
		self::assertIsInt( $status );
		self::assertIsInt( $footer );
		self::assertTrue( $wrapper < $status && $status < $footer, 'The busy status precedes the band inside the hidden-state wrapper.' );
	}

	#[DataProvider( 'create_view_matrix' )]
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- The included production view reads these parameters as local template variables.
	public function test_create_view_updates_hint_sits_between_the_select_and_the_notice_and_follows_the_source(
		PackagePagePresenter $package_view,
		bool $explicit_provider,
		bool $open_repository_picker
	): void {
		$package_provider_settings        = $this->provider_settings( true );
		$development_environment_detected = true;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/create.php';
		$html = (string) ob_get_clean();

		$select  = strpos( $html, 'name="ran_booster[deployment_policy]"' );
		$branch  = strpos( $html, 'data-ran-booster-policy-source="branch"' );
		$notice  = strpos( $html, 'data-ran-booster-local-development-warning' );
		$release = strpos( $html, 'data-ran-booster-policy-source="release_asset" hidden' );
		self::assertIsInt( $select );
		self::assertIsInt( $branch );
		self::assertIsInt( $notice );
		self::assertIsInt( $release );
		self::assertTrue( $select < $branch && $branch < $release && $release < $notice, 'Select, hint, then notice.' );
		self::assertStringContainsString( 'ran-booster-deployment-policy-field--operation', $html );
		self::assertSame( 1, substr_count( $html, 'Manual waits for an administrator. Automatic deploys on push.' ) );
		self::assertSame( 1, substr_count( $html, 'Manual waits for an administrator. Automatic installs validated releases.' ) );
		self::assertDoesNotMatchRegularExpression( '/data-ran-booster-policy-source="branch"[^>]*hidden/', $html );
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
			. '&amp;package=' . $managed_package_identifier;

		self::assertStringContainsString(
			'<a class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-solid" aria-describedby="ran-booster-link-installed-help" href="' . $manage_url . '">Manage ' . $package_view->get_type() . '</a>',
			$form
		);
		self::assertMatchesRegularExpression(
			'/name="ran_booster\[install_another\]" value="1"\s*>Install another ' . $package_view->get_type() . '<\/button>/',
			$form
		);
		self::assertStringNotContainsString( '>Install and add another</button>', $form );
		self::assertStringNotContainsString( '<button type="submit" class="ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-brand is-solid"', $form );
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
	public function test_provider_notice_wraps_original_content_only_when_unavailable(
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

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath   = new \DOMXPath( $document );
		$notices = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " notice-error ")][.//strong[normalize-space(.) = "Git provider unavailable."]]' );
		self::assertNotFalse( $notices );
		self::assertCount( $provider_available ? 0 : 1, $notices );
		if ( $provider_available ) {
			return;
		}
		$provider_notice = $notices->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $provider_notice );
		self::assertTrue( $provider_notice->hasAttribute( 'data-ran-booster-ui-notice' ) );
		self::assertSame( 'error', $provider_notice->getAttribute( 'data-ran-booster-ui-notice-intent' ) );
		self::assertFalse( $provider_notice->hasAttribute( 'role' ) );
		self::assertFalse( $provider_notice->hasAttribute( 'aria-live' ) );
		$content = $xpath->query( './div[@data-ran-booster-ui-notice-content]', $provider_notice );
		self::assertNotFalse( $content );
		self::assertCount( 1, $content );
		$notice_children = $xpath->query( './*', $provider_notice );
		self::assertNotFalse( $notice_children );
		// The static Notice description is followed only by its decorative error icon.
		self::assertCount( 2, $notice_children );
		self::assertSame( 1.0, $xpath->evaluate( 'count(./*[2][local-name()="svg" and @class="ran-wp-ui-notice__icon" and @aria-hidden="true"])', $provider_notice ) );
		$notice_content = $content->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $notice_content );
		self::assertSame( 'ran-wp-ui-text is-body-md ran-wp-ui-notice__description', $notice_content->getAttribute( 'class' ) );
		$paragraphs = $xpath->query( './p', $notice_content );
		self::assertNotFalse( $paragraphs );
		self::assertCount( 2, $paragraphs );
		$notice_text = $xpath->evaluate( 'string(.)', $notice_content );
		self::assertIsString( $notice_text );
		self::assertStringContainsString( 'Git provider unavailable.', $notice_text );
		self::assertStringContainsString( 'This package remains linked but cannot be edited or deployed until its Git provider is registered again.', $notice_text );
		self::assertStringContainsString( 'owner/example', $notice_text );
		self::assertStringContainsString( 'provider-id', $notice_text );
		self::assertStringContainsString( 'id="ran-booster-package-edit-form"', $html );
		self::assertStringContainsString( 'value="unlink-' . $package_view->get_type() . '"', $html );
		self::assertStringContainsString( 'name="ran_booster[confirm_package_removal]" value="1" required', $html );
		self::assertStringContainsString( 'name="_ran_booster_reinstall_nonce"', $html );
	}

	#[DataProvider( 'edit_view_matrix' )]
	public function test_edit_view_offers_matching_install_another_route_even_when_provider_unavailable(
		PackagePagePresenter $package_view,
		bool $provider_available,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite']   = $multisite;
		$GLOBALS['ran_booster_dashboard_test_multisite'] = $multisite;
		$package                          = $this->package( $package_view );
		$package_provider_settings        = $this->provider_settings( $provider_available );
		$development_environment_detected = true;

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'class="ran-booster-settings-section ran-booster-package-operation-settings ran-booster-card" data-ran-booster-card="package-edit" aria-labelledby="ran-booster-package-operation-heading"', $html );
		self::assertMatchesRegularExpression( '/<select[^>]*name="ran_booster\[deployment_policy\]"[^>]*form="ran-booster-package-edit-form"/', $html );
		self::assertMatchesRegularExpression( '/<button[^>]*form="ran-booster-package-edit-form"[^>]*data-ran-booster-package-settings-save/', $html );
		self::assertStringContainsString( 'hx-include="#ran-booster-package-edit-form, [form=&quot;ran-booster-package-edit-form&quot;]"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $this->form_by_id( $html, 'ran-booster-package-edit-form' ) );
		self::assertStringContainsString( 'name="_ran_booster_reinstall_nonce"', $this->form_by_id( $html, 'ran-booster-package-edit-form' ) );

		$base_url             = $multisite ? 'https://example.test/wp-admin/network/admin.php' : 'https://example.test/wp-admin/admin.php';
		$expected_install_url = $base_url
			. '?page=' . $package_view->get_create_page_slug()
			. '&amp;provider=gh&amp;open_picker=1';
		$install_another_link = '<a class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline" href="' . $expected_install_url . '">Install another ' . $package_view->get_type() . '</a>';

		self::assertStringNotContainsString( 'ran-booster-package-settings__install-another', $html );
		self::assertStringNotContainsString( '>Cancel</a>', $html );
		self::assertStringNotContainsString( 'class="ran-booster-package-settings__intro ran-booster-page-heading__description"', $html );
		self::assertStringNotContainsString( 'WordPress disabled', $html );
		self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Branch · main</p>', $html );
		if ( 1 !== preg_match( '/Current source<\/p>(.*?)<\/div>/s', $html, $current_source ) ) {
			self::fail( 'The Current source summary did not render.' );
		}
		// Heading value plus the repository line; no third source-state line.
		self::assertSame( 2, substr_count( $current_source[1], '<p ' ), $package_view->get_type() );
		self::assertStringNotContainsString( 'Included with Booster', $current_source[1] );
		self::assertStringNotContainsString( 'provider is unavailable', $current_source[1] );
		$save_actions = $this->action_group_by_class( $html, 'ran-booster-package-settings__save-actions' );
		self::assertStringContainsString( $install_another_link, $save_actions );
		// The Shell breadcrumb is the route back to the Managed list; the action bar no longer repeats it.
		self::assertStringNotContainsString( 'Back to Managed', $save_actions );
		$save_position            = strpos( $save_actions, 'data-ran-booster-package-settings-save' );
		$install_another_position = strpos( $save_actions, $install_another_link );
		self::assertIsInt( $save_position );
		self::assertIsInt( $install_another_position );
		self::assertLessThan( $install_another_position, $save_position );
		if ( ! $provider_available ) {
			self::assertStringContainsString( '<strong>Git provider unavailable.</strong>', $html );
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
			self::assertMatchesRegularExpression(
				'~<a href="https://github.com/owner/example" class="ran-booster-repository-link"[^>]*>Open repository<span class="screen-reader-text"> owner/example</span> <span aria-hidden="true">↗</span><span class="screen-reader-text"> \(opens in a new tab\)</span></a>~',
				$html,
				'The visible link text is Open repository; the repository name stays in the accessible name.'
			);
			$advanced_position = strpos( $html, '<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings ran-booster-disclosure"' );
			self::assertIsInt( $advanced_position );
			$advanced_end = strpos( $html, '</details>', $advanced_position );
			self::assertIsInt( $advanced_end );
			$readiness_position = strpos( $html, 'id="ran-booster-branch-readiness"', $advanced_position );
			self::assertIsInt( $readiness_position );
			$automation_position = strpos( $html, 'name="ran_booster[deployment_policy]"' );
			self::assertIsInt( $automation_position );
			$operation_position = strpos( $html, 'class="ran-booster-settings-section ran-booster-package-operation-settings ran-booster-card"' );
			self::assertIsInt( $operation_position );
			$label_position = strpos( $html, '<label for="ran-booster-deployment-policy">', $operation_position );
			self::assertIsInt( $label_position );
			$select_position = strpos( $html, 'name="ran_booster[deployment_policy]"', $label_position );
			self::assertIsInt( $select_position );
			$description_position = strpos( $html, 'Manual waits for an administrator. Automatic deploys on push.', $select_position );
			self::assertIsInt( $description_position );
			$notice_position = strpos( $html, 'data-ran-booster-local-development-warning', $description_position );
			self::assertIsInt( $notice_position );
			$guidance_position = strpos( $html, 'data-ran-booster-reinstall-guidance', $notice_position );
			self::assertIsInt( $guidance_position );
			$actions_position = strpos( $html, 'class="ran-booster-package-operation-settings__actions"', $guidance_position );
			self::assertIsInt( $actions_position );
			$reinstall_position = strpos( $html, 'data-ran-booster-settings-reinstall', $actions_position );
			self::assertIsInt( $reinstall_position );
			$operation_end = strpos( $html, '</section>', $operation_position );
			self::assertIsInt( $operation_end );
			$save_actions_position = strpos( $html, 'class="ran-wp-ui-card__content ran-booster-package-settings__save-actions"', $operation_end );
			self::assertIsInt( $save_actions_position );
			self::assertLessThan( $automation_position, $advanced_position );
			self::assertTrue( $advanced_position < $readiness_position );
			self::assertTrue( $readiness_position < $advanced_end );
			// Label, select, description, notice, guidance, then the action group last.
			self::assertTrue( $operation_position < $label_position );
			self::assertTrue( $label_position < $select_position );
			self::assertTrue( $select_position < $description_position );
			self::assertTrue( $description_position < $notice_position );
			self::assertTrue( $notice_position < $guidance_position );
			self::assertTrue( $guidance_position < $actions_position );
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
			self::assertStringContainsString( 'Deploy the saved branch, manually or on Push-to-Deploy.', $html );
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
		self::assertMatchesRegularExpression( '/Stop managing ' . preg_quote( $type, '/' ) . '\\s*<\\/button>/', $danger_zone );
		self::assertMatchesRegularExpression( '/Stop managing and delete ' . preg_quote( $type, '/' ) . '\\s*<\\/button>/', $danger_zone );
		self::assertStringContainsString(
			'plugin' === $type
				? 'Settings may be permanently removed, while incomplete cleanup may leave incompatible data. This is not a rollback.'
				: 'Active, parent and depended-on themes are protected.',
			$danger_zone
		);
	}

	public function test_source_warnings_keep_their_static_warning_envelopes(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package                   = $this->package( $package_view );
			$package_provider_settings = $this->provider_settings( true );
			$package_source            = array( 'unavailable' => true );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();
			self::assertMatchesRegularExpression( '/<div class="notice notice-warning inline ran-wp-ui-notice ran-wp-ui-box-sizing is-warning" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="warning">\s*<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>\s*<p>Booster will not reinterpret this package as a branch deployment\..*?<\/p>\s*<\/div>\s*<svg class="ran-wp-ui-notice__icon"[^>]*>.*?<\/svg>\s*<\/div>/s', $html );
		}
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
			$install_another_link = '<a class="ran-wp-ui-reset-a ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline" href="' . $base_url . '?page=' . $package_view->get_create_page_slug() . '&amp;provider=gh&amp;open_picker=1">Install another ' . $package_view->get_type() . '</a>';
			$actions              = $this->action_group_by_class( $html, 'ran-booster-settings-actions' );

			self::assertStringNotContainsString( 'data-ran-booster-package-settings-save', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'data-ran-booster-card="package-edit"', $html, $package_view->get_type() );
			self::assertStringContainsString( $install_another_link, $actions, $package_view->get_type() );
			self::assertStringNotContainsString( 'Back to Managed', $actions, $package_view->get_type() );
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
				'<details id="ran-booster-advanced-source-settings" class="ran-booster-settings-disclosure ran-booster-advanced-source-settings ran-booster-disclosure" data-ran-booster-ui-disclosure-fallback data-ran-booster-package-disclosure data-ran-booster-advanced-source-settings open',
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
			self::assertStringContainsString( '<input type="hidden" name="ran_booster[provider]" class="ran-booster-provider-input" value="gh">', $edit_form );
			self::assertStringContainsString( '<input type="hidden" name="ran_booster[repository]" value="owner/example">', $edit_form );
			self::assertStringNotContainsString( 'owner/attempted', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[branch\]"[^>]*value="attempted-branch"/', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[subdirectory\]"[^>]*value="attempted\/path"/', $html );
			self::assertMatchesRegularExpression( '/<option value="automatic"[^>]*selected="selected"/', $html );
			self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Branch · main</p>', $html );
			self::assertStringContainsString( '<p class="ran-booster-package-summary__value">Manual</p>', $html );
		}
	}

	public function test_installed_edit_locks_provider_and_repository_while_install_stays_editable(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$package                   = $this->package( $package_view );
			$package_provider_settings = $this->provider_settings( true );

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
			$html = (string) ob_get_clean();

			$edit_form = $this->form_by_id( $html, 'ran-booster-package-edit-form' );
			self::assertStringContainsString( '<input type="hidden" name="ran_booster[provider]" class="ran-booster-provider-input" value="gh">', $edit_form );
			self::assertStringContainsString( '<input type="hidden" name="ran_booster[repository]" value="owner/example">', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[provider_repository_id]" class="ran-booster-provider-repository-id-input" value="provider-id"', $edit_form );
			self::assertStringContainsString( 'name="ran_booster[provider_repository_identity_source]" class="ran-booster-provider-repository-identity-source-input" value="stored"', $edit_form );
			if ( 1 !== preg_match( '/<select id="ran-booster-provider"([^>]*)>(.*?)<\/select>/s', $edit_form, $provider ) ) {
				self::fail( 'The Edit form must render the Git provider select.' );
			}
			self::assertStringContainsString( 'name="ran_booster[provider]"', $provider[1] );
			self::assertStringContainsString( 'aria-describedby="ran-booster-repository-fixed-description"', $provider[1] );
			self::assertStringContainsString( 'disabled="disabled"', $provider[1] );
			self::assertStringNotContainsString( 'ran-booster-provider-input', $provider[1], 'Only the hidden provider input carries the script hook.' );
			self::assertMatchesRegularExpression( '/<option value="gh"[^>]*selected="selected"[^>]*>GitHub<\/option>/', $provider[2] );
			self::assertSame( 1, substr_count( $edit_form, 'id="ran-booster-provider"' ), 'The provider id renders once.' );
			self::assertSame( 1, substr_count( $edit_form, 'ran-booster-provider-input' ), 'Exactly one control carries the provider script hook.' );
			self::assertStringNotContainsString( 'id="ran-booster-provider-name"', $edit_form );

			if ( 1 !== preg_match( '/<input id="ran-booster-repository-name"([^>]*)>/', $edit_form, $repository ) ) {
				self::fail( 'The Edit form must render the repository input.' );
			}
			self::assertStringContainsString( 'name="ran_booster[repository]"', $repository[1] );
			self::assertStringContainsString( 'value="owner/example"', $repository[1] );
			self::assertMatchesRegularExpression( '/ readonly[ >]/', $repository[1] . ' ' );
			self::assertStringNotContainsString( 'disabled', $repository[1], 'The repository stays focusable and copyable.' );
			self::assertStringNotContainsString( ' required', $repository[1] );
			self::assertStringContainsString( 'aria-describedby="ran-booster-repository-fixed-description"', $repository[1] );
			self::assertSame( 1, substr_count( $edit_form, 'id="ran-booster-repository-name"' ) );
			if ( 1 !== preg_match( '/<button type="button" class="[^"]*ran-booster-open-repository-picker"([^>]*)>Pick (?:Plugin|Theme) repository<\/button>/', $edit_form, $picker ) ) {
				self::fail( 'The Edit form must render the picker button.' );
			}
			self::assertStringContainsString( 'disabled="disabled"', $picker[1] );
			self::assertStringContainsString( 'data-ran-booster-repository-locked', $picker[1] );
			self::assertStringNotContainsString( 'repository-link', $edit_form );
			self::assertStringNotContainsString( 'target="_blank"', $this->fieldset_html( $edit_form ), 'The card no longer links the repository.' );

			$notice = '<div id="ran-booster-repository-fixed-description" class="notice notice-info inline ran-booster-settings-fields__notice ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p>Provider and repository can&#039;t be changed after install.</p></div><svg class="ran-wp-ui-notice__icon"';
			self::assertSame( 1, substr_count( $edit_form, $notice ), 'The fixed-repository info Notice renders once.' );
			self::assertStringNotContainsString( 'can&#039;t change after install', $edit_form );
			self::assertStringNotContainsString( 'can\'t change after install', $edit_form );
			self::assertSame( 1, substr_count( $edit_form, 'id="ran-booster-repository-fixed-description"' ) );
			$fieldset = $this->fieldset_html( $edit_form );
			self::assertGreaterThan( (int) strpos( $fieldset, 'id="ran-booster-repository-name"' ), (int) strpos( $fieldset, 'id="ran-booster-repository-fixed-description"' ), 'The Notice follows the repository field.' );
			self::assertMatchesRegularExpression( '~' . preg_quote( $notice, '~' ) . '.*</div>\s*</div>\s*$~s', $fieldset, 'The Notice ends the fieldset.' );
			self::assertStringNotContainsString( 'Only install Git provider integrations you trust', $html );
			self::assertStringNotContainsString( 'class="description">The Git provider', $html );

			self::assertMatchesRegularExpression( '/name="ran_booster\[credential_id\]"(?![^>]*disabled)[^>]*>/', $edit_form );
			self::assertMatchesRegularExpression( '/name="ran_booster\[branch\]"(?![^>]*disabled)[^>]*form="ran-booster-package-edit-form"/', $html );
			self::assertMatchesRegularExpression( '/name="ran_booster\[deployment_policy\]"(?![^>]*disabled)[^>]*>/', $html );

			// The Install page renders in its own scope so no Edit bindings leak into it.
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The included production view reads these parameters as local template variables.
			$create = ( static function ( PackagePagePresenter $package_view, array $package_provider_settings ): string {
				$explicit_provider      = false;
				$open_repository_picker = false;
				ob_start();
				require dirname( __DIR__, 2 ) . '/views/packages/create.php';

				return (string) ob_get_clean();
			} )( $package_view, $package_provider_settings );

			$create_form = $this->form_by_id( $create, 'ran-booster-package-create-form' );
			self::assertMatchesRegularExpression( '/<select id="ran-booster-provider" name="ran_booster\[provider\]" class="ran-booster-provider-input" >/', $create_form );
			self::assertStringContainsString( 'Only install Git provider integrations you trust', $create_form );
			self::assertMatchesRegularExpression( '/<select id="ran-booster-provider"(?![^>]*disabled)[^>]*>/', $create_form );
			self::assertMatchesRegularExpression( '/ran-booster-open-repository-picker"(?![^>]*disabled)[^>]*>/', $create_form );
			self::assertStringNotContainsString( ' readonly', $create_form );
			self::assertStringNotContainsString( 'data-ran-booster-repository-locked', $create_form );
			self::assertMatchesRegularExpression( '/<input id="ran-booster-repository-name" name="ran_booster\[repository\]"[^>]*required>/', $create_form );
			self::assertMatchesRegularExpression( '/class="[^"]*ran-booster-open-repository-picker"[^>]*>[^<]+<\/button>/', $create_form );
			self::assertStringNotContainsString( 'ran-booster-open-repository-picker" data-package-type="' . $package_view->get_type() . '"  disabled', $create_form );
			self::assertStringNotContainsString( 'ran-booster-repository-fixed-description', $create );
			self::assertStringNotContainsString( '<input type="hidden" name="ran_booster[provider]"', $create_form );
		}
	}

	public function test_edit_source_choices_use_in_place_navigation_with_anchored_fallback(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			foreach ( array( 'branch', 'release_asset' ) as $package_source_view ) {
				$identifier_value       = 'plugin' === $package_view->get_type() ? 'example/example.php' : 'example-theme';
				$package_source_mode    = 'edit';
				$package_return_args    = array(
					'return_type'     => $package_view->get_type(),
					'return_provider' => 'gh',
					'return_s'        => 'Rock&provider=bb&page=other#frag+more',
				);
				$package_current_source = 'branch' === $package_source_view ? 'release_asset' : 'branch';
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
				self::assertMatchesRegularExpression( '/hx-get="[^"]*return_type=' . $package_view->get_type() . '&amp;return_provider=gh&amp;return_s=Rock%26provider%3Dbb%26page%3Dother%23frag%2Bmore"/', $html );
				self::assertStringContainsString( '>Branch</strong>', $html, $package_view->get_type() );
				self::assertStringContainsString( '>Releases</strong>', $html, $package_view->get_type() );
				self::assertStringContainsString( '<legend class="screen-reader-text">Update source</legend>', $html, $package_view->get_type() );
				self::assertStringContainsString( '<h3 id="ran-booster-package-source-heading" class="ran-booster-section__title">Update source</h3>', $html, $package_view->get_type() );
				self::assertStringContainsString( '<div class="ran-booster-package-source__header">', $html, $package_view->get_type() );
				self::assertStringNotContainsString( '<header', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'aria-labelledby="ran-booster-package-source-heading"', $html, $package_view->get_type() );
				self::assertStringContainsString( 'ran-booster-package-source--navigation', $html, $package_view->get_type() );
				self::assertStringContainsString( 'ran-booster-source-choices--navigation ran-booster-provider-task-tabs', $html, $package_view->get_type() );
				self::assertStringContainsString( '<ul class="filter-links ran-section-navigation ran-section-navigation--thin">', $html, $package_view->get_type() );
				self::assertSame( 2, substr_count( $html, 'ran-booster-provider-task-tab ran-section-navigation__link' ), $package_view->get_type() );
				self::assertSame( 2, substr_count( $html, '<li>' ), $package_view->get_type() );
				self::assertStringNotContainsString( 'nav-tab', $html, $package_view->get_type() );
				self::assertSame( 1, substr_count( $html, ' current"' ), $package_view->get_type() );
				self::assertStringContainsString( 'role="navigation" aria-label="Update source settings"', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'Viewing settings does not change the update source.', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'Selecting it does not install the package.', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'aria-controls=', $html, $package_view->get_type() );
				// The method in use gets the shared dot with its hidden label and no text badge.
				self::assertStringNotContainsString( 'ran-booster-source-choice__current-source', $html, $package_view->get_type() );
				self::assertStringNotContainsString( '>Active<', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'ran-wp-ui-badge', $html, $package_view->get_type() );
				self::assertSame( 1, substr_count( $html, 'ran-booster-provider-task-tab__source-indicator' ), $package_view->get_type() );
				self::assertSame( 1, substr_count( $html, '<span class="screen-reader-text">In use</span>' ), $package_view->get_type() );
				self::assertMatchesRegularExpression(
					'/data-ran-booster-source-choice="' . $package_current_source . '"[^>]*>[\\s\\S]*?<span class="ran-booster-provider-task-tab__source-indicator" title="In use" aria-hidden="true"><\\/span><span class="screen-reader-text">In use<\\/span>/',
					$html,
					$package_view->get_type()
				);
				self::assertStringContainsString( 'ran-booster-source-choice__content', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'Current source', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'Branch description', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'Releases meta', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'ran-booster-source-choice__radio', $html, $package_view->get_type() );
				self::assertStringNotContainsString( 'ran-booster-source-choice__navigation-cue', $html, $package_view->get_type() );
				self::assertMatchesRegularExpression(
					'/<span(?=[^>]*aria-current="page")(?=[^>]*tabindex="-1")(?=[^>]*data-ran-booster-source-choice="' . $package_source_view . '")[^>]*>/',
					$html,
					$package_view->get_type()
				);
			}
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
				'url'               => '',
				'disabled'          => false,
				'client_hydratable' => false,
			),
			'release_asset' => array(
				'heading'           => 'Published releases',
				'description'       => 'Published releases require the repository root.',
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
		self::assertStringNotContainsString( 'aria-controls=', $html );
		self::assertStringNotContainsString( ' disabled=', $html );
	}

	public function test_edit_source_tabs_keep_a_disabled_releases_tab_visible_with_its_reason(): void {
		$package_view           = PackagePagePresenter::plugin();
		$package_source_mode    = 'edit';
		$package_source_view    = 'branch';
		$package_current_source = 'branch';
		$package_source_choices = array(
			'branch'        => array(
				'heading'           => 'Branch',
				'description'       => 'Deploy the configured branch.',
				'url'               => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&source_view=branch',
				'disabled'          => false,
				'client_hydratable' => false,
			),
			'release_asset' => array(
				'heading'           => 'Releases',
				'description'       => 'Releases need the repository root.',
				'url'               => 'https://example.test/wp-admin/admin.php?page=ran-booster-plugins&source_view=release_asset',
				'disabled'          => true,
				'client_hydratable' => false,
			),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
		$html = (string) ob_get_clean();

		if ( 1 !== preg_match( '/<button id="ran-booster-source-tab-release_asset"[^>]*>/', $html, $tab ) ) {
			self::fail( 'The disabled Releases tab must render as a button.' );
		}
		self::assertStringContainsString( 'aria-disabled="true"', $tab[0] );
		self::assertStringContainsString( 'title="Releases need the repository root."', $tab[0] );
		self::assertStringContainsString( 'is-disabled', $tab[0] );
		self::assertStringContainsString( 'ran-booster-provider-task-tab ran-section-navigation__link', $tab[0] );
		self::assertStringNotContainsString( ' disabled=', $tab[0] );
		self::assertStringContainsString( '>Releases</strong>', $html );
		// Only the active method carries the dot; the disabled tab does not.
		self::assertSame( 1, substr_count( $html, 'ran-booster-provider-task-tab__source-indicator' ) );
	}

	public function test_add_source_cards_markup_is_unchanged_by_the_shared_tab_strip(): void {
		$package_view           = PackagePagePresenter::plugin();
		$package_source_mode    = 'create';
		$package_source_view    = 'branch';
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
				'url'               => '',
				'disabled'          => 'release_asset' === $source_key,
				'client_hydratable' => 'branch' === $source_key,
			);
		}

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
		$html = (string) ob_get_clean();

		$expected = file_get_contents( dirname( __DIR__ ) . '/fixtures/source-choices-create.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a repository fixture in a test.
		self::assertIsString( $expected );
		// Indentation is template whitespace; every tag, attribute and text node is pinned.
		self::assertSame( preg_replace( '/\s+/', ' ', $expected ), preg_replace( '/\s+/', ' ', $html ) );
		self::assertStringNotContainsString( 'ran-section-navigation', $html );
		self::assertStringNotContainsString( 'ran-booster-provider-task-tab', $html );
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function source_card_state_provider(): array {
		$states = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'create', 'edit' ) as $mode ) {
				foreach ( array( false, true ) as $fallback ) {
					$states[ $type . ' ' . $mode . ( $fallback ? ' fallback' : ' default' ) ] = array( $type, $mode, $fallback );
				}
			}
		}

		return $states;
	}

	#[DataProvider( 'source_card_state_provider' )]
	public function test_source_cards_render_no_meta_line_in_any_context( string $type, string $mode, bool $fallback ): void {
		$package_source_mode = $mode;
		$package_view        = 'plugin' === $type ? PackagePagePresenter::plugin() : PackagePagePresenter::theme();
		$page_url            = 'https://example.test/wp-admin/admin.php?page=' . $package_view->get_page_slug();

		$package_source_choices = $package_view->get_source_choice_defaults( $page_url, $fallback );
		foreach ( $package_source_choices as $choice ) {
			self::assertFalse( array_key_exists( 'meta', $choice ), 'The projection carries no meta key.' );
		}
		// A legacy filter that still supplies meta must not bring the line back.
		foreach ( $package_source_choices as $key => $choice ) {
			$package_source_choices[ $key ]['meta'] = 'Legacy meta text';
		}
		$package_source_choices['release_asset']['disabled'] = ! $fallback;
		$package_source_view                                 = 'branch';
		$package_current_source                              = 'branch';
		$identifier_value                                    = 'plugin' === $type ? 'example/example.php' : 'example-theme';

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Releases</strong>', $html );
		self::assertStringNotContainsString( 'signed push', $html );
		self::assertStringNotContainsString( 'through WordPress', $html );
		self::assertStringNotContainsString( 'Deploy when new packages are released.', $html, 'The pane strapline never doubles the card sub-lines.' );
		if ( 'create' === $mode ) {
			self::assertStringContainsString( 'Deploy the saved branch, manually or on Push-to-Deploy.', $html );
			if ( ! $fallback ) {
				self::assertStringContainsString( 'Deploy published releases.', $html );
			}
		}
		self::assertStringNotContainsString( 'data-ran-booster-source-meta', $html );
		self::assertStringNotContainsString( 'ran-booster-source-choice__meta', $html );
		self::assertStringNotContainsString( 'Legacy meta text', $html );
		self::assertStringNotContainsString( 'Included with Booster', $html );
		self::assertStringNotContainsString( 'Choose repository first', $html );
		self::assertStringNotContainsString( 'Git provider capability required', $html );
	}

	public function test_unavailable_edit_source_choice_does_not_reference_a_missing_pane(): void {
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $package_view ) {
			$identifier_value       = 'plugin' === $package_view->get_type() ? 'example/example.php' : 'example-theme';
			$package_source_mode    = 'edit';
			$package_source_view    = 'branch';
			$package_current_source = 'branch';
			$package_source_choices = array(
				'branch'        => array(
					'heading'           => 'Branch',
					'description'       => 'Deploy the configured branch.',
					'url'               => 'https://example.test/wp-admin/admin.php?page=' . $package_view->get_page_slug() . '&source_view=branch',
					'disabled'          => false,
					'client_hydratable' => false,
				),
				'release_asset' => array(
					'heading'           => 'Releases',
					'description'       => 'Published releases are unavailable.',
					'url'               => '',
					'disabled'          => true,
					'client_hydratable' => false,
				),
			);

			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/source-choices.php';
			$html = (string) ob_get_clean();

			self::assertStringContainsString( 'aria-disabled="true"', $html, $package_view->get_type() );
			self::assertStringNotContainsString( 'aria-controls=', $html, $package_view->get_type() );
			self::assertMatchesRegularExpression(
				'/<span(?=[^>]*aria-current="page")(?=[^>]*tabindex="-1")(?=[^>]*data-ran-booster-source-choice="branch")[^>]*>/',
				$html,
				$package_view->get_type()
			);
		}
	}

	/** @return list<array{PackagePagePresenter, PackageSource, bool}> */
	public static function operation_card_matrix(): array {
		$rows = array();
		foreach ( array( PackagePagePresenter::plugin(), PackagePagePresenter::theme() ) as $view ) {
			foreach ( array( PackageSource::BRANCH, PackageSource::RELEASE_ASSET ) as $source ) {
				foreach ( 'plugin' === $view->get_type() ? array( true, false ) : array( false ) as $active ) {
					$rows[] = array( $view, $source, $active );
				}
			}
		}

		return $rows;
	}

	#[DataProvider( 'operation_card_matrix' )]
	public function test_package_operation_card_is_symmetrical_across_sources(
		PackagePagePresenter $package_view,
		PackageSource $current_source,
		bool $already_active
	): void {
		$package = $this->package( $package_view );
		$package->set_source( $current_source, 2 );
		$package_provider_settings                  = $this->provider_settings( true );
		$package_source                             = array(
			'current'           => $current_source->value,
			'selected'          => $current_source->value,
			'unavailable'       => false,
			'advanced_sections' => array(),
		);
		$development_environment_detected           = true;
		$GLOBALS['ran_booster_bulk_active_plugins'] = $already_active && 'plugin' === $package_view->get_type() ? array( 'example/example.php' ) : array();
		// The activation link is capability-gated; grant it explicitly instead of relying on whichever capability double loaded first.
		$had_capabilities                         = array_key_exists( 'ran_booster_test_capabilities', $GLOBALS );
		$previous_capabilities                    = $GLOBALS['ran_booster_test_capabilities'] ?? null;
		$GLOBALS['ran_booster_test_capabilities'] = array(
			'activate_plugins'      => true,
			'switch_themes'         => true,
			'manage_network_themes' => true,
		);
		$GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_operation_actions'] = array(
			static function ( string $current, string $selected ): void {
				if ( 'release_asset' === $current && 'release_asset' === $selected ) {
					echo '<a class="disabled" aria-disabled="true" tabindex="-1" data-ran-booster-managed-release-native-update>Update now</a>';
				}
			},
		);

		ob_start();
		try {
			require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		} finally {
			$html = (string) ob_get_clean();
			unset( $GLOBALS['ran_booster_bulk_active_plugins'], $GLOBALS['ran_booster_admin_view_actions']['ran_booster_admin_package_operation_actions'] );
			if ( $had_capabilities ) {
				$GLOBALS['ran_booster_test_capabilities'] = $previous_capabilities;
			} else {
				unset( $GLOBALS['ran_booster_test_capabilities'] );
			}
		}

		$operation_start = strpos( $html, 'ran-booster-package-operation-settings ran-booster-card' );
		self::assertIsInt( $operation_start );
		$operation_end = strpos( $html, '</section>', $operation_start );
		self::assertIsInt( $operation_end );
		$card = substr( $html, $operation_start, $operation_end - $operation_start );

		$branch  = PackageSource::BRANCH === $current_source;
		$buttons = array();
		if ( ! $already_active ) {
			$buttons[] = 'plugin' === $package_view->get_type() ? '>Activate plugin</a>' : '>Activate theme</a>';
		}
		$buttons[] = $branch ? 'data-ran-booster-settings-reinstall' : 'data-ran-booster-managed-release-native-update';

		$needles = array(
			'<label for="ran-booster-deployment-policy">',
			'name="ran_booster[deployment_policy]"',
			$branch ? 'Automatic deploys on push.' : 'Automatic installs validated releases.',
			'data-ran-booster-local-development-warning',
		);
		if ( $branch ) {
			$needles[] = 'data-ran-booster-reinstall-guidance';
		}
		$needles[] = 'class="ran-booster-package-operation-settings__actions" role="group" aria-label="Package operations"';
		$previous  = -1;
		foreach ( array_merge( $needles, $buttons ) as $needle ) {
			$position = strpos( $card, $needle );
			if ( false === $position ) {
				self::fail( 'Missing in Package operation card: ' . $needle );
			}
			self::assertGreaterThan( $previous, $position, $needle );
			$previous = $position;
		}
		self::assertSame( 1, substr_count( $card, 'ran-booster-package-operation-settings__actions' ) );
		self::assertSame( count( $buttons ), preg_match_all( '/<(?:a|button)\b/', (string) preg_replace( '/^.*?class="ran-booster-package-operation-settings__actions".*?>/s', '', $card, 1 ) ) );
		// The action group closes the card body; nothing follows it inside the Updates field.
		self::assertMatchesRegularExpression( '/<\/(?:a|button)>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>\s*$/', $card );
		// Branch packages keep Reinstall out of the Releases card and vice versa.
		self::assertSame( $branch, str_contains( $card, 'data-ran-booster-settings-reinstall' ) );
		self::assertSame( ! $branch, str_contains( $card, 'data-ran-booster-managed-release-native-update' ) );
	}

	public function test_package_operation_card_renders_no_empty_action_group(): void {
		$package_view = PackagePagePresenter::plugin();
		$package      = $this->package( $package_view );
		$package->set_source( PackageSource::RELEASE_ASSET, 2 );
		$package_provider_settings                  = $this->provider_settings( true );
		$package_source                             = array(
			'current'           => 'release_asset',
			'selected'          => 'release_asset',
			'unavailable'       => false,
			'advanced_sections' => array(),
		);
		$GLOBALS['ran_booster_bulk_active_plugins'] = array( 'example/example.php' );

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/edit.php';
		$html = (string) ob_get_clean();
		unset( $GLOBALS['ran_booster_bulk_active_plugins'] );

		// Plugin already active and no add-on action: no group wrapper at all.
		self::assertStringContainsString( 'name="ran_booster[deployment_policy]"', $html );
		self::assertStringNotContainsString( 'ran-booster-package-operation-settings__actions', $html );
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
			self::assertStringContainsString( 'data-ran-booster-card="package-edit"', $html, $package_view->get_type() );

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
			self::assertMatchesRegularExpression( '/id="ran-booster-source-pane-branch"[^>]*role="group"[^>]*aria-labelledby="ran-booster-source-tab-branch-heading"/', $html, $package_view->get_type() );
			self::assertSame( 1, substr_count( $html, 'id="ran-booster-source-tab-branch-heading"' ), $package_view->get_type() );
			self::assertMatchesRegularExpression( '/<strong id="ran-booster-source-tab-branch-heading" data-ran-booster-source-heading>Branch<\/strong>/', $html, $package_view->get_type() );
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

	#[DataProvider( 'managed_package_context_matrix' )]
	public function test_package_lists_do_not_offer_management_removal_without_asettings_read_failure(
		PackagePagePresenter $package_view,
		string $managed_package_identifier,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite'] = $multisite;
		self::assertSame( $managed_package_identifier, $this->package( $package_view )->get_identifier() );
		foreach ( array( 'empty', 'populated', 'filtered', 'no-providers' ) as $state ) {
			$packages           = 'populated' === $state ? array( $this->package( $package_view ) ) : array();
			$package_providers  = 'no-providers' === $state ? array() : $this->provider_settings( true )['providers'];
			$package_list_total = 'filtered' === $state ? 1 : count( $packages );
			$package_list_state = array(
				'search'   => 'filtered' === $state ? 'missing' : '',
				'provider' => '',
				'source'   => '',
				'policy'   => '',
			);
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			$html = (string) ob_get_clean();

			self::assertStringNotContainsString( 'Recover package management', $html, $state );
			self::assertStringNotContainsString( 'recovery=1', $html, $state );
			self::assertStringNotContainsString( 'Review management removal', $html, $state );
		}
	}

	#[DataProvider( 'managed_package_context_matrix' )]
	public function test_unidentified_inventory_failure_never_offers_an_installed_package_picker(
		PackagePagePresenter $package_view,
		string $managed_package_identifier,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite'] = $multisite;
		$packages                                      = array();
		$package_providers                             = array();
		$package_list_unavailable                      = true;
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( $package_view->get_plural_label(), $html );
		self::assertStringContainsString( 'Package settings could not be loaded.', $html );
		self::assertStringNotContainsString( 'Add your first', $html );
		self::assertStringNotContainsString( 'recovery=1', $html );
		self::assertStringNotContainsString( 'Review management removal', $html );
		self::assertStringNotContainsString( $managed_package_identifier, $html );
	}

	#[DataProvider( 'managed_package_context_matrix' )]
	public function test_damaged_package_confirmation_is_exact_and_has_no_unrelated_choices(
		PackagePagePresenter $package_view,
		string $managed_package_identifier,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite'] = $multisite;
		$recovery_identifier                           = $managed_package_identifier;
		$recovery_name                                 = 'Example <package> ' . str_repeat( 'long-package-name-', 12 );
		$recovery_reason                               = 'The saved update revision is invalid.';
		$recovery_state                                = 'damaged';
		$recovery_blocker                              = null;
		$recovery_confirmation                         = array(
			'id'               => '42',
			'package'          => $managed_package_identifier,
			'type'             => 'plugin' === $package_view->get_type() ? 1 : 2,
			'source'           => 'branch',
			'adoption_token'   => 'lifetime-token',
			'digest'           => 'record-digest',
			'installed_digest' => 'installed-digest',
		);
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/recovery.php';
		$html = (string) ob_get_clean();

		self::assertStringContainsString( esc_html( $recovery_name ), $html );
		self::assertStringContainsString( $recovery_reason, $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="recover-' . $package_view->get_type() . '"', $html );
		self::assertStringContainsString( 'name="ran_booster[identifier]" value="' . $recovery_identifier . '"', $html );
		self::assertStringContainsString( 'name="ran_booster[confirm_package_removal]" value="1" required', $html );
		foreach ( array( 'adoption_token', 'digest', 'installed_digest' ) as $field ) {
			self::assertStringContainsString( 'name="ran_booster[' . $field . ']" value="' . $recovery_confirmation[ $field ] . '"', $html );
		}
		self::assertStringContainsString(
			'name="_wpnonce" value="' . \RAN\Admin\PackageAdminController::recovery_action( $package_view->get_type(), $recovery_identifier, 'lifetime-token', 'record-digest', 'installed-digest' ) . '"',
			$html
		);
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			self::assertTrue( $document->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' ) );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$xpath             = new \DOMXPath( $document );
		$padded_task       = $xpath->query( '//div[@class="ran-booster-package-recovery"]/h2/following-sibling::section[contains(@class,"ran-booster-settings-section__body")]//form[@method="post"]' );
		$forms             = $xpath->query( '//form[@method="post"]' );
		$unrelated_choices = $xpath->query( '//select|//form[@method="get"]' );
		self::assertInstanceOf( \DOMNodeList::class, $forms );
		self::assertInstanceOf( \DOMNodeList::class, $unrelated_choices );
		self::assertInstanceOf( \DOMNodeList::class, $padded_task );
		self::assertSame( 1, $padded_task->length );
		self::assertSame( 1, $forms->length );
		self::assertSame( 0, $unrelated_choices->length );
		self::assertStringNotContainsString( 'relink=', $html );
		self::assertStringNotContainsString( 'Link this installed package', $html );
		self::assertStringNotContainsString( 'Recover package management', $html );
	}

	#[DataProvider( 'managed_package_context_matrix' )]
	public function test_healthy_blocked_and_unidentified_recovery_states_offer_no_removal_form(
		PackagePagePresenter $package_view,
		string $managed_package_identifier,
		bool $multisite
	): void {
		$GLOBALS['ran_booster_package_view_multisite'] = $multisite;
		foreach ( array( 'healthy', 'blocked', 'unknown' ) as $recovery_state ) {
			$recovery_identifier   = 'unknown' === $recovery_state ? '' : $managed_package_identifier;
			$recovery_name         = 'Example package ' . str_repeat( 'long-package-name-', 12 );
			$recovery_reason       = 'A safely bounded <explanation>.';
			$recovery_confirmation = null;
			$recovery_blocker      = null;
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/recovery.php';
			$html = (string) ob_get_clean();

			self::assertStringNotContainsString( 'Back to Managed ' . $package_view->get_plural_label(), $html, $recovery_state );
			self::assertStringNotContainsString( 'ran-booster-package-settings__back', $html, $recovery_state );
			self::assertStringContainsString( esc_html( $recovery_name ), $html, $recovery_state );
			self::assertStringNotContainsString( '<form', $html, $recovery_state );
			self::assertStringNotContainsString( '<select', $html, $recovery_state );
			self::assertStringNotContainsString( 'relink=', $html, $recovery_state );
			self::assertStringNotContainsString( 'name="ran_booster[action]"', $html, $recovery_state );
			self::assertSame( 'healthy' === $recovery_state, str_contains( $html, 'Open package settings' ), $recovery_state );
			if ( 'healthy' === $recovery_state ) {
				self::assertStringContainsString( 'package=' . $managed_package_identifier, $html );
			} else {
				self::assertStringContainsString( 'A safely bounded &lt;explanation&gt;.', $html, $recovery_state );
			}
		}
	}

	public function test_package_badges_render_known_activity_and_bounded_extension_tones(): void {
		$package_view = PackagePagePresenter::plugin();
		$package      = $this->package( $package_view );
		$package->set_deployment_policy( \RAN\Deployment\DeploymentPolicy::AUTOMATIC );
		$render = static function ( ?\RAN\Deployment\DeploymentAttempt $attempt, array $badges = array() ) use ( $package, $package_view ): string {
			$packages               = array( $package );
			$package_providers      = array(
				array(
					'code'   => 'gh',
					'label'  => 'GitHub',
					'deploy' => true,
				),
			);
			$package_activity       = array( 'items' => array( $package->get_identifier() => array( 'latest' => $attempt ) ) );
			$package_extension_rows = array( $package->get_identifier() => array( 'badges' => $badges ) );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/index.php';
			return (string) ob_get_clean();
		};

		foreach ( array(
			'queued'          => array( 'Queued', 'pending', 'informational' ),
			'running'         => array( 'Running', 'pending', 'informational' ),
			'succeeded'       => array( 'Succeeded', 'ok', 'stable' ),
			'failed'          => array( 'Failed', 'error', 'high' ),
			'needs_attention' => array( 'Needs attention', 'error', 'high' ),
		) as $state => [ $label, $tone, $intent ] ) {
			$terminal = in_array( $state, array( 'succeeded', 'failed', 'needs_attention' ), true );
			$attempt  = \RAN\Deployment\DeploymentAttempt::from_database(
				array(
					'id'                      => 1,
					'correlation_id'          => str_repeat( '0', 32 ),
					'source'                  => 'manual',
					'operation'               => 'update',
					'package_type'            => 'plugin',
					'package_slug'            => 'example',
					'package_source'          => 'branch',
					'package_source_revision' => 1,
					'release_identity'        => null,
					'provider'                => 'gh',
					'provider_repository_id'  => 'provider-id',
					'requested_ref'           => 'main',
					'resolved_ref'            => null,
					'delivery_id'             => null,
					'delivery_digest'         => null,
					'state'                   => $state,
					'mutation_started_at'     => null,
					'outcome_code'            => match ( $state ) {
					'succeeded' => 'deployed', 'failed' => 'preflight_failed', 'needs_attention' => 'interrupted', default => null },
					'request_json'            => '{"repository":"owner/example","credential_id":null,"private":false,"configured_branch":"main","package_slug":"example","subdirectory":null,"deployment_policy":"automatic","initiating_user_id":1,"maximum_artifact_bytes":52428800}',
					'created_at'              => '2026-07-19 00:00:00',
					'finished_at'             => $terminal ? '2026-07-19 00:00:00' : null,
					'resolved_at'             => null,
					'resolved_by'             => null,
				)
			);
			if ( 'running' === $state ) {
				$GLOBALS['ran_booster_package_view_translations']['ran-booster']['Running'] = 'Running & "review"';
				$label = 'Running &amp; &quot;review&quot;';
			}
			$html = $render( $attempt );
			unset( $GLOBALS['ran_booster_package_view_translations'] );
			self::assertStringContainsString( 'is-' . $intent . '-intent ran-booster-deployment-state ran-booster-deployment-state--' . $state . '" data-ran-booster-badge data-ran-booster-badge-intent="' . $intent . '" data-ran-booster-activity-state', $html );
			self::assertStringContainsString( '>' . $label . '</span>', $html );
			if ( 'succeeded' !== $state ) {
				self::assertStringContainsString( 'is-' . $intent . '-intent" data-ran-booster-badge data-ran-booster-badge-intent="' . $intent . '" data-ran-booster-activity-badge>' . $label . '</span>', $html );
			}
		}

		foreach ( array(
			'neutral' => 'none',
			'ok'      => 'stable',
			'pending' => 'informational',
			'warning' => 'medium',
			'error'   => 'high',
		) as $tone => $intent ) {
			$html = $render(
				null,
				array(
					array(
						'label' => 'Release & "review"',
						'tone'  => $tone,
					),
				)
			);
			self::assertStringContainsString( '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-' . $intent . '-intent" data-ran-booster-badge data-ran-booster-badge-intent="' . $intent . '">Release &amp; &quot;review&quot;</span>', $html );
		}
		self::assertStringContainsString( '<span class="ran-wp-ui-text is-body-sm ran-wp-ui-badge is-none-intent" data-ran-booster-badge data-ran-booster-badge-intent="none" data-ran-booster-activity-badge hidden></span>', $render( null ) );
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
					'credentials_url'       => $available ? 'admin.php?page=ran-booster&tab=gh&view=credentials' : '',
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
		$start = strpos( $html, '<details id="ran-booster-package-danger-zone" class="ran-booster-settings-disclosure ran-booster-package-danger-zone ran-booster-disclosure"' );
		self::assertNotFalse( $start, 'The package settings page should include the danger zone.' );

		return substr( $html, $start );
	}

	private function form_by_id( string $html, string $id ): string {
		self::assertMatchesRegularExpression( '/<form\s+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html );
		preg_match( '/<form\s+id="' . preg_quote( $id, '/' ) . '".*?<\/form>/s', $html, $matches );

		self::assertTrue( array_key_exists( 0, $matches ) );

		return $matches[0];
	}


	private function fieldset_html( string $html ): string {
		if ( 1 !== preg_match( '/<fieldset[^>]*>(.*?)<\/fieldset>/s', $html, $matches ) ) {
			self::fail( 'The form must render the repository configuration fieldset.' );
		}

		return trim( $matches[1] );
	}

	private function action_group_by_class( string $html, string $css_class ): string {
		self::assertMatchesRegularExpression( '/<div[^>]*class="[^"]*' . preg_quote( $css_class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/s', $html );
		preg_match( '/<div[^>]*class="[^"]*' . preg_quote( $css_class, '/' ) . '[^"]*"[^>]*>.*?<\/div>/s', $html, $matches );

		self::assertTrue( array_key_exists( 0, $matches ) );

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
