<?php

declare(strict_types=1);

namespace Tests\Admin;

use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\CredentialValidator;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryProvider;

final class CredentialValidationProvider implements RepositoryProvider, CredentialValidator {

	use \Tests\RepositoryProvider\Support\SuppliesProviderDiagnostics;

	/** @var list<string> */
	public array $validatedIds = array();

	public function __construct( private CredentialValidationResult $result ) {
	}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			ProviderCode::parse( 'bb' ),
			'Bitbucket',
			'https://bitbucket.org/',
			'Workspace'
		);
	}

	public function validateCredential( string $credentialId ): CredentialValidationResult {
		$this->validatedIds[] = $credentialId;

		return $this->result;
	}
}
