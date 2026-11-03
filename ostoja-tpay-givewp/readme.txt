=== Ostoja – Tpay Payment Gateway for GiveWP ===
Contributors: ostoja
Tags: givewp, donations, tpay, blik, payment gateway
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept one-time GiveWP donations through Tpay (BLIK, bank transfer, cards) using the Tpay Open API.

== Description ==

Adds Tpay as an off-site payment gateway for GiveWP. Donors are redirected to Tpay to pay.
A donation is marked complete only after a JWS-signed Tpay notification is verified and the
transaction is re-fetched from the Tpay API (ID, amount and currency must match).

* GiveWP Visual Form Builder and Option-Based forms
* Sandbox and production environments
* API keys can be kept in wp-config.php; secrets are never rendered in HTML
* Idempotent notification handling (no duplicate receipts)

This plugin is not affiliated with or endorsed by Tpay (Krajowy Integrator Płatności S.A.) or GiveWP.

== External services ==

This plugin connects to Tpay (https://tpay.com), operated by Krajowy Integrator Płatności S.A.:

* api.tpay.com / openapi.sandbox.tpay.com – to authenticate, create a transaction (amount, description,
  donor email and name, return/notification URLs) and read its status, when a donor submits a donation
  and when Tpay sends a payment notification.
* secure.tpay.com / secure.sandbox.tpay.com – to download Tpay's public certificates used to verify
  notification signatures.

Tpay terms of service and privacy policy are available at https://tpay.com (TODO: link the exact pages before submitting to WordPress.org).

== Installation ==

1. Install and activate GiveWP and this plugin.
2. Add your Tpay Open API keys in wp-config.php (OSTOJA_TPAY_CLIENT_ID, OSTOJA_TPAY_CLIENT_SECRET,
   optional OSTOJA_TPAY_SECURITY_CODE, OSTOJA_TPAY_SANDBOX) or in Donations → Settings → Payment Gateways → Tpay.
3. Enable the Tpay gateway and set the GiveWP currency to PLN.
4. Use "Test connection" and make a sandbox donation.

== Changelog ==

= 0.1.0 =
* First version for sandbox testing.
