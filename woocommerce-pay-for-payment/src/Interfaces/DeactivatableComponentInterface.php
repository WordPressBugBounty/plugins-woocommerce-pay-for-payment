<?php

namespace Pay4Payment\Interfaces;

/**
 * A component that leaves persistent scheduling or state behind and must
 * undo it when the plugin is deactivated — shared code stays free of
 * concrete component names.
 *
 * `Plugin::deactivate()` walks the whole component list of the build
 * (`Bindings::COMPONENTS` and, in the Pro build, `Pro\Bindings::COMPONENTS`)
 * and calls every component implementing this interface – today only the
 * Pro build's fee watchdog schedules background work, but a shared
 * component that starts to is covered without another change.
 */
interface DeactivatableComponentInterface {

	/**
	 * Undo what {@see ComponentInterface::setup()} scheduled or persisted
	 * for the background — called on plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate(): void;

}
