<?php

namespace Pay4Payment;

use Pay4Payment\Admin\ConditionOptionsApi;
use Pay4Payment\Admin\LegacyFilterNotice;
use Pay4Payment\Admin\RestApi;
use Pay4Payment\Admin\Settings;
use Pay4Payment\Admin\OptinNotice;
use Pay4Payment\Admin\OptinPageFallback;
use Pay4Payment\Admin\UpgradeNotice;
use Pay4Payment\Fees\CartFeeVisibilityInterface;
use Pay4Payment\Fees\FeeProductInterface;
use Pay4Payment\Fees\NullCartFeeVisibility;
use Pay4Payment\Fees\NullFeeProduct;
use Pay4Payment\Fees\NullOrderFeeCalculator;
use Pay4Payment\Fees\NullTierConditions;
use Pay4Payment\Fees\OrderFeeCalculatorInterface;
use Pay4Payment\Fees\TierConditionsInterface;

/**
 * What every build puts into the container: the free↔Pro seams – every
 * interface the shared code depends on where free and Pro behave
 * differently, bound to the free build's implementation (CONTEXT.md → Pro
 * seams) – and the shared components, in setup order.
 *
 * `ContainerFactory` wires them; the main plugin file swaps in
 * `Pro\Bindings::SEAMS` for the Pro build and appends
 * `Pro\Bindings::COMPONENTS`, inside the one block the Freemius deployment
 * strips from the free build, together with `src/Pro/`. `BindingsTest`
 * keeps the two maps in step; the rules for adding a seam are in
 * docs/agents/freemius.md.
 */
final class Bindings {

	/**
	 * Interface → the free build's implementation.
	 *
	 * @var array<class-string, class-string>
	 */
	public const SEAMS = [
		TierConditionsInterface::class     => NullTierConditions::class,
		OrderFeeCalculatorInterface::class => NullOrderFeeCalculator::class,
		FeeProductInterface::class         => NullFeeProduct::class,
		CartFeeVisibilityInterface::class  => NullCartFeeVisibility::class,
	];

	/**
	 * The components every build sets up, in setup order – `Plugin::init()`
	 * walks this list, followed by the build's own (`Pro\Bindings::COMPONENTS`
	 * in the Pro build, nothing in the free one). The order is pinned by
	 * `BindingsTest`; a component that must hook before another belongs
	 * ahead of it here, not in a priority.
	 *
	 * @var array<int, class-string<\Pay4Payment\Interfaces\ComponentInterface>>
	 */
	public const COMPONENTS = [
		Admin::class,
		Settings::class,
		Frontend::class,
		RestApi::class,
		ConditionOptionsApi::class,
		LegacyFilterNotice::class,
		UpgradeNotice::class,
		OptinNotice::class,
		OptinPageFallback::class,
		Migration::class,
		Integrations::class,
		BlocksIntegration::class,
		PlanReview::class,
		OrderFeeSync::class,
	];
}
