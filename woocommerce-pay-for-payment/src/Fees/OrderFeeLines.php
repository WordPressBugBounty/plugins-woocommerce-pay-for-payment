<?php

namespace Pay4Payment\Fees;

use Pay4Payment\Helpers\Cast;

/**
 * The plugin's fee lines on a `WC_Order`: how they are recognised, written
 * and changed. Two kinds exist — a `WC_Order_Item_Fee` (the default, what
 * checkout creates and what core recommends for the order-pay page) and a
 * line item on the managed fee product (the ERP/invoicing option). This
 * module owns both kinds; callers only decide which one (by passing the
 * managed product, or not).
 *
 * Identity is the public contract accountants and integrations rely on:
 * `_pay4payment_fee_gateway` (gateway id) and `_pay4payment_fee_source`
 * (`checkout|order_pay|admin|converted`) on every line the plugin writes.
 * Lines placed before tagging existed are recognised by their title
 * (`FeeTitlePatterns`); the `pay4payment_is_plugin_fee_item` filter lets a
 * 3rd party decide the rest.
 *
 * Everything goes through the `WC_Order` / `WC_Order_Item` API (HPOS-safe).
 * Items are not saved here unless WooCommerce already did so — the caller
 * persists the order (`calculate_totals()` / `save()` save its items).
 */
class OrderFeeLines {

	const META_GATEWAY = '_pay4payment_fee_gateway';
	const META_SOURCE = '_pay4payment_fee_source';

	/**
	 * Product lines only: whether the fee is taxable ('yes'/'no'). A
	 * product line's tax status otherwise follows the product; the managed
	 * product reads this to honour the method's tax setting per line.
	 */
	const META_TAXABLE = '_pay4payment_fee_taxable';

	const SOURCE_CHECKOUT = 'checkout';
	const SOURCE_ORDER_PAY = 'order_pay';
	const SOURCE_ADMIN = 'admin';
	const SOURCE_CONVERTED = 'converted';

	const KIND_FEE = 'fee';
	const KIND_PRODUCT = 'product';

	/**
	 * Title placeholders the engine substitutes; wildcarded in the
	 * title-match fallback.
	 */
	const TITLE_PLACEHOLDERS = [ '[FIXED_AMOUNT]', '[PERCENT_AMOUNT]', '[CART_TOTAL]', '[MINIMUM_AMOUNT]', '[MAXIMUM_AMOUNT]' ];

	/**
	 * Title patterns for untagged fee lines
	 * @var FeeTitlePatterns
	 */
	private $patterns;

	/**
	 * Constructor.
	 *
	 * @param FeeTitlePatterns $patterns Title patterns for untagged fee lines.
	 */
	public function __construct( FeeTitlePatterns $patterns ) {
		$this->patterns = $patterns;
	}

	/**
	 * The gateway id a line is tagged with, '' when it is not the plugin's.
	 *
	 * @param \WC_Order_Item $item Order item.
	 * @return string
	 */
	public static function gateway_of( $item ): string {
		return Cast::to_string( $item->get_meta( self::META_GATEWAY ) );
	}

	/**
	 * The subset of found lines belonging to a gateway.
	 *
	 * @template TLine of array{gateway_id: string}
	 * @param array<int, TLine> $lines Lines as returned by {@see find()}.
	 * @param string $gateway_id Gateway id.
	 * @return array<int, TLine> Same shape, re-indexed.
	 */
	public static function lines_for( array $lines, string $gateway_id ): array {
		return array_values( array_filter( $lines, static function ( $line ) use ( $gateway_id ) {
			return $line['gateway_id'] === $gateway_id;
		} ) );
	}

	/**
	 * The plugin's fee lines on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{item_id: int, gateway_id: string, kind: string, tagged: bool, item: \WC_Order_Item}>
	 */
	public function find( $order ): array {
		$found = [];

		foreach ( (array) $order->get_fees() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Fee ) {
				continue;
			}
			$gateway_id = self::gateway_of( $item );
			$tagged = '' !== $gateway_id;
			if ( ! $tagged ) {
				$gateway_id = $this->match_title( Cast::to_string( $item->get_name() ) );
			}

			/**
			 * Filters whether a fee line on an order is one of the plugin's.
			 *
			 * Tagged lines and title matches arrive as `true`; anything else
			 * as `false`. A line claimed here without a recognisable gateway
			 * counts as stale and is removed on the next gateway switch.
			 *
			 * @since 3.0.0
			 * @param bool $is_plugin_fee Whether the line is the plugin's.
			 * @param \WC_Order_Item_Fee $item The fee line.
			 * @param \WC_Order $order The order.
			 */
			$is_ours = (bool) apply_filters( 'pay4payment_is_plugin_fee_item', $tagged || '' !== $gateway_id, $item, $order );
			if ( ! $is_ours ) {
				continue;
			}

			$found[] = [
				'item_id' => (int) $item_id,
				'gateway_id' => $gateway_id,
				'kind' => self::KIND_FEE,
				'tagged' => $tagged,
				'item' => $item,
			];
		}

		foreach ( (array) $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$gateway_id = self::gateway_of( $item );
			if ( '' === $gateway_id ) {
				continue;
			}
			$found[] = [
				'item_id' => (int) $item_id,
				'gateway_id' => $gateway_id,
				'kind' => self::KIND_PRODUCT,
				'tagged' => true,
				'item' => $item,
			];
		}

		return $found;
	}

	/**
	 * Tag an order item as the plugin's fee line. Does not save.
	 *
	 * @param \WC_Order_Item $item Order item.
	 * @param string $gateway_id Gateway id.
	 * @param string $source One of the SOURCE_* constants.
	 * @return void
	 */
	public static function tag( $item, string $gateway_id, string $source ): void {
		$item->add_meta_data( self::META_GATEWAY, $gateway_id, true );
		$item->add_meta_data( self::META_SOURCE, $source, true );
	}

	/**
	 * Write the fee onto the order as the configured line kind: a line on
	 * the managed product when one is given (and WooCommerce accepts it), a
	 * fee line otherwise — never silently skip charging.
	 *
	 * @param \WC_Order $order Order.
	 * @param Fee $fee The fee.
	 * @param string $gateway_id Gateway id.
	 * @param string $source One of the SOURCE_* constants.
	 * @param \WC_Product|null $product The managed fee product (product mode), or null.
	 * @return \WC_Order_Item The new line.
	 */
	public function add_line( $order, Fee $fee, string $gateway_id, string $source, $product = null ) {
		if ( $product instanceof \WC_Product ) {
			$item = $this->add_product_line( $order, $product, $fee, $gateway_id, $source );
			if ( $item ) {
				return $item;
			}
		}

		return $this->add_fee_line( $order, $fee, $gateway_id, $source );
	}

	/**
	 * Add the fee as a `WC_Order_Item_Fee`. Persisted with the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param Fee $fee The fee.
	 * @param string $gateway_id Gateway id.
	 * @param string $source One of the SOURCE_* constants.
	 * @return \WC_Order_Item_Fee The new item.
	 */
	public function add_fee_line( $order, Fee $fee, string $gateway_id, string $source ) {
		$item = new \WC_Order_Item_Fee();
		$this->write_fee_item( $item, $fee );
		self::tag( $item, $gateway_id, $source );
		$order->add_item( $item );

		return $item;
	}

	/**
	 * Add the fee as a line item on the managed fee product. WooCommerce
	 * saves the line when adding it; the meta (and taxes, when given) go on
	 * with one more save.
	 *
	 * @param \WC_Order $order Order.
	 * @param \WC_Product $product The managed fee product.
	 * @param Fee $fee The fee.
	 * @param string $gateway_id Gateway id.
	 * @param string $source One of the SOURCE_* constants.
	 * @param array<string, array<int|string, mixed>>|null $taxes Taxes to carry over verbatim (`total`/`subtotal`), or null to leave them to `calculate_totals()`.
	 * @return \WC_Order_Item_Product|null The new item, or null when WooCommerce refused it.
	 */
	public function add_product_line( $order, $product, Fee $fee, string $gateway_id, string $source, ?array $taxes = null ) {
		$item_id = $order->add_product( $product, 1, [
			'name' => $fee->title,
			'subtotal' => $fee->cost,
			'total' => $fee->cost,
			'tax_class' => self::fee_tax_class( $fee ),
		] );

		$item = $order->get_item( (int) $item_id );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}

		self::tag( $item, $gateway_id, $source );
		$item->add_meta_data( self::META_TAXABLE, self::taxable_flag( $fee ), true );
		if ( null !== $taxes ) {
			$item->set_taxes( $taxes );
		}
		$item->save();

		return $item;
	}

	/**
	 * Replace one of the plugin's fee lines with the same fee on the managed
	 * product — same title, amount, tax class and the taxes checkout already
	 * computed, so the order's totals do not move.
	 *
	 * @param \WC_Order $order Order.
	 * @param \WC_Order_Item_Fee $item The fee line to convert.
	 * @param \WC_Product $product The managed fee product.
	 * @return \WC_Order_Item_Product|null The product line, or null when WooCommerce refused it (the fee line then stays).
	 */
	public function convert_to_product_line( $order, $item, $product ) {
		$fee = new Fee(
			Cast::to_string( $item->get_name() ),
			Cast::to_float( $item->get_total() ),
			'taxable' === $item->get_tax_status(),
			Cast::to_string( $item->get_tax_class() ),
			0.0
		);

		$taxes = $item->get_taxes();
		$total_taxes = isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : [];

		$new = $this->add_product_line( $order, $product, $fee, self::gateway_of( $item ), self::SOURCE_CONVERTED, [
			'total' => $total_taxes,
			'subtotal' => $total_taxes,
		] );
		if ( ! $new ) {
			return null;
		}

		$order->remove_item( (int) $item->get_id() );

		return $new;
	}

	/**
	 * Make an existing plugin fee line (either kind) carry the given fee.
	 * Idempotent: a line that already carries it is left alone.
	 *
	 * @param \WC_Order_Item $item The line, as returned by {@see find()}.
	 * @param Fee $fee The fee.
	 * @return bool Whether the line changed.
	 */
	public function update_line( $item, Fee $fee ): bool {
		if ( $item instanceof \WC_Order_Item_Fee ) {
			$unchanged = $fee->title === Cast::to_string( $item->get_name() )
				&& $fee->cost === Cast::to_float( $item->get_total() )
				&& self::fee_tax_status( $fee ) === $item->get_tax_status()
				&& self::fee_tax_class( $fee ) === Cast::to_string( $item->get_tax_class() );
			if ( $unchanged ) {
				return false;
			}
			$this->write_fee_item( $item, $fee );
		} elseif ( $item instanceof \WC_Order_Item_Product ) {
			$unchanged = $fee->title === Cast::to_string( $item->get_name() )
				&& $fee->cost === Cast::to_float( $item->get_subtotal() )
				&& $fee->cost === Cast::to_float( $item->get_total() )
				&& self::fee_tax_class( $fee ) === Cast::to_string( $item->get_tax_class() )
				&& self::taxable_flag( $fee ) === $item->get_meta( self::META_TAXABLE );
			if ( $unchanged ) {
				return false;
			}
			$item->set_name( $fee->title );
			$item->set_subtotal( (string) $fee->cost );
			$item->set_total( (string) $fee->cost );
			$item->set_tax_class( self::fee_tax_class( $fee ) );
			$item->update_meta_data( self::META_TAXABLE, self::taxable_flag( $fee ) );
		} else {
			return false;
		}

		$item->save();

		return true;
	}

	/**
	 * Remove lines from the order (persisted by the caller's save/recalc).
	 *
	 * @param \WC_Order $order Order.
	 * @param int[] $item_ids Item ids.
	 * @return void
	 */
	public function remove_lines( $order, array $item_ids ): void {
		foreach ( $item_ids as $item_id ) {
			$order->remove_item( (int) $item_id );
		}
	}

	/**
	 * Sum of the given lines' totals including tax — what the customer
	 * actually pays for them.
	 *
	 * @param array<int, array{item: \WC_Order_Item}> $lines Lines as returned by {@see find()}.
	 * @return float
	 */
	public static function total_incl_tax( array $lines ): float {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$item = $line['item'];
			if ( self::is_amount_carrier( $item ) ) {
				$total += Cast::to_float( $item->get_total() ) + Cast::to_float( $item->get_total_tax() );
			}
		}

		return $total;
	}

	/**
	 * Sum of the given lines' tax totals — what leaves the order's tax
	 * total when they are removed.
	 *
	 * @param array<int, array{item: \WC_Order_Item}> $lines Lines as returned by {@see find()}.
	 * @return float
	 */
	public static function total_tax( array $lines ): float {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$item = $line['item'];
			if ( self::is_amount_carrier( $item ) ) {
				$total += Cast::to_float( $item->get_total_tax() );
			}
		}

		return $total;
	}

	/**
	 * The item kinds that carry a plugin fee amount — the one predicate
	 * behind both line summers, so total and tax can never disagree about
	 * which lines count.
	 *
	 * @param \WC_Order_Item $item Order item.
	 * @return bool
	 * @phpstan-assert-if-true \WC_Order_Item_Fee|\WC_Order_Item_Product $item
	 */
	private static function is_amount_carrier( $item ): bool {
		return $item instanceof \WC_Order_Item_Fee || $item instanceof \WC_Order_Item_Product;
	}

	/**
	 * Regex recognising a rendered fee title from its stored form:
	 * placeholders become wildcards, everything else matches literally.
	 *
	 * @param string $title Stored tier title (or the gateway title it falls back to).
	 * @return string PCRE pattern.
	 */
	public static function title_pattern( string $title ): string {
		$quoted = preg_quote( trim( $title ), '/' );
		foreach ( self::TITLE_PLACEHOLDERS as $placeholder ) {
			$quoted = str_replace( preg_quote( $placeholder, '/' ), '.+?', $quoted );
		}

		return '/^' . $quoted . '$/u';
	}

	/**
	 * Write a fee onto a fee item: name, amounts, tax status and class.
	 *
	 * @param \WC_Order_Item_Fee $item Fee item.
	 * @param Fee $fee The fee.
	 * @return void
	 */
	private function write_fee_item( $item, Fee $fee ): void {
		$item->set_name( $fee->title );
		$item->set_amount( (string) $fee->cost );
		$item->set_total( (string) $fee->cost );
		$item->set_tax_status( self::fee_tax_status( $fee ) );
		$item->set_tax_class( self::fee_tax_class( $fee ) );
	}

	/**
	 * The tax status a fee item carries for a fee.
	 *
	 * @param Fee $fee The fee.
	 * @return string
	 */
	private static function fee_tax_status( Fee $fee ): string {
		return $fee->taxable ? 'taxable' : 'none';
	}

	/**
	 * The tax class a line — fee item or product line — carries for a fee:
	 * the shape checkout's `create_order_fee_lines()` produces (standard
	 * class when non-taxable), which also keeps an unresolved `inherit` of
	 * a non-taxable fee away from `set_tax_class()` — it throws on the slug.
	 *
	 * @param Fee $fee The fee.
	 * @return string
	 */
	private static function fee_tax_class( Fee $fee ): string {
		return $fee->taxable ? $fee->tax_class : '';
	}

	/**
	 * The `_pay4payment_fee_taxable` value for a fee.
	 *
	 * @param Fee $fee The fee.
	 * @return string
	 */
	private static function taxable_flag( Fee $fee ): string {
		return $fee->taxable ? 'yes' : 'no';
	}

	/**
	 * Gateway whose title pattern matches an untagged fee line name, or ''.
	 *
	 * The patterns are built here rather than by the caller because this is
	 * the only thing that reads them, and only untagged lines get here — an
	 * order this plugin version wrote has none, so the usual order never
	 * compiles a regex at all (#180).
	 *
	 * @param string $name Fee line name.
	 * @return string
	 */
	private function match_title( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}
		foreach ( $this->patterns->build() as $gateway_id => $patterns ) {
			foreach ( (array) $patterns as $pattern ) {
				if ( is_string( $pattern ) && 1 === preg_match( $pattern, $name ) ) {
					return (string) $gateway_id;
				}
			}
		}

		return '';
	}
}
