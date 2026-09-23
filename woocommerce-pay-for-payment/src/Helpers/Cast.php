<?php

namespace Pay4Payment\Helpers;

/**
 * Scalar coercions for values that reach the plugin as `mixed` — option
 * arrays, filter results, session data, request payloads.
 *
 * Each method behaves exactly like the corresponding PHP cast for scalars
 * and null (the values those sources actually carry) and maps anything
 * else — arrays, objects — to the type's empty value, where a bare cast
 * would warn, throw, or yield `1` / `"Array"`. Boundary code can then stay
 * honest about receiving `mixed` without a cast-on-mixed at every read.
 */
final class Cast {

	/**
	 * Coerce to float: `(float)` for scalars and null, `0.0` otherwise.
	 *
	 * @param mixed $value Value.
	 * @return float
	 */
	public static function to_float( $value ): float {
		return is_scalar( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Coerce to string: `(string)` for scalars and null, `''` otherwise.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function to_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Coerce to int: `(int)` for numeric values (ids arrive as ints or
	 * numeric strings — option values, hook arguments, meta), `0` otherwise.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function to_int( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * The string entries of a list, re-indexed — everything else dropped.
	 *
	 * @param array<int|string, mixed> $values Values.
	 * @return string[]
	 */
	public static function to_string_list( array $values ): array {
		return array_values( array_filter( $values, 'is_string' ) );
	}
}
