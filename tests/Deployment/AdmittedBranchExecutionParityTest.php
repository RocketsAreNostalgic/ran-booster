<?php

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Focused admitted-boundary collaborators live with the test.

namespace Tests\Deployment;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentOutcome;
use RAN\WPBranchUpdater\V1\Contract\AdmittedArchiveSource;
use RAN\WPBranchUpdater\V1\Contract\AdmittedAttemptJournal;
use RAN\WPBranchUpdater\V1\Contract\AdmittedBranchArtifact;
use RAN\WPBranchUpdater\V1\Contract\AdmittedPackageExecutor;
use RAN\WPBranchUpdater\V1\Contract\AdmittedTargetFacts;
use RAN\WPBranchUpdater\V1\Contract\MutationLock;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchDurabilityFailure;
use RAN\WPBranchUpdater\V1\Runtime\AdmittedBranchStageFailure;
use RAN\WPBranchUpdater\V1\Runtime\BranchDeploymentDeclaration;
use RAN\WPBranchUpdater\V1\Runtime\BranchUpdater;
use RAN\WPBranchUpdater\V1\Runtime\CorePackageExecutionResult;
use RuntimeException;

final class AdmittedBranchExecutionParityTest extends TestCase {
	public function testDowngradeIsBlockedBeforeMutation(): void {
		$host = new ParityAdmittedHost();
		$host->artifactVersion = '0.9.0';

		$code = $this->deploy( $host, 'update' );

		self::assertSame( DeploymentOutcome::CODE_DOWNGRADE_BLOCKED, $code );
		self::assertNotContains( 'mutation', $host->events );
		self::assertNotContains( 'execute', $host->events );
		self::assertContains( 'cleanup', $host->events );
		self::assertContains( 'finish:downgrade_blocked', $host->events );
	}

	public function testPolicyFailureIsProjectedBeforeArtifactAcquisition(): void {
		$host = new ParityAdmittedHost();
		$host->policyFailure = DeploymentOutcome::CODE_POLICY_BLOCKED;

		$code = $this->deploy( $host, 'update' );

		self::assertSame( DeploymentOutcome::CODE_POLICY_BLOCKED, $code );
		self::assertNotContains( 'prepare', $host->events );
		self::assertNotContains( 'mutation', $host->events );
		self::assertNotContains( 'execute', $host->events );
		self::assertContains( 'finish:policy_blocked', $host->events );
	}

	public function testMutationStartDurabilityFailurePreventsCoreExecutionAndPreservesAmbiguity(): void {
		$host = new ParityAdmittedHost();
		$host->mutationStartFailure = true;

		try {
			$this->deploy( $host, 'update' );
			self::fail( 'Durability uncertainty at the mutation fence must escape the package runner.' );
		} catch ( AdmittedBranchDurabilityFailure $failure ) {
			self::assertSame( 'Unable to persist the mutation fence.', $failure->getMessage() );
		}

		self::assertContains( 'mutation', $host->events );
		self::assertNotContains( 'execute', $host->events );
		self::assertContains( 'cleanup', $host->events );
		self::assertFalse( $this->containsPrefix( $host->events, 'finish:' ) );
	}

	public function testSuccessfulInstallUsesDurableAdoptionAfterVerifiedMutation(): void {
		$host = new ParityAdmittedHost();
		$host->baseline = null;

		$code = $this->deploy( $host, 'install' );

		self::assertSame( DeploymentOutcome::CODE_DEPLOYED, $code );
		self::assertContains( 'mutation', $host->events );
		self::assertContains( 'execute', $host->events );
		self::assertContains( 'installed', $host->events );
		self::assertContains( 'adopt', $host->events );
		self::assertLessThan(
			array_search( 'adopt', $host->events, true ),
			array_search( 'installed', $host->events, true )
		);
		self::assertContains( 'finish:deployed', $host->events );
	}

	public function testCleanupFailureAfterMutationIsProjectedAsInterrupted(): void {
		$host = new ParityAdmittedHost();
		$host->cleanupFailure = true;

		$code = $this->deploy( $host, 'update' );

		self::assertSame( DeploymentOutcome::CODE_INTERRUPTED, $code );
		self::assertContains( 'mutation', $host->events );
		self::assertContains( 'execute', $host->events );
		self::assertContains( 'cleanup', $host->events );
		self::assertContains( 'finish:interrupted', $host->events );
	}

	private function deploy( ParityAdmittedHost $host, string $operation ): string {
		$declaration = new BranchDeploymentDeclaration(
			'42',
			'plugin',
			'example',
			'owner/example',
			'R_example',
			'main',
			null,
			$operation,
			null,
			'example/example.php'
		);
		$updater = BranchUpdater::forAdmittedAttempt( $declaration, $host, $host, $host, $host, $host );
		return $updater->plugin( 'owner/example', 'R_example', 'main', null, 'example' )->deploy();
	}

	/** @param list<string> $events */
	private function containsPrefix( array $events, string $prefix ): bool {
		foreach ( $events as $event ) {
			if ( str_starts_with( $event, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}

final class ParityAdmittedHost implements AdmittedAttemptJournal, AdmittedArchiveSource, AdmittedTargetFacts, AdmittedPackageExecutor, MutationLock {
	/** @var list<string> */
	public array $events = array();
	/** @var array{identifier:string,version:string,active:bool}|null */
	public ?array $baseline = array( 'identifier' => 'example/example.php', 'version' => '1.0.0', 'active' => false );
	public string $artifactVersion = '2.0.0';
	public ?string $policyFailure = null;
	public bool $mutationStartFailure = false;
	public bool $cleanupFailure = false;
	private ParityAdmittedArtifact $artifact;

	public function __construct() {
		$this->artifact = new ParityAdmittedArtifact( $this );
	}

	public function recordResolvedRef( string $ref ): void {
		$this->events[] = 'resolved';
	}

	public function markMutationStarted(): void {
		$this->events[] = 'mutation';
		if ( $this->mutationStartFailure ) {
			throw new AdmittedBranchDurabilityFailure( 'Unable to persist the mutation fence.' );
		}
	}

	public function finish( string $code ): void {
		$this->events[] = 'finish:' . $code;
	}

	public function prepare( BranchDeploymentDeclaration $deployment, ?array $baseline ): AdmittedBranchArtifact {
		$this->events[] = 'prepare';
		return $this->artifact;
	}

	public function verifyCurrentHead(): void {
		$this->events[] = 'verify';
	}

	public function assertMutationAllowed(): void {
		$this->events[] = 'allowed';
		if ( null !== $this->policyFailure ) {
			throw new AdmittedBranchStageFailure( $this->policyFailure );
		}
	}

	public function frozenTarget( BranchDeploymentDeclaration $deployment, bool $deferExisting ): ?array {
		$this->events[] = $deferExisting ? 'frozen:defer' : 'frozen:live';
		return $this->baseline;
	}

	public function maintenanceActive(): bool {
		$this->events[] = 'maintenance';
		return false;
	}

	public function recheckManaged( BranchDeploymentDeclaration $deployment ): void {
		$this->events[] = 'recheck';
	}

	public function installed( BranchDeploymentDeclaration $deployment ): array {
		$this->events[] = 'installed';
		return array( 'identifier' => 'example/example.php', 'version' => $this->artifactVersion, 'active' => false );
	}

	public function baselineNow( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
		$this->events[] = 'baseline-now';
		return $baseline;
	}

	public function adopt( BranchDeploymentDeclaration $deployment ): bool {
		$this->events[] = 'adopt';
		return true;
	}

	public function preflight( BranchDeploymentDeclaration $deployment, AdmittedBranchArtifact $artifact ): void {
		$this->events[] = 'preflight';
	}

	public function execute( BranchDeploymentDeclaration $deployment, ?array $baseline, AdmittedBranchArtifact $artifact ): CorePackageExecutionResult {
		$this->events[] = 'execute';
		return CorePackageExecutionResult::succeeded();
	}

	public function run( callable $operation ): mixed {
		$this->events[] = 'lock:start';
		try {
			return $operation();
		} finally {
			$this->events[] = 'lock:end';
		}
	}
}

final class ParityAdmittedArtifact implements AdmittedBranchArtifact {
	public function __construct( private ParityAdmittedHost $host ) {}

	public function resolvedRef(): string {
		return str_repeat( 'a', 40 );
	}

	public function expectedVersion(): string {
		return $this->host->artifactVersion;
	}

	public function assertUnchanged(): void {
		$this->host->events[] = 'unchanged';
	}

	public function cleanup(): void {
		$this->host->events[] = 'cleanup';
		if ( $this->host->cleanupFailure ) {
			throw new RuntimeException( 'Unable to clean the admitted artifact.' );
		}
	}
}
