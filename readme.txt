=== Marketplace Connector for Temu ===
Contributors: 
Tags: woocommerce, temu, marketplace, multichannel, inventory sync
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell your WooCommerce products on Temu. Talks to the Temu Open API directly, with no middleman service and no fees on your sales.

== Description ==

Marketplace Connector for Temu links a WooCommerce store to a Temu seller
account. Your site talks to Temu's API directly, so your catalogue and your
customers' order data never pass through a third-party service, and nothing is
metered against your sales volume.

Supports Temu's EU, US and Global regions, for both local and cross-border
seller accounts.

= Status =

Early development. The connection layer, product publishing, publication status
polling, stock and price sync and order import are complete and tested.
Fulfilment - pushing tracking numbers back to Temu - is the remaining piece.

Note that Temu grants its price API per store, through their business team. Stock
sync works for every seller; price sync needs that grant, and the plugin tells you
plainly when it is missing rather than silently leaving prices unchanged.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it.
2. Create an app on the Temu partner console for your region.
3. Go to WooCommerce > Temu, enter your app key and secret, and connect.

== Frequently Asked Questions ==

= Do I need a Temu partner app? =

Yes. Temu's API requires an app key and secret issued by their partner console.
The plugin supports pasting an access token by hand, so you can start before
your app is published on Temu's App Store.

= Where is my data stored? =

On your own server. The app secret and access token are encrypted at rest using
your site's WordPress salts.

== Changelog ==

= 0.1.0 =
* Signed Temu Open API client with retry, backoff and typed error handling.
* Seller authorisation via callback or manual token, with region auto-detection.
* Connection settings screen showing mall, token expiry and granted API scopes.
* Product publishing through the Add Products V3 API, including unit conversion
  and image URL validation.
* Background publication status polling with a Temu column on the products list.
* Stock sync using Temu's full-overwrite mode, coalesced so a burst of sales
  costs one API call.
* Price sync that respects Temu's price-order workflow and explains, per product,
  when the platform will not accept a change.
* Order import, polling Temu on last-update time, with buyer address decryption
  you can switch off if you fulfil elsewhere.
