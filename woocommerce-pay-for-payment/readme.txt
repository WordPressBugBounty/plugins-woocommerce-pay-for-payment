=== Pay for Payment for WooCommerce – Payment Gateway Fees & Discounts ===
Contributors: vyskoczilova
Plugin URI: https://payforpayment.com/
Donate link: https://paypal.me/KarolinaVyskocilova
Tags: payment fee, cod fee, surcharge, checkout fee, payment gateway
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 7.7.0
WC tested up to: 11.2.1
Stable tag: 3.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Payment gateway fees & discounts for WooCommerce: COD surcharges, card fees, fixed or percentage — on every gateway, block checkout included.

== Description ==

Add a payment fee or a discount to any WooCommerce payment method: a flat amount, a percentage of the cart, or both together. Charge a cash-on-delivery fee, a card surcharge, or a checkout fee on any gateway — so the methods that cost you money pay for themselves. Or use a **negative** amount as a discount, rewarding shoppers who pick the method that costs you the least.

Configure everything from a single, WooCommerce-native settings screen — **WooCommerce > Settings > Pay for payment** — that lists every payment method your store has, enabled or not, in one place. This also covers gateways whose own settings screens don't leave room for extra fields (WooCommerce Payments, Stripe, Klarna, and other React-based gateways), because the plugin no longer depends on the gateway's own settings form.

Documentation, worked examples and the free/Pro comparison are at [payforpayment.com](https://payforpayment.com/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment).

**Need the fee only on some orders?** [Pay for Payment Pro](https://payforpayment.com/free-vs-pro/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment) adds conditions (cart total, country, shipping method, user role and more), several fee tiers per method, and fees on manual and "Pay for order" orders — no code. [Try it free for 7 days](https://payforpayment.com/pricing/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment), no card needed.

= Features =

- **Fixed charge and/or a percentage** of the cart, combinable ("2.5% + 0.50").
- **Minimum and maximum caps** — "charge at least" and "charge at most" limit the whole fee, fixed part included, whenever a percentage is set.
- **Negative amounts** turn the charge into a discount — reward shoppers for picking a cheaper payment method instead of only punishing expensive ones.
- Choose what the percentage is calculated from: cart subtotal plus any combination of **shipping, coupons and cart taxes** (details in the FAQ).
- **Tax-aware**: mark the fee taxable, pick its tax class (including "inherit", the highest rate already present in the cart), or enter an amount that already includes tax.
- **Disable for free or zero-cost shipping**, per gateway.
- **Custom fee title with placeholders** — `[FIXED_AMOUNT]`, `[PERCENT_AMOUNT]`, `[CART_TOTAL]`, `[MINIMUM_AMOUNT]`, `[MAXIMUM_AMOUNT]`.
- **VAT-exempt customers** are never charged fee tax.
- Works on the **classic checkout** and, with basic support, on the **WooCommerce block checkout** — fees calculate and the total updates when the shopper changes payment method.
- Settings **migrate automatically** for stores upgrading from Pay for Payment 2.x; nothing to reconfigure, and your old settings are left exactly where they were.
- **Plugin API** for anything the UI doesn't cover yet — see the FAQ.

= Compatibility =

- **WooCommerce block checkout** (basic support — see the FAQ for a known limitation with block themes).
- **HPOS** (WooCommerce's custom order tables) — declared compatible.
- **WPML** — see the FAQ.

The fee is converted to the shopper's currency, with nothing to switch on, by:

- [Price Based on Country for WooCommerce](https://wordpress.org/plugins/woocommerce-product-price-based-on-countries/) & PRO (1.8+)
- [WooCommerce Multilingual & Multicurrency (WCML)](https://wordpress.org/plugins/woocommerce-multilingual/)
- [CURCY – Multi Currency for WooCommerce](https://wordpress.org/plugins/woo-multi-currency/) & PRO
- [FOX – Currency Switcher for WooCommerce (WOOCS)](https://wordpress.org/plugins/woocommerce-currency-switcher/)
- [WooCommerce Multi-Currency](https://woocommerce.com/products/multi-currency/) (2.5.0+; built and tested against a USD base currency)

[YayCurrency PRO](https://yaycommerce.com/yaycurrency-woocommerce-multi-currency-switcher/) was listed as compatible in 2.x. That conversion lives in YayCurrency, not in this plugin, and has not been retested against 3.0.

Version details and caveats are on the [compatibility page](https://payforpayment.com/docs/compatibility/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment).

= Limitations =

- **PayPal**: not recommended — PayPal's Acceptable Use Policy prohibits surcharging PayPal payments. See the FAQ for the sanctioned alternative (a discount on other methods).
- Mercado Pago's gateway has known issues handling WooCommerce fees; get in touch with Mercado Pago support if you run into this.
- **The fee applies at checkout only.** WooCommerce does not run cart fees on the "Pay for order" page ([woocommerce/woocommerce#17794](https://github.com/woocommerce/woocommerce/issues/17794)) or on orders you create in wp-admin, so no fee is added there. Nobody is over-charged either way: if a customer returns to the order-pay page and picks a different method, the previous method's fee is removed. (Pro charges the fee on both — see below.)
- Block checkout support has one known gap — see the FAQ.
- Full list, with workarounds: [known limitations](https://payforpayment.com/docs/limits/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment).

= Pay for Payment Pro =

Everything above is free, forever — not a trial, and nothing in it is switched off later. When one fee per payment method stops being enough, Pro adds the rules and the reach:

- **[Conditional fees](https://payforpayment.com/docs/conditions/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)** — charge only when the rule fits: cart total, coupon, product, product category, country or state, user role, shipping method, shipping zone or shipping class. "No COD fee above 2 000", "card surcharge for wholesale customers only" — set up in the settings screen, no filter code.
- **[Multiple fee tiers per payment method](https://payforpayment.com/docs/fee-tiers/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)** — stack more than one rule on a gateway, applied first-match-wins ("2 % over 5 000, otherwise a flat 50").
- **[Fees on manual and order-pay orders](https://payforpayment.com/docs/order-pay-and-manual-orders/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)** — charge the fee beyond checkout: on orders you create in wp-admin, and on the "Pay for order" page, where the customer sees each method's fee and the resulting total before paying.
- **[Fee as a product line](https://payforpayment.com/docs/fee-as-product-line/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)** — write the fee as a line on a managed hidden product instead of a fee line, for invoicing and ERP exports that skip fee lines.
- **[Display controls](https://payforpayment.com/docs/display-controls/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)** — hide the fee on the cart page, or show each method's fee beside its name at checkout so shoppers compare before they choose.
- **Apple Pay and Google Pay via Stripe** — the fee is priced into the express payment sheet before the shopper authorises, so the amount they approve is the amount the order charges.
- **Direct support** by email instead of the public forum.

One plan, every feature, on 1, 5 or 25 sites. Try it for 7 days with no card; after you buy, refunds run for 14 days under Freemius' refund policy. Cancel any time — the free plugin keeps working and your settings stay put.

**[Compare free and Pro](https://payforpayment.com/free-vs-pro/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)** · **[See pricing and start the trial](https://payforpayment.com/pricing/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)**

= Special Credits =

Originally developed by [Jörn Lund (@podpirate)](https://github.com/mcguffin/woocommerce-payforpayment), who abandoned the project in 2016.

== Installation ==

Follow the standard [WordPress plugin installation procedure](https://wordpress.org/documentation/article/manage-plugins/).

= Configuration =

After activation, go to **WooCommerce > Settings > Pay for payment**. This tab lists every payment method your store has, enabled or not; open one to configure its fee (or discount). This works identically for every gateway, including gateways with their own React-based admin screens.

== Frequently Asked Questions ==

= Can I use it with PayPal? =

Not for a surcharge. PayPal's user agreement prohibits charging customers extra for using PayPal — see the "No Surcharges" clause of the [PayPal User Agreement](https://www.paypal.com/webapps/mpp/ua/useragreement-full). The sanctioned way to steer shoppers away from PayPal is the opposite approach: set up a **discount** (a negative fixed amount or negative percentage, see above) on the payment methods you'd rather they use, instead of surcharging PayPal directly.

= Why does the fee show on the cart page even before I've picked a payment method at checkout? =

The cart page doesn't know your payment method yet, so the plugin previews the fee for whichever gateway WooCommerce would otherwise default to: the payment method you (or a returning customer) last selected in this session, failing that the store's configured default gateway (WooCommerce > Settings > Payments), and failing that the first enabled gateway. Once the shopper reaches checkout and actually picks a payment method, the fee recalculates for that method — on the classic checkout live, on the cart page only on the next page load/refresh.

= What exactly does each "Include in calculation" checkbox add to the percentage base? =

The percentage always starts from the cart subtotal excluding tax. Each checkbox adds more to that base before the percentage is applied:

- **Shipping**: adds the shipping cost. Example: a 2% fee with "Shipping" checked on a 100 subtotal + 10 shipping charges 2% of 110 = 2.20, instead of 2% of 100 = 2.00.
- **Coupons**: subtracts the cart's coupon discount from the base first. Example: 2% fee on a 100 subtotal with a 20 coupon applied charges 2% of 80 = 1.60.
- **Taxes**: adds the cart's tax total (and, combined with "Shipping", the shipping tax too) to the base. Example: 2% fee with "Taxes" checked on a 100 subtotal + 21 cart tax charges 2% of 121 = 2.42.
- **Fees**: adds the order's other fee lines (e.g. from another plugin) to the base. This works on orders you create in wp-admin and on the "Pay for order" page (both Pro). At checkout it currently adds nothing, because WooCommerce totals the cart's other fees only after this plugin has calculated its own — so leave it off if your fees are charged at checkout.

Checkboxes combine — e.g. "Shipping" + "Coupons" both checked feeds `subtotal + shipping − coupon discount` into the percentage.

= How is the order fee recorded, and how do I find it as an accountant? =

The plugin adds the fee through WooCommerce's own cart-fee API (`WC_Cart::add_fee()`), so WooCommerce stores it exactly the way it stores any other fee: as a regular **fee line item** on the order, carrying whatever title you configured (e.g. "COD surcharge"). It shows up on the order in wp-admin, on customer-facing order emails/invoices, and in any WooCommerce order export the same way a shipping or other fee line would.

To tell the plugin's fee apart from other fees, every fee line it writes carries two order-item meta keys: `_pay4payment_fee_gateway` (the payment method id, e.g. `cod`) and `_pay4payment_fee_source` (`checkout`, `order_pay`, `admin` or `converted` — where the line was written). Pro stores can switch the fee to a **product line** on a hidden "Payment fee" product (for ERP/invoicing exports that skip fee lines); those lines carry the same meta keys. A code example for reading them is on the [order item meta](https://payforpayment.com/docs/order-item-meta/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment) page.

= The customer paid with another method after a failed payment — which fee is on the order? =

On the free plugin, no fee at all. When the customer changes the payment method on the order-pay page ("Pay for order"), the fee line of the method they moved away from is removed, and WooCommerce doesn't run cart fees on that page, so nothing takes its place. They are never charged for a method they didn't use. Know that a customer can reach this on purpose: let the first payment fail, then pay on the order-pay page with the method that carries the fee. If that fee matters to you, Pro closes it.

On Pro, with "Charge the fee on manual & order-pay orders" turned on, it's the fee of the method they actually paid with: the old fee is replaced by the new one, and the order-pay page shows each method's fee and the resulting total before they pay. Orders you create in wp-admin get their fee the same way, on save or Recalculate.

= Does it work with the WooCommerce block checkout? =

Basic support, yes: with the block checkout in use, fees calculate and the order total updates when the shopper changes payment method, the same as on the classic checkout. One known limitation: this only works when the Checkout block is placed directly in the page content of your checkout page. If a block theme places the Checkout block inside a **template part** instead (rather than the page content), the plugin can't detect it — the fee still calculates on the initial page load, but it won't live-update when the shopper switches payment method. Workaround: keep the Checkout block in the page content rather than a template part.

= I can't set up the fee rule I need — the option isn't in the settings screen. =

The free settings screen covers a fixed amount and/or a percentage of the cart (with the base options above), min/max caps, and disabling on free/zero shipping.

Rules such as "no fee above a certain cart total", or fees per user role, country or shipping zone, are what [Pro's conditions](https://payforpayment.com/docs/conditions/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment) do, from the same settings screen and with no code. If you would rather write code, the plugin's filters work on the free version too (full reference: [developer filters](https://payforpayment.com/docs/developer-filters/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)):

`woocommerce_pay4pay_apply` — decide whether a charge is applied at all.

`woocommerce_pay4pay_applyfor_{$payment_gateway_id}` — decide whether a charge is applied for one specific payment gateway.

`woocommerce_pay4pay_{$payment_gateway_id}_amount` — alter the calculated amount of the charge.

`woocommerce_pay4pay_charges_fixed` / `_percentage` / `_minimum` / `_maximum` — filter the raw configured values before calculation.

`woocommerce_pay4pay_get_current_gateway_settings` — rewrite a payment method's whole fee configuration for the request: the amounts, the caps, the tax handling, the title and the disable rules, using the same `pay4pay_*` keys 2.x used. Return `false` to charge nothing at all. It runs for the checkout charge, the order-pay charge and the fee preview alike, so all three agree. Two notes if you are bringing a 2.x snippet across: it shapes a fee for a payment method that is switched on in the settings screen (a method whose fee lives entirely in your code is switched on for you during the upgrade, and the settings screen tells you if one is ever missed), and COD's `enable_for_methods` is read from the gateway itself rather than from the array you return.

= WPML — how do I translate the fee title and amount? =

Go to **WPML > String Translation** and look for the `woocommerce-pay-for-payment` text domain. You'll find strings named "{payment-method-id} - item title" and "{payment-method-id} - charges fixed" for each configured gateway.

= I'm upgrading from Pay for Payment 2.x — do I need to reconfigure anything? =

No. The first time this version runs, it migrates your existing per-gateway settings into the new central settings screen automatically. Your old (2.x) settings are left untouched in their original location — the migration reads them, it doesn't move or delete them.

= What is the difference between the free plugin and Pro? =

The free plugin charges one fee (or discount) per payment method, at checkout, on every order. Pro decides *when* and *where*: conditions and several fee tiers per method, fees on manual and "Pay for order" orders, the fee as a product line, display controls, and the fee priced into Apple Pay / Google Pay via Stripe. Both use the same settings screen and the same saved settings, so upgrading replaces the plugin and keeps every fee you have set up. [Side-by-side comparison](https://payforpayment.com/free-vs-pro/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment) · [7-day trial, no card](https://payforpayment.com/pricing/?utm_source=wporg&utm_medium=readme&utm_campaign=pay4payment)

= I found a bug, or want to request a feature. Where do I report it? =

For the free plugin, please use the [WordPress.org support forum](https://wordpress.org/support/plugin/woocommerce-pay-for-payment/). Pro customers get a direct line instead — commercial support isn't handled through the wp.org forum: use the Support link in the plugin's settings screen, or your Freemius account's Contact page. By email, support@payforpayment.com reaches the same inbox.

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team help validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/vdp/9e5fc033-ffcf-4b05-8c2d-291a0a5df632)

== Screenshots ==

1. The central settings screen for one payment method, with a fee configured: a fixed amount plus a percentage of the cart, min/max caps included.
2. Pro: the conditional-rules builder — stack several fee tiers for one method, each with its own conditions (cart total, coupon, product, country, user role, shipping method and more), applied first-match-wins.
3. The fee at checkout, as the customer sees it: a regular line item alongside shipping and tax.
4. Translating the fee label and fixed charge with WPML's String Translation module.

== Changelog ==

= 3.0.3 - 2026-10-23 =

* Accessibility: on the classic checkout, choosing a payment method with the keyboard no longer throws focus back to the top of the page on every arrow-key step while the fee updates. It stays on the payment method the shopper picked.
* Accessibility: in the settings, an error, including a failed save, now stays on screen until you close it instead of disappearing after five seconds. A failed save is also flagged next to "Save changes", where you clicked.
* Accessibility: red error messages and the "No changes to save" line in the settings are darker, so they are readable on the gray background. A field you reach with the Tab key no longer ends up hidden behind the save bar or the admin header.
* Tested up to WooCommerce 11.2.1.

= 3.0.2 - 2026-10-12 =

* Security: the block checkout now charges the fee of the payment method named in the place-order request itself. Before, the fee followed the method the shopper's browser had sent earlier — a request sent straight to the WooCommerce checkout API, skipping that step, placed an order on a fee-bearing method without its fee. Reported through Patchstack; thanks to the reporter.

= 3.0.1 - 2026-10-09 =

* Updated Freemius SDK to the latest version.
* Tested up to WordPress 7.1.3 and WooCommerce 11.2.0.

= 3.0.0 - 2026-09-23 =

Rewritten from the ground up. Everything 2.x could do, it still does — same fee maths, same developer filters, and your settings move themselves.

**Upgrading from 2.x**

* Your per-gateway fee settings migrate automatically the first time the plugin runs. Nothing to reconfigure. The old settings are left exactly where they were.
* Fees are now configured in one place: WooCommerce > Settings > Pay for payment. The fields no longer appear on each gateway's own settings page.
* Requires PHP 8.0 or newer and WooCommerce 7.7 or newer.
* The plugin is listed as "Pay for Payment for WooCommerce – Payment Gateway Fees & Discounts" — same plugin, same slug, same settings.

**New**

* One settings screen for every payment method, built with WooCommerce's own admin components. It also covers gateways whose own settings screens have no room for extra fields — WooCommerce Payments, Stripe, Klarna and other React-based gateways.
* The screen lists every payment method you have, enabled or not, so you can set a fee up before the method goes live.
* Block checkout support: the fee is calculated and the total updates when the shopper changes payment method, the same as on the classic checkout.
* Fee lines the plugin writes now carry `_pay4payment_fee_gateway` and `_pay4payment_fee_source` order-item meta, so accountants and exports can tell them from any other fee.
* Uninstalling removes every option the plugin wrote, including the standalone rows 2.x left behind. The `pay4pay_*` keys stored inside each gateway's own WooCommerce settings are left untouched — those are your 2.x configuration, and keeping them is what lets you roll back to 2.x.


**Changed**

* A cart with nothing left to pay for — goods fully discounted and no shipping cost — no longer gets a payment fee. In 2.x the check looked at the cart total *before* discounts, so a 100 % coupon still paid the fee. Paid shipping on free goods still charges.
* "Tax class based on cart items" now also applies when the fee amount excludes tax. In 2.x it only worked for tax-inclusive amounts and quietly fell back to the standard rate otherwise, so the fee's tax may change on stores that mix tax classes.
* Fees are charged only for the payment methods you have switched on in the settings screen. Everything that charged a fee in 2.x is switched on for you during the upgrade.
* The "Extra Charge" column on WooCommerce > Settings > Payments is gone. WooCommerce rebuilt that screen and removed the hook the column was built on, so it no longer had anywhere to appear. Every method's fee is shown on the plugin's own settings screen instead.
* VAT-exempt customers now pay the net of a fee whose amount includes tax, not the full amount. A fee set to 12.10 including 21 % tax is charged to everyone as 10.00 plus tax; a VAT-exempt customer now pays 10.00 with no tax, where 2.x charged them the whole 12.10. This only affects stores that both tick "Fee amount already includes tax" and have VAT-exempt customers.

**Fixed**

* When a customer's payment fails and they pay the order again with a different method, the first method's fee is removed from the order instead of staying on it.
* The previous method's fee is likewise removed from block-checkout and express (Apple Pay / Google Pay) orders, before the payment amount is authorized.
* The checkout no longer fatals when another plugin puts a cart entry without product data in the basket while the fee's tax class is "based on cart items".
* The "Standard rate" tax class can be picked again. The settings screen was sending a value the server rejected, so the fee fell back to "based on cart items" and was taxed at the highest rate in the cart.
* A fee you switched off stays off — updating the plugin used to switch fees migrated from 2.x back on.
* Your settings migrate even if the update was applied while WooCommerce was deactivated. Before, the plugin recorded the migration as done and the store came back with no fees configured at all.
* "Do not charge the fee on free shipping" looks at every shipping package again, as in 2.x. A cart split into several shipments charged the fee whenever the first package was not the free-shipping one.
* "Charge at least" and "Charge at most" accept negative amounts again — the bound that limits a discount is itself negative, so "5 % off, never more than 50 off" can be set up once more. A method carrying such a cap from 2.x can also be saved again instead of blocking on a validation error.
* A fee set up by hand in 3.0 now starts from the same defaults as in 2.x, so the same numbers produce the same charge: the amount you type contains its tax, the percentage is calculated on a base that includes cart taxes, and the standard tax class applies.
* Stores using Price Based on Country charge the converted fee again, rather than the base-currency number in the customer's currency.
* With Multi Currency for WooCommerce (CURCY) active, an order created in the admin in a currency other than the shop's no longer gets a payment fee in the shop's currency — no fee is added, and the order says why.
* Leaving the settings screen after saving no longer raises the browser's "Leave site?" warning.
* The settings screen is fully translated in all eight bundled languages (Czech, German, Spanish, Italian, Dutch (BE/NL), Slovak, Turkish) — a large part of it had fallen back to English.
* Validation messages name the field they appear under ("Fee label", "Minimum fee") instead of internal field names.

**Developers**

* All 2.x filters keep their names and signatures: `woocommerce_pay4pay_charges_fixed`, `_charges_percentage`, `_charges_minimum`, `_charges_maximum`, `_apply`, `_applyfor_{gateway_id}` and `_{gateway_id}_amount`. They still fire inside the calculation with the same intermediate values, and the fee is still added on `woocommerce_cart_calculate_fees` at `PHP_INT_MAX - 1`.
* Those filters now receive one extra trailing argument: the index of the fee rule being calculated. Existing callbacks are unaffected.
* `woocommerce_pay4pay_get_current_gateway_settings` keeps working. It is rebuilt over the new settings schema and fires with the same fourteen `pay4pay_*` keys, so a 2.x snippet runs unchanged at checkout. Returning `false` still switches the fee off. (On Pro it fires on the order-pay charge and in the per-method fee preview too — those paths do not exist in the free plugin, so there is nothing for it to shape there.) Two things to know when bringing a snippet across: it shapes a fee for a method that is switched on in the settings screen (a method whose fee lives entirely in your code is switched on for you during the upgrade), and COD's `enable_for_methods` is read from the gateway rather than from the array you return.
* REST: `pay4payment/v1/settings` (GET/POST, `manage_woocommerce`).
* WPML string names are unchanged, so existing translations of the fee title and fixed charge keep working.
* The five multi-currency integrations (Price Based on Country, WooCommerce Multi-Currency, Woo Multi Currency, WCML, WOOCS) are carried over.
* HPOS and cart/checkout blocks compatibility are declared.

= 2.2.2 and earlier =
Every release back to 1.0.0 (2013) is listed at [payforpayment.com/changelog](https://payforpayment.com/changelog/).

== Upgrade Notice ==

= 3.0.3 =
Accessibility fixes for the classic checkout and the settings screen: keyboard focus stays where the shopper or you left it, errors stay until closed, and low-contrast text is darker.

= 3.0.2 =
Security fix for the block checkout: an order placed by sending a request straight to WooCommerce's checkout API could skip the fee of the payment method it named. Update now.

= 3.0.0 =
Major rewrite. Your fee settings migrate automatically, so there is nothing to reconfigure, and your 2.x settings stay where they were. Do check them once after updating at WooCommerce > Settings > Pay for payment, where every fee now lives.
