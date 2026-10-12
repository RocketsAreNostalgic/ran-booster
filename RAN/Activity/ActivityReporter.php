<?php

declare(strict_types=1);

namespace RAN\Activity;

interface ActivityReporter {

	public function begin( ActivityRequest $request ): ActivityOperation;
}
