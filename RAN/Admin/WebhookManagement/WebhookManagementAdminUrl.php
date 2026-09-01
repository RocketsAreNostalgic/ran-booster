<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

/** @internal Builds routes for the network-admin-owned webhook management UI. */
final class WebhookManagementAdminUrl {
	public static function forPath( string $path ): string {
		if ( str_starts_with( $path, 'admin-post.php' ) ) {
			return admin_url( $path );
		}

		return is_multisite() ? network_admin_url( $path ) : admin_url( $path );
	}
}
