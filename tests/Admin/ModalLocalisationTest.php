<?php

declare(strict_types=1);

namespace Tests\Admin;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminViewWordPressFunctions.php';

final class ModalLocalisationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['ran_booster_admin_test_translations'] = array(
			'ran-booster' => array(
				'Add repository credential'     => 'Ajouter un identifiant de depot',
				'Add Push-to-Deploy secret'     => 'Ajouter un secret Push-to-Deploy',
				'Close'                         => 'Fermer',
				'Label'                         => 'Etiquette',
				'Credential type'               => 'Type d’identifiant',
				'Credential secret'             => 'Secret d’identifiant',
				'Scope'                         => 'Portee',
				'Target'                        => 'Cible',
				'e.g. Deployment access'        => 'p. ex. acces de deploiement',
				'e.g. Organisation webhooks'    => 'p. ex. webhooks d’organisation',
				'Save credential'               => 'Enregistrer l’identifiant',
				'Save webhook secret'           => 'Enregistrer le secret webhook',
				'Cancel'                        => 'Annuler',
				'Delete repository credential?' => 'Supprimer l’identifiant de depot ?',
				'You are about to delete'       => 'Vous allez supprimer',
				'from this site. This removes its saved secret and cannot be undone.' => 'de ce site. Son secret enregistre sera supprime sans possibilite de retour.',
				'Booster has verified that no managed package currently uses this credential.' => 'Booster a verifie qu’aucun paquet gere n’utilise cet identifiant.',
				'This credential is the default for public repository lookup. Deleting it returns %s public lookup to Anonymous and the provider’s public API limits.' => 'Cet identifiant est celui de la recherche publique par defaut. Le supprimer rend la recherche publique de %s a Anonymous et aux limites de l’API publique du fournisseur.',
				'Yes, delete credential'        => 'Oui, supprimer l’identifiant',
				'%s — already configured'       => '%s — deja configure',
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_admin_test_translations'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testModalCoreCopyTranslatesWithoutChangingProviderDataOrInteractionContracts(): void {
		$html = $this->renderModals();

		self::assertStringContainsString( 'id="ran-booster-access-modal-title" class="ran-booster-dialog__title">Ajouter un identifiant de depot</h2>', $html );
		self::assertStringContainsString( 'id="ran-booster-webhook-modal-title" class="ran-booster-dialog__title">Ajouter un secret Push-to-Deploy</h2>', $html );
		self::assertSame( 3, substr_count( $html, 'aria-label="Fermer"' ) );
		self::assertStringContainsString( '>Etiquette <input type="text" name="ran_booster[label]"', $html );
		self::assertStringContainsString( 'placeholder="p. ex. acces de deploiement"', $html );
		self::assertStringContainsString( 'placeholder="p. ex. webhooks d’organisation"', $html );
		self::assertStringContainsString( '>Portee <select name="ran_booster[scope]"', $html );
		self::assertStringContainsString( 'class="ran-booster-webhook-target-label">Cible</span>', $html );
		self::assertStringContainsString( '>Enregistrer l’identifiant</button>', $html );
		self::assertStringContainsString( '>Enregistrer le secret webhook</button>', $html );
		self::assertStringContainsString( '>Oui, supprimer l’identifiant</button>', $html );
		self::assertStringContainsString( 'Booster a verifie qu’aucun paquet gere n’utilise cet identifiant.', $html );
		self::assertStringContainsString( 'recherche publique de Provider &lt;data&gt; a Anonymous', $html );
		self::assertStringContainsString( 'workspace — deja configure</option>', $html );
		self::assertStringContainsString( 'workspace/example — deja configure</option>', $html );

		self::assertStringContainsString( 'data-provider-label="Provider &lt;data&gt;"', $html );
		self::assertStringContainsString( '>Provider credential kind</option>', $html );
		self::assertStringContainsString( '>Provider field <input type="text"', $html );
		self::assertStringContainsString( 'placeholder="provider-field-placeholder" disabled', $html );
		self::assertStringContainsString( '>Provider scope</option>', $html );
		self::assertStringContainsString( 'data-target-label="Provider target"', $html );
		self::assertStringContainsString( 'value="workspace" data-webhook-profile-id="workspace-hook" disabled', $html );
		self::assertStringContainsString( 'value="workspace/example" data-webhook-profile-id="repository-hook" disabled', $html );

		self::assertStringContainsString( 'id="ran-booster-access-modal-title"', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="save-access-profile"', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="delete-access-profile"', $html );
		self::assertStringContainsString( 'name="ran_booster[action]" value="save-webhook-profile"', $html );
		self::assertSame( 3, substr_count( $html, 'name="_wpnonce" value="ran-booster-save-secrets"' ) );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:save-access-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:delete-access-profile"', $html );
		self::assertStringContainsString( 'data-ran-booster-interaction-operation="core:save-webhook-profile"', $html );
		self::assertSame( 3, substr_count( $html, 'hx-target="#ran-booster-provider-profile-region"' ) );
		self::assertSame( 3, substr_count( $html, 'hx-sync="this:drop"' ) );
	}

	private function renderModals(): string {
		$hasCredentialSettings = true;
		$hasWebhookSettings    = true;
		$provider              = array(
			'code'             => 'provider-code',
			'label'            => 'Provider <data>',
			'credential_kinds' => array(
				array(
					'code'               => 'provider-kind',
					'label'              => 'Provider credential kind',
					'secret_label'       => 'Provider secret label',
					'secret_placeholder' => 'provider-secret-placeholder',
					'fields'             => array(
						array(
							'key'         => 'provider-field',
							'label'       => 'Provider field',
							'type'        => 'text',
							'placeholder' => 'provider-field-placeholder',
							'description' => 'Provider field description',
							'required'    => true,
						),
					),
				),
			),
			'webhook_scopes'   => array(
				array(
					'code'               => 'owner',
					'label'              => 'Provider scope',
					'requires_target'    => true,
					'target_label'       => 'Provider target',
					'target_placeholder' => 'provider-target-placeholder',
					'description'        => 'Provider scope description',
				),
			),
		);
		$webhook_profiles      = array(
			array(
				'id'     => 'workspace-hook',
				'scope'  => 'owner',
				'target' => 'workspace',
			),
			array(
				'id'     => 'repository-hook',
				'scope'  => 'repository',
				'target' => 'workspace/example',
			),
		);
		$managedRepositories   = array(
			'owners'       => array( 'workspace' ),
			'repositories' => array( array( 'target' => 'workspace/example' ) ),
		);

		ob_start();
		require dirname( __DIR__, 2 ) . '/views/provider/modals.php';

		return (string) ob_get_clean();
	}
}
