# Pay for Payment for WooCommerce PRO

Combined free + Pro codebase for Pay for Payment for WooCommerce — Freemius builds the free version from this repo after upload. WIP.

## New in 3.0

A complete refactor of the old plugin that allowed me to drop of lot of legacy code and make the plugin more robust and easier to maintain.

* Dropping support of old WooCommerce versions. Minimum required version is now 7.7.0.
* Dropping support of old WordPress versions. Minimum required version is now 6.2.0.
* Dropping support of old PHP versions. Minimum required version is now 8.0.0.
* Plugin classes are completely refactored and rewritten. The plugin is now using namespace `Pay4Payment` and all classes are autoloaded. If you vere checking for the old plugin class (`Pay4Pay`) in your code, you need to update it - recommended is to use check against `PAY4PAYMENT_VERSION` constant.

## Requirements

* PHP 8.0+
* WordPress 6.2+
* WooCommerce 7.7+
* A Node/npm version compatible with `@wordpress/scripts` ^30 (see `package.json`)

## Getting started (dev environment)

This plugin lives inside a full WordPress install managed by ddev, one directory up from this repo, at `/Users/kv/Development/wc-pay-for-payment/`. To spin up a browser-testable site:

1. From `/Users/kv/Development/wc-pay-for-payment/`, run `ddev start`.
2. Visit `https://wc-pay-for-payment.ddev.site/wp-admin` (credentials: see this project’s `AGENTS.md`, Debugging section).

For code-level development and testing (no server needed), from this plugin directory:

1. `composer global require wpify/scoper` (once, globally)
2. `composer install`
3. `npm install`

## The marketing site (`site/`)

`payforpayment.com` is an Astro static site living in `site/`, with its own
npm tree. It is not part of the plugin — `site/` is absent from the root
`package.json` `files` list, so `npm run plugin-zip` never picks it up — and
it has nothing to do with ddev: no PHP, no database, no container.

```bash
cd site
npm install
npm run dev       # http://localhost:4321
npm run dev:host  # also serves on the network, for phone testing
npm run build     # → site/dist/
npm run check     # astro check
```

### Testing it on a phone

`npm run dev:host`, then open one of these from the device:

| From | URL |
| --- | --- |
| A device on the tailnet | `http://air-m5.tailb188c0.ts.net:4321/` |
| Same, by IP | `http://100.65.190.109:4321/` |
| Same Wi-Fi, no tailnet | `http://192.168.50.84:4321/` |

**`*.kbnt.cz` does not reach this server.** Every name under it resolves to
`100.88.171.31`, which is the M1 — the ddev box serving the WordPress dev
store above. The Astro site runs on whichever machine has this repo checked
out, so previewing it means using that machine's own address. The addresses
in the table are the M5's.

Hosts must be listed in `vite.server.allowedHosts` in `site/astro.config.mjs`
or Vite answers 403 rather than serving the page. The LAN entry is a DHCP
lease and will change eventually.

Full detail — fonts, checkout, deployment — is in `site/README.md`.

## Common commands

PHP (composer):

* `composer test:unit` — run PHPUnit (WP_Mock based)
* `composer analyse` — PHPStan (level max)
* `composer lint` — parallel-lint
* `composer check` — lint + standards:check + analyse + test:unit

JS/TS (npm):

* `npm run dev` — watch mode (`wp-scripts start`)
* `npm run build` — production build to `/build/`
* `npm run test:unit` — Jest unit tests
* `npm run lint:js` / `npm run lint:css` — ESLint / Stylelint
* `npm run plugin-zip` — build a distributable zip

## Testing

### Test Structure

Tests are located alongside their components with the `.test.tsx` extension:

* `assets/js/components/ErrorBoundary.test.tsx` - Error boundary component tests
* `assets/js/components/Settings.test.tsx` - Main settings page tests
* `assets/js/components/settings/ErrorNotice.test.tsx` - Error notice component tests
* `assets/js/components/settings/FixedChargeField.test.tsx` - Fixed charge field tests
* `assets/js/components/settings/PercentageChargeFields.test.tsx` - Percentage charge fields tests
* `assets/js/components/settings/TaxSettings.test.tsx` - Tax settings component tests

### Test Configuration

* **Configuration**: `jest.config.js`
* **Setup**: `jest.setup.js` - Includes WordPress global mocks and test environment setup
* **Coverage thresholds**: 60% branches, 70% functions/lines/statements

### Writing Tests

When adding new components, follow these patterns:

1. Mock WordPress dependencies (`@wordpress/api-fetch`, `@wordpress/data`)
2. Use `render` from `@testing-library/react` for component rendering
3. Use `screen` queries to find elements
4. Use `userEvent` for simulating user interactions
5. Use `waitFor` for async operations

Example:

```typescript
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import MyComponent from './MyComponent';

describe('MyComponent', () => {
  it('should render correctly', () => {
    render(<MyComponent />);
    expect(screen.getByText('Hello')).toBeInTheDocument();
  });

  it('should handle clicks', async () => {
    const user = userEvent.setup();
    const handleClick = jest.fn();

    render(<MyComponent onClick={handleClick} />);
    await user.click(screen.getByRole('button'));

    expect(handleClick).toHaveBeenCalledTimes(1);
  });
});
```

## Architecture Decisions

### Array-Based Payment Method Settings

The plugin stores payment method settings in an array structure even though the current version only uses a single configuration per method:

```javascript
paymentMethods: {
    [methodId]: [settingsObject]  // Array with single item
}
```

**Rationale**: This array structure is intentional preparation for future pro version features that will support multiple fee configurations per payment method (e.g., tier-based pricing, conditional fees).

**Current Implementation**:

```javascript
// Access pattern in current version
settings.paymentMethods[gateway.id]?.[0]  // Always use first config
```

**Future Pro Features**:

```javascript
// Multiple fee configurations per method
paymentMethods[gateway.id][0]  // Default fee config
paymentMethods[gateway.id][1]  // Premium tier config
paymentMethods[gateway.id][2]  // VIP tier config
```

**Important**: Do not "simplify" this to a flat object structure. The array pattern is a forward-compatible design decision, not technical debt.

## DEV notes

1. Install wpify/scoper globally: `composer global require wpify/scoper`
2. Sibling-plugin architecture reference — a best-practice example of the shared php-di / `wpify/scoper` / REST-endpoint architecture: `git@github.com:vyskoczilova/kybernaut-phone-validator.git` (clone on demand; not part of the build).

## Uses the following 3rd party libraries

* [wpify/scoper](https://packagist.org/packages/wpify/scoper) for scoping Composer dependencies
* [wordpress storybook](https://wordpress.github.io/gutenberg/?path=/docs/docs-introduction--page) for [@wordpress/components](https://www.npmjs.com/package/@wordpress/components) library
* [@woocommerce/currency](https://www.npmjs.com/package/@woocommerce/currency)

## More docs

* `PRD.md` — product requirements for v3.0.0
* `TODO.md` — maps work areas to GitHub issues
* `CONTEXT.md` — domain glossary / ubiquitous language
* `docs/RELEASE.md` — release checklist (Freemius + wp.org SVN)
* `site/README.md` — the marketing site: layout, fonts, Freemius checkout, Cloudflare Pages deployment
