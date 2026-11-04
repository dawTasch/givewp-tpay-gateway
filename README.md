# Tpay Payment Gateway for GiveWP

**🇬🇧 English** · [🇵🇱 Polski](README.pl.md)

Accept donations in your WordPress site with **[GiveWP](https://wordpress.org/plugins/give/)** and **[Tpay](https://tpay.com)**: BLIK, Polish bank transfers and cards. The donor fills in the GiveWP form, pays on Tpay's secure page, and the donation is marked **complete only after a signed Tpay notification has been verified against the Tpay API**. After that, GiveWP sends the thank-you email.

Built for [Ostoja](https://ostoja.gda.pl). Free and open source (GPL-2.0-or-later). Any organisation that uses GiveWP and Tpay can use it.

> **Status: 0.1.0, sandbox testing.** Do not switch to production until you have run the [test checklist](#5-test-in-sandbox).

---

## Features

- **Off-site payment.** Card and bank data never touch your site. Donors pay on Tpay (BLIK, pay-by-link, cards, Google Pay and Apple Pay, depending on your Tpay account).
- **Verified payments only.** Each notification is checked three ways: its JWS signature (certificate chain pinned to Tpay's root CA), an optional `md5sum` check, and a fresh `GET /transactions/{id}` that must match the donation's ID, amount and currency.
- **No keys in the browser.** API keys live in `wp-config.php` or in write-only admin fields. The front end gets nothing but a help text.
- **Idempotent.** Repeated or parallel notifications never complete a donation twice or send duplicate emails.
- **Refunds.** Tpay `CHARGEBACK` notifications mark the donation as *Refunded*.
- **Works with both GiveWP form types:** Visual Form Builder (v3) and Option-Based Form Editor (v2).
- **Uses GiveWP Test mode.** When GiveWP *Test mode* is on, payments go to the Tpay sandbox; when it is off, they go to production. Sandbox and production keys are stored separately, so switching never requires re-entering keys. Each donation is always verified in the environment it was created in, so a sandbox notification can never complete a live donation, and the reverse is also true.
- **"Test connection" button** for each environment.
- Everything else is standard GiveWP: forms, donor list, reports, CSV export, receipts and GDPR tools.

## How it works

```mermaid
sequenceDiagram
    autonumber
    actor D as Donor
    participant WP as WordPress<br/>(GiveWP + this plugin)
    participant T as Tpay
    D->>WP: Submit GiveWP form (amount, email)
    WP->>WP: Donation created as "Pending"
    WP->>T: OAuth + POST /transactions (server-to-server)
    T-->>WP: transactionPaymentUrl
    WP-->>D: Redirect to Tpay
    D->>T: Pay with BLIK / transfer / card
    T->>WP: POST notification + X-JWS-Signature
    WP->>WP: Verify JWS signature (+ md5sum)
    WP->>T: GET /transactions/{id}
    T-->>WP: status, amount paid
    WP->>WP: Donation set to "Complete", GiveWP sends receipt email
    WP-->>T: TRUE
    T-->>D: Redirect back to the GiveWP confirmation page
```

When the donor returns to your site, the donation status does **not** change. Only the verified notification can mark a donation as paid.

Everything runs on your existing WordPress hosting. No extra server or SaaS is needed.

| Component | Responsibility |
|---|---|
| GiveWP | Forms, donations, donors, reports, emails, GDPR |
| This plugin | Talks to Tpay, verifies notifications, updates donation status |
| Tpay | Payment page, BLIK, banks, cards, payouts |
| SMTP/API mail plugin (optional) | Reliable delivery of GiveWP emails, see [Emails](#emails) |

## Requirements

- WordPress 6.0+, PHP 7.4+ with OpenSSL
- GiveWP 3.x or 4.x, with currency set to **PLN**
- HTTPS, and your server must be able to make outgoing HTTPS requests to `*.tpay.com`
- A Tpay merchant account with **Open API** keys

## Installation

### 1. Install the plugin

1. Download `ostoja-tpay-givewp-x.y.z.zip` from [Releases](../../releases).
2. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the ZIP, then **Install** and **Activate**.
   GiveWP must be installed and active first.

### 2. Get your Tpay keys

Tpay has two separate accounts with separate keys:

| | Where | Keys used when |
|---|---|---|
| **Production** | Tpay Merchant Panel → **Integration → API** → Open API keys | GiveWP *Test mode* is **off** |
| **Sandbox** | Register at [register.sandbox.tpay.com](https://register.sandbox.tpay.com), then [panel.sandbox.tpay.com](https://panel.sandbox.tpay.com) → **Integration → API** | GiveWP *Test mode* is **on** |

Optional: **Notifications → Security** in each panel shows a *security code* that enables an extra `md5sum` check.

### 3. Configure the keys

**Recommended:** add the keys to `wp-config.php`, above the line `/* That's all, stop editing! */`:

```php
// Production (GiveWP Test mode OFF)
define('OSTOJA_TPAY_CLIENT_ID', 'production-client-id');
define('OSTOJA_TPAY_CLIENT_SECRET', 'production-client-secret');
define('OSTOJA_TPAY_SECURITY_CODE', 'production-security-code');   // optional

// Sandbox (GiveWP Test mode ON)
define('OSTOJA_TPAY_SANDBOX_CLIENT_ID', 'sandbox-client-id');
define('OSTOJA_TPAY_SANDBOX_CLIENT_SECRET', 'sandbox-client-secret');
define('OSTOJA_TPAY_SANDBOX_SECURITY_CODE', 'sandbox-security-code'); // optional
```

This way the keys never reach the database, database backups or the admin UI. You only need the set or sets you actually use.

**Alternative:** go to **Donations → Settings → Payment Gateways → Tpay** and enter the keys there, in the *Production* and *Sandbox* groups. Secret fields are write-only: a saved secret is never shown again, and leaving the field empty keeps the current value.

### 4. Enable the gateway

1. **Donations → Settings → General → Currency**: set **Polish Złoty (PLN)**.
2. **Donations → Settings → Payment Gateways → Gateways**: enable **Tpay** for the form types you use.
3. **Donations → Settings → Payment Gateways → Tpay**: check the status table and click **Test connection** for each configured environment.

### 5. Test in sandbox

Turn **GiveWP Test mode on** (Donations → Settings → Payment Gateways → Gateways → *Test mode*).

- [ ] Make a donation, get redirected to `secure.sandbox.tpay.com`, pay, return to the confirmation page.
- [ ] Within a few seconds the donation is **Complete** with the note *"Płatność potwierdzona przez Tpay (TR-…)"* (plugin messages are currently in Polish).
- [ ] The donor receives the GiveWP receipt email.
- [ ] An abandoned payment stays **Pending**.
- [ ] `curl -X POST "<notification URL>"` returns `401 FALSE`. The notification URL is shown on the Tpay settings screen.
- [ ] **Donations → Tools → Logs** shows no `Tpay:` errors.

### 6. Go live

Make sure the production keys are configured and *Test connection (Production)* is green. Then turn **GiveWP Test mode off** and make one small real donation, for example 1 zł via BLIK. You can refund it in the Tpay panel; the donation will then switch to *Refunded*.

Donations started before the switch are still verified in the environment they were created in.

## Emails

GiveWP sends donor receipts and admin notifications through `wp_mail()`. For reliable delivery, install a mail plugin such as **FluentSMTP** or **WP Mail SMTP**, connect it to a transactional email provider's **API** (Brevo, Amazon SES, Mailgun, Postmark, SendGrid…), and keep the provider's API key in `wp-config.php`. Also set up SPF, DKIM and DMARC for your domain.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Donors see "Nie udało się rozpocząć płatności" (could not start the payment) | Keys missing or wrong for the current environment. For example, Test mode is off but only sandbox keys are set, or sandbox keys were entered as production keys. Use *Test connection*, then check the donation notes. |
| Donation stays *Pending* after a successful payment | The notification is not reaching your site. Common causes: a security plugin (Wordfence), Cloudflare Bot Fight Mode, a `www` ↔ non-`www` or HTTP → HTTPS redirect, or maintenance mode. Check **Donations → Tools → Logs**. |
| `Tpay: odrzucono powiadomienie z nieprawidłowym podpisem JWS` (invalid JWS signature) in logs | Outgoing HTTPS to `secure.tpay.com` or `secure.sandbox.tpay.com` is blocked, or the request did not come from Tpay. |

## Security

- Secrets are used server-side only and never rendered in HTML, not even as password field values.
- The notification endpoint accepts only `POST` requests signed by Tpay. The signing certificate URL is pinned per environment, so the plugin never fetches attacker-supplied URLs, and the certificate must chain to Tpay's root CA and be within its validity period.
- The donation status changes only after the transaction is re-fetched from the Tpay API and the ID, `hiddenDescription`, amount and currency all match. The notification's `tr_id` must also equal *our* transaction's title. A valid Tpay signature alone only proves the message came from Tpay, so another Tpay merchant cannot reuse our donation reference to complete or refund a donation.
- Concurrent notifications for the same donation are serialised with a database lock (`GET_LOCK`), so there is no double completion and no duplicate receipts.
- Every rejection before verification returns the same `401 FALSE`, so the endpoint does not reveal which donation IDs exist. Invalid-signature log entries are throttled (at most one per 10 minutes).
- The payment redirect URL must be HTTPS on `tpay.com`.

Found a vulnerability? Please report it privately via GitHub Security Advisories, not in a public issue.

## Development

```bash
bin/test.sh          # php -l + JWS verifier tests on PHP 7.4 and 8.3 (Docker)
DOCKER=0 bin/test.sh # same, using local PHP
bin/build-zip.sh     # dist/ostoja-tpay-givewp-<version>.zip
```

Releases: bump `Version:` in `ostoja-tpay-givewp/ostoja-tpay-givewp.php` and `Stable tag` in `readme.txt`, then push a tag `vX.Y.Z`. GitHub Actions runs the tests and attaches the ZIP to a GitHub Release.

```
ostoja-tpay-givewp/       WordPress plugin (this folder goes into the ZIP)
├── ostoja-tpay-givewp.php  bootstrap, gateway registration
├── src/TpayGateway.php     GiveWP gateway: create payment, handle notification
├── src/TpayApi.php         Tpay Open API client (OAuth, transactions)
├── src/JwsVerifier.php     notification signature verification
├── src/Settings.php        admin settings section
├── src/Config.php          wp-config constants / settings
└── assets/js/              gateway registration for Visual Form Builder
tests/                    standalone tests
bin/                      test and build scripts
```

## Roadmap

- Poll Tpay for donations still pending after N hours, in case a notification was lost
- Refunds started from the GiveWP admin through the Tpay API
- Recurring donations

## Disclaimer

This plugin is a **free connector** between GiveWP and Tpay, provided **"as is", without warranty of any kind** and without any liability of the authors (see sections 11–12 of the [GPL](LICENSE)). You use it at your own risk. You are responsible for your Tpay account and agreement, your configuration, testing before going live, and legal compliance (including privacy and GDPR obligations towards your donors). Payments are processed by Tpay under your own agreement with Tpay.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).

*Tpay is a trademark of Krajowy Integrator Płatności S.A. GiveWP is a trademark of its owners. This project is independent and is not affiliated with or endorsed by either of them.*
