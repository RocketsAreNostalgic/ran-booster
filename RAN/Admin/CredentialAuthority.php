<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * @internal The one rule for using or choosing a saved repository credential (#1236, #1329).
 */
final class CredentialAuthority {

	/** Whether the current user may use a saved credential: both capabilities, as for secure storage. */
	public static function held(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
	}
}
