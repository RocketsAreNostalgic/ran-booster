<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';
require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__, 2 ) . '/RAN/Admin/Component/AdminActionRenderer.php';

use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;
use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\Plugin;

final class PackageIndexWorkingCopyBadgeTest extends TestCase {
	public function test_working_copy_badge_renders_beside_paused_on_updates_line_with_label_only(): void {
		$packages               = array(
			$this->plugin( 'working/working.php', 'Working Plugin' ),
			$this->plugin( 'clean/clean.php', 'Clean Plugin' ),
		);
		$package_providers      = array(
			array(
				'code'                => 'gh',
				'label'               => 'GitHub',
				'deploy'              => true,
				'credential_profiles' => array(),
			),
		);
		$package_view           = PackagePagePresenter::plugin();
		$package_paused         = array( 'working/working.php' );
		$package_working_copies = array( 'working/working.php' );
		$package_activity       = array(
			'items'       => array(),
			'unavailable' => false,
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/packages/index.php';
		$html = (string) ob_get_clean();

		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new \DOMXPath( $document );

		$rows = '//tr[contains(@class, "ran-booster-package-row--primary")]';
		self::assertSame( 2.0, $xpath->evaluate( 'count(' . $rows . '//p[contains(@class, "ran-booster-package-row__update-state")])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count((' . $rows . ')[1]//p[contains(@class, "ran-booster-package-row__update-state")]/*[contains(@class, "ran-wp-ui-badge")][1][normalize-space()="Manual"])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count((' . $rows . ')[1]//p[contains(@class, "ran-booster-package-row__update-state")]/*[contains(@class, "ran-wp-ui-badge")][2][normalize-space()="Paused" and contains(@class, "is-medium-intent")])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count((' . $rows . ')[1]//p[contains(@class, "ran-booster-package-row__update-state")]/*[contains(@class, "ran-wp-ui-badge")][3][normalize-space()="Working copy" and contains(@class, "is-medium-intent")])' ) );
		self::assertSame( 1.0, $xpath->evaluate( 'count((' . $rows . ')[2]//p[contains(@class, "ran-booster-package-row__update-state")]/*[contains(@class, "ran-wp-ui-badge")])' ) );
		self::assertSame( 1, substr_count( $html, 'Working copy' ) );
	}

	private function plugin( string $identifier, string $name ): Plugin {
		$plugin = Plugin::from_wp_array(
			$identifier,
			array_fill_keys( array( 'PluginURI', 'Version', 'Description', 'Author', 'AuthorURI', 'TextDomain', 'DomainPath', 'Title', 'AuthorName' ), '' )
				+ array(
					'Name'    => $name,
					'Network' => false,
				)
		);
		$plugin->set_repository( new ManagedRepository( 'gh', 'owner/' . dirname( $identifier ), 'repository-1', 'main' ) );
		$plugin->set_deployment_policy( DeploymentPolicy::MANUAL );
		return $plugin;
	}
}
