<?php

namespace Pay4Payment;

use Pay4Payment\Interfaces\ComponentInterface;
use Pay4Payment\Interfaces\DeactivatableComponentInterface;

/**
 * Main plugin class: sets up every component of the build, in order.
 *
 * The list is the build's – `Bindings::COMPONENTS` (shared) followed by
 * the Pro build's `Pro\Bindings::COMPONENTS` – concatenated by
 * `ContainerFactory::build()`, so this class names no component and no
 * Pro class (CONTEXT.md → Pro seams). `Migration` is the one component
 * held by name too: activation runs its migration after the setup pass.
 */
class Plugin {

	/**
	 * Migration – a component of the list, held for {@see activate()}
	 * @var Migration
	 */
	private $migration;

	/**
	 * Every component of this build, in setup order
	 * @var ComponentInterface[]
	 */
	private $components;

	/**
	 * Plugin constructor.
	 * All dependencies are injected via DI container (`ContainerFactory`).
	 *
	 * @param Migration $migration Migration.
	 * @param ComponentInterface[] $components Every component of this build, in setup order.
	 */
	public function __construct( Migration $migration, array $components ) {
		$this->migration = $migration;
		$this->components = $components;
	}

	/**
	 * Method called on plugin init.
	 *
	 * @return void
	 */
	public function init(): void {

		foreach ( $this->components as $component ) {
			$component->setup();
		}

	}

	/**
	 * Method called on plugin activation.
	 *
	 * @param bool $network_wide Plugin activated network wide.
	 *
	 * @return void
	 */
	public function activate( $network_wide ): void {

		$this->init();
		$this->migration->maybe_migrate();

	}

	/**
	 * Method called on plugin deactivation.
	 *
	 * @param bool $network_wide Plugin deactivated network wide.
	 *
	 * @return void
	 */
	public function deactivate( $network_wide ): void {

		foreach ( $this->components as $component ) {
			if ( $component instanceof DeactivatableComponentInterface ) {
				$component->deactivate();
			}
		}

	}

}
