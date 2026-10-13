<?php

declare(strict_types=1);

namespace RAN\Activity;

/** The public capability exposes progress without exposing a terminal method. */
final class ProgressOnly implements ActivityProgress {

	public function __construct( private ActivityProgress $operation ) {
	}

	public function progress( string $code, string $message = '' ): void {
		$this->operation->progress( $code, $message );
	}
}
