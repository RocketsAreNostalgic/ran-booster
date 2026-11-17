<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderSettingsPresenter;
use RAN\Dashboard;
use ReflectionClass;
use ReflectionMethod;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';
require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

final class CredentialEditorFallbackTest extends TestCase {

	public function test_native_failure_restores_only_allowlisted_non_secret_fields(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['ran_booster']      = array(
			'action'        => 'save-access-profile',
			'provider'      => 'fixture',
			'id'            => '',
			'label'         => 'Draft <b>label</b>',
			'kind'          => 'token',
			'expires_on'    => '2027-06-01',
			'self_destruct' => '1',
			'configuration' => array(
				'account' => 'Draft account',
				'other'   => 'DROP-ME',
				'nested'  => array( 'SECRET' ),
			),
			'secret'        => 'NEVER-REDISPLAY',
			'_wpnonce'      => 'DO-NOT-REUSE',
		);
		$dashboard                 = ( new ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$dashboard->messages       = array(
			array(
				'type' => 'error',
				'code' => 'ran_booster_credentials_error',
			),
		);
		try {
			$request = $this->request( $dashboard );
			self::assertSame( array( 'open', 'draft', 'configuration', 'self_destruct', 'id', 'label', 'kind', 'expires_on' ), array_keys( $request ) );
			self::assertSame( array( 'account' => 'Draft account' ), $request['configuration'] );
			$form = $this->project( $request );
			$html = $this->render( $form );
			self::assertStringNotContainsString( 'NEVER-REDISPLAY', $html );
			self::assertStringNotContainsString( 'DO-NOT-REUSE', $html );
			self::assertStringNotContainsString( 'DROP-ME', $html );
			$xpath = $this->xpath( $html );
			self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"][@hidden])' ) );
			self::assertSame( 'Draft account', $xpath->evaluate( 'string(//input[@name="ran_booster[configuration][account]"]/@value)' ) );
			self::assertSame( '', $xpath->evaluate( 'string(//input[@name="ran_booster[secret]"]/@value)' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//input[@name="ran_booster[self_destruct]"][@checked])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"]//input[@name="_wpnonce"])' ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"]//*[@role="dialog"])' ) );
			$dashboard->messages = array(
				array(
					'type'    => 'success',
					'message' => 'Saved',
				),
			);
			self::assertSame(
				array(
					'id'   => '',
					'open' => false,
				),
				$this->request( $dashboard )
			);
		} finally {
			$_POST = array();
			unset( $_SERVER['REQUEST_METHOD'] );
		}
	}

	public function test_wrong_provider_and_malformed_requests_do_not_project_drafts(): void {
		$dashboard                 = ( new ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$dashboard->messages       = array( array( 'code' => 'ran_booster_credentials_error' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$valid                     = array(
			'action'   => 'save-access-profile',
			'provider' => 'fixture',
			'id'       => '',
			'label'    => 'Draft',
			'kind'     => 'token',
		);
		try {
			foreach ( array( array( 'provider' => 'other' ), array( 'id' => array( 'bad' ) ), array( 'id' => '../bad' ), array( 'action' => 'delete-access-profile' ) ) as $override ) {
				$_POST['ran_booster'] = array_replace( $valid, $override );
				self::assertSame(
					array(
						'id'   => '',
						'open' => false,
					),
					$this->request( $dashboard )
				);
			}
			$_POST['ran_booster']                  = $valid;
			$_POST['ran_booster']['configuration'] = array( 'account' => array( 'NOT-A-STRING' ) );
			self::assertSame( array( 'account' => '' ), $this->request( $dashboard )['configuration'] );
			$_POST['ran_booster']['kind'] = 'unknown';
			self::assertSame( array(), $this->request( $dashboard )['configuration'] );
		} finally {
			$_POST = array();
			unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HX_REQUEST'] );
		}
	}

	public function test_htmx_failure_does_not_create_a_native_draft(): void {
		$dashboard                  = ( new ReflectionClass( Dashboard::class ) )->newInstanceWithoutConstructor();
		$dashboard->messages        = array( array( 'code' => 'ran_booster_credentials_error' ) );
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['HTTP_HX_REQUEST'] = 'true';
		$_POST['ran_booster']       = array(
			'action'   => 'save-access-profile',
			'provider' => 'fixture',
			'id'       => '',
			'label'    => 'Draft',
			'kind'     => 'token',
		);
		try {
			self::assertSame(
				array(
					'id'   => '',
					'open' => false,
				),
				$this->request( $dashboard )
			);
		} finally {
			$_POST = array();
			unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_HX_REQUEST'] );
		}
	}

	public function test_add_and_edit_forms_are_usable_without_any_javascript(): void {
		foreach ( array( array( 'open' => true ), array( 'id' => 'saved' ) ) as $request ) {
			$form  = $this->project( $request );
			$xpath = $this->xpath( $this->render( $form ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"][@hidden])' ) );
			self::assertSame( 1.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"]//form[@novalidate])' ) );
			self::assertSame( 'post', $xpath->evaluate( 'string(//*[@id="ran-booster-credential-editor"]//form/@method)' ) );
			self::assertSame( 'save-access-profile', $xpath->evaluate( 'string(//*[@id="ran-booster-credential-editor"]//input[@name="ran_booster[action]"]/@value)' ) );
			self::assertSame( 2.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"]//select/option)' ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"]//input[contains(@name,"configuration")][@disabled or @required])' ) );
			self::assertSame( 0.0, $xpath->evaluate( 'count(//*[@id="ran-booster-credential-editor"]//p[contains(@class,"config-field")][@hidden])' ) );
			self::assertSame( '', $xpath->evaluate( 'string(//input[@name="ran_booster[secret]"]/@value)' ) );
			self::assertSame( isset( $request['id'] ) ? 0.0 : 1.0, $xpath->evaluate( 'count(//input[@name="ran_booster[secret]"][@required])' ) );
			self::assertSame( $request['id'] ?? '', $xpath->evaluate( 'string(//*[@id="ran-booster-credential-editor"]//input[@name="ran_booster[id]"]/@value)' ) );
		}
		self::assertFalse(
			$this->project(
				array(
					'id'   => 'immutable',
					'open' => true,
				)
			)['open']
		);
		self::assertFalse(
			$this->project(
				array(
					'id'   => 'missing',
					'open' => true,
				)
			)['open']
		);
	}

	/**
	 * Reads the current request globals through the production boundary.
	 *
	 * @phpstan-impure
	 * @return array<string, mixed>
	 */
	private function request( Dashboard $dashboard ): array {
		return ( new ReflectionMethod( Dashboard::class, 'requested_credential_form' ) )->invoke( $dashboard, $this->provider() );
	}

	/** @return array<string, mixed> */
	private function provider(): array {
		$kind = array(
			'code'               => 'token',
			'label'              => 'Token',
			'secret_label'       => 'Secret',
			'secret_placeholder' => '',
			'fields'             => array(
				array(
					'key'         => 'account',
					'label'       => 'Account',
					'type'        => 'text',
					'required'    => true,
					'placeholder' => '',
					'description' => '',
				),
			),
		);
		return array(
			'code'             => 'fixture',
			'label'            => 'Fixture',
			'credential_kinds' => array(
				$kind,
				array_replace(
					$kind,
					array(
						'code'   => 'second',
						'label'  => 'Second',
						'fields' => array(
							array_replace(
								$kind['fields'][0],
								array(
									'key'  => 'other',
									'type' => 'email',
								)
							),
						),
					)
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $request Native form request.
	 * @return array<string, mixed>
	 */
	private function project( array $request ): array {
		$presenter = ( new ReflectionClass( ProviderSettingsPresenter::class ) )->newInstanceWithoutConstructor();
		$rows      = array(
			array(
				'id'                  => 'saved',
				'label'               => 'Stored label',
				'kind'                => 'token',
				'configuration'       => array( 'account' => 'Stored account' ),
				'editable'            => true,
				'provider_expires_on' => '2027-07-01',
				'expiry'              => array( 'manual_expires_on' => '2027-06-01' ),
			),
			array(
				'id'       => 'immutable',
				'editable' => false,
			),
		);
		return ( new ReflectionMethod( ProviderSettingsPresenter::class, 'credential_form' ) )->invoke( $presenter, $this->provider(), $rows, $request );
	}

	/** @param array<string, mixed> $credential_form Safe form projection. */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The included production view reads this local template binding.
	private function render( array $credential_form ): string {
		$provider                = $this->provider();
		$has_credential_settings = true;
		$has_webhook_settings    = false;
		$credentials_url         = '/wp-admin/admin.php?page=ran-booster&tab=fixture&view=credentials';
		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider/modals.php';
		return (string) ob_get_clean();
	}

	private function xpath( string $html ): DOMXPath {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return new DOMXPath( $document );
	}
}
