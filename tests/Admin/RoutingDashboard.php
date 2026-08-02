<?php

declare(strict_types=1);

namespace Tests\Admin;

use RAN\Dashboard;

final class RoutingDashboard extends Dashboard {

	/** @var list<array{view: string, data: array<string, mixed>}> */
	private array $rendered = array();

	protected function render( $view, $data = array() ) {
		$this->rendered[] = array(
			'view' => $view,
			'data' => $data,
		);

		return $this->rendered[ array_key_last( $this->rendered ) ];
	}
}
