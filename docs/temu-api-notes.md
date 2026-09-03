# Temu Open API - verified notes

Everything here was checked against Temu's own documentation rather than
inferred. Where something is still unconfirmed it says so explicitly.

## Endpoints

| Region | API host | Partner console | Seller Center (local) | Seller Center (crossborder) |
|---|---|---|---|---|
| EU | `https://openapi-b-eu.temu.com/openapi/router` | `partner-eu.temu.com` | `seller-eu.temu.com` | `agentseller-eu.temu.com` |
| US | `https://openapi-b-us.temu.com/openapi/router` | `partner-us.temu.com` | `seller.temu.com` | `agentseller.temu.com` |
| Global | `https://openapi-b-global.temu.com/openapi/router` | `partner.temu.com` | `seller.temu.com` | `agentseller.temu.com` |

All calls are `POST` to that single URL with `Content-Type: application/json`.
The method name travels in the `type` body parameter, not in the path.

## Common parameters

`type`, `app_key`, `timestamp`, `sign`, `access_token`, `data_type` are required
on every call. `version` is optional and defaults to V1.

`timestamp` is unix seconds, 10 digits, and must satisfy
`now - 300s <= timestamp <= now + 300s`. Server clock drift is therefore a real
failure mode - see error codes 3000011 and 3000012.

## Signature

Confirmed by reproducing Temu's published worked example exactly
(`tests/test-signer.php`, vector digest `4CCF219942D4180C6DDA3CE36C1B838F`).

1. Sort all parameters by key, ASCII ascending.
2. Concatenate `key . value` with no separators.
3. Prepend **and** append `app_secret`.
4. MD5, then uppercase.

Nested objects and arrays are signed as their JSON serialisation. The string
that gets hashed must be byte identical to what lands in the request body, so
`Signer` and `Client` deliberately share one encoder.

**Open question:** Temu's example contains no slashes and no non-ASCII, so it
cannot tell us whether they canonicalise with escaped slashes or unicode. We
default to `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and expose the
`wtsync_json_flags` filter. If error 3000001 (SIGN_UNVALID) appears only once
image URLs or accented titles are in play, flip these flags first.

## Authorisation

Two supported flows:

- **Callback** (recommended by Temu, needs App Store publication): send the
  seller to
  `{seller_center}/open-platform/client-manage/authorization?appKey=...&redirect_uri=...&state=...`
  Temu returns them to `redirect_uri` with `app_key`, `callback_host`, `code`
  and `state`. Exchange the code via `bg.open.accesstoken.create`, passing the
  code as **both** `code` and `access_token` on that first call.
- **Manual**: the seller authorises in Seller Center and is shown the access
  token to copy. Works with an unpublished app, which is why this plugin
  supports it.

`callback_host` in the callback tells you which gateway the token belongs to -
more reliable than whatever region the merchant selected in a form.

Tokens last roughly **one year** (the doc sample's request timestamp and
`expiredTime` are 364.99 days apart) and there is **no refresh grant**. The only
remedy on expiry is re-authorisation, so warn well ahead of the date.

## Scopes

Scopes are literal API method names. `bg.open.accesstoken.create` returns
`apiScopeList` with the exact methods granted, plus
`appSubscribeEventCodeList` for webhooks. A typical local-seller grant covers
`bg.local.goods.*`, `bg.local.goods.compliance.*`, `bg.order.*`,
`bg.logistics.*`, `bg.aftersales.*` and `bg.freight.template.list.query`.

Nothing in the observed scope set looks gated behind a commercial partnership.

## Rate limits

Default **20 requests/second per app_key**. Exceeding it returns error `4000004`
`RATE_LIMIT_EXCEED_EXCEPTION`. Increases are requested from partner@temu.com.

## Error codes worth handling

| Code | Meaning | Response |
|---|---|---|
| 1000000 | SUCCESS | - |
| 3000001 | SIGN_UNVALID | Do not retry; check JSON flags |
| 3000011 / 3000012 | timestamp invalid / expired | Do not retry; check NTP |
| 3000021 / 3000027 | app lacks API permission | App registration problem |
| 3000032 | seller did not grant this scope | Ask seller to re-authorise |
| 3000034 | token expired or replaced | Reconnect |
| 4000004 | rate limited | Retry with backoff |
| 4000000 / 2000000 / 7000000 | system / business exception | Retry with backoff |
| 5000003 | IP not allow-listed | App registration problem |

## Product listing - Add Products V3

Method: **`temu.local.goods.v3.add`** (note the `temu.*` namespace, not `bg.*`).

V3 is the "just send us your raw data" endpoint. Compared with V1/V2 it removes
four separate integration steps:

| You no longer need to | Because V3 |
|---|---|
| Call `bg.local.goods.cats.get` and walk the category tree | Takes `extCatName`, a free-text category path, and recommends the category |
| Call `bg.local.goods.image.upload` | Downloads images from public URLs you supply |
| Call `bg.local.goods.template.get` for attribute definitions | Accepts your own attribute names and values and maps them |
| Match Temu's variation definitions | Accepts your own variation names and normalises them |

### Required fields

Per the guide's own "Required Fields" section, only these five:
`externalGoodsId`, `goodsName`, `externalSkuId`, `images`, `basePrice`.

The field table marks more fields as required (`skuList`, `quantity`,
`packageInfo`, `variations`), but the authoritative required list is the five
above. We send the others whenever we have real data and omit them otherwise,
rather than inventing filler values.

### Structure

```
goodsBasic: externalGoodsId, goodsName, goodsDesc, extCatName,
            goodsCarouselImage[], detailImage[], productType, shipmentLimitDay
attributes: [{ name, value[] }]
skuList:    [{ externalSkuId, images[], price{basePrice{amount,currency},
              listPrice{...}}, quantity, packageInfo{weight,length,width,height},
              variations[{name, value}], barCode{barCodeType, barCodeId[]},
              references{code{name,id}, note[]} }]
```

### Limits

| Field | Limit |
|---|---|
| `goodsName` | 500 chars, truncated; rich text stripped; emoji filtered |
| `goodsDesc` | 10,000 chars; rich text allowed |
| `extCatName` | 500 chars |
| `externalGoodsId` / `externalSkuId` | 128 chars, unique per store |
| `goodsCarouselImage` | 10 images, extras discarded |
| `detailImage` | 50 images |
| `attributes` | 200 attributes, 128 chars each, 1,000 values per attribute |
| `skuList` | 500 SKUs per product |
| `variations` | 5 per SKU |

### Types and units that matter

- **Prices are STRINGs**, with a separate `currency`. Decimal precision is
  per-site and over-precision is rejected with error 150011018. This is also
  why `Signer` refuses float parameters outright.
- **`packageInfo` values are bare numeric strings, in grams and centimetres**,
  with no unit suffix. WooCommerce stores whatever the shop configured, so
  conversion is mandatory - a store working in pounds would be out by ~450x.
- **A zero is treated as "not supplied"** and Temu substitutes its own defaults
  (weight 100 g, length 10 cm, width 20 cm, height 30 cm). We send package data
  all-or-nothing so Temu never silently mixes real values with defaults.
- `variations[].value` is a single STRING; `attributes[].value` is an ARRAY.

### GPSR and compliance - this changed our plan

The V3 guide states that **"Compliance attributes and manufacturer information
will be automatically extracted based on the product title, description, and
attributes provided by the merchant."** Their own worked example is a German
cosmetics product whose `goodsDesc` contains a prose "Manufacturer: ..." line
and a PZN.

So GPSR data does **not** block product creation in V3. It is mined from the
description. The practical consequence for this plugin is the opposite of what
we assumed: send the *fullest* description available rather than a trimmed one,
because the prose is the compliance input.

Structured compliance editing still exists for after creation:
`bg.local.goods.compliance.edit`, `temu.local.goods.compliance.v2.edit`,
`bg.local.goods.compliance.rules.get` (whose `repInfoList` parameter appears to
carry EU Responsible Person records). **Not yet verified:** the exact
`repInfoList` shape. That is the next thing to pin down for the EU RP field.

### After creation

The response returns `goodsId` plus your `externalGoodsId`. Keep the mapping.

A returned `goodsId` means *created*, **not** *publishable*. Temu then runs
category recognition, attribute enrichment and SKU generation asynchronously.
Wait roughly **10 minutes**, then poll `bg.local.goods.list.query` or
`temu.local.goods.list.retrieve`:

- **Draft** - incomplete, cannot be submitted for review.
- **Incomplete** - complete and automatically submitted; awaiting review.

Common reasons a product sticks in Draft:

1. Missing attributes that the recommended category requires.
2. Image download failure - restricted host, rate limiting, bad or expired URL,
   or a file too large to fetch before timeout. This is why the mapper refuses
   localhost, `.local`, `.test` and RFC1918 image URLs up front: a staging site
   would otherwise produce silent Draft products with no error anywhere.
3. Duplicate `externalGoodsId` or `externalSkuId`.

### Scope caveat

The scope list we observed on a sample token contains `bg.local.goods.add` but
**not** `temu.local.goods.v3.add`. Whether V3 needs its own grant is unconfirmed.
`Publisher::assert_ready()` therefore checks for the scope and fails with a
clear message, but only when a scope list was actually recorded - an empty list
means unknown, not denied.

### Notable V3 error codes

| Code | Message |
|---|---|
| 150010260 | No categories recommended - complete the product name and retry |
| 150010261 | Recommended category cannot be sold |
| 150011018 | Currency allows at most N decimal points |
| 150011019 | Input {field}:{value} is incorrect |
| 150011041 | Exceeded the maximum number of {*} allowed |
| 150011100 | Daily product listing limit reached |
| 150011101 | Price change exceeds the allowed range; retry within N minutes |

## Status polling

Method: **`bg.local.goods.publish.status.get`** - present in the observed scope
list, and it takes a **batch**, so a whole catalogue costs a handful of calls.

Request: `{ "goodsIdList": [1, 2, ...] }`
Response: `result.goodsPublishStatusList[] = { goodsId, status, subStatus }`

Related, richer, also in the observed scope list:

- `bg.local.goods.list.query` - paged via `pageNo`/`pageSize`, filterable by
  `goodsIdList`, and returns `outGoodsSn` (our `externalGoodsId`), `goodsName`,
  `status4VO`, `subStatus4VO`, `goodsShowSubStatus`, `skuInfoList`, prices.
  Useful for reconciliation when local meta has drifted.
- `temu.local.goods.list.retrieve` - newer, cursor paged via `pageToken`, and
  can query by **`outSkuSnList` / `outGoodsSnList`**, i.e. by *our* identifiers
  rather than Temu's. The better choice for a repair or re-sync pass.

### The enum problem

**`status` and `subStatus` are undocumented integers.** The scraped API
reference gives no enumeration, and the V3 guide names only two lifecycle
states in prose: **Draft** (incomplete, cannot be submitted) and **Incomplete**
(complete, auto-submitted, awaiting review).

We deliberately do **not** guess the mapping. `PublishStatus` stores the raw
codes and renders them as "Temu status 2 / 5", which a merchant can quote to
Temu support. Once the codes are confirmed against a live seller account, fill
them in through the `wtsync_publish_status_labels` filter - no core changes
needed:

```php
add_filter( 'wtsync_publish_status_labels', function ( $labels ) {
    $labels[1] = __( 'Draft', 'my-plugin' );
    return $labels;
} );
```

**This is the highest-value open question left.** Confirming the enum turns the
whole feature from "here is a number" into "this product is stuck in Draft".

### Consequences for the poller design

Because there is no documented terminal state, there is no reliable "it is
settled, stop asking" signal. Giving up is therefore by **attempt count**
(default 12) rather than by state, which at the 15 minute sweep cadence covers
about three hours per product. Both are filterable via
`wtsync_status_max_attempts` and `wtsync_status_batch_size`.

Other decisions worth remembering:

- **First check is delayed 12 minutes** after publish. Temu's guide says to wait
  about ten; asking sooner only burns rate limit while the product is still
  being enriched.
- **A failed batch does not abandon the sweep.** A rate limit partway through a
  catalogue must not discard readings already collected.
- **A product missing from the response is not an error.** The record may not
  exist yet. The attempt is counted and no status is invented.
- **Action Scheduler is preferred over WP-Cron** where available, since it
  survives a site with no traffic. WP-Cron is the fallback.

## Stock sync

Method: **`bg.local.goods.stock.edit`**, in the observed scope list.

Two modes, and the choice between them is a correctness decision rather than a
preference:

| Mode | Payload | Behaviour |
|---|---|---|
| Full | `skuStockTargetList: [{skuId, stockTarget}]` | Overwrites the level |
| Incremental | `skuStockChangeList: [{skuId, stockDiff}]` | Adds a delta |

**We use full.** `Client` retries on rate limits and transient failures, and a
retried delta applies twice; a retried overwrite does not. Temu's own guide warns
that duplicate requests lead to multiple operations on the value and asks for
idempotency control. WooCommerce is the source of truth anyway, so a delta buys
nothing. Sending both modes in one request is an error (`150013003`).

`requestUniqueKey` is sent regardless, derived from the levels themselves so it
is stable for a given state and changes when the state does.

Quantities must be **0 to 1,000,000** (`150013002`), so levels are floored and
clamped. WooCommerce allows negative stock on backorder; Temu does not.

## Price sync

Methods: **`bg.local.goods.priceorder.change.sku.price`** and
**`bg.local.goods.priceorder.query`**.

### Two constraints that shape the whole feature

**1. The price API is whitelisted.** Temu documents it as a sensitive interface
"only available to whitelisted, locally developed applications", granted per
store through their business team. `bg.local.goods.sku.list.price.query` is
described as available only to "self-developed sellers or a small number of
third-party service providers".

A seller without that grant can publish products and sync stock perfectly well
and simply cannot push prices. `PriceSync::is_available()` therefore treats a
known-but-missing scope as a hard stop with a plain-language explanation, while
an empty scope list means *unknown* and the attempt is allowed so the API can be
the authority.

**2. Prices are negotiated, not set.** Temu runs a price order workflow:

| Status | Meaning |
|---|---|
| 100 | Under review |
| 101 | Awaiting merchant confirmation |
| 201 | Passed |
| 202 | Rejected, pending modification |
| 203 | Merchant confirmed |
| 204 | Merchant initiated modification |
| 205 | Merchant refused |
| 206 | Rejected |

A modification may only be initiated from **201, 202, 203, 205 or 206**. While a
price is under review (100) or waiting on the merchant (101), a change is
refused. So `bg.local.goods.priceorder.query` (`priceOrderType=2`) is checked
before anything is sent.

Failing to *read* the workflow is not treated as a block — that endpoint is
whitelisted just as tightly, and a permission error there says nothing about
whether a change would be accepted.

### Base price is not the shelf price

> "The base price reflects the amount you receive from each sale under standard,
> non-promotional conditions."

Temu sets what the buyer pays on top of it. Defaulting to the WooCommerce price
keeps the seller's settlement equal to what they charge in their own shop, which
is a sane starting point, but a seller carrying different margins per channel
will want `wtsync_base_price` to adjust it.

### Errors worth translating

| Code | Meaning |
|---|---|
| 150011103 | An ERP price is sitting unconfirmed in Seller Center; confirm it first |
| 150011101 | The change was larger than Temu allows in one step |
| 150010233 | The product is in a promotion or its cool-down; cannot lower the price |
| 150019004 | Price outside the range Temu will accept for that SKU |
| 150011018 | Too many decimal places for the currency |

## The SKU identifier problem

Both stock and price address SKUs by Temu's numeric `skuId`, which is minted at
listing time and is not knowable from anything the shop holds. So the mapping has
to be fetched and cached.

`temu.local.goods.list.retrieve` accepts `outGoodsSnList` — *our* identifiers —
and returns `skuInfoList: [{skuId, skuSn, specList, stock}]`.

**Unconfirmed:** whether `skuSn` echoes the `externalSkuId` we supplied or is
Temu's own serial. `SkuMap` handles both honestly: it matches on the identifier
when that works, falls back to the only possible pairing for a single-SKU
listing, and **refuses to map anything else**. Pairing multi-SKU listings by
position would push one variation's stock onto another, which is a far worse
failure than not syncing. Confirming `skuSn` against a live account would remove
the fallback entirely.

## Order import

### The order model

Temu is two tiers. A **parent order** (`parentOrderSn`, e.g. `PO-76-9900…`) is the
transaction; under it sit one or more **order items** (`orderSn`), each bound to
exactly one Temu SKU. That maps onto a WooCommerce order and its line items.

The `parentOrderSn` embeds the `regionId` — `PO-**76**-…` is Germany — and the
same region appears on every item beneath it.

### Data is split across four endpoints

Order, address and money are deliberately **not coupled**:

| Method | Gives |
|---|---|
| `bg.order.list.v2.get` | Orders and their items; poll by `updateAtStart`/`updateAtEnd`, paged |
| `bg.order.detail.v2.get` | Fuller detail for one order |
| `bg.order.shippinginfo.v2.get` | Shipping address, **masked** |
| `bg.order.decryptshippinginfo.get` | Shipping address, **real** |
| `bg.order.amount.query` | Money |

Temu recommends polling on **last update time**, not creation time, so an order
that changes after it was first seen comes back round.

### PII is opt-out, and that is the point

The default shipping endpoint returns a masked address — phone numbers come back
in a `XXX-XXX` shape. Only `bg.order.decryptshippinginfo.get` returns something
shippable.

The importer fetches the real address because an order cannot be fulfilled
without it, but `wtsync_decrypt_addresses` turns that off for a shop that fulfils
elsewhere, keeping buyer personal data out of WooCommerce entirely. That is worth
knowing for a non-EU seller shipping into the EU.

Address fields map as: `addressExtra.firstName`/`lastName` (falling back to
splitting `receiptName`), `addressLine1`, `addressLine2` + `addressLine3` joined
into WooCommerce's single second line, `regionName3` (or `regionName4`) as the
city, `regionName2` as the state, `postCode`, and the country resolved from
`regionId` because Temu only names it in prose.

### Order status — undocumented again

Temu names five statuses in prose — **Pending, Unshipped, Shipped, Delivered,
Cancelled** — and publishes **no mapping to the integers** `parentOrderStatus`
actually carries. Same situation as the publish status.

So a WooCommerce status is only ever set from a code that has been explicitly
mapped through `wtsync_order_status_map`, which ships empty. Guessing here is
worse than guessing a publish status: marking an unshipped order complete loses a
shipment.

**Cancellation is knowable without the enum.** `isCancelledDuringPending` is a
documented boolean on each order item, so a fully cancelled order is detected
from that rather than from a guess.

### Pending orders

Temu's guide is explicit: while an order is Pending the consumer can still change
the address and the quantity, and sellers should begin processing only once it
reaches Unshipped. Temu advances it automatically, usually within minutes.

Once the status enum is confirmed, `wtsync_should_import_order` should filter
Pending orders out. Until then everything is imported, because skipping on a
guessed code could hide real orders.

### Money — unit ambiguity

`bg.order.amount.query` returns `{amount, currency}` pairs for `customerPaid`,
`retailPriceTotalTaxIncl`, `taxTotal`, `shipAmountTotalTaxIncl`, `taxTotal`,
`estimatedRevenue`, `discountFromTEMU`, `discountFromSeller` and `refundsTotal`.

**Unconfirmed:** whether `amount` is major or minor units. Everywhere else in the
API money is a decimal string; here the schema shows a bare number. We treat it
as major units and expose `wtsync_order_amount` as the one-line correction. The
first real order settles it — check an imported total against Seller Center
before trusting the numbers. The whole payload is kept in order meta regardless,
since WooCommerce has no field for the seller revenue split.
