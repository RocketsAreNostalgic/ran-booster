<?php

declare( strict_types = 1 );

namespace Tests\Admin\WebhookManagement\Display;

use PHPUnit\Framework\TestCase;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use ReflectionClass;

require_once __DIR__ . '/WebhookDisplayModelWordPressFunctions.php';

final class WebhookDisplayModelTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_webhook_display_test_translations'] );
	}

	public function testItTranslatesKnownFixedCoreNoticeCopy(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			'The provider rejected the webhook setup request. No remote hook was established.' => 'Le fournisseur a refuse la configuration du webhook. Aucun webhook distant n a ete etabli.',
		);

		self::assertSame(
			'Le fournisseur a refuse la configuration du webhook. Aucun webhook distant n a ete etabli.',
			$this->display()->notice( 'setup_failed' )
		);
	}

	public function testItTranslatesFormattedRecoveryCopyWithoutChangingReferenceIds(): void {
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			'Provider state may have changed, but the current webhook-management record was not overwritten. Inspect provider hook reference %1$s and Core signing profile %2$s before retrying.' => 'Profil Core %2$s et reference de webhook %1$s a inspecter.',
		);

		self::assertSame(
			'Profil Core profile-abc et reference de webhook hook-123 a inspecter.',
			$this->display()->notice(
				'recovery_record_failed',
				array(
					'hook_id'    => 'hook-123',
					'profile_id' => 'profile-abc',
				)
			)
		);
	}

	public function testItTranslatesDefaultCoreCopyWithoutChangingProviderRemediation(): void {
		$default     = 'Webhook management could not confirm that the remote webhook operation succeeded. Review the recorded status before retrying.';
		$remediation = 'Provider remediation: retain result_code=opaque and https://provider.example/hooks/123 exactly.';
		$GLOBALS['ran_booster_webhook_display_test_translations']['ran-booster'] = array(
			$default => 'Le resultat de l operation webhook doit etre verifie dans l etat enregistre.',
		);

		self::assertSame( 'Le resultat de l operation webhook doit etre verifie dans l etat enregistre.', $this->display()->notice( 'unknown_result' ) );
		self::assertSame( $remediation, $this->display()->notice( 'unknown_result', null, $remediation ) );
	}

	private function display(): WebhookDisplayModel {
		return ( new ReflectionClass( WebhookDisplayModel::class ) )->newInstanceWithoutConstructor();
	}
}
