<?php

namespace Pay4Payment\Fees;

/**
 * The free build's cart-fee visibility: the fee always shows. Hide-on-cart
 * is Pro; with this bound (`Bindings::SEAMS`) the cart charges and
 * displays exactly as it did pre-Pro, whatever a lapsed configuration
 * still stores.
 */
final class NullCartFeeVisibility implements CartFeeVisibilityInterface {

	/**
	 * Never suppress.
	 *
	 * @return bool
	 */
	public function suppress_cart_fee(): bool {
		return false;
	}
}
