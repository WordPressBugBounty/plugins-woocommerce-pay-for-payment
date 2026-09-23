<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Admin\Settings;
use Pay4Payment\Helpers\Cast;

/**
 * Method-scoped calculation context: the gateway plus the MethodSettings
 * tax fields and the customer's VAT exemption. The caller resolves all of
 * it (Settings reads, WC-runtime adapter) — the engine only consumes.
 */
final class FeeContext {

	/**
	 * Current gateway. The engine reads ->id and ->title and forwards the
	 * object verbatim into the frozen `woocommerce_pay4pay_*` filters,
	 * whose v2 signatures require it.
	 * @var object{id: string, title: string}
	 */
	public object $gateway;

	/**
	 * MethodSettings `taxes` — whether the fee is taxable.
	 * @var bool
	 */
	public bool $taxable;

	/**
	 * MethodSettings `includes_taxes` — whether the configured amount
	 * already includes taxes.
	 * @var bool
	 */
	public bool $include_taxes;

	/**
	 * MethodSettings `tax_class`; `inherit` allowed.
	 * @var string
	 */
	public string $tax_class;

	/**
	 * Customer VAT exemption, resolved by the caller through the
	 * WC-runtime adapter.
	 * @var bool
	 */
	public bool $is_vat_exempt;

	/**
	 * The tax rates a (resolved) tax class carries for whoever is being
	 * charged – what the inclusive-tax deflation divides by. The cart path
	 * keeps `WC_Tax::get_rates()`, the session customer's rates (v2
	 * parity); the order path hands in the order's own taxable location,
	 * because there the session customer may be a guest following a pay
	 * link or absent altogether (admin, cron), while the line is taxed at
	 * the order's location on save (#266).
	 * @var callable(string): array<int|string, mixed>
	 */
	private $tax_rates;

	/**
	 * Constructor.
	 *
	 * @param object{id: string, title: string} $gateway Gateway.
	 * @param bool $taxable Whether the fee is taxable.
	 * @param bool $include_taxes Whether the amount includes taxes.
	 * @param string $tax_class Tax class.
	 * @param bool $is_vat_exempt Customer VAT exemption.
	 * @param callable(string): array<int|string, mixed>|null $tax_rates Tax rates per tax class; `WC_Tax::get_rates()` when null.
	 */
	public function __construct(object $gateway, bool $taxable = false, bool $include_taxes = false, string $tax_class = '', bool $is_vat_exempt = false, ?callable $tax_rates = null) {
		$this->gateway = $gateway;
		$this->taxable = $taxable;
		$this->include_taxes = $include_taxes;
		$this->tax_class = $tax_class;
		$this->is_vat_exempt = $is_vat_exempt;
		$this->tax_rates = $tax_rates ?? [ \WC_Tax::class, 'get_rates' ];
	}

	/**
	 * The tax rates of a tax class – `inherit` already resolved by the
	 * engine, never passed here.
	 *
	 * @param string $tax_class Tax class ('' = standard).
	 * @return array<int|string, mixed> `WC_Tax::get_rates()` shape (rate id => rate row).
	 */
	public function tax_rates( string $tax_class ): array {
		return ( $this->tax_rates )( $tax_class );
	}

	/**
	 * Build a context from canonical MethodSettings keys.
	 *
	 * Sparse arrays are completed through `Settings::complete_method()` – the
	 * single source of truth for canonical defaults, which are v2's own
	 * (#147): an absent `tax_class` is the standard rate `''`, not `inherit`,
	 * and an absent `includes_taxes` is true.
	 *
	 * @param object{id: string, title: string} $gateway Gateway.
	 * @param array<int|string, mixed> $method_settings Canonical method settings (reads `taxes`, `includes_taxes`, `tax_class`).
	 * @param bool $is_vat_exempt Customer VAT exemption.
	 * @param callable(string): array<int|string, mixed>|null $tax_rates Tax rates per tax class; `WC_Tax::get_rates()` when null.
	 * @return self
	 */
	public static function for_gateway(object $gateway, array $method_settings, bool $is_vat_exempt = false, ?callable $tax_rates = null): self {
		$method_settings = Settings::complete_method($method_settings);

		return new self(
			$gateway,
			(bool) $method_settings['taxes'],
			(bool) $method_settings['includes_taxes'],
			Cast::to_string($method_settings['tax_class']),
			$is_vat_exempt,
			$tax_rates
		);
	}

	/**
	 * Whether the engine will read the cart items: only the `inherit`
	 * resolution consumes them, and it runs whenever the fee is taxable.
	 * VAT exemption can only flip taxable off inside the engine, so this
	 * never under-reads — the gate for `CartSnapshot::from_cart()`.
	 *
	 * @return bool
	 */
	public function needs_cart_items(): bool {
		return $this->taxable && 'inherit' === $this->tax_class;
	}
}
