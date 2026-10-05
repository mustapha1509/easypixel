# EasyPixel — Meta Pixel + Conversions API for PrestaShop 8.x / 9.x

## What's actually in this build

This is a real, installable module skeleton — not a mockup. It implements the
highest-impact features from your spec, fully working:

- **Dual delivery**: every tracked event fires via the browser Pixel *and*
  server-side Conversions API, sharing one `event_id` so Meta deduplicates.
- **Core events**: PageView, ViewContent, AddToCart, InitiateCheckout,
  Purchase, Refund — each individually enabled/disabled in the admin.
- **Purchase controls**: VAT/shipping inclusion toggle, payment-validated-only
  gating, delay-by-payment-module (e.g. hold Bank Transfer/Cheque until
  paid), Basic Mode for redirect gateways, exclude customer groups /
  customer IDs / order statuses, back-office order exclusion.
- **Refund event** via CAPI on refund/cancel order statuses (with the
  understanding that it's a reporting/audience signal, not a Purchase
  reversal in Ads Manager).
- **GDPR consent gate**: cookie or localStorage check, blocks both Pixel and
  CAPI until granted, plus an optional CSS-selector listener for consent
  managers that update state without a page reload.
- **Advanced Matching**: SHA-256 hashed email / name / address fields, with
  per-group opt-in (contact / personal / address) in the admin.
- **IP handling**: raw, and a best-effort IPv6 preference mode; CloudFlare
  `CF-Connecting-IP` auto-detected.
- **Admin tools**: settings form, one-click "Send Test Event" against your
  Test Event Code, and a CAPI log viewer showing the last 10 events with
  full payload/response.
- **Performance**: configurable header/footer placement, configurable defer
  timer before the pixel script loads.

## Installation

1. Zip the `easypixel/` folder (folder name must stay `easypixel`).
2. Back Office → Modules → Upload a module → select the zip.
3. Configure → enter your **Pixel ID** and **Conversions API Access Token**
   (generate both in Events Manager → Settings → Conversions API).
4. Set a Test Event Code, click **Send Test Event**, confirm it appears in
   Events Manager → Test Events.
5. Walk through a real purchase in a test order and check the CAPI log table
   plus Meta Pixel Helper for the full funnel.

## Architecture notes for whoever extends this

- `easypixel.php` — module class: hooks, purchase-filter logic, admin form.
- `classes/EasyPixelCapi.php` — CAPI HTTP client + hashing + log rotation
  (keeps last 10 rows automatically).
- `views/js/easypixel-front.js` — consent gate, AddToCart via PrestaShop's
  native `updateCart` JS event, Basic Mode Purchase flush.
- `views/templates/hook/easypixel-header.tpl` — emits the base Pixel script and
  any browser-side events queued by PHP for the current page.
- Configuration is stored via PrestaShop's `Configuration` class, which is
  already shop-context-aware — so per-shop values work out of the box for
  a *single* pixel per shop. See roadmap below for true multi-pixel.

