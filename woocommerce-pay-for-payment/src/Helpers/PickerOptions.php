<?php

namespace Pay4Payment\Helpers;

/**
 * The picker option builders: a `{value, label}` read model over WC/WP
 * store data for the settings screen's selects (CONTEXT.md → Condition
 * options).
 *
 * All but one serve `Admin\ConditionOptionsApi`, the rules builder's
 * source – coupons, shipping methods/zones/classes, countries + states,
 * roles, categories, products; `tax_class_options()` feeds the fee's
 * tax-class select through the settings payload (`Admin\RestApi`, #154).
 * Every `value` is exactly what a rule stores and `Pro\ConditionEvaluator`
 * matches on – the wire format is the evaluator's, never the label.
 *
 * Reads that go through the `WC()` singleton go through the WC-runtime
 * adapter ({@see WooCommerce}), which stays the one seam to it; the rest
 * are WooCommerce/WordPress functions and classes read here directly.
 * Extracted from the adapter (#266) so that it keeps to the runtime
 * facts the fee paths need and this class to the screen's lists.
 */
class PickerOptions {

	/**
	 * WC-runtime adapter
	 * @var WooCommerce
	 */
	private $wc;

	/**
	 * @param WooCommerce $wc WC-runtime adapter.
	 */
	public function __construct( WooCommerce $wc ) {
		$this->wc = $wc;
	}

	/**
	 * Published coupon codes as picker options (`value` = the code as
	 * stored — WooCommerce keeps codes lower-case — `label` the same),
	 * alphabetical, capped: the list is a convenience for the rules
	 * builder, the code itself is free text in a rule (and may carry `*`
	 * wildcards), so a store with more coupons than the cap loses nothing.
	 *
	 * @param int $limit Maximum number of codes.
	 * @return array<int, array{value: string, label: string}>
	 */
	public function coupon_options( int $limit ): array {
		$options = array();
		foreach ( (array) get_posts( array(
			'post_type'              => 'shop_coupon',
			'post_status'            => 'publish',
			'numberposts'            => $limit,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			// Only the title is read: skip the meta/term cache priming (a
			// popular coupon carries one `_used_by` meta row per redemption).
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) ) as $post ) {
			$code = $post instanceof \WP_Post ? (string) $post->post_title : '';
			if ( '' !== $code ) {
				$options[] = self::option( $code, $code );
			}
		}

		return $options;
	}

	/**
	 * Every enabled shipping-method instance of every zone — the "rest of
	 * the world" zone included — as picker options: `value` is the
	 * evaluator's `method_id:instance_id`, `label` the title qualified by
	 * its zone ("Zásilkovna — Czechia"), because one method is usually
	 * configured in several zones.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function shipping_method_options(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		// Every configured zone, then zone 0 ("rest of the world").
		$zone_ids = array_map( 'intval', array_keys( (array) \WC_Shipping_Zones::get_zones() ) );
		$zone_ids[] = 0;

		$options = array();
		foreach ( $zone_ids as $zone_id ) {
			$zone = \WC_Shipping_Zones::get_zone( $zone_id );
			if ( ! $zone instanceof \WC_Shipping_Zone ) {
				continue;
			}
			$zone_name = (string) $zone->get_zone_name();
			foreach ( (array) $zone->get_shipping_methods( true ) as $method ) {
				if ( ! $method instanceof \WC_Shipping_Method ) {
					continue;
				}
				$instance_id = (int) $method->get_instance_id();
				$options[] = self::option(
					$instance_id ? $method->id . ':' . $instance_id : (string) $method->id,
					(string) $method->get_title() . ' — ' . $zone_name
				);
			}
		}

		return $options;
	}

	/**
	 * Shipping zones as picker options (`value` = the zone id a
	 * `shipping_zone` rule stores, as a string; `label` the zone name) —
	 * every configured zone, then zone 0 ("rest of the world").
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function shipping_zone_options(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		// The zone rows already carry the names — construct no zone
		// objects for them; only zone 0 ("rest of the world"), which
		// get_zones() omits, is loaded.
		$options = array();
		foreach ( (array) \WC_Shipping_Zones::get_zones() as $zone_id => $zone_data ) {
			$name = is_array( $zone_data ) ? Cast::to_string( $zone_data['zone_name'] ?? '' ) : '';
			if ( '' !== $name ) {
				$options[] = self::option( (string) (int) $zone_id, $name );
			}
		}

		$rest_of_world = \WC_Shipping_Zones::get_zone( 0 );
		if ( $rest_of_world instanceof \WC_Shipping_Zone ) {
			$options[] = self::option( '0', (string) $rest_of_world->get_zone_name() );
		}

		return $options;
	}

	/**
	 * Shipping classes as picker options (`value` = slug, what a
	 * `shipping_class` rule stores).
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function shipping_class_options(): array {
		$options = array();
		foreach ( $this->wc->shipping_classes() as $class ) {
			if ( $class instanceof \WP_Term ) {
				$options[] = self::option( (string) $class->slug, self::decode( (string) $class->name ) );
			}
		}

		return $options;
	}

	/**
	 * Countries, each followed by its states, as picker options in the
	 * evaluator's wire format: `CZ`, `US`, `US:CA` — labels state-qualified
	 * ("United States (US) — California").
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function country_options(): array {
		$countries = $this->wc->countries();
		if ( ! $countries ) {
			return array();
		}

		$options = array();
		foreach ( (array) $countries->get_countries() as $code => $name ) {
			$code = (string) $code;
			$name = self::decode( Cast::to_string( $name ) );
			$options[] = self::option( $code, $name );

			$states = $countries->get_states( $code );
			if ( ! is_array( $states ) ) {
				continue;
			}
			foreach ( $states as $state_code => $state_name ) {
				$options[] = self::option( $code . ':' . (string) $state_code, $name . ' — ' . self::decode( Cast::to_string( $state_name ) ) );
			}
		}

		return $options;
	}

	/**
	 * Site user roles as picker options (`value` = role slug, `label` the
	 * translated display name). WP data, read here like the roles themselves.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function user_role_options(): array {
		$options = array();
		foreach ( (array) wp_roles()->get_names() as $slug => $name ) {
			$options[] = self::option( (string) $slug, translate_user_role( Cast::to_string( $name ) ) );
		}

		return $options;
	}

	/**
	 * Product categories (every term, empties included) as picker options
	 * (`value` = term id, what a `product_category` rule stores). A failed
	 * taxonomy lookup is an empty list.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function product_category_options(): array {
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$options = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$options[] = self::option( (string) $term->term_id, self::decode( (string) $term->name ) );
			}
		}

		return $options;
	}

	/**
	 * Products as picker options (`value` = product id, `label`
	 * "Name (#id)"): given ids re-hydrate exactly those (a stored rule's
	 * values), otherwise a search term goes through the product data
	 * store's search, capped; neither ids nor a term is an empty answer —
	 * never a catalogue dump.
	 *
	 * @param string $search Search term.
	 * @param array<int|string, mixed> $include_ids Product ids to hydrate (wins over the term; non-ids dropped).
	 * @param int $limit Maximum number of search hits.
	 * @return array<int, array{value: string, label: string}>
	 */
	public function product_options( string $search, array $include_ids, int $limit ): array {
		$options = array();
		foreach ( $this->products( $search, $include_ids, $limit ) as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$id = (int) $product->get_id();
			$options[] = self::option( (string) $id, sprintf( '%s (#%d)', (string) $product->get_name(), $id ) );
		}
		return $options;
	}

	/**
	 * The products behind `product_options()`.
	 *
	 * Parent products only: the snapshot collects parent and variation ids
	 * per line, so a rule naming the parent covers its variations, and a
	 * search never hands out variation ids the hydration would drop.
	 *
	 * @param string $search Search term.
	 * @param array<int|string, mixed> $include_ids Product ids to hydrate.
	 * @param int $limit Maximum number of search hits.
	 * @return array<int|string, mixed> Product objects (whatever `wc_get_products()` returns).
	 */
	private function products( string $search, array $include_ids, int $limit ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$ids = self::positive_ints( $include_ids );
		if ( array() === $ids ) {
			if ( '' === $search || ! class_exists( '\WC_Data_Store' ) ) {
				return array();
			}
			$ids = self::positive_ints( (array) \WC_Data_Store::load( 'product' )->search_products( $search, '', false, false, $limit ) );
			if ( array() === $ids ) {
				return array();
			}
		}

		return (array) wc_get_products( array(
			'include' => $ids,
			'limit'   => count( $ids ),
			'orderby' => 'title',
			'order'   => 'ASC',
		) );
	}

	/**
	 * Tax classes as select options for the fee's "Tax class" field.
	 *
	 * Not a conditions-builder picker — this one rides the settings payload,
	 * not `condition-options` — but held to the same rule: `value` is the
	 * canonical value by construction, taken from the very source
	 * `Sanitizer::tax_class()` validates against. Which means the **standard
	 * class is the empty string**, the key `wc_get_product_tax_class_options()`
	 * uses and what v2 stored, never `standard`.
	 *
	 * That distinction is the whole point of building the list here. The
	 * settings app used to read WooCommerce's REST tax-classes endpoint,
	 * which calls the same class `standard`; the UI therefore offered a value
	 * the server rejected, and the rejection fell back to `inherit` — taxing
	 * the fee at the highest rate in the cart instead of the standard one
	 * (#143). Sourcing the options here removes the second vocabulary rather
	 * than translating between the two (#154).
	 *
	 * A merchant-made class whose slug is `standard` is representable: it
	 * gets its own entry, distinct from the built-in one at `''`.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public function tax_class_options(): array {
		if ( ! function_exists( 'wc_get_product_tax_class_options' ) ) {
			return array();
		}

		$options = array();
		foreach ( (array) wc_get_product_tax_class_options() as $value => $label ) {
			// Array keys: PHP turns a numeric-string key into an int, and the
			// standard class's key is the empty string — cast, never assume.
			$options[] = self::option( (string) $value, self::decode( (string) $label ) );
		}

		return $options;
	}

	/**
	 * Distinct positive integers out of a list of mixed scalars.
	 *
	 * @param array<int|string, mixed> $values Values.
	 * @return int[]
	 */
	private static function positive_ints( array $values ): array {
		$ids = array();
		foreach ( $values as $value ) {
			$id = Cast::to_int( $value );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * One picker option.
	 *
	 * @param string $value The canonical value a rule stores and the evaluator matches on.
	 * @param string $label The human label.
	 * @return array{value: string, label: string}
	 */
	private static function option( string $value, string $label ): array {
		return array(
			'value' => $value,
			'label' => $label,
		);
	}

	/**
	 * Stored names may be HTML-entity encoded; picker labels are plain text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	}

}
