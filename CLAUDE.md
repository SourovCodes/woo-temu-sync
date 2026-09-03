# Woo Temu Sync

A WooCommerce extension that lists products on the **Temu** marketplace and syncs stock, prices and
orders through the Temu Open API. It talks to Temu directly: no middleman SaaS, no per-sale fee.

- Text domain / slug: `woo-temu-sync`
- PHP namespace: `WooTemuSync\` (PSR-4 → `includes/`)
- Global prefix: `wtsync` / `woo_temu_sync` — constants, hooks, options and meta keys.
  WPCS rejects prefixes shorter than four characters.
- Options use the long prefix (`woo_temu_sync_connection`); hooks, transients and meta use the
  short one (`wtsync_status_batch_size`, `_wtsync_temu_goods_id`).

## Why this plugin exists

The established connectors are SaaS middleware: every API call routes through the vendor's servers,
which is what lets them meter the shop's sales volume and bill against it.

A self-hosted plugin calling `openapi-b-eu.temu.com` from the shop's own server has no metering
surface and no marginal cost, so "free" is sustainable here in a way it is not for them. It also
keeps the catalogue and the customer order data on the merchant's own infrastructure, which matters
for a seller established outside the market they sell into.

## Minimum requirements

The plugin deliberately targets the current release of everything. There is no
backwards-compatibility budget: do not add shims, polyfills or version branches for older
WordPress, WooCommerce or PHP.

| Requirement | Floor | Enforced by |
|---|---|---|
| WordPress | 7.0 (current latest) | `Requires at least` header |
| PHP | 8.2 | `Requires PHP` header, `composer.json`, PHPCompatibilityWP `testVersion` |
| WooCommerce | 11.0 (current latest) | `Requires Plugins` header plus a runtime `version_compare` check |
| HPOS | **Required, not merely supported** | Runtime check; the plugin refuses to boot without it |

**High-Performance Order Storage is a hard requirement**, gated in `bootstrap()` before
`Plugin::instance()->init()` runs. Temu orders are imported as WooCommerce orders, so they have to
land in the store WooCommerce actually reads. Gating now rather than when order import ships keeps
the requirement out of an upgrade path, where it would break sites that were already running.

Because WooCommerce is guaranteed, Action Scheduler is too: there is no WP-Cron fallback anywhere
and none should be added.

## Environment

Development runs against the **Local by WP Engine** site *TestShop*, which this directory is
symlinked into:

| | |
|---|---|
| Site root | `~/Local Sites/testshop/app/public` |
| URL | http://testshop.local |
| Plugin path in site | `wp-content/plugins/woo-temu-sync` → symlink to this repo |

**A bare `wp` command does not work** — Local serves MySQL over a socket the Homebrew PHP cannot
see. Use the wrapper, which resolves the site, socket and PHP build from Local's `sites.json`:

```bash
./bin/wp plugin list
```

**Publishing products cannot work from testshop.local.** Temu downloads product images from the URLs
we send it, and `Schema::is_public_image_url()` refuses `.local`, `.test`, `localhost` and RFC1918
addresses — correctly, because Temu genuinely cannot reach them. Without that guard the products
would be created and then sit in Draft for ever with no error recorded anywhere. To publish from a
development site, expose the uploads over a tunnel and override the check:

```php
add_filter( 'wtsync_is_public_image_url', '__return_true' );
```

## Commands

```bash
composer lint           # phpcs against the WooCommerce standard
composer lint:fix       # phpcbf — fix what can be fixed automatically
composer test           # PHPUnit against the WordPress test library
composer test:coverage  # …with a coverage report in coverage-html/
composer i18n           # re-extract the POT and recompile the translations
composer i18n:check     # is the POT still in step with the source?
composer check          # everything CI checks: validate, version, i18n, lint, test
composer build          # build dist/woo-temu-sync-<version>.zip
./bin/wp <args>         # wp-cli against the Local site
```

`bin/install-wp-tests.sh` provisions the `woo_temu_tests` database once, before the first
`composer test`.

Note that `composer lint` prints a progress count of parallel batches, not files — "7 / 7" is
normal. Run `phpcs --parallel=1` if you want a per-file count.

## Coding standards

`phpcs.xml.dist` (ruleset `WooCommerce-Core`) is the authority — when this document and the sniffs
disagree, the sniffs win.

- Tabs for indentation, Yoda conditions, spaces inside parentheses.
- `snake_case` functions and variables, `PascalCase` classes, `UPPER_SNAKE_CASE` constants.
- Every file, class, method and property carries a docblock. Inline comments are full sentences.
- Every hook needs a docblock **with an `@since` tag** — `WooCommerce.Commenting.CommentHooks`
  fails the build without one.
- Class files follow PSR-4 (`includes/Api/Client.php`), not `class-*.php`.
- **Exception messages count as output.** `WordPress.Security.EscapeOutput.ExceptionNotEscaped`
  checks every argument to a `throw new`, not just the message, so escape the code and the API
  method name too. These messages do reach admin notices, so the sniff is right.

## Security — not negotiable

- **Sanitise on input, escape on output.** `SettingsPage::guard()` verifies the capability and the
  nonce and then *returns* the unslashed request data, so handlers never touch `$_POST` themselves.
  Keep it that way: it puts the superglobal in one place, immediately after the checks that
  authorise reading it.
- **Never log credentials.** `Client::log()` strips `sign` and `access_token` before firing
  `wtsync_api_call`. The app secret must never reach a log line.
- **The stored secrets are encrypted and there is no plaintext fallback.** `Credentials::encrypt()`
  returns an empty string rather than storing an unprotected token when OpenSSL is missing. Failing
  to store a token is recoverable; leaking one is not. `Credentials::has_encryption()` exists so the
  settings screen can say so.
- **Never commit a real credential**, including as a test fixture. The signing vector in
  `SignerTest` is the one printed in Temu's public documentation, not anybody's key.

## The Temu API

`docs/temu-api-notes.md` is the reference and records what was verified against Temu's own
documentation versus what is still assumed. Read it before touching the API layer. The essentials:

- One endpoint per region, POST only, method name in the `type` body parameter. EU is
  `https://openapi-b-eu.temu.com/openapi/router`.
- **Signing**: sort parameters by ASCII key, concatenate `key . value` with no separators, wrap the
  whole thing in `app_secret` at both ends, MD5, uppercase. `SignerTest` reproduces Temu's published
  worked example exactly — if that test ever fails, the platform changed the algorithm and
  everything else is broken too.
- **Nested structures are signed as their JSON serialisation**, so `Signer` and `Client` share one
  encoder. Sign one serialisation and send another and every call fails with `3000001 SIGN_UNVALID`.
  If that starts happening only once image URLs or accented titles are involved, the JSON flags are
  the suspect — `wtsync_json_flags` is the escape hatch.
- **Money is never a float.** `Signer::encode_value()` throws on one. Temu wants a decimal string
  plus a separate currency, and float formatting would make the signed value drift from the intended
  one.
- Rate limit is 20 req/s per app key; `4000004` means back off. `Client` retries retryable codes
  with jittered exponential backoff and never retries deterministic ones.
- **Tokens last about a year and cannot be refreshed.** The only remedy on expiry is the seller
  authorising again, so the settings screen warns ahead of the date.

## Product publishing

`temu.local.goods.v3.add`. V3 accepts the store's own raw data and normalises it server side, which
removes the category tree walk, the image upload calls and the attribute matching that V1/V2 needed.

- Only five fields are genuinely required: `externalGoodsId`, `goodsName`, `externalSkuId`,
  `images`, `basePrice`. Send the rest when there is real data and omit it otherwise rather than
  inventing filler.
- **Compliance and manufacturer data is extracted from the prose.** Temu mines the title,
  description and attributes for it, so `Mapper` sends the *long* description in preference to the
  short one. Trimming descriptions would quietly cost GPSR information.
- **`packageInfo` is all-or-nothing.** Temu treats a missing or zero dimension as "not supplied" and
  substitutes its own defaults, so a real weight beside invented dimensions silently misprices
  shipping.
- **Units are converted, not passed through.** Temu wants bare grams and centimetres; WooCommerce
  stores whatever the shop configured. A store working in pounds would be out by a factor of 450.
- `externalGoodsId` / `externalSkuId` must never change for the life of a product — Temu keys
  updates off them. Both are filterable for multi-store setups.

## Status polling

Publishing is asynchronous: a `goodsId` means *created*, not *live*. Temu then spends minutes on
category recognition, attribute enrichment and SKU generation before the listing can go to review.

`bg.local.goods.publish.status.get` takes a batch, so a whole catalogue costs a handful of calls.
`Scheduler` queues the first check 12 minutes after publish — Temu's own guide says to wait about
ten — and sweeps every 15 minutes after that.

**The status enumeration is undocumented.** Temu returns `status` and `subStatus` as bare integers
and publishes no mapping; the only lifecycle names in their prose are "Draft" and "Incomplete". We
deliberately do not guess: `PublishStatus` shows the raw codes, which a merchant can quote to Temu
support, and `wtsync_publish_status_labels` fills them in once they are confirmed against a live
account. **This is the highest-value open question in the project** — confirming it turns "Temu
status 2 / 5" into "stuck in Draft".

Because there is no documented terminal state, polling gives up by attempt count rather than by
state (`wtsync_status_max_attempts`, default 12), and the products list column says
"stopped checking" rather than leaving the merchant wondering why the status froze.

## Stock and price sync

Changes are **coalesced, never pushed inline**. A busy shop fires a stock change
on every checkout; pushing each one synchronously would put a Temu round trip
inside a customer's checkout and walk into the 20 req/s limit. `SyncQueue` marks
the product dirty and queues one Action Scheduler job (`wtsync_sync_debounce`,
default 120s), so ten sales in a minute cost one API call. A variation is always
queued as its parent, because Temu updates a whole listing at once.

An hourly reconciliation sweep walks the published catalogue a batch at a time,
because hooks miss things — direct database writes, importers, a job lost in a
migration. It is cheap: both syncs compare against what Temu last accepted and
send nothing when the values match, so a settled catalogue costs no API calls.

- **Stock uses full overwrite, never the incremental delta.** The client retries,
  and a retried delta applies twice. This is not a preference; do not "optimise"
  it into `skuStockChangeList`.
- **A failed push must not record the values as accepted**, or the change
  detection would suppress the retry and the shop would silently stop syncing.
  Both syncs only write their last-sent meta after Temu accepts.
- **Most sellers cannot sync prices at all.** Temu's price API is whitelisted per
  store through their business team, and prices go through a negotiation
  workflow that refuses changes mid-review. `PriceSync` records why on the
  product and the products column shows it, because a price that silently never
  moves is the worst possible failure mode here.
- **`SkuMap` refuses to guess.** Where a multi-SKU listing cannot be matched by
  identifier it maps nothing and logs why. Pushing one variation's stock onto
  another is worse than not pushing at all.

## Order import

Temu orders are two-tier: a parent order is the transaction, each order item is
one SKU. One parent order becomes one WooCommerce order, keyed on
`parentOrderSn`, so re-importing is a no-op.

`OrderPoller` walks `bg.order.list.v2.get` by last update time every ten minutes.
**The cursor only advances after a clean pass.** A failure partway through must
re-cover the same window rather than skip it: an order missed here is an order
the seller never ships. The window also overlaps the previous one by five
minutes, because Temu's clock is not ours and the import is idempotent anyway.

- **Never drop an order because a product could not be matched.** An unmatched
  line becomes a plain line item carrying Temu's own product name. A missing
  order is invisible to the seller; an untidy one is not.
- **Never guess a status.** `parentOrderStatus` is an undocumented integer, and
  marking an unshipped order complete loses a shipment. Only codes explicitly
  mapped through `wtsync_order_status_map` set a WooCommerce status; everything
  else lands in the configurable new-order status and records the raw code.
  Cancellation is the exception and is read from the documented
  `isCancelledDuringPending` boolean.
- **The real buyer address is a deliberate second call.** Temu masks it by
  default. `wtsync_decrypt_addresses` turns the real one off entirely for a shop
  that fulfils elsewhere, which keeps buyer personal data out of WooCommerce.
- **Order totals are not yet trusted.** See `docs/temu-api-notes.md`: Temu's
  amount payload does not say whether it is major or minor units. Check the first
  real imported order against Seller Center, then pin it with
  `wtsync_order_amount` if needed.

## Working agreement

- Run `composer lint` before calling any change done.
- Add or update a test with each behaviour change — `composer test`.
- Update `docs/temu-api-notes.md` whenever something about the Temu API is confirmed or disproved,
  and say explicitly which it is. Half this project's risk is in what the vendor documentation does
  not say.
- Verify against the real site (`./bin/wp`, http://testshop.local/wp-admin) rather than reasoning
  about what WooCommerce would do.
- Bump the version in both the `woo-temu-sync.php` header and the `WTSYNC_VERSION` constant
  together — `bin/check-version.sh` fails the build when they drift apart.

## Languages

`composer i18n` re-extracts `languages/woo-temu-sync.pot` and recompiles every catalogue; it uses
wp-cli's `i18n` commands, which are pure PHP, so no gettext toolchain is needed.

**Only the English source strings exist today.** German is not yet translated and there is no
`I18nTest` enforcing it, unlike woo-kontor-sync-pro. If this ships to German-speaking merchants,
port that test and the `de_DE` / `de_DE_formal` catalogues across, along with the German locale
mapping — WordPress does not fall back between German locales, so an Austrian shop would otherwise
read an English admin screen.

## Continuous integration and releases

`.github/workflows/ci.yml` runs the same commands the working agreement asks for: coding standards,
translations, a dependency audit, the suite on PHP 8.2/8.3/8.4, coverage, and a build so every pull
request proves the release artefact still builds.

`composer build` produces `dist/woo-temu-sync-<version>.zip`, its `.sha256`, and `update.json`.
`Updates\Updater` reads that manifest from the repository's latest GitHub release, which is how
installed sites are offered updates. It is registered *before* the WooCommerce and HPOS gates, since
an update is often what fixes a plugin sitting inert behind one of them.

## Dependency versions — do not "upgrade" these

- **PHP_CodeSniffer stays on 3.x.** Require `woocommerce/woocommerce-sniffs ^2.0` and let it resolve
  the standards stack; do not pin PHPCS directly.
- **PHPUnit stays on 9.x.** WordPress's test library calls APIs removed in PHPUnit 10.
- `config.platform.php` is pinned to `8.2.29` so Composer resolves for the site's runtime rather
  than the host's PHP.
