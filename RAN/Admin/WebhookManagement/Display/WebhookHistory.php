<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Display;

use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Installation\InstallationRecord;
use RAN\Admin\WebhookManagement\Installation\InstallationStore;

/** @internal Exact local schema-4 history read. */
final readonly class WebhookHistory {
	public function __construct( private ManagedPackageWebhookAuthorityResolver $authorities, private InstallationStore $records ) {}

	public function forPackage( string $type, string $identifier ): ?WebhookHistoryView {
		$authority = $this->authorities->forPackage( $type, $identifier );
		if ( null === $authority ) {
			return null;
		}
		$record = $this->records->find( $authority['provider_code'], $authority['repository_id'] );

		return $record instanceof InstallationRecord
			? $this->fromRecord( $record )
			: null;
	}

	public static function fromRecord( InstallationRecord $record ): WebhookHistoryView {
		return new WebhookHistoryView( $record->providerCode(), $record->repositoryId(), $record->status(), $record->checkedAt() );
	}
}
