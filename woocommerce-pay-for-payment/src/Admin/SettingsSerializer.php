<?php

namespace Pay4Payment\Admin;

use Pay4Payment\Helpers\Cast;
use Pay4Payment\Helpers\Sanitizer;

/**
 * Class SettingsSerializer
 *
 * The canonical↔wire module (see CONTEXT.md): `to_wire()` assembles the REST
 * payload — the canonical schema per gateway inside the `{methods,
 * paymentMethods}` envelope — and `from_wire_method()` turns one gateway's
 * incoming payload back into the canonical array to store.
 *
 * `from_wire_method()` carries the merge invariant: saving known fields never
 * touches unknown stored keys, per tier and per method. Tiers the UI does not
 * send and Pro keys inside sent tiers survive a save verbatim; tiers are only
 * ever deleted through the explicit replace-tiers operation the Pro multi-tier
 * editor sends (see `from_wire_method()`). Validation is NOT this module's job
 * — SettingsValidator is the trust boundary and runs on this module's output.
 */
class SettingsSerializer {

	/**
	 * Known per-tier keys (FeeTier). Everything else on a tier is preserved
	 * from the stored counterpart.
	 */
	private const TIER_NUMERIC_KEYS = [
		'charges_fixed',
		'charges_percentage_value',
		'charges_percentage_minimum',
		'charges_percentage_maximum',
	];

	/**
	 * Build the wire payload from stored canonical data.
	 *
	 * @param string[] $methods Enabled gateway ids.
	 * @param array<string, array<int|string, mixed>> $per_method Stored per-gateway option arrays, keyed by gateway id.
	 * @param array<int|string, mixed> $general Stored general (store-level) settings.
	 * @return array{methods: string[], paymentMethods: array<string, array<int|string, mixed>>, offCheckoutFees: bool, feeLineType: string, hideFeeOnCart: bool, showFeeInDescription: bool}
	 */
	public function to_wire( array $methods, array $per_method, array $general = [] ): array {
		$payment_methods = [];

		foreach ( $per_method as $method_id => $stored ) {
			$payment_methods[ $method_id ] = $this->normalize_method( is_array( $stored ) ? $stored : [] );
		}

		$general = array_merge( Settings::DEFAULTS_GENERAL, $general );
		$type = $general['fee_line_type'];

		return [
			'methods' => $methods,
			'paymentMethods' => $payment_methods,
			// The store-level (global) wire keys, camelCase. The single
			// read-side boundary for globals — add future global keys here.
			// A corrupt stored type reads as a fee line, like the PHP accessor.
			'offCheckoutFees' => (bool) $general['off_checkout_fees'],
			'feeLineType' => Settings::is_fee_line_type( $type ) ? $type : Settings::FEE_LINE_TYPE_FEE,
			'hideFeeOnCart' => (bool) $general['hide_fee_on_cart'],
			'showFeeInDescription' => (bool) $general['show_fee_in_description'],
		];
	}

	/**
	 * Merge the incoming general (store-level) wire keys into the stored
	 * general array — the write-side boundary for globals (camel→snake,
	 * casts). Honours the merge invariant: keys the UI did not send, and
	 * unknown stored keys, survive untouched.
	 *
	 * The line-type enum passes through unsanitized when it is not a known
	 * value (but is a string) so the trust boundary (SettingsValidator)
	 * rejects it — the same discipline as the tier fields.
	 *
	 * @param array<int|string, mixed> $incoming Wire payload (the whole request params).
	 * @param array<int|string, mixed> $stored Stored general settings.
	 * @return array<int|string, mixed> Canonical general array ready to store.
	 */
	public function from_wire_general( array $incoming, array $stored ): array {
		$result = array_merge( Settings::DEFAULTS_GENERAL, $stored );

		if ( array_key_exists( 'methods', $incoming ) ) {
			// Uncast: the trust boundary requires a list of gateway ids, and
			// a cast would turn a stray string into a one-entry list of it.
			$result['methods'] = $incoming['methods'];
		}
		// The pre-#236 keys are dead the moment the screen saves: what they
		// described has already been read out of them
		// (`Settings::upgrade_general_shape()`), and leaving them behind would
		// let a future reader find two answers to the same question.
		foreach ( Settings::LEGACY_GENERAL_KEYS as $legacy_key ) {
			unset( $result[ $legacy_key ] );
		}

		if ( array_key_exists( 'offCheckoutFees', $incoming ) ) {
			$result['off_checkout_fees'] = (bool) $incoming['offCheckoutFees'];
		}
		if ( array_key_exists( 'feeLineType', $incoming ) ) {
			$type = $incoming['feeLineType'];
			$result['fee_line_type'] = is_string( $type ) ? sanitize_text_field( $type ) : $type;
		}
		if ( array_key_exists( 'hideFeeOnCart', $incoming ) ) {
			$result['hide_fee_on_cart'] = (bool) $incoming['hideFeeOnCart'];
		}
		if ( array_key_exists( 'showFeeInDescription', $incoming ) ) {
			$result['show_fee_in_description'] = (bool) $incoming['showFeeInDescription'];
		}

		return $result;
	}

	/**
	 * Merge one gateway's incoming wire payload into its stored canonical
	 * settings, honoring the merge invariant.
	 *
	 * With `$replace_tiers` (the explicit Pro tier operation, sent by the
	 * multi-tier editor) the incoming `extra_charge` is the complete,
	 * ordered tier list — reorders and removals included. Each sent tier
	 * may name the stored tier it descends from (`_stored_index`, a
	 * wire-only key that is never stored) so unknown stored keys follow
	 * their tier through a reorder instead of sticking to an array slot.
	 * Without the flag the free-UI merge semantics apply: index-aligned
	 * merge, unsent trailing tiers preserved verbatim.
	 *
	 * @param array<int|string, mixed> $incoming Incoming per-gateway payload (canonical shape).
	 * @param array<int|string, mixed> $stored Stored per-gateway option array.
	 * @param string $default_title Gateway title used for the "%s fee" default.
	 * @param bool $replace_tiers Whether the incoming tier list replaces the stored one.
	 * @return array<int|string, mixed> Canonical method array ready to store.
	 */
	public function from_wire_method( array $incoming, array $stored, string $default_title, bool $replace_tiers = false ): array {
		$result = $this->normalize_method( $stored );

		if ( array_key_exists( 'taxes', $incoming ) ) {
			$result['taxes'] = (bool) $incoming['taxes'];
		}
		if ( array_key_exists( 'includes_taxes', $incoming ) ) {
			$result['includes_taxes'] = (bool) $incoming['includes_taxes'];
		}
		if ( array_key_exists( 'tax_class', $incoming ) ) {
			$result['tax_class'] = Sanitizer::tax_class( $incoming['tax_class'] );
		}
		if ( array_key_exists( 'disable_on', $incoming ) ) {
			$result['disable_on'] = $this->sanitize_choices( (array) $incoming['disable_on'], Settings::DISABLE_ON_OPTIONS );
		}
		if ( isset( $incoming['extra_charge'] ) && is_array( $incoming['extra_charge'] ) ) {
			if ( ! SettingsValidator::is_list( $incoming['extra_charge'] ) ) {
				// One tier sent flat where the list belongs. Not wrapped into
				// a list and not read as one (its values are not tiers): it
				// passes through untouched so the trust boundary rejects the
				// save, the same discipline as the numeric tier fields.
				$result['extra_charge'] = $incoming['extra_charge'];
				return $result;
			}

			// normalize_method() always leaves a non-empty tier list here.
			$stored_tiers = is_array( $result['extra_charge'] ) ? $result['extra_charge'] : [];
			$merged_tiers = $this->merge_tiers( array_values( $incoming['extra_charge'] ), $stored_tiers, $default_title, $replace_tiers );

			// The canonical tier list is never empty; an empty replace
			// payload keeps the stored tiers (like an empty merge one).
			if ( ! empty( $merged_tiers ) ) {
				$result['extra_charge'] = $merged_tiers;
			}
		}

		return $result;
	}

	/**
	 * Merge the incoming tier list over the stored one, per the merge
	 * invariant or the replace-tiers operation (see `from_wire_method()`).
	 *
	 * An entry that is not an array is not a tier and is never made into
	 * one: it is kept in its slot as sent so the trust boundary reports it
	 * (`Tier must be an object`) and the save is refused. Casting it would
	 * turn it into the default tier, silently replacing what the merchant
	 * had stored there – with replace-tiers, every stored tier at once.
	 *
	 * @param array<int, mixed> $incoming_tiers Incoming tier list (a list, re-indexed).
	 * @param array<int|string, mixed> $stored_tiers Stored tiers, normalized.
	 * @param string $default_title Gateway title for the "%s fee" default.
	 * @param bool $replace_tiers Whether the incoming tier list replaces the stored one.
	 * @return array<int, mixed> Merged tiers; empty when nothing was sent.
	 */
	private function merge_tiers( array $incoming_tiers, array $stored_tiers, string $default_title, bool $replace_tiers ): array {
		$merged_tiers = [];

		if ( $replace_tiers ) {
			// The sent list wins: each tier merges over the stored tier
			// it descends from (its `_stored_index`), a tier without one
			// is new, and stored tiers nothing references are dropped.
			foreach ( $incoming_tiers as $incoming_tier ) {
				if ( ! is_array( $incoming_tier ) ) {
					$merged_tiers[] = $incoming_tier;
					continue;
				}
				$origin = $incoming_tier['_stored_index'] ?? null;
				$base = $this->stored_tier_base( $stored_tiers, is_numeric( $origin ) ? (int) $origin : -1 );
				$merged_tiers[] = $this->merge_tier( $incoming_tier, $base, $default_title );
			}

			return $merged_tiers;
		}

		foreach ( $incoming_tiers as $index => $incoming_tier ) {
			if ( ! is_array( $incoming_tier ) ) {
				$merged_tiers[] = $incoming_tier;
				continue;
			}
			$base = $this->stored_tier_base( $stored_tiers, $index );
			$merged_tiers[] = $this->merge_tier( $incoming_tier, $base, $default_title );
		}

		// The merge invariant: tiers the UI did not send are preserved.
		foreach ( array_slice( $stored_tiers, count( $incoming_tiers ) ) as $untouched_tier ) {
			$merged_tiers[] = $untouched_tier;
		}

		return $merged_tiers;
	}

	/**
	 * The stored tier an incoming tier merges over: the addressed stored
	 * tier when it exists and is well-formed, the default tier otherwise.
	 *
	 * @param array<int|string, mixed> $stored_tiers Stored tiers.
	 * @param int $index Stored index to resolve (-1 = none).
	 * @return array<int|string, mixed>
	 */
	private function stored_tier_base( array $stored_tiers, int $index ): array {
		return isset( $stored_tiers[ $index ] ) && is_array( $stored_tiers[ $index ] )
			? $stored_tiers[ $index ]
			: $this->normalize_tier( [] );
	}

	/**
	 * Normalize a stored option array to the canonical shape: defaults
	 * filled, types cast, tiers non-empty. Unknown keys pass through on both
	 * the method and tier level.
	 *
	 * @param array<int|string, mixed> $stored Stored per-gateway option array.
	 * @return array<int|string, mixed>
	 */
	private function normalize_method( array $stored ): array {
		$method = Settings::complete_method( $stored );

		$tiers = is_array( $method['extra_charge'] ) && ! empty( $method['extra_charge'] )
			? array_values( $method['extra_charge'] )
			: Settings::DEFAULTS_METHODS['extra_charge'];

		$method['extra_charge'] = array_map( function ( $tier ) {
			return $this->normalize_tier( (array) $tier );
		}, $tiers );

		$method['taxes'] = (bool) $method['taxes'];
		$method['includes_taxes'] = (bool) $method['includes_taxes'];
		$method['disable_on'] = (array) $method['disable_on'];

		return $method;
	}

	/**
	 * Normalize one stored tier to the canonical shape: completed against
	 * the default tier, numeric fields float-cast, the two lists arrays.
	 * Unknown keys pass through.
	 *
	 * @param array<int|string, mixed> $tier Stored tier (may be sparse).
	 * @return array<int|string, mixed>
	 */
	private function normalize_tier( array $tier ): array {
		$tier = Settings::complete_tier( $tier );
		foreach ( self::TIER_NUMERIC_KEYS as $key ) {
			$tier[ $key ] = Cast::to_float( $tier[ $key ] );
		}
		$tier['charges_percentage_include'] = (array) $tier['charges_percentage_include'];
		$tier['conditions'] = is_array( $tier['conditions'] ) ? array_values( $tier['conditions'] ) : (array) $tier['conditions'];
		return $tier;
	}

	/**
	 * Merge one incoming tier over its stored counterpart. Known FeeTier keys
	 * are overwritten (sanitized/coerced); unknown keys survive from the base.
	 *
	 * @param array<int|string, mixed> $incoming Incoming tier.
	 * @param array<int|string, mixed> $base Stored counterpart tier or the default tier, normalized (`normalize_tier()`).
	 * @param string $default_title Gateway title for the "%s fee" default.
	 * @return array<int|string, mixed>
	 */
	private function merge_tier( array $incoming, array $base, string $default_title ): array {
		$tier = $base;

		if ( array_key_exists( 'item_title', $incoming ) ) {
			// Non-string input passes through untouched so the trust
			// boundary rejects it (same discipline as the numeric fields).
			$tier['item_title'] = is_string( $incoming['item_title'] )
				? sanitize_text_field( $incoming['item_title'] )
				: $incoming['item_title'];
		}
		foreach ( self::TIER_NUMERIC_KEYS as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				// Coerce only genuinely numeric input; anything else passes
				// through untouched so the trust boundary (SettingsValidator,
				// which runs on this method's output) can reject it.
				$tier[ $key ] = is_numeric( $incoming[ $key ] ) ? floatval( $incoming[ $key ] ) : $incoming[ $key ];
			}
		}
		if ( array_key_exists( 'charges_percentage_include', $incoming ) ) {
			$tier['charges_percentage_include'] = $this->sanitize_choices(
				(array) $incoming['charges_percentage_include'],
				Settings::PERCENTAGE_INCLUDE_OPTIONS
			);
		}
		if ( array_key_exists( 'conditions', $incoming ) ) {
			$tier['conditions'] = $this->sanitize_conditions( $incoming['conditions'] );
		}

		// Sent tiers always end up with a displayable title. The only place
		// this rule lives: the settings app sends the label as the merchant
		// left it and reads the healed one back off the save response (#193),
		// so a stored title cannot depend on which side did the filling.
		if ( is_string( $tier['item_title'] ) && '' === trim( $tier['item_title'] ) ) {
			$tier['item_title'] = sprintf(
				/* translators: %s: payment method title */
				__( '%s fee', 'woocommerce-pay-for-payment' ),
				$default_title
			);
		}

		return $tier;
	}

	/**
	 * Sanitize an incoming `conditions` value (Pro conditional rules).
	 *
	 * Text fields of well-formed rows are sanitized; every structure
	 * violation passes through untouched so the trust boundary
	 * (SettingsValidator, which runs on this method's output) rejects it
	 * — the same discipline as the numeric tier fields. Unknown keys on a
	 * row survive (forward compatibility with newer condition shapes).
	 *
	 * @param mixed $conditions Incoming conditions value.
	 * @return mixed
	 */
	private function sanitize_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return $conditions;
		}

		return array_map( function ( $condition ) {
			if ( ! is_array( $condition ) ) {
				return $condition;
			}

			foreach ( [ 'type', 'operator' ] as $key ) {
				if ( isset( $condition[ $key ] ) && is_string( $condition[ $key ] ) ) {
					$condition[ $key ] = sanitize_text_field( $condition[ $key ] );
				}
			}

			if ( isset( $condition['value'] ) ) {
				$condition['value'] = $this->sanitize_condition_value( $condition['value'] );
			}

			return $condition;
		}, array_values( $conditions ) );
	}

	/**
	 * Sanitize a condition's `value`: strings (standalone or inside the
	 * choices list) are text-sanitized, everything else passes through
	 * for the trust boundary to judge.
	 *
	 * @param mixed $value Condition value.
	 * @return mixed
	 */
	private function sanitize_condition_value( $value ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		if ( is_array( $value ) ) {
			return array_map( function ( $entry ) {
				return is_string( $entry ) ? sanitize_text_field( $entry ) : $entry;
			}, array_values( $value ) );
		}

		return $value;
	}

	/**
	 * Keep only allowed values. Non-string entries can never match a
	 * choice, so they are dropped up front.
	 *
	 * @param array<int|string, mixed> $values Submitted values.
	 * @param string[] $allowed Allowed values.
	 * @return string[]
	 */
	private function sanitize_choices( array $values, array $allowed ): array {
		$submitted = [];
		foreach ( $values as $value ) {
			if ( is_string( $value ) ) {
				$submitted[] = sanitize_text_field( $value );
			}
		}

		return array_values( array_intersect( $allowed, $submitted ) );
	}
}
