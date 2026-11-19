=== Localized Price Manager ===
Contributors: trkr
Tags: woocommerce, currency, pricing, geolocation, exchange-rates
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.2
WC requires at least: 7.9
WC tested up to: 11.2
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show WooCommerce shoppers prices in their own country's currency, from rules you set or converted automatically at stored exchange rates.

== Description ==

Localized Price Manager shows each visitor your WooCommerce prices in the currency of their country. You decide how much control you want: set an exact price for one product in one country, give a whole category one currency or rate, or leave everything to automatic conversion at exchange rates refreshed in the background.

https://youtu.be/Wxik6k-mbyI

= How a price is chosen =

The plugin detects the visitor's country, then walks a strict hierarchy and stops at the first level that gives an answer, so a narrow rule always beats a broad one:

1. **Product** — a fixed price, sale price, rate or block for one product in one country.
2. **Category** — a currency, rate or block for every product in a category, inherited down the category tree.
3. **Country group** — a shared currency or fixed rate for a group of countries; a group that sets one prices its countries ahead of their own currency.
4. **Country** — the currency the visitor's country is mapped to, converted at the stored exchange rate. Every country starts out mapped to its own national currency.
5. **Global fallback** — show the store price as it is, convert it to one global currency, or hide the price and block the purchase.

= Automatic conversion =

Exchange rates are fetched in the background from the provider you choose every 3, 4 or 6 hours or once a day, for USD, EUR, your global fallback currency and your shop base currency. Converted prices are always brought to the target currency's decimals, rounded to the nearest step, or always up, or always down. Prices you type in are never converted or rounded.

= Country detection =

The visitor's country comes from a chain of providers you tick and order: a country header from your CDN, the MaxMind database managed by WooCommerce, WooCommerce's own geolocation, or web services. Logged-in customers can instead be priced by the country on their customer profile.

= Key features =

* Per-country prices, sale prices, rates and purchase blocks on simple products
* Category rules for a currency, a rate or a block, inherited by sub-categories
* Country groups sharing one currency or one fixed rate
* Locale-correct currency symbols, decimal and thousand separators, including zero-decimal currencies such as yen
* Orders recorded in the currency the customer paid
* Works with the Cart and Checkout blocks through the Store API, and with HPOS
* Caching on Redis, Memcached, the WordPress object cache or transients, with background warm-up of popular products
* Diagnostic log with cache, error, service and audit channels, and an audit trail of every configuration and price change
* WP-CLI commands for migrations, rate refreshes and the cache

= Important limits — please read before installing =

* **Simple products only.** Variable, grouped, external and subscription products are never localized; they are shown in your store's base currency, correctly labelled.
* **One currency per cart.** A product whose currency differs from the products already in the cart cannot be added. The customer sees an error message.
* **Coupons, shipping costs and spend thresholds are not converted.** They are applied exactly as you entered them, as amounts of the currency the customer is shown. A fixed coupon of 10 takes off 10 units of that currency; percentage coupons are unaffected.
* **Tax is not taken into account** in the conversion.
* **Single site only.** WordPress multisite networks are not supported.
* **Exchange rates are used exactly as the provider returns them.** The plugin does not check a rate for plausibility. Check the stored rates after changing provider.
* **Payment gateways are not configured by this plugin.** Make sure your gateway accepts the currencies you sell in.
* **Catalogue sorting and the price filter widget** use your store's base prices.
* **Full-page caches** (caching plugins, host or CDN page caching) serve one visitor's prices to everyone unless the shop pages are excluded or the cache varies by country.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/localized-price-manager/`, or install it through the Plugins screen.
2. Activate it. WooCommerce must be installed and active. The database tables are created and every country is mapped to its national currency.
3. Go to **WooCommerce → Localized Price → Geolocation**. Tick and order the providers you want to use. To respect privacy and avoid unexpected external connections, no provider is enabled by default. While none is ticked, visitor country detection is paused, and an admin notice is shown.
4. On **Rate Settings**, tick your preferred exchange-rate providers, then click **Update rates now** to fetch the first rates. No external provider is enabled by default. While none is ticked, rates are not fetched in the background, and an admin notice is shown.
5. Optionally, edit a simple product and open its **Localized Prices** tab, or a product category, to add per-country rules.

== Frequently Asked Questions ==

= Prices are not localized. What should I check? =

In this order:

1. **WooCommerce → Localized Price → General → Localized pricing** is on.
2. At least one provider is ticked on the **Geolocation** tab. An admin warning is shown while none is.
3. **Customer country source** is **IP geolocation**. With **Billing address**, guests have no country and see the fallback.
4. The visitor's country row on **Countries & Groups** is switched on, and the **Exchange Rates** tab lists a rate for its currency.
5. The product is a **simple** product.
6. No full-page cache (caching plugin, host cache, CDN page cache) serves the shop pages. A cached page shows the prices of whoever loaded it first.
7. On a local test site, web geolocation services are never asked about private addresses such as 127.0.0.1. Preview a country instead: with `WP_DEBUG` on and logged in as a shop manager, add `?lpm_country=FR` to a shop URL.

The **Logs** tab (Service channel) records failed geolocation and exchange-rate lookups.

= Every visitor gets the same country. Why? =

Your site is behind a CDN or proxy and the plugin sees the proxy's address. On the **Geolocation** tab choose the **Trusted CDN** strategy, list the client IP header your CDN writes (for example `CF-Connecting-IP`) and its published IP ranges. If you also trust a country header such as `CF-IPCountry`, always set the client IP header too: lookups are cached per IP address, so without it visitors behind the same CDN server share one country.

= Which product types are supported? =

Simple products only. Other types are shown in your store's base currency, with your base currency's symbol and formatting, next to localized simple products.

= Can products in different currencies be in one cart? =

No. Adding a product whose currency differs from the cart's is refused with a message asking the customer to complete the purchase or empty the cart first. This applies to every product type.

= Where do exchange rates come from? =

From the providers you configure on **Rate Settings**: Frankfurter (no key), Fixer, Open Exchange Rates, ExchangeRatesAPI, FxRatesAPI and ExchangeRate-API (API key required, stored encrypted). Only the providers you tick are used, in your order, with the preferred provider first when it is ticked; unticked providers are never used, even the preferred one. The first that answers is used. Keyed providers need a paid plan for the base currencies the plugin requests; a request the plan refuses falls through to the next ticked provider. If none answers, the previously stored rates are kept. With none ticked, no rate is fetched at all and an admin warning is shown; the stored rates stay in use.

= What does "Hide price and product" do? =

The price and the add to cart button disappear and the product cannot be bought, on the shop, search, the product page and through the Store API. The same happens for a country blocked on a product or a category. The product still appears in catalogue listings and pagination. To remove it from the catalogue, use WooCommerce's own catalogue visibility.

= Does the admin area or the WooCommerce REST API show converted prices? =

No. The admin, admin AJAX for orders, and every REST route except the Store API used by the Cart and Checkout blocks work in your store's own currency. Orders are read back in the currency they were recorded in.

= What happens to my data if I uninstall? =

Nothing is deleted unless you switch on **Delete all data on uninstall** on the General tab first. While it is on, deleting the plugin permanently removes its tables (price rules, country mappings, groups, rates, logs), its settings, cached data, the country stored on customer profiles, its log files. Currency information saved on orders stays. Pending background tasks are removed on uninstall either way. Deactivation only stops the scheduled tasks.

== External services ==

This plugin connects to third-party services only when you enable them in its settings. The full list, with what each request sends, is in [External services](https://github.com/Degisken-Yazilim/woocommerce-localized-price-manager/blob/main/EXTERNAL-SERVICES.md).

= Exchange-rate providers =

Contacted by the background rate refresh and the **Update rates now** button, only for the providers you tick. A request sends a base currency code and, for keyed providers, your API key. No customer data is sent.

* **Frankfurter** — [Licence and terms](https://frankfurter.dev/license/); no privacy policy published
* **Fixer** — [Terms](https://fixer.io/terms), [Privacy policy](https://fixer.io/privacy)
* **Open Exchange Rates** — [Terms](https://openexchangerates.org/terms), [Privacy policy](https://openexchangerates.org/privacy)
* **ExchangeRatesAPI** — [Terms](https://exchangeratesapi.io/terms/), [Privacy policy](https://exchangeratesapi.io/privacy/)
* **FxRatesAPI** — [Terms](https://fxratesapi.com/legal/terms-conditions), [Privacy policy](https://fxratesapi.com/legal/privacy-policy)
* **ExchangeRate-API** — [Terms of service and privacy policy](https://www.exchangerate-api.com/terms)

= Geolocation services =

Contacted to detect a visitor's country, only for the providers you tick. A request sends the **visitor's IP address** and, for keyed providers, your API key. Private and local addresses are never sent.

* **GeoJS** — [Terms of use](https://www.geojs.io/tos/), [Privacy policy](https://www.geojs.io/privacy/)
* **IP-API** — plain HTTP, non-commercial use only. [Terms and privacy policy](https://ip-api.com/docs/legal)
* **IP2Location.io** — [Terms](https://www.ip2location.io/terms-of-service), [Privacy policy](https://www.ip2location.io/privacy-policy)
* **IPinfo** — [Terms](https://ipinfo.io/terms-of-service), [Privacy policy](https://ipinfo.io/privacy-policy)
* **ipstack** — [Terms](https://www.ideracorp.com/legal/APILayer), [Privacy policy](https://www.ideracorp.com/Legal/PrivacyPolicy)
* **WooCommerce Geolocation** — without a MaxMind database, WooCommerce itself sends the IP address to its own geolocation services (ipinfo.io, and ip-api.com or [country.is](https://country.is/) depending on the WooCommerce version).
* **MaxMind** — the plugin reads the database WooCommerce downloads and makes no call itself. [GeoLite2 licence](https://www.maxmind.com/en/geolite/eula), [Privacy policy](https://www.maxmind.com/en/privacy-policy)

== Privacy ==

The plugin stores the visitor's detected country, keeps the IP address on its diagnostic log entries, and registers personal data exporters, erasers and suggested privacy policy text with WordPress. Details are in [Privacy](https://github.com/Degisken-Yazilim/woocommerce-localized-price-manager/blob/main/PRIVACY.md).

== Source code ==

The JavaScript and CSS in `assets/dist` are compiled. Their uncompiled source is in `assets/src` in the [public repository](https://github.com/Degisken-Yazilim/woocommerce-localized-price-manager).

== Screenshots ==

1. Single product page: Automatic country-based price resolution for each visitor
2. Shop catalog and WooCommerce blocks: Products automatically displayed in the shopper's local currency
3. Cart and checkout: Single-currency cart totals and seamless checkout in the agreed currency
4. Customer account and order history: Orders recorded and tracked in the currency the shopper agreed to pay
5. Product edit screen: Granular per-country pricing overrides (Tier 1) and custom exchange rate multipliers
6. Countries & Groups settings: 240+ pre-configured countries, regional country groups, and live exchange rates

== Changelog ==

See the [changelog](https://github.com/Degisken-Yazilim/woocommerce-localized-price-manager/blob/main/changelog.txt).

== Upgrade Notice ==

= 1.0.0 =
Initial release.
