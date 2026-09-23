<?php

namespace Pay4Payment\Fees;

/**
 * The condition vocabulary: the types a tier's `conditions` rows may carry
 * and the operators each type allows — the single PHP source of truth
 * (CONTEXT.md → Condition; the TS mirror is `CONDITION_OPERATORS` in
 * `validation.ts`).
 *
 * Shared by both builds on purpose. The free build validates and stores
 * condition rows (the merge invariant keeps Pro data intact across a
 * downgrade and the validator must know what a well-formed row is); only
 * the Pro build also evaluates them (`Pro\ConditionEvaluator`, stripped
 * from the free build). Knowing the vocabulary is not a Pro feature —
 * acting on it is.
 */
final class ConditionSchema {

	public const TYPE_CART_TOTAL = 'cart_total';
	public const TYPE_USER_ROLE = 'user_role';
	public const TYPE_SHIPPING_ZONE = 'shipping_zone';
	public const TYPE_SHIPPING_CLASS = 'shipping_class';
	public const TYPE_PRODUCT = 'product';
	public const TYPE_PRODUCT_CATEGORY = 'product_category';
	/**
	 * Applied coupon codes (as the merchant typed them; `*` wildcards).
	 */
	public const TYPE_COUPON = 'coupon';
	/**
	 * The chosen shipping method: `method_id:instance_id` (one zone
	 * instance), or a bare `method_id` for every instance of that method
	 * (`*` wildcards). The zone itself is `shipping_zone`.
	 */
	public const TYPE_SHIPPING_METHOD = 'shipping_method';
	/**
	 * Destination country `CZ`, or state `US:CA` — a country value also
	 * covers every state of that country.
	 */
	public const TYPE_COUNTRY = 'country';

	public const OP_AT_LEAST = 'at_least';
	public const OP_AT_MOST = 'at_most';
	public const OP_IN = 'in';
	public const OP_NOT_IN = 'not_in';

	/**
	 * Allowed operators per condition type. `SettingsValidator` (free and
	 * Pro), `Pro\ConditionEvaluator` and the TS mirror all follow this map.
	 */
	public const OPERATORS = [
		self::TYPE_CART_TOTAL => [ self::OP_AT_LEAST, self::OP_AT_MOST ],
		self::TYPE_USER_ROLE => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_SHIPPING_ZONE => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_SHIPPING_CLASS => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_PRODUCT => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_PRODUCT_CATEGORY => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_COUPON => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_SHIPPING_METHOD => [ self::OP_IN, self::OP_NOT_IN ],
		self::TYPE_COUNTRY => [ self::OP_IN, self::OP_NOT_IN ],
	];

	/**
	 * Types whose values match as case-insensitive globs in the evaluator
	 * (`SUMMER*` starts with, `*2026` ends with, `*SALE*` contains): the
	 * free-text-shaped ones, whose values come in families. Stored values
	 * stay literal; only `Pro\ConditionEvaluator` interprets the `*`.
	 */
	public const WILDCARD_TYPES = [ self::TYPE_COUPON, self::TYPE_SHIPPING_METHOD ];
}
