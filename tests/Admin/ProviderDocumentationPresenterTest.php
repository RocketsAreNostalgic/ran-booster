<?php

declare(strict_types=1);

namespace RAN\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RAN\Admin\ProviderDocumentationPresenter;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Tests\RepositoryProvider\Support\ExternalFixtureProvider;

final class ProviderDocumentationPresenterTest extends TestCase {

	public function test_projects_the_exact_registered_metadata_object(): void {
		$registry = new ProviderRegistry( array( new ExternalFixtureProvider( 'fixture' ) ) );
		$metadata = $registry->ordered_metadata()[0];
		$guides   = ( new ProviderDocumentationPresenter( $registry ) )->build();

		self::assertCount( 1, $guides );
		self::assertSame( $metadata, $guides[0]['metadata'] );
		self::assertSame( 'fixture', $guides[0]['code'] );
	}
}
