# External services

Localized Price Manager connects to third-party services only when you enable them in its settings. Each call goes through the WordPress HTTP API.

## Exchange-rate providers

Used by the background refresh (every 3, 4 or 6 hours or daily, as configured) and by the **Update rates now** / **Refresh rates now** buttons. A request sends a base currency code (USD, EUR, your global fallback currency or your shop base currency) and, for keyed providers, your API key. No customer data is sent.

Only the providers you tick are contacted: the preferred provider first when it is ticked, the others only when an earlier one fails. With none ticked, no provider is contacted. Keyed providers are contacted only once you have entered their key, and need a paid plan for the base currencies requested; a request the plan refuses falls through to the next ticked provider.

- **Frankfurter** (`api.frankfurter.dev`) — no API key. [Service](https://frankfurter.dev/) · [Licence and terms](https://frankfurter.dev/license/) · Privacy policy: none published; the service states it does not log personal data, IP addresses or request URLs.
- **Fixer** (`data.fixer.io`) — API key. [Terms](https://fixer.io/terms) · [Privacy policy](https://fixer.io/privacy)
- **Open Exchange Rates** (`openexchangerates.org`) — API key. [Terms](https://openexchangerates.org/terms) · [Privacy policy](https://openexchangerates.org/privacy)
- **ExchangeRatesAPI** (`api.exchangeratesapi.io`) — API key. [Terms](https://exchangeratesapi.io/terms/) · [Privacy policy](https://exchangeratesapi.io/privacy/)
- **FxRatesAPI** (`api.fxratesapi.com`) — API key. [Terms](https://fxratesapi.com/legal/terms-conditions) · [Privacy policy](https://fxratesapi.com/legal/privacy-policy)
- **ExchangeRate-API** (`v6.exchangerate-api.com`) — API key. [Terms of service and privacy policy](https://www.exchangerate-api.com/terms)

## Geolocation services

Used to detect a visitor's country when **Customer country source** is **IP geolocation**, and to detect a customer's country at login. Only the providers you tick on the **Geolocation** tab are used, in your order; later ones are asked only when earlier ones give no answer.

A request sends the **visitor's IP address** and, for keyed providers, your API key. Private and local addresses are never sent. Each answer is cached for 24 hours; a guest's country is then kept in the WooCommerce session for 24 hours, a customer's on their profile for 12 hours.

- **GeoJS** (`get.geojs.io`) — no key. [Service](https://www.geojs.io/) · [Terms of use](https://www.geojs.io/tos/) · [Privacy policy](https://www.geojs.io/privacy/)
- **IP2Location.io** (`api.ip2location.io`) — API key. [Terms](https://www.ip2location.io/terms-of-service) · [Privacy policy](https://www.ip2location.io/privacy-policy)
- **IPinfo** (`api.ipinfo.io`) — access token. [Terms](https://ipinfo.io/terms-of-service) · [Privacy policy](https://ipinfo.io/privacy-policy)
- **ipstack** (`api.ipstack.com`) — access key. [Terms](https://www.ideracorp.com/legal/APILayer) · [Privacy policy](https://www.ideracorp.com/Legal/PrivacyPolicy)

## Providers that run through WooCommerce

- **MaxMind** — the plugin reads the GeoLite2 database file that WooCommerce's MaxMind Geolocation integration downloads with your MaxMind licence key. The plugin itself makes no call to MaxMind. [GeoLite2 licence](https://www.maxmind.com/en/geolite/eula) · [Privacy policy](https://www.maxmind.com/en/privacy-policy)

## CDN country header

The **CDN Header** provider reads a country code your own CDN has added to the request. It makes no outside call.
