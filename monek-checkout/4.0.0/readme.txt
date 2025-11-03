=== Monek Checkout ===
Contributors: humberstone83
Tags: credit card, payments, monek, woocommerce
Requires at least: 6.0
Tested up to: 6.8.2
Requires PHP: 7.4
Stable tag: 5.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Monek Checkout delivers an embedded iframe checkout for WooCommerce, mounting the new hosted fields and express surfaces so you can integrate the latest SDK callbacks in your store theme.

== Description ==

The plugin replaces the legacy hosted redirect with a modern embedded checkout experience powered by the latest Monek Checkout SDK. It mounts secure hosted fields and optional express wallets directly on the WooCommerce checkout form and wires up the primary SDK callbacks (amount, cardholder details, etc.) ready for a future server-side submission flow.

Key features:
* Embedded checkout iframe with hosted card fields and configurable styling.
* Express wallet surface (Apple Pay, etc.) rendered above the billing form when available.
* Blocks and classic checkout support with a single JavaScript controller.
* SDK callback scaffolding so future iterations can intercept submission and finalise payments from your server.

== Installation ==

1. Upload the plugin to your WordPress site or install it from the Plugins screen.
2. Activate the plugin through the Plugins menu.
3. Go to **WooCommerce → Settings → Payments** and enable **Monek Checkout**.
4. Enter your Monek publishable key (and optionally the secret key for future use), then save changes.

Once configured, the embedded checkout iframe will appear on the WooCommerce checkout page with callbacks in place, ready for you to extend with your own payment submission logic.

== Frequently Asked Questions ==

= Does this build charge cards or change order statuses? =
No. This release focuses purely on mounting the checkout UI and exposing SDK callbacks. You will need to add your own payment submission and order management logic on top of this foundation.

= Can the express wallet surface be hidden? =
Yes. Disable **Express checkout** in the gateway settings to hide the express surface if you only want the hosted card fields.

== Changelog ==

=5.0.0=
*Release Date - 2025-10-24*

* Rebuilt the plugin to focus on embedding the new checkout iframe and wiring SDK callbacks for future payment handling.
* Removed legacy hosted checkout, callback, consignment, and transaction helper code paths.
* Added lightweight gateway, Blocks integration, and assets to mount the checkout and express surfaces in WooCommerce.
