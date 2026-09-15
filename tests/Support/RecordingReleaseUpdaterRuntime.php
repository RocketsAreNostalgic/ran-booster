<?php

declare(strict_types=1);

namespace Tests\Support;

/** Records native-target registrar arguments for host composition tests. */
final class RecordingReleaseUpdaterRuntime {
	/** @var list<mixed> */
	public array $arguments = array();

	public function plugin( mixed ...$arguments ): object {
		$this->arguments = $arguments;

		return new class() {};
	}
}
