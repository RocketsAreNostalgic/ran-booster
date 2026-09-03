<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Admin\PackagePagePresenter;

final class PackageFieldsLocalisationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_test_translations']       = array();
		$GLOBALS['ran_booster_repository_admin_translations'] = array();
		$GLOBALS['ran_booster_package_view_translations']     = array(
			'ran-booster' => array(
				"Managed package type singular label\004Plugin" => 'Extension',
				"Managed package type singular label\004Theme" => 'Habillage',
				'%1$s repository'             => 'Dépôt %1$s',
				'Pick %1$s repository'        => 'Choisir le dépôt %1$s',
				'repo-name/package-name'      => 'nom-du-dépôt/nom-du-paquet',
				'Repository locator supplied by the selected provider.' => 'Localisateur de dépôt fourni par le fournisseur sélectionné.',
				'Repository provider'         => 'Fournisseur de dépôt',
				'Choose the Git service.'     => 'Choisissez le service Git.',
				'Repository branch'           => 'Branche du dépôt',
				'main, development etc.'      => 'main, développement, etc.',
				'Leave blank to use the repository provider\'s default branch.' => 'Laissez vide pour utiliser la branche par défaut du fournisseur.',
				'Repository access'           => 'Accès au dépôt',
				'Default / public repository' => 'Dépôt public par défaut',
				'Private repos require a PAT with appropriate access.' => 'Les dépôts privés nécessitent un PAT avec l\'accès approprié.',
				'Only install provider integrations you trust: an active provider can read its saved credentials. Booster does not authenticate a third-party publisher.' => 'N\'installez que des intégrations fiables.',
				' — Provider unavailable'     => ' — Fournisseur indisponible',
			),
		);
	}

	#[DataProvider( 'layoutProvider' )]
	public function testFieldsTranslateCoreCopyInBothLayoutsWithoutChangingProviderData( string $layout ): void {
		$packageView = 'grid' === $layout ? PackagePagePresenter::plugin() : PackagePagePresenter::theme();

		$repository = $this->render(
			'repository.php',
			compact( 'layout', 'packageView' ) + array(
				'packageFieldLayout'      => $layout,
				'repositoryValue'         => 'group/example',
				'providerBrowseAvailable' => true,
				'repositoryReadOnly'      => false,
			)
		);
		$provider   = $this->render(
			'provider.php',
			array(
				'packageFieldLayout'       => $layout,
				'repositoryReadOnly'       => false,
				'packageMutationAvailable' => true,
				'providerCode'             => 'provider-code',
				'providerOptions'          => array(
					array(
						'code'                => 'provider-code',
						'label'               => 'Provider <owned>',
						'available'           => false,
						'deploy'              => false,
						'browse'              => true,
						'webhooks'            => false,
						'repository_url_base' => 'https://provider.test/',
					),
				),
			)
		);
		$branch     = $this->render(
			'branch.php',
			array(
				'packageFieldLayout' => $layout,
				'branchReadOnly'     => false,
				'branchValue'        => 'main',
				'packageFieldForm'   => '',
			)
		);
		$credential = $this->render(
			'credential.php',
			array(
				'packageFieldLayout'   => $layout,
				'providerCode'         => 'provider-code',
				'selectedCredentialId' => 'credential-id',
				'providerOptions'      => array(
					array(
						'code'                => 'provider-code',
						'credential_profiles' => array(
							array(
								'id'         => 'credential-id',
								'label'      => 'Profile <owned>',
								'kind_label' => 'PAT <owned>',
								'detail'     => 'detail <owned>',
							),
						),
					),
				),
			)
		);

		self::assertStringContainsString( 'grid' === $layout ? 'Dépôt Extension' : 'Dépôt Habillage', $repository );
		self::assertStringContainsString( 'grid' === $layout ? 'Choisir le dépôt Extension' : 'Choisir le dépôt Habillage', $repository );
		self::assertStringContainsString( 'placeholder="nom-du-dépôt/nom-du-paquet"', $repository );
		self::assertStringContainsString( 'name="ran_booster[repository]"', $repository );
		self::assertStringContainsString( 'data-package-type="' . ( 'grid' === $layout ? 'plugin' : 'theme' ) . '"', $repository );
		self::assertStringContainsString( 'Fournisseur de dépôt', $provider );
		self::assertStringContainsString( 'Provider &lt;owned&gt; — Fournisseur indisponible', $provider );
		self::assertStringContainsString( 'value="provider-code"', $provider );
		self::assertStringContainsString( 'Branche du dépôt', $branch );
		self::assertStringContainsString( 'placeholder="main, développement, etc."', $branch );
		self::assertStringContainsString( 'name="ran_booster[branch]"', $branch );
		self::assertStringContainsString( 'Accès au dépôt', $credential );
		self::assertStringContainsString( 'Dépôt public par défaut', $credential );
		self::assertStringContainsString( 'Profile &lt;owned&gt; — PAT &lt;owned&gt; · detail &lt;owned&gt;', $credential );
		self::assertStringContainsString( 'value="credential-id"', $credential );
	}

	/** @return iterable<string, array{string}> */
	public static function layoutProvider(): iterable {
		yield 'grid' => array( 'grid' );
		yield 'table' => array( 'table' );
	}

	/** @param array<string, mixed> $variables */
	private function render( string $name, array $variables ): string {
		return ( static function () use ( $name, $variables ): string {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Focused template fixture supplies the exact view variables.
			extract( $variables, EXTR_SKIP );
			ob_start();
			require dirname( __DIR__, 2 ) . '/views/packages/fields/' . $name;

			return (string) ob_get_clean();
		} )();
	}
}
