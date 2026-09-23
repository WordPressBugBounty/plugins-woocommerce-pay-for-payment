<?php

namespace Pay4Payment\Helpers;

class Sanitizer {

	/**
	 * Sanitize tax class: a known tax class slug (or `inherit`) passes,
	 * anything else — non-string input included — falls back to `inherit`.
	 *
	 * The standard class has two names: WooCommerce stores it as the empty
	 * string (the key `wc_get_product_tax_class_options()` uses, and what v2
	 * stored), while its REST tax-classes endpoint calls it `standard`. The
	 * settings screen's select is fed canonical values directly (its options
	 * come from that same PHP function, shipped in the settings GET payload —
	 * #154), so the REST spelling is never met on the wire; this defensive
	 * translation stays for any other caller that still hands in that slug.
	 * Accept it and normalize it, because the fallback is not a no-op:
	 * `inherit` taxes the fee at the highest rate in the cart, so rejecting it
	 * silently changed the tax charged (#143).
	 *
	 * Known classes are matched first, so the translation can only ever catch
	 * a slug that means nothing else: `WC_Tax::create_tax_class()` checks new
	 * classes against the *additional* ones only, so a store really can hold a
	 * merchant-made class whose slug is `standard`, and that class must keep
	 * winning over the REST spelling of the built-in one.
	 *
	 * @param mixed $tax_class Tax class.
	 * @return string
	 */
	public static function tax_class( $tax_class ) {
		if ( ! is_string( $tax_class ) ) {
			return 'inherit';
		}
		$tax_class_sanitized = \sanitize_text_field( $tax_class );
		$available_tax_classes = array_merge(array_keys(\wc_get_product_tax_class_options()), ['inherit']);
		if ( in_array( $tax_class_sanitized, $available_tax_classes, true ) ) {
			return $tax_class_sanitized;
		}
		if ( 'standard' === $tax_class_sanitized ) {
			return '';
		}
		return 'inherit';
	}

}
