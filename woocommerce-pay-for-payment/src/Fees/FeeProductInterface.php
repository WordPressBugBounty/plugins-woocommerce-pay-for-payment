<?php

namespace Pay4Payment\Fees;

/**
 * The product that carries the plugin's fee as a line item in product mode
 * — the ERP/invoicing option of the off-checkout fee sync (CONTEXT.md →
 * Off-checkout fee mode, Managed fee product, Pro seams).
 *
 * Product mode is a Pro feature. The free build binds `NullFeeProduct`
 * (there is no product: a fee line is always a `WC_Order_Item_Fee`); the
 * Pro build binds `Pro\ManagedFeeProduct`, which yields nothing without a
 * licence — the caller asks this seam, never the Premium gate.
 */
interface FeeProductInterface {

	/**
	 * The fee product, or null when there is none — the free build, an
	 * unlicensed Pro store, or WooCommerce could not create it.
	 *
	 * @return \WC_Product|null
	 */
	public function get(): ?\WC_Product;
}
