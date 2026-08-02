<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use InvalidArgumentException;
/** Bounded, non-secret fitness evidence for one explicit webhook action. */
final readonly class RepositoryWebhookFitnessResult {
	public function __construct(
		private string $support,
		private string $suitability,
		private string $leastPrivilege,
		private string $evidence,
		private string $code,
		private string $checkedAt,
		private string $remediation
	) {
		$this->assertValue( $support, array( 'supported', 'unsupported', 'unknown' ) );
		$this->assertValue( $suitability, array( 'suitable', 'insufficient', 'unknown' ) );
		$this->assertValue( $leastPrivilege, array( 'appropriate', 'overscoped', 'unknown' ) );
		$this->assertValue( $evidence, array( 'observed', 'inferred', 'unknown_by_design', 'assessment_unavailable', 'stale' ) );
		$this->assertText( $code, 96, '/\A[a-z0-9][a-z0-9._-]*\z/D' );
		$this->assertText( $checkedAt, 32, '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D' );
		$this->assertText( $remediation, 512 );
	}
	/** @return array{support:string,suitability:string,least_privilege:string,evidence:string,code:string,checked_at:string,remediation:string} */
	public function toArray(): array {
		return array(
			'support'         => $this->support,
			'suitability'     => $this->suitability,
			'least_privilege' => $this->leastPrivilege,
			'evidence'        => $this->evidence,
			'code'            => $this->code,
			'checked_at'      => $this->checkedAt,
			'remediation'     => $this->remediation,
		);
	}
	private function assertValue( string $value, array $allowed ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Webhook fitness result is invalid.' );
		}
	}
	private function assertText( string $value, int $limit, ?string $pattern = null ): void {
		if ( '' === $value || strlen( $value ) > $limit || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || ( null !== $pattern && 1 !== preg_match( $pattern, $value ) ) ) {
			throw new InvalidArgumentException( 'Webhook fitness result is invalid.' );
		}
	}
}
