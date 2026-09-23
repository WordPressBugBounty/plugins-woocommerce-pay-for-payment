<?php

namespace Pay4Payment\Fees;

/**
 * Whether the fee the cart path just computed should be withheld from the
 * cart — the display-controls seam of the cart path (CONTEXT.md → Display
 * controls, Pro seams). Presentation only: the answer never changes what
 * a fee would cost, only whether `Frontend::integrate()` adds it to this
 * calculation.
 *
 * Hide-on-cart is a Pro feature. The free build binds
 * `NullCartFeeVisibility` (never suppress); the Pro build binds
 * `Pro\DisplayControls`, which carries the licence check itself — the
 * caller asks this seam, never the Premium gate — and records a
 * suppression so its hint surfaces (classic cart row, Store API field)
 * can say why no fee is showing.
 */
interface CartFeeVisibilityInterface {

	/**
	 * Whether to withhold the computed cart fee from this calculation.
	 * Asked by `Frontend::integrate()` right before `add_fee()`, so a
	 * suppression always refers to a real fee.
	 *
	 * @return bool
	 */
	public function suppress_cart_fee(): bool;
}
