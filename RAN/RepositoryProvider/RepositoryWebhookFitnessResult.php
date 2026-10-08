<?php
declare(strict_types=1);
namespace RAN\RepositoryProvider;

use InvalidArgumentException;
/** Bounded, non-secret fitness evidence for one explicit webhook action. */
final readonly class RepositoryWebhookFitnessResult {
	public function __construct(
		private string $support,
		private string $suitability,
		private string $least_privilege,
		private string $evidence,
		private string $code,
		private string $checked_at,
		private string $remediation
	) {
		$this->assert_value( $support, array( 'supported', 'unsupported', 'unknown' ) );
		$this->assert_value( $suitability, array( 'suitable', 'insufficient', 'unknown' ) );
		$this->assert_value( $least_privilege, array( 'appropriate', 'overscoped', 'unknown' ) );
		$this->assert_value( $evidence, array( 'observed', 'inferred', 'unknown_by_design', 'assessment_unavailable', 'stale' ) );
		$this->assert_text( $code, 96, '/\A[a-z0-9][a-z0-9._-]*\z/D' );
		$this->assert_text( $checked_at, 32, '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D' );
		$this->assert_text( $remediation, 512 );
	}
	/** @return array{support:string,suitability:string,least_privilege:string,evidence:string,code:string,checked_at:string,remediation:string} */

	public function to_array(): array {
		return array(
			'support'         => $this->support,
			'suitability'     => $this->suitability,
			'least_privilege' => $this->least_privilege,
			'evidence'        => $this->evidence,
			'code'            => $this->code,
			'checked_at'      => $this->checked_at,
			'remediation'     => $this->remediation,
		);
	}
	/** @param list<string> $allowed */
	private function assert_value( string $value, array $allowed ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Webhook fitness result is invalid.' );
		}
	}
	private function assert_text( string $value, int $limit, ?string $pattern = null ): void {
		if ( '' === $value || strlen( $value ) > $limit || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) || ( null !== $pattern && 1 !== preg_match( $pattern, $value ) ) ) {
			throw new InvalidArgumentException( 'Webhook fitness result is invalid.' );
		}
	}
}
