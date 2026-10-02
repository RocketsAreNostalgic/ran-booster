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
	public function test_downgrade_is_blocked_before_mutation(): void {
		$host                   = new ParityAdmittedHost();
		$host->artifact_version = '0.9.0';

		$code = $this->deploy( $host, 'update' );

		self::assertSame( DeploymentOutcome::CODE_DOWNGRADE_BLOCKED, $code );
		self::assertNotContains( 'mutation', $host->events );
		self::assertNotContains( 'execute', $host->events );
		self::assertContains( 'cleanup', $host->events );
		self::assertContains( 'finish:downgrade_blocked', $host->events );
	}

	public function test_policy_failure_is_projected_before_artifact_acquisition(): void {
		$host                 = new ParityAdmittedHost();
		$host->policy_failure = DeploymentOutcome::CODE_POLICY_BLOCKED;

		$code = $this->deploy( $host, 'update' );

		self::assertSame( DeploymentOutcome::CODE_POLICY_BLOCKED, $code );
		self::assertNotContains( 'prepare', $host->events );
		self::assertNotContains( 'mutation', $host->events );
		self::assertNotContains( 'execute', $host->events );
		self::assertContains( 'finish:policy_blocked', $host->events );
	}

	public function test_mutation_start_durability_failure_prevents_core_execution_and_preserves_ambiguity(): void {
		$host                         = new ParityAdmittedHost();
		$host->mutation_start_failure = true;

		try {
			$this->deploy( $host, 'update' );
			self::fail( 'Durability uncertainty at the mutation fence must escape the package runner.' );
		} catch ( AdmittedBranchDurabilityFailure $failure ) {
			self::assertSame( 'Unable to persist the mutation fence.', $failure->getMessage() );
		}

		self::assertContains( 'mutation', $host->events );
		self::assertNotContains( 'execute', $host->events );
		self::assertContains( 'cleanup', $host->events );
		self::assertFalse( $this->contains_prefix( $host->events, 'finish:' ) );
	}

	public function test_successful_install_uses_durable_adoption_after_verified_mutation(): void {
		$host           = new ParityAdmittedHost();
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

	public function test_cleanup_failure_after_mutation_is_projected_as_interrupted(): void {
		$host                  = new ParityAdmittedHost();
		$host->cleanup_failure = true;

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
		$updater     = BranchUpdater::for_admitted_attempt( $declaration, $host, $host, $host, $host, $host );
		return $updater->plugin( 'owner/example', 'R_example', 'main', null, 'example' )->deploy();
	}

	/** @param list<string> $events */
	private function contains_prefix( array $events, string $prefix ): bool {
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
	public ?array $baseline             = array(
		'identifier' => 'example/example.php',
		'version'    => '1.0.0',
		'active'     => false,
	);
	public string $artifact_version     = '2.0.0';
	public ?string $policy_failure      = null;
	public bool $mutation_start_failure = false;
	public bool $cleanup_failure        = false;
	private ParityAdmittedArtifact $artifact;

	public function __construct() {
		$this->artifact = new ParityAdmittedArtifact( $this );
	}

	public function record_resolved_ref( string $ref ): void {
		$this->events[] = 'resolved';
	}

	public function mark_mutation_started(): void {
		$this->events[] = 'mutation';
		if ( $this->mutation_start_failure ) {
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

	public function verify_current_head(): void {
		$this->events[] = 'verify';
	}

	public function assert_mutation_allowed(): void {
		$this->events[] = 'allowed';
		if ( null !== $this->policy_failure ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double transports a domain failure code.
			throw new AdmittedBranchStageFailure( $this->policy_failure );
		}
	}

	public function frozen_target( BranchDeploymentDeclaration $deployment, bool $defer_existing ): ?array {
		$this->events[] = $defer_existing ? 'frozen:defer' : 'frozen:live';
		return $this->baseline;
	}

	public function maintenance_active(): bool {
		$this->events[] = 'maintenance';
		return false;
	}

	public function recheck_managed( BranchDeploymentDeclaration $deployment ): void {
		$this->events[] = 'recheck';
	}

	public function installed( BranchDeploymentDeclaration $deployment ): array {
		$this->events[] = 'installed';
		return array(
			'identifier' => 'example/example.php',
			'version'    => $this->artifact_version,
			'active'     => false,
		);
	}

	public function baseline_now( BranchDeploymentDeclaration $deployment, array $baseline ): ?array {
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

	public function resolved_ref(): string {
		return str_repeat( 'a', 40 );
	}

	public function expected_version(): string {
		return $this->host->artifact_version;
	}

	public function assert_unchanged(): void {
		$this->host->events[] = 'unchanged';
	}

	public function cleanup(): void {
		$this->host->events[] = 'cleanup';
		if ( $this->host->cleanup_failure ) {
			throw new RuntimeException( 'Unable to clean the admitted artifact.' );
		}
	}
}
