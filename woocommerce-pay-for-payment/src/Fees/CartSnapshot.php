<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Helpers\Cast;

/**
 * Plain-data snapshot of the cart facts the fee math consumes. No live
 * `WC_Cart` crosses the fee-engine seam — `from_cart()` is the only
 * WC_Cart-aware code in this namespace, and it concentrates the
 * knowledge of which cart fields the calculation reads.
 */
final class CartSnapshot {

	/**
	 * Cart subtotal excluding taxes.
	 * @var float
	 */
	public float $subtotal_ex_tax;

	/**
	 * Shipping total.
	 * @var float
	 */
	public float $shipping_total;

	/**
	 * Total of fees already in the cart.
	 * @var float
	 */
	public float $fee_total;

	/**
	 * Coupon discount on the goods, ex tax – `WC_Cart::get_discount_total()`
	 * on the cart, `WC_Order::get_total_discount()` on an order. One figure,
	 * subtracted once. v2 (and this class until #266) summed
	 * `get_total_discount()` and `$discount_cart`, but since WC 3.2
	 * `WC_Cart::get_total_discount()` returns `wc_price()` markup or false,
	 * which casts to 0.0, so real stores only ever subtracted
	 * `$discount_cart` – the same figure as here; the outcome is unchanged.
	 * @var float
	 */
	public float $discount;

	/**
	 * Cart tax total.
	 * @var float
	 */
	public float $tax_total;

	/**
	 * Shipping tax total.
	 * @var float
	 */
	public float $shipping_tax_total;

	/**
	 * Cart items as plain triples, order preserved. Used only for the
	 * `inherit` tax-class resolution. Line values are coerced to float the
	 * way a PHP cast would, which keeps the resolution's v2 truthiness
	 * checks intact for every numeric input.
	 * @var array<int, array{line_tax: float, line_total: float, tax_class: string}>
	 */
	public array $items;

	/**
	 * Constructor.
	 *
	 * @param float $subtotal_ex_tax Subtotal ex taxes.
	 * @param float $shipping_total Shipping total.
	 * @param float $fee_total Fees total.
	 * @param float $discount Coupon discount, ex tax.
	 * @param float $tax_total Tax total.
	 * @param float $shipping_tax_total Shipping tax total.
	 * @param array<int, array{line_tax: float, line_total: float, tax_class: string}> $items Item triples (line_tax, line_total, tax_class).
	 */
	public function __construct(
		float $subtotal_ex_tax = 0.0,
		float $shipping_total = 0.0,
		float $fee_total = 0.0,
		float $discount = 0.0,
		float $tax_total = 0.0,
		float $shipping_tax_total = 0.0,
		array $items = []
	) {
		$this->subtotal_ex_tax = $subtotal_ex_tax;
		$this->shipping_total = $shipping_total;
		$this->fee_total = $fee_total;
		$this->discount = $discount;
		$this->tax_total = $tax_total;
		$this->shipping_tax_total = $shipping_tax_total;
		$this->items = $items;
	}

	/**
	 * Snapshot a live cart.
	 *
	 * Pass `$with_items = FeeContext::needs_cart_items()`: items feed only
	 * the `inherit` tax-class resolution, and snapshotting them touches
	 * every cart line (`get_tax_class()` fires per-product filters), which
	 * the pre-extraction code never did outside that path.
	 *
	 * Cart entries that are not arrays are skipped, and an item without a
	 * product object (3rd-party pseudo items) is snapshotted with the
	 * standard tax class — neither can fatal the checkout.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @param bool $with_items Whether to snapshot the cart items.
	 * @return self
	 */
	public static function from_cart($cart, bool $with_items = true): self {

		$items = [];
		if ($with_items) {
			foreach ((array) $cart->get_cart() as $item) {
				if (!is_array($item)) {
					continue;
				}
				$product = $item['data'] ?? null;
				$items[] = [
					'line_tax' => Cast::to_float($item['line_tax'] ?? 0),
					'line_total' => Cast::to_float($item['line_total'] ?? 0),
					'tax_class' => is_object($product) && is_callable([$product, 'get_tax_class'])
						? Cast::to_string($product->get_tax_class())
						: '',
				];
			}
		}

		return new self(
			(float) $cart->subtotal_ex_tax,
			(float) $cart->shipping_total,
			(float) $cart->fee_total,
			(float) $cart->get_discount_total(),
			(float) $cart->tax_total,
			(float) $cart->shipping_tax_total,
			$items
		);

	}
}
