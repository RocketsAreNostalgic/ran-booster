<?php

declare(strict_types=1);

namespace RAN\Tests\Security;

use PHPUnit\Framework\TestCase;
use RAN\AddOn\Portability\PortabilityFacade;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\WebhookAssistance\WebhookAssistanceFacade;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Booster;
use RAN\Internal\CoreContainer;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\Secrets\SecretsFile;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

final class SecretBoundaryNegativeConformanceTest extends TestCase {

	/** @var list<class-string> */
	private const SAFE_ORDINARY_ADD_ON_CONTRACTS = array(
		AdminInteractionFacade::class,
		PortabilityFacade::class,
		ProspectiveReleaseFacade::class,
		ReleaseTrackingFacade::class,
		WebhookAssistanceFacade::class,
	);

	/** @var list<string> */
	private const FORBIDDEN_AUTHORITY_TYPES = array(
		Booster::class,
		CoreContainer::class,
		ProviderCredentialStore::class,
		SecretsFile::class,
		'RAN\\RepositoryProvider\\ProviderRegistry',
		'RAN\\Secrets\\EncryptedSecretsEnvelopeCodec',
		'RAN\\Secrets\\SiteKeyStore',
	);

	/** @var list<string> */
	private const FORBIDDEN_ORDINARY_ADD_ON_METHODS = array(
		'credentialMaterial',
		'credential_material',
		'credentialMaterials',
		'credential_materials',
		'make',
		'path',
		'webhookMaterials',
		'webhook_materials',
		'withCredential',
		'with_credential',
	);

	/** @var list<string> */
	private const REQUIRED_CANARY_SURFACES = array(
		'archive',
		'log',
		'notice',
		'result',
		'support',
	);

	public function test_safe_ordinary_add_on_contracts_expose_no_secret_authority_types_or_methods(): void {
		foreach ( self::SAFE_ORDINARY_ADD_ON_CONTRACTS as $contract ) {
			$reflection = new ReflectionClass( $contract );

			foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
				self::assertNotContains(
					$method->name,
					self::FORBIDDEN_ORDINARY_ADD_ON_METHODS,
					'An ordinary add-on contract acquired a forbidden authority method.'
				);
				$this->assert_type_is_safe( $method->getReturnType() );
				foreach ( $method->getParameters() as $parameter ) {
					$this->assert_parameter_is_safe( $parameter );
				}
			}
		}
	}

	public function test_credential_bearing_provider_contract_remains_provider_bound_and_read_only(): void {
		$reflection = new ReflectionClass( ProviderCredentialStore::class );
		$methods    = array_map(
			static fn ( ReflectionMethod $method ): string => $method->name,
			$reflection->getMethods( ReflectionMethod::IS_PUBLIC )
		);
		sort( $methods );

		self::assertSame( array( 'credential_material', 'credential_profiles', 'has_webhook_profile' ), $methods );

		$material   = $reflection->getMethod( 'credential_material' );
		$parameters = $material->getParameters();
		self::assertCount( 1, $parameters );
		self::assertSame( 'id', $parameters[0]->name );
		self::assertSame( '?string', (string) $parameters[0]->getType() );
		self::assertFalse( $reflection->hasMethod( 'webhook_materials' ) );
		self::assertFalse( $reflection->hasMethod( 'save_credential' ) );
		self::assertFalse( $reflection->hasMethod( 'path' ) );
	}

	public function test_global_and_bulk_acquisition_paths_are_closed(): void {
		$webhook_assistance = new ReflectionClass( WebhookAssistanceFacade::class );
		foreach ( array( 'provision', 'releaseProfile', 'release_profile', 'withCredential', 'with_credential' ) as $removed_method ) {
			self::assertFalse( $webhook_assistance->hasMethod( $removed_method ) );
		}
		self::assertFalse( interface_exists( 'RAN\\AddOn\\WebhookAssistance\\WebhookCleanupFacade' ) );

		$booster = new ReflectionClass( Booster::class );
		self::assertFalse( $booster->hasMethod( 'getInstance' ) );
		self::assertFalse( $booster->hasMethod( 'setInstance' ) );
		foreach ( array( 'bind', 'make', 'resolve' ) as $method ) {
			self::assertFalse( $booster->hasMethod( $method ) );
		}

		$container = new ReflectionClass( CoreContainer::class );
		self::assertTrue( $container->isFinal() );
		foreach ( array( 'bind', 'make' ) as $method ) {
			self::assertTrue( $container->getMethod( $method )->isPublic() );
			self::assertStringContainsString(
				'@internal Core composition only; this is not an extension API.',
				(string) $container->getMethod( $method )->getDocComment()
			);
		}
		self::assertTrue( $container->getMethod( 'resolve' )->isPrivate() );

		$secrets = new ReflectionClass( SecretsFile::class );
		self::assertFalse( $secrets->hasMethod( 'credentialMaterials' ) );
		foreach ( array( 'credential_material', 'path', 'webhook_materials' ) as $method ) {
			self::assertTrue( $secrets->getMethod( $method )->isPublic() );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source inspection is the contract under test.
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/ran-booster.php' );
		self::assertIsString( $bootstrap );
		self::assertDoesNotMatchRegularExpression( '/function\s+ran_booster\s*\(/', $bootstrap );
		self::assertStringNotContainsString( 'RAN_BOOSTER_LOGGING_API_VERSION', $bootstrap );
		self::assertFalse( interface_exists( 'RAN\\AddOn\\Logging\\LoggingFacade' ) );
		self::assertFalse( class_exists( 'RAN\\AddOn\\Logging\\CoreLoggingFacade' ) );
		self::assertStringNotContainsString( 'Booster::getInstance', $bootstrap );
		self::assertStringNotContainsString( "\$GLOBALS['ran_booster_instance']", $bootstrap );
		self::assertDoesNotMatchRegularExpression( '/->bind\(\s*(?:[\'\"]RAN\\\\Booster[\'\"]|Booster::class)/', $bootstrap );
		self::assertMatchesRegularExpression(
			'/\( static function \(\) use \([^\n]+\): void \{\s+\$ran_booster_container\s+=\s+new CoreContainer\(\);\s+\$ran_booster_runtime\s+=\s+new Booster\( \$ran_booster_container \);/s',
			$bootstrap,
			'The live Core container and runtime must remain scoped inside the bootstrap closure.'
		);
		self::assertStringNotContainsString( ', $logging', $bootstrap );
	}

	public function test_synthetic_canary_matrix_covers_every_required_surface_and_transform(): void {
		$surfaces = array( 'result', 'log', 'notice', 'support', 'archive' );
		sort( $surfaces );
		self::assertSame( self::REQUIRED_CANARY_SURFACES, $surfaces );

		$canary   = implode( '', array( 'synthetic', '-', 'boundary', '-', 'probe', '-', '47' ) );
		$variants = array(
			'exact'      => $canary,
			'prefixed'   => 'prefix_' . $canary,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reversible encoding is one required negative-conformance transform.
			'base64'     => base64_encode( $canary ),
			'urlencoded' => rawurlencode( $canary . ' /' ),
			'fragmented' => array( substr( $canary, 0, 12 ), substr( $canary, 12 ) ),
		);

		self::assertCount( 5, $variants );
		foreach ( $variants as $variant ) {
			self::assertTrue(
				$this->surface_contains_canary( array( 'bounded_output' => $variant ), $canary ),
				'The negative-conformance detector missed a required synthetic transform.'
			);
		}
		self::assertFalse(
			$this->surface_contains_canary(
				array(
					'result'  => 'The operation could not be verified.',
					'notice'  => 'Review the provider settings.',
					'support' => 'Use the random support reference.',
				),
				$canary
			),
			'Safe bounded output was misclassified.'
		);
	}

	private function assert_parameter_is_safe( ReflectionParameter $parameter ): void {
		$this->assert_type_is_safe( $parameter->getType() );
		self::assertNotContains(
			'callable',
			$this->type_names( $parameter->getType() ),
			'An ordinary add-on contract acquired a generic execution callback.'
		);
	}

	private function assert_type_is_safe( ?ReflectionType $type ): void {
		foreach ( $this->type_names( $type ) as $name ) {
			self::assertNotContains(
				$name,
				self::FORBIDDEN_AUTHORITY_TYPES,
				'An ordinary add-on contract acquired a forbidden authority type.'
			);
		}
	}

	/** @return list<string> */
	private function type_names( ?ReflectionType $type ): array {
		if ( $type instanceof ReflectionNamedType ) {
			return array( $type->getName() );
		}
		if ( $type instanceof ReflectionUnionType ) {
			return array_map(
				static fn ( ReflectionNamedType $named ): string => $named->getName(),
				$type->getTypes()
			);
		}

		return array();
	}

	/** @param array<string, mixed> $surface */
	private function surface_contains_canary( array $surface, string $canary ): bool {
		$leaves = array();
		array_walk_recursive(
			$surface,
			static function ( mixed $value ) use ( &$leaves ): void {
				if ( is_scalar( $value ) ) {
					$leaves[] = (string) $value;
				}
			}
		);
		$joined     = implode( '', $leaves );
		$normalized = preg_replace( '/[^A-Za-z0-9]+/', '', $joined );
		$needle     = preg_replace( '/[^A-Za-z0-9]+/', '', $canary );

		if ( ! is_string( $normalized ) || ! is_string( $needle ) ) {
			return false;
		}

		return str_contains( $joined, $canary )
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The detector must recognize the required reversible transform.
			|| str_contains( $joined, base64_encode( $canary ) )
			|| str_contains( $joined, rawurlencode( $canary . ' /' ) )
			|| str_contains( $normalized, $needle );
	}
}
