<?php

declare(strict_types=1);

namespace RAN\Activity;

/** Provider progress never owns the enclosing Core operation's completion. */
interface ActivityProgress {

	public function progress( string $code, string $message = '' ): void;
}
