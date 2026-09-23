<?php

namespace Pay4Payment\Integrations;

interface IntegrationInterface {

	/**
	 * Whether the integrated 3rd-party plugin is active.
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * Register the integration hooks.
	 *
	 * @return void
	 */
	public function setup();

}
