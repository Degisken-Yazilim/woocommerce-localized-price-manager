# Privacy

## What the plugin stores

- The visitor's detected country and the time it was checked: in the WooCommerce session for guests, and on the customer's profile for logged-in customers.
- When enabled by the store, the `lpm_country` cookie stores the resolved country code for up to 24 hours for page cache configuration.
- In its diagnostic log, the user ID, username and IP address of the request. The full IP address is kept on audit entries and on entries of warning severity or above, and with its last part removed on other entries. While the site runs with `WP_DEBUG` on, the full IP address is kept on every entry. Entries are deleted after the configured retention period (30 days by default).

## WordPress privacy tools

The plugin registers with the WordPress privacy tools:

- Two **personal data exporters** (customer country, diagnostic log entries) under **Tools → Export Personal Data**.
- Two **personal data erasers** under **Tools → Erase Personal Data**: one removes the stored customer country, the other anonymizes the customer's log entries by clearing the user ID, username and IP address.
- Suggested **privacy policy text** under **Settings → Privacy**, describing the geolocation lookup, the stored country and the diagnostic log.

## Data sent to other services

Visitor IP addresses are sent to the geolocation services you enable. See [External services](EXTERNAL-SERVICES.md).
