=== Ostoja – Tpay Payment Gateway for GiveWP ===
Contributors: ostoja
Tags: givewp, donations, tpay, blik, payment gateway
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept one-time GiveWP donations through Tpay (BLIK, bank transfer, cards) using the Tpay Open API.

== Description ==

Adds Tpay as an off-site payment gateway for GiveWP. Donors are redirected to Tpay to pay.
A donation is marked complete only after a JWS-signed Tpay notification is verified and the
transaction is re-fetched from the Tpay API (ID, amount and currency must match).

* GiveWP Visual Form Builder and Option-Based forms
* Follows GiveWP Test mode: sandbox when on, production when off; separate keys for each
* Every donation is verified in the environment it was created in
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

Payments are processed by Tpay under the site owner's own agreement with Tpay; Tpay's terms and privacy policy apply (https://tpay.com).

== Disclaimer ==

This plugin is a free connector provided "as is", without warranty of any kind (see sections 11–12 of the GPL).
The site owner is responsible for their Tpay account, configuration, testing and compliance.

== Installation ==

1. Install and activate GiveWP and this plugin.
2. Add your Tpay Open API keys in wp-config.php – production: OSTOJA_TPAY_CLIENT_ID, OSTOJA_TPAY_CLIENT_SECRET
   (optional OSTOJA_TPAY_SECURITY_CODE); sandbox: OSTOJA_TPAY_SANDBOX_CLIENT_ID, OSTOJA_TPAY_SANDBOX_CLIENT_SECRET
   (optional OSTOJA_TPAY_SANDBOX_SECURITY_CODE) – or in Donations → Settings → Payment Gateways → Tpay.
3. Enable the Tpay gateway and set the GiveWP currency to PLN.
4. Turn GiveWP Test mode on, use "Test connection" and make a sandbox donation. Turn Test mode off to go live.

== Changelog ==

= 0.2.1 =
* Fix: donors were sent to tpay.com instead of back to the site after paying – GiveWP passes return URLs rawurlencoded; they are now decoded and restricted to the site domain.

= 0.2.0 =
* Environment now follows GiveWP Test mode (the plugin's own sandbox switch and OSTOJA_TPAY_SANDBOX are removed).
* Separate sandbox and production keys (OSTOJA_TPAY_SANDBOX_* constants / settings).
* Each donation is created and verified in its own environment (GiveWP donation mode).

= 0.1.1 =
* Fix: accept the paid amount from the signed notification when the Tpay API omits payments.amountPaid (verified in sandbox).

= 0.1.0 =
* First version for sandbox testing.
