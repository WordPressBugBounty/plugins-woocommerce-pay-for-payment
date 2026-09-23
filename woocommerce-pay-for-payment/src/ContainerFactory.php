<?php

namespace Pay4Payment;

use Pay4Payment\Interfaces\ComponentInterface;
use Pay4PaymentDeps\DI\Container;
use Pay4PaymentDeps\DI\ContainerBuilder;

use function Pay4PaymentDeps\DI\autowire;
use function Pay4PaymentDeps\DI\create;
use function Pay4PaymentDeps\DI\get;

/**
 * Builds the plugin's container from a build's seam bindings and
 * components. The main plugin file decides WHICH — `Bindings::SEAMS` for
 * the free build, the `Pro\Bindings` overlay inside the one block the
 * Freemius deployment strips — and hands them here, where PHPStan and
 * `ContainerFactoryTest` can reach the wiring (docs/agents/freemius.md).
 * `Plugin` gets one component list: the shared `Bindings::COMPONENTS`
 * first, the build's own after them.
 */
final class ContainerFactory {

	/**
	 * @param string $plugin_file Absolute path of the main plugin file (`PluginUtils`).
	 * @param array<class-string, class-string> $seams Interface → implementation for every Pro seam.
	 * @param array<int, class-string<ComponentInterface>> $components Build-specific components, set up after the shared ones, in setup order.
	 * @return Container
	 */
	public static function build( string $plugin_file, array $seams, array $components ): Container {
		$definitions = [
			PluginUtils::class => create()->constructor( $plugin_file ),
			// By parameter NAME: renaming Plugin's parameter without this
			// string breaks activation, not compilation — ContainerFactoryTest
			// resolves Plugin from both maps to catch exactly that.
			Plugin::class      => autowire()->constructorParameter(
				'components',
				array_map( static fn( string $component ) => get( $component ), array_merge( Bindings::COMPONENTS, $components ) )
			),
		];

		// An alias per seam, not a second autowired entry: a stateful
		// implementation (the managed fee product memoises its product id)
		// must be one instance whether it is reached through the interface
		// or, as a Pro component, through its own class name.
		foreach ( $seams as $interface => $implementation ) {
			$definitions[ $interface ] = get( $implementation );
		}

		$builder = new ContainerBuilder();
		$builder->addDefinitions( $definitions );

		return $builder->build();
	}
}
