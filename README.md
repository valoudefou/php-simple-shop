# Simple PHP Shop

Minimal PHP storefront wired to the AB Tasty PHP experimentation SDK. It demonstrates multiple UX variants, checkout telemetry, and an in-browser log viewer for quick debugging.

## Getting Started

```bash
composer install
php -S 127.0.0.1:8000
```

Then open <http://127.0.0.1:8000> and tap `L` (outside inputs) to pop open the in-browser AB Tasty log viewer for live SDK output.

## Deploying to Vercel

This repo ships with `vercel.json`, so you only need the Vercel CLI:

```bash
npm i -g vercel         # once
vercel login            # authenticate
vercel link             # link this directory to a Vercel project
vercel env add FLAGSHIP_ENV_ID   # add your Flagship env id
vercel env add FLAGSHIP_API_KEY  # add the matching API key
vercel --prod           # deploy
```

The config tells Vercel to run `composer install --no-dev --optimize-autoloader`, bundle the PHP entry points with `vercel-php`, and route all non-static paths through `index.php`. Local filesystem writes (e.g., `transactions.log`) fall back to `/tmp/simple-php-shop/transactions.log` on the serverless runtime so the demo keeps ticking even on read-only deployments.

## Integration

- `bootstrap.php` boots the AB Tasty SDK with verbose logging.
- Copy `.env.example` to `.env` and provide your `FLAGSHIP_ENV_ID` / `FLAGSHIP_API_KEY`; `bootstrap.php` loads them at runtime so secrets stay out of version control. If the file is missing, the app falls back to the public demo credentials bundled with the repo so you can still run the project locally.
- Every page calls `flagshipVisitorId()` to generate a random visitor for each fetch, making it easy to see variation responses. A `uniqid` fallback covers environments without `random_bytes`.
- Before each visitor is built we call `fetchSearchQueryForCountry('GB')`; when that mock Search Console API responds it returns the last GB search query (e.g., “feature flag tool”), and we inject it into the AB Tasty context as `query => "<term>"` so features can target users based on what they searched before landing here. The endpoint is a curl-friendly proxy that mimics Google Search Console:

  ```bash
  curl -X POST \
    https://searchconsole-googleapis.vercel.app/v1/sites/https%3A%2F%2Fexample.com%2F/searchAnalytics:query \
    -H "Content-Type: application/json" \
    -d '{"startDate":"2025-01-01","endDate":"2025-01-31","dimensions":["date","page","query"]}'
  ```

  which responds with rows containing `query`, `country`, etc., letting you demonstrate intent-based targeting without real GSC credentials.
- You can force the checkout experience by appending `?checkout_flow=0` or `?checkout_flow=1` to any page. The chosen value is stored in the session and used as the default value for the `checkout_flow` feature flag—so forcing `0` keeps the standard two-step flow while `1` exercises the single-page cart + checkout.

### Checkout Flow Flag

A new feature flag (`checkout_flow`) powers two checkout experiences:

| Value | Experience |
| ----- | ---------- |
| `0` | Classic multi-page flow (cart → checkout). `cart.php` shows only cart contents, `checkout.php` hosts the form and confirmation. |
| `1` | Integrated single-page experience. `cart.php` combines cart, customer info form, payment form, submission logic, and confirmation state; `checkout.php` redirects back to `cart.php` when this flag is on. |

#### Activating checkout Flow Variants with AB Tasty

1. **Define the `checkout_flow` flag in AB Tasty app** with two variations (`0` and `1`). Ensure it is typed as a numeric or JSON flag returning an integer.
2. **Pass the desired targeting context from PHP**:
   ```php
   $checkoutFlowPreference = currentCheckoutFlowPreference(); // respects ?checkout_flow=0|1 override
   $context = [
       'company' => 'Dyson',
       'checkout_flow' => $checkoutFlowPreference, // optional if you want AB Tasty to target on it
   ];
   $visitor = Flagship::newVisitor(flagshipVisitorId(), true)
       ->setContext($context)
       ->build();
   $visitor->fetchFlags();
   $checkoutFlowFlag = $visitor->getFlag('checkout_flow', $checkoutFlowPreference);
   $checkoutFlow = (int) $checkoutFlowFlag->getValue($checkoutFlowPreference);
   ```
3. **Use the resolved value in the controllers**:
   - When `$checkoutFlow === 0`, render the classic cart + checkout pages.
   - When `$checkoutFlow === 1`, `cart.php` renders the integrated flow and `checkout.php` immediately redirects back to `cart.php`.
4. **For local QA** you can force either path (and thereby change the default AB Tasty value) via `?checkout_flow=0` or `?checkout_flow=1` in the query string. This preference is stored in the session so it persists while you browse.

Both flows create AB Tasty transaction + item hits and append a JSON line to the transaction log (project-root locally, `/tmp/simple-php-shop/transactions.log` on Vercel).

### Telemetry Hits

AB Tasty's PHP SDK emits all three KPI hit types—`EVENT` for engagement, `TRANSACTION` for orders, and `ITEM` for line-level revenue attribution—so you can inspect funnel health end-to-end.

- **Add to cart** (`product.php`): sends a `USER_ENGAGEMENT` `EVENT` (`add_to_cart`), plus a lightweight `TRANSACTION` hit (currency, count, revenue) and an `ITEM` hit describing the product/quantity. This lets you track catalog engagement before checkout.
- **Checkout confirmation** (`checkout.php`): logs the order locally, flushes the cart, and emits a full `TRANSACTION` + per-item `ITEM` hit pair to AB Tasty for analytics.

## Developer Log Panel

AB Tasty logs are captured in an in-memory ring buffer (latest 200 entries) via `SessionFlagshipLogManager`. A floating panel (`partials/log-panel.php`) renders those entries on every page:

- Press `L` anywhere (outside inputs) to toggle it.
- Filter by typing in the search box.
- Context arrays render as JSON inside each log card.

## File Guide

- `index.php` – Landing page with product grid and hero banner.
- `product.php` – Product detail view with add-to-cart form.
- `cart.php` – Cart table plus optional integrated checkout flow.
- `checkout.php` – Dedicated checkout form + confirmation (used when `checkout_flow = 0`).
- `partials/log-panel.php` – Reusable AB Tasty log viewer.
- `transactions.log` – Local append-only record of mock orders (or `/tmp/simple-php-shop/transactions.log` on Vercel).

## Notes

- Credentials in `bootstrap.php` target the demo AB Tasty environment shipped with the project. Replace them with your own for production.
- Because visitor IDs change on every request, you can exercise both flag variations quickly but won’t persist segmentation between page loads unless you modify the helper to store IDs per session.
