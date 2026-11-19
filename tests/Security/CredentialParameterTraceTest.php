<?php

declare(strict_types=1);

namespace RAN\Tests\Security;

use PHPUnit\Framework\TestCase;
use RAN\Deployment\AdmittedBranchHostAdapter;
use RAN\RepositoryProvider\AuthenticatedPreparedArchive;
use ReflectionClass;
use SensitiveParameter;

/**
 * A stack trace prints a frame's arguments when zend.exception_ignore_args is off, so a parameter that can
 * carry a credential or a header array with one must be marked in the classes that handle archive requests.
 */
final class CredentialParameterTraceTest extends TestCase {

	/** Names that carry a credential or the request arguments and headers a credential is injected into. */
	private const CARRIERS = '/token|secret|authorization|credential|password|headers|arguments/i';

	/**
	 * Frames that run before the credential is injected, so they hold only the caller's request.
	 *
	 * @var list<string>
	 */
	private const PRE_INJECTION = array(
		'AuthenticatedPreparedArchive::authenticate_request',
		'AuthenticatedPreparedArchive::has_authorization',
	);

	public function test_every_credential_carrying_parameter_is_marked_sensitive(): void {
		$checked  = array();
		$unmarked = array();
		foreach ( array( AuthenticatedPreparedArchive::class, AdmittedBranchHostAdapter::class ) as $class ) {
			$reflection = new ReflectionClass( $class );
			foreach ( $reflection->getMethods() as $method ) {
				foreach ( $method->getParameters() as $parameter ) {
					$name = $reflection->getShortName() . '::' . $method->getName();
					if ( $method->getDeclaringClass()->getName() !== $class || 1 !== preg_match( self::CARRIERS, $parameter->getName() ) || in_array( $name, self::PRE_INJECTION, true ) ) {
						continue;
					}
					$checked[] = $name . '($' . $parameter->getName() . ')';
					if ( array() === $parameter->getAttributes( SensitiveParameter::class ) ) {
						$unmarked[] = $name . '($' . $parameter->getName() . ')';
					}
				}
			}
		}

		// The names the rule must reach, so a renamed or removed parameter cannot make the check vacuous.
		self::assertContains( 'AuthenticatedPreparedArchive::guard_request($arguments)', $checked );
		self::assertContains( 'AuthenticatedPreparedArchive::strip_authentication_from_redirect($headers)', $checked );
		self::assertContains( 'AuthenticatedPreparedArchive::has_valid_authorization($headers)', $checked );
		self::assertSame( array(), $unmarked, 'These parameters can carry a credential and need #[\SensitiveParameter].' );
	}
}
