<?php

namespace Pay4Payment\Fees;

/**
 * The free build's fee product: there is none. Product mode is Pro; with
 * this bound (`Bindings::SEAMS`) every line the sync writes is a plain
 * `WC_Order_Item_Fee`, whatever mode a lapsed Pro configuration still
 * stores.
 */
final class NullFeeProduct implements FeeProductInterface {

	/**
	 * No product — fee lines only.
	 *
	 * @return \WC_Product|null
	 */
	public function get(): ?\WC_Product {
		return null;
	}
}
