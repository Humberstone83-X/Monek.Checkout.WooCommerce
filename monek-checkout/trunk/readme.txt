=== Monek Checkout ===
Contributors: humberstone83
Tags: credit card, payments, monek, woocommerce
Requires at least: 6.0
Tested up to: 6.8.2
Requires PHP: 7.4
Stable tag: 5.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Monek Checkout delivers an embedded iframe checkout for WooCommerce that tokenises cards, triggers 3DS challenges, and returns the payment token to your store for server-side completion.

== Description ==

The plugin replaces the legacy hosted redirect with a modern embedded checkout experience powered by the latest Monek Checkout SDK. It mounts secure hosted fields and optional express wallets directly on the WooCommerce checkout form, intercepts the Place Order flow to perform 3DS, and hands the resulting token back to WooCommerce so you can complete the payment server-side.

Key features:
* Embedded checkout iframe with hosted card fields and configurable styling.
* Express wallet surface (Apple Pay, etc.) rendered above the billing form when available.
* Blocks and classic checkout support with a single JavaScript controller.
* Server-side payment completion hook that stores the token, session ID, and metadata on the order for follow-up capture.

== Installation ==

1. Upload the plugin to your WordPress site or install it from the Plugins screen.
2. Activate the plugin through the Plugins menu.
3. Go to **WooCommerce → Settings → Payments** and enable **Monek Checkout**.
4. Enter your Monek publishable and secret keys, then save changes.

Once configured, the embedded checkout iframe will appear on the WooCommerce checkout page and the plugin will collect tokens ready for completion via your server integration.

== Frequently Asked Questions ==

= Why do new orders enter the “On hold” status? =
The plugin finalises 3DS and tokenisation in the browser but leaves payment capture to your server. Orders are set to “On hold” after the token is stored so you can complete the payment with the Monek API and update the status accordingly.

= Can the express wallet surface be hidden? =
Yes. Disable **Express checkout** in the gateway settings to hide the express surface if you only want the hosted card fields.

== Changelog ==

=5.0.0=
*Release Date - 2025-10-24*

* Rebuilt the plugin around the embedded checkout iframe with server-side completion hooks.
* Removed legacy hosted checkout, callback, consignment, and transaction helper code paths.
* Added lightweight gateway, Blocks integration, and assets to mount the new checkout with express wallets.
