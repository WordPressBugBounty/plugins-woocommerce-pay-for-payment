<?php
/**
 * Settings Validator
 *
 * Validates payment method settings before saving.
 * Mirrors frontend validation rules for security.
 *
 * @package WooCommerce_Pay_For_Payment
 */

namespace Pay4Payment\Admin;

use Pay4Payment\Fees\ConditionSchema;
use Pay4Payment\Helpers\Cast;

/**
 * Class SettingsValidator
 *
 * Validates payment method settings
 */
class SettingsValidator {

	/**
	 * Size caps on the tier list, a tier's condition rows and a condition's
	 * value list. Generous on purpose – an order of magnitude above what a
	 * merchant configures by hand – so they never get in the way of the
	 * settings app, while bounding what one save can ask of the validator,
	 * the stored option and every fee calculation that walks the list
	 * afterwards. A client that reaches them is not the settings app.
	 */
	public const MAX_TIERS = 50;

	public const MAX_CONDITIONS = 50;

	public const MAX_CONDITION_VALUES = 500;

	/**
	 * Whether a value is a list: an array with no string keys – what the
	 * JSON decoder makes of a JSON array, numeric keys included. The
	 * serializer asks this of an incoming `extra_charge` before reading it
	 * as a tier list, so one tier sent flat is refused here rather than
	 * read as several.
	 *
	 * @phpstan-assert-if-true array<int, mixed> $value
	 * @param mixed $value Candidate value.
	 * @return bool
	 */
	public static function is_list( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( is_string( $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate payment method settings.
	 *
	 * Expects the canonical per-gateway shape (see CONTEXT.md):
	 * `payment_methods[gateway_id] = ['extra_charge' => [tiers], …method fields]`.
	 *
	 * Errors are keyed `[gateway_id]['tiers'][tier_index][field]` – or, when
	 * the tier list itself is malformed (not a list, or over the cap),
	 * `[gateway_id]['tiers']` is a string, and a row that is not a tier
	 * object is a string at its index. The `[gateway_id]['method']` bucket
	 * is reserved for method-level rules (none exist yet). The TS mirror (`validation.ts`) uses the same keys
	 * for the tier rules. Store-level settings (`$settings['general']`) use
	 * the `['general'][field]` bucket; they have no TS mirror on purpose —
	 * the UI can only emit known values (`utils/generalSettings.ts` coerces
	 * the wire), so this server-side check is the only one.
	 *
	 * @param array<int|string, mixed> $settings The settings to validate.
	 * @return array{is_valid: bool, errors: array<int|string, mixed>} Whether everything validated, plus the errors keyed as described above.
	 */
	public static function validate( $settings ) {
		$errors = array();

		if ( isset( $settings['general'] ) && is_array( $settings['general'] ) ) {
			$general_errors = self::validate_general( $settings['general'] );
			if ( ! empty( $general_errors ) ) {
				$errors['general'] = $general_errors;
			}
		}

		if ( isset( $settings['payment_methods'] ) && is_array( $settings['payment_methods'] ) ) {
			foreach ( $settings['payment_methods'] as $gateway_id => $method_settings ) {
				if ( ! is_array( $method_settings ) ) {
					continue;
				}

				if ( array_key_exists( 'extra_charge', $method_settings ) ) {
					$tier_errors = self::validate_tiers( $method_settings['extra_charge'] );
					if ( ! empty( $tier_errors ) ) {
						$errors[ $gateway_id ]['tiers'] = $tier_errors;
					}
				}
			}
		}

		return array(
			'is_valid' => empty( $errors ),
			'errors'   => $errors,
		);
	}

	/**
	 * Validate the store-level (general) settings.
	 *
	 * @param array<int|string, mixed> $general The canonical general array.
	 * @return array<string, string> Validation errors keyed by field.
	 */
	private static function validate_general( $general ) {
		$errors = array();

		// Gateway ids, and the REST route builds option names from them, so
		// nothing but a list of strings may get through here.
		if ( array_key_exists( 'methods', $general ) && ( ! self::is_list( $general['methods'] ) || Cast::to_string_list( $general['methods'] ) !== $general['methods'] ) ) {
			$errors['methods'] = __( 'Enabled methods must be a list of payment method ids', 'woocommerce-pay-for-payment' );
		}

		if ( isset( $general['fee_line_type'] ) && ! Settings::is_fee_line_type( $general['fee_line_type'] ) ) {
			$errors['fee_line_type'] = __( 'Invalid fee line type', 'woocommerce-pay-for-payment' );
		}

		return $errors;
	}

	/**
	 * Validate a method's tier list.
	 *
	 * Returns a string for a whole-field problem (not a list, over the
	 * cap), or an array keyed by tier index; each entry is either a string
	 * (row not a tier object) or the tier's field errors.
	 *
	 * A row that is not an object is an error, never skipped: the
	 * serializer keeps such a row as sent precisely so it can be refused
	 * here – a save that carries one has replaced a stored tier with junk,
	 * or (replace-tiers) every stored tier, and must not be stored.
	 *
	 * @param mixed $tiers The `extra_charge` value.
	 * @return string|array<int, string|array<string, mixed>> Empty array when valid.
	 */
	private static function validate_tiers( $tiers ) {
		if ( ! self::is_list( $tiers ) ) {
			return __( 'Fee tiers must be a list', 'woocommerce-pay-for-payment' );
		}

		if ( count( $tiers ) > self::MAX_TIERS ) {
			return sprintf(
				/* translators: %d: maximum number of fee tiers per payment method */
				__( 'A payment method can have at most %d fee tiers', 'woocommerce-pay-for-payment' ),
				self::MAX_TIERS
			);
		}

		$errors = array();

		foreach ( $tiers as $index => $tier ) {
			if ( ! is_array( $tier ) ) {
				$errors[ $index ] = __( 'Tier must be an object', 'woocommerce-pay-for-payment' );
				continue;
			}
			$tier_errors = self::validate_tier_settings( $tier );
			if ( ! empty( $tier_errors ) ) {
				$errors[ $index ] = $tier_errors;
			}
		}

		return $errors;
	}

	/**
	 * Validate one fee tier.
	 *
	 * A key that is present is validated whatever its value – `null`
	 * included, which `isset()` would have let through unvalidated and
	 * into the option (a stored null title then reaches the settings app
	 * against a contract that promises a string).
	 *
	 * @param array<int|string, mixed> $settings The tier fields.
	 * @return array<string, mixed> Validation errors keyed by field.
	 */
	private static function validate_tier_settings( $settings ) {
		$errors = array();

		// The four amount fields share one rule: any finite number, positive
		// or negative, and only a value `is_numeric()` disagrees with is
		// refused. They differ solely in the message. Mirrors
		// `validateNumeric()` (TS, issue #189).
		//
		// Negative is allowed everywhere on purpose. A negative charge is a
		// discount – the readme markets it – and the bound that limits a
		// discount is itself negative: "5 % off, never more than 50 off" is a
		// minimum of -50, since `FeeEngine` clamps with `max( $min, $cost )`.
		// Zero disables a bound. v2 stored whatever `floatval()` produced and
		// only refused `min > max` with both bounds positive; v3 briefly
		// rejected any negative, which made discount caps unconfigurable and
		// blocked the save of a migrated method that already had one (#146).
		$amount_fields = array(
			'charges_fixed' => __( 'Fixed fee must be a valid number', 'woocommerce-pay-for-payment' ),
			'charges_percentage_value' => __( 'Percentage fee must be a valid number', 'woocommerce-pay-for-payment' ),
			'charges_percentage_minimum' => __( 'Minimum fee must be a valid number', 'woocommerce-pay-for-payment' ),
			'charges_percentage_maximum' => __( 'Maximum fee must be a valid number', 'woocommerce-pay-for-payment' ),
		);

		foreach ( $amount_fields as $field => $message ) {
			if ( array_key_exists( $field, $settings ) && ! is_numeric( $settings[ $field ] ) ) {
				$errors[ $field ] = $message;
			}
		}

		// Validate min/max relationship
		if ( array_key_exists( 'charges_percentage_minimum', $settings ) && array_key_exists( 'charges_percentage_maximum', $settings ) ) {
			$error = self::validate_min_max_relationship(
				$settings['charges_percentage_minimum'],
				$settings['charges_percentage_maximum']
			);
			if ( $error ) {
				$errors['charges_percentage_maximum'] = $error;
			}
		}

		// Validate item title
		if ( array_key_exists( 'item_title', $settings ) ) {
			$error = self::validate_item_title( $settings['item_title'] );
			if ( $error ) {
				$errors['item_title'] = $error;
			}
		}

		// Validate conditions (Pro conditional rules)
		if ( array_key_exists( 'conditions', $settings ) ) {
			$condition_errors = self::validate_conditions( $settings['conditions'] );
			if ( ! empty( $condition_errors ) ) {
				$errors['conditions'] = $condition_errors;
			}
		}

		return $errors;
	}

	/**
	 * Validate a tier's `conditions` value.
	 *
	 * Returns a string for a whole-field problem (not a list), or an array
	 * keyed by row index; each row error is either a string (row not an
	 * object) or an array keyed by field (`type`, `operator`, `value`).
	 * The TS mirror (`validation.ts`) flattens the same keys.
	 *
	 * @param mixed $conditions The conditions value.
	 * @return string|array<int, string|array<string, string>> Empty array when valid.
	 */
	private static function validate_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return __( 'Conditions must be a list', 'woocommerce-pay-for-payment' );
		}

		if ( count( $conditions ) > self::MAX_CONDITIONS ) {
			return sprintf(
				/* translators: %d: maximum number of conditions per fee tier */
				__( 'A fee tier can have at most %d conditions', 'woocommerce-pay-for-payment' ),
				self::MAX_CONDITIONS
			);
		}

		$errors = array();

		foreach ( array_values( $conditions ) as $index => $condition ) {
			$condition_errors = self::validate_condition( $condition );
			if ( ! empty( $condition_errors ) ) {
				$errors[ $index ] = $condition_errors;
			}
		}

		return $errors;
	}

	/**
	 * Validate one condition row.
	 *
	 * Known types are validated strictly against the condition schema's
	 * vocabulary. An unknown (but non-empty string) type passes — data
	 * authored by a newer or 3rd-party build must stay savable after a
	 * downgrade, and the (Pro) evaluator fails unknown types closed at
	 * runtime, so they can never charge a fee here.
	 *
	 * @param mixed $condition The condition row.
	 * @return string|array<string, string> Empty array when valid.
	 */
	private static function validate_condition( $condition ) {
		if ( ! is_array( $condition ) ) {
			return __( 'Condition must be an object', 'woocommerce-pay-for-payment' );
		}

		$errors = array();

		$type = isset( $condition['type'] ) ? $condition['type'] : null;
		if ( ! is_string( $type ) || '' === trim( $type ) ) {
			$errors['type'] = __( 'Condition type must be a non-empty string', 'woocommerce-pay-for-payment' );
			return $errors;
		}

		if ( ! isset( ConditionSchema::OPERATORS[ $type ] ) ) {
			return $errors;
		}

		$operator = isset( $condition['operator'] ) ? $condition['operator'] : null;
		if ( ! is_string( $operator ) || ! in_array( $operator, ConditionSchema::OPERATORS[ $type ], true ) ) {
			$errors['operator'] = __( 'Invalid operator for this condition type', 'woocommerce-pay-for-payment' );
		}

		$value = isset( $condition['value'] ) ? $condition['value'] : null;
		if ( ConditionSchema::TYPE_CART_TOTAL === $type ) {
			if ( ! is_numeric( $value ) ) {
				$errors['value'] = __( 'Cart total condition value must be a valid number', 'woocommerce-pay-for-payment' );
			} elseif ( floatval( $value ) < 0 ) {
				$errors['value'] = __( 'Cart total condition value cannot be negative', 'woocommerce-pay-for-payment' );
			}
		} elseif ( ! is_array( $value ) || array() === $value || count( array_filter( $value, 'is_scalar' ) ) !== count( $value ) ) {
			$errors['value'] = __( 'Condition value must be a non-empty list of choices', 'woocommerce-pay-for-payment' );
		} elseif ( count( $value ) > self::MAX_CONDITION_VALUES ) {
			$errors['value'] = sprintf(
				/* translators: %d: maximum number of values per condition */
				__( 'A condition can list at most %d values', 'woocommerce-pay-for-payment' ),
				self::MAX_CONDITION_VALUES
			);
		}

		return $errors;
	}

	/**
	 * Validate min/max relationship
	 * Maximum must be >= minimum, unless either bound is 0 (disabled)
	 *
	 * @param mixed $minimum Minimum value.
	 * @param mixed $maximum Maximum value.
	 * @return string|null Error message or null if valid.
	 */
	private static function validate_min_max_relationship( $minimum, $maximum ) {
		$min_value = Cast::to_float( $minimum );
		$max_value = Cast::to_float( $maximum );

		// Zero disables a bound (`FeeEngine` reads both through `! empty()`,
		// so a zero becomes -INF / INF), and a disabled bound cannot conflict
		// with the other one. v2 drew the same line from the other side: it
		// only refused `min > max` when both were positive (issue #146).
		if ( $min_value === 0.0 || $max_value === 0.0 ) {
			return null;
		}

		if ( $max_value < $min_value ) {
			return __( 'Maximum fee must be greater than or equal to minimum fee', 'woocommerce-pay-for-payment' );
		}

		return null;
	}

	/**
	 * Validate item title (required field)
	 *
	 * The 200 cap counts *characters*, not bytes: `strlen()` counts UTF-8
	 * bytes, so a diacritic-heavy title well under 200 characters could run
	 * past 200 bytes and fail here while the `validation.ts` mirror (which
	 * counted UTF-16 code units) still passed it — invisible to the merchant
	 * until the generic 400 (issue #177). `mb_strlen()` matches the TS side's
	 * code-point count for every character on the wire (astral code points
	 * aside, which titles do not realistically contain).
	 *
	 * @param mixed $value The value to validate.
	 * @return string|null Error message or null if valid.
	 */
	private static function validate_item_title( $value ) {
		if ( ! is_string( $value ) ) {
			return __( 'Fee label must be a string', 'woocommerce-pay-for-payment' );
		}

		$trimmed = trim( $value );

		if ( empty( $trimmed ) ) {
			return __( 'Fee label cannot be empty', 'woocommerce-pay-for-payment' );
		}

		if ( mb_strlen( $value ) > 200 ) {
			return __( 'Fee label is too long (maximum 200 characters)', 'woocommerce-pay-for-payment' );
		}

		return null;
	}
}
