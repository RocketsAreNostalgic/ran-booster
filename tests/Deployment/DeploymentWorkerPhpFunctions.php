<?php

declare(strict_types=1);

namespace RAN\Deployment;

function wp_doing_cron(): bool {
	return true === ( $GLOBALS['ran_booster_worker_doing_cron'] ?? false );
}
