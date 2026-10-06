<?php

declare(strict_types=1);

// Analysis-only external base contract; never loaded by the product or fixture runtime.
// Source: wp-cli/wp-cli v2.12.0 php/class-wp-cli-command.php, blob 7634a1ce18a42174763d2ca620f0eeb27e08da4e.
// WP-CLI supplies this class when it loads the installed command fixture.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Exact upstream WP-CLI base-class identity required by the installed command fixture.
abstract class WP_CLI_Command {
	public function __construct() {}
}
