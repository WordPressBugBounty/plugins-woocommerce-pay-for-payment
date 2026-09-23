<?php

namespace Pay4Payment\Fees;

/**
 * The tier selection's answer: one canonical FeeTier together with its
 * identity — the tier's index in the gateway's ordered `extra_charge`
 * (`Settings::get_tiers()`, where entry 0 is the free tier).
 *
 * The index is what makes a multi-tier configuration addressable from the
 * outside (#122): the engine keys the per-tier WPML string names on it
 * (tier 0 keeps the v2 names, so existing translations survive), and the
 * frozen `woocommerce_pay4pay_*` filter chain carries it as an additive
 * trailing argument, so third-party code can tell which tier it is
 * adjusting. Selection and calculation hand the pair around atomically —
 * a tier can never arrive at the engine without its identity.
 */
final class SelectedTier {

	/**
	 * The tier's index in the gateway's ordered tier list — 0 is the free
	 * tier, and the index every per-tier WPML string name and filter
	 * argument refers to.
	 * @var int
	 */
	public int $index;

	/**
	 * The canonical FeeTier (`item_title`, `charges_fixed`,
	 * `charges_percentage_*`, `conditions`, …).
	 * @var array<int|string, mixed>
	 */
	public array $tier;

	/**
	 * Constructor.
	 *
	 * @param int $index Tier index in the gateway's ordered tier list.
	 * @param array<int|string, mixed> $tier Canonical FeeTier.
	 */
	public function __construct(int $index, array $tier) {
		$this->index = $index;
		$this->tier = $tier;
	}
}
