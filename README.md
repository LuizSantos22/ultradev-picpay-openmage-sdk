# ultradev-picpay-openmage-sdk

PicPay Checkout for [OpenMage](https://github.com/OpenMage/magento-lts) (Magento 1): **credit card**, **Pix** and **PicPay Wallet (QR Code)** in a single payment method. Adapted from the official PicPay Magento 2 module.

Card data is tokenized in the browser with the PicPay **CheckoutTransparent SDK** (loaded from the CDN). The card number, expiry date and CVV never reach your server, which only receives a one-time temporary token.

> **Status:** not yet validated against the live PicPay API or a production store. Test in sandbox first and review the [Known limitations](#known-limitations).

## Features

- One payment method with three branches: Card, Pix and Wallet (QR Code). Clicking a branch again closes it.
- Card tokenization via the PicPay SDK (CDN technique). Card fields have no `name` attribute, so the browser never submits them.
- Temporary token (valid for 5 minutes, single use) is kept in memory only and is never stored in the database. It is renewed automatically if it gets old.
- Installments (without interest) with configurable maximum and minimum installment amount.
- Pix and Wallet QR Code on the success page, with copy-and-paste code, an expiration countdown and automatic status polling.
- Webhook endpoint with Bearer token validation, plus optional confirmation of the charge status through the API before changing the order.
- Automatic invoice when the charge is paid, automatic cancellation on canceled/denied/expired charges, and credit memos on refunds/chargebacks.
- Online capture (late capture) and refund from the admin.
- API hosts, endpoint prefix and SDK URL are configurable in the admin.
- Debug log with sensitive data masked.

## Requirements

- OpenMage 20.x
- PHP 8.1 or higher, with the `curl` and `json` extensions
- Store currency: **BRL**
- PicPay Checkout credentials (Client ID and Client Secret)
- For card payments: the store's **CNPJ** and a **transparentToken**, both provided by PicPay

## Installation

### With modman

```bash
modman clone https://github.com/LuizSantos22/ultradev-picpay-openmage-sdk.git
```

### With Composer

Add the repository to your project's `composer.json` and require the package:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/LuizSantos22/ultradev-picpay-openmage-sdk" }
    ]
}
```

```bash
composer require ultradev/picpay-openmage-sdk
```

### Manual

Copy the files to the matching paths in your OpenMage root:

```
app/code/community/Ultradev/Picpay
app/etc/modules/Ultradev_Picpay.xml
app/design/frontend/base/default/layout/ultradev_picpay.xml
app/design/frontend/base/default/template/ultradev/picpay
skin/frontend/base/default/ultradev/picpay
```

### After installing

1. Clear the cache (System > Cache Management).
2. Open any page of the store. The install script runs automatically and adds the `picpay_charge_id` column to `sales_flat_order`.

## Configuration

Go to **System > Configuration > Sales > Payment Methods > PicPay (Card, Pix and Wallet)**.

| Field | Description |
|---|---|
| Enabled | Turns the method on or off. |
| Sandbox | Uses the sandbox API URL. Start with this enabled. |
| API URL (production / sandbox) | Defaults: `https://checkout-api.picpay.com/` and `https://checkout-api-sandbox.picpay.com/`. Confirm the current hosts with PicPay. |
| Endpoint prefix | Default `api/v1/`. OAuth always stays at the root. |
| Client ID / Client Secret | API credentials from the PicPay panel. |
| Webhook token | Same token registered in the PicPay panel. |
| Confirm status on webhook | Recommended. Queries the charge in the API before changing the order. |
| Sync status on success page | Fallback in case the webhook is late or fails. |
| Soft descriptor | Text shown on the card statement (max. 13 characters). |
| Enable Card / Pix / Wallet | Enables each branch and sets its label. |
| Card auto capture | `No` = authorize on order, capture when the invoice is created online. |
| Merchant CNPJ (`merchantCredential`) | Used by the SDK. Required for the card branch. |
| `transparentToken` (SDK) | Provided by PicPay. Public: it is sent to the browser together with the SDK. |
| SDK URL | Default `https://checkout.picpay.com/cdn/pp-transparent-v1.0.0.js` (production). For sandbox, use the script provided by PicPay. |
| Max installments / Minimum installment amount | Installment rules for the card. |
| Pix expiration | In minutes. |
| Order statuses | Pending, paid and paid (virtual) statuses. |
| Address lines | Which `street` line holds street, number, complement and district. |
| Debug log | Writes to `var/log/ultradev_picpay.log`. |

> The **Card** option only appears at checkout when both the CNPJ and the `transparentToken` are filled in. Without them the module works with Pix and Wallet only.

### Webhook

Register this URL in the PicPay panel:

```
https://your-store.com/picpaycheckout/callback/payments
```

PicPay must send the configured token as `Authorization: Bearer <token>`.

## How card payments work

1. The customer opens the Card branch. The browser loads the SDK from the configured CDN URL.
2. On **Continue** (payment step) or **Place Order**, the browser calls `CheckoutTransparent` (`setCredentials`, `getCardBrand`, `createTemporaryCard`) and receives a `temporaryToken`.
3. Only the token, card brand, last 4 digits and installments are submitted to the server.
4. The server sends the token as `credit.temporaryCardToken` in `charge/authorization`, then discards it.

## Getting access from PicPay

Before enabling card payments, ask PicPay for:

1. The **`transparentToken`** for your store.
2. The **SDK script URL for sandbox**. The documentation only lists the production script.
3. **Written confirmation** of how the SDK flow affects your PCI DSS scope, and which Self-Assessment Questionnaire (SAQ) applies. The SDK keeps card data off your server, but customers still type it on your page.
4. Confirmation of the **current API hosts**. The module defaults to `checkout-api.picpay.com` with `api/v1/`, while PicPay's authentication docs mention `ecommerce-api.svcp.picpay.com` (production) and `ecommerce-api.svcp.ppay.me` (staging). If requests return 404, adjust the URL and prefix fields in the admin.

## Known limitations

- **Native one-page checkout only.** The JS hooks `Payment.save` and `Review.save` from `opcheckout.js` (base and RWD). OneStepCheckout, FireCheckout and similar extensions have their own scripts and need an adjustment.
- **No 3-D Secure.** PicPay supports it, but it requires extra iframes and steps that are not implemented.
- **BRL only.**
- **PCI scope.** The card is typed on your page before being tokenized. Confirm your compliance requirements with PicPay and your acquirer.
- **Token field name.** The SDK docs example spells it `temporatyCardToken`, while the API reference uses `temporaryCardToken` (used here). If the API rejects it, change it in `Model/Builder.php`, inside `$tx['credit']`.

## Troubleshooting

| Symptom | What to check |
|---|---|
| Card option does not appear | CNPJ and `transparentToken` must both be filled in, and Card must be enabled. |
| PicPay does not appear at all | Client ID/Secret filled in, currency is BRL, and the cache was cleared. |
| 404 from the API | Adjust API URL and endpoint prefix in the admin. |
| "Could not load the card validator" | SDK URL is wrong or blocked (ad blocker, CSP). Check the browser console. |
| Order stays pending after payment | Check the webhook URL and token, and enable "Sync status on success page". |

Enable the debug log and inspect `var/log/ultradev_picpay.log`. Card numbers, CVV, secrets and access tokens are masked.

## Module structure

```
app/code/community/Ultradev/Picpay/
├── Block/            Form, Info, Success
├── Helper/Data.php
├── Model/            Api, Builder, Method, Observer, Processor, Source/Streetline
├── controllers/      CallbackController (webhook), StatusController (polling)
├── etc/              config.xml, system.xml
└── sql/              install-1.0.0.php
app/design/frontend/base/default/
├── layout/ultradev_picpay.xml
└── template/ultradev/picpay/   form.phtml, success.phtml
skin/frontend/base/default/ultradev/picpay/
├── css/picpay.css
└── js/                         picpay-form.js, picpay-success.js
```

## License

[OSL-3.0](https://opensource.org/licenses/OSL-3.0)
