<?php

namespace Pay4Payment\Fees;

/**
 * The computed payment fee. The first four properties map 1:1 onto the
 * `WC_Cart::add_fee()` arguments.
 */
final class Fee {

	/**
	 * Resolved fee-line title (placeholders replaced, WPML-translated).
	 * @var string
	 */
	public string $title;

	/**
	 * Fee amount ex taxes, rounded to `wc_get_rounding_precision()`.
	 * Negative values are discounts.
	 * @var float
	 */
	public float $cost;

	/**
	 * Whether WooCommerce should tax the fee. Always false for
	 * VAT-exempt customers, regardless of settings. A NEGATIVE fee is
	 * taxed by core's distribution rule regardless of this flag — see
	 * `Pro\FeeHintFormatter::negative_fee_tax()` (#133).
	 * @var bool
	 */
	public bool $taxable;

	/**
	 * Tax class of the fee — a concrete class whenever taxable (the engine
	 * resolves `inherit`, see FeeEngine); the configured value otherwise.
	 * @var string
	 */
	public string $tax_class;

	/**
	 * The percentage calculation base (for fee-preview breakdowns like
	 * "10% of $110"). Zero for fixed-only fees.
	 * @var float
	 */
	public float $calculation_base;

	/**
	 * Constructor.
	 *
	 * @param string $title Fee title.
	 * @param float $cost Fee amount ex taxes.
	 * @param bool $taxable Whether the fee is taxable.
	 * @param string $tax_class Tax class.
	 * @param float $calculation_base Percentage calculation base.
	 */
	public function __construct(string $title, float $cost, bool $taxable, string $tax_class, float $calculation_base) {
		$this->title = $title;
		$this->cost = $cost;
		$this->taxable = $taxable;
		$this->tax_class = $tax_class;
		$this->calculation_base = $calculation_base;
	}
}
