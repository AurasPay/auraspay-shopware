# AURAS Pay for Shopware 6

Official Shopware 6.7 payment plugin for accepting cryptocurrency through the
AURAS Pay hosted checkout.

## Features

- Merchant API key and webhook secret configuration
- Customer currency and blockchain network selection
- Hosted AURAS Pay checkout redirect
- Signed webhook verification using the exact raw request body
- Server-side payment re-verification before marking a transaction paid
- Idempotent external payment ID mapping in Shopware transaction custom fields
- No subscription or commission logic

## Installation

1. Upload `AurasPayShopware.zip` in **Extensions > My extensions**.
2. Install and activate **AURAS Pay**.
3. Open the extension configuration and enter the API key and webhook secret.
4. In the AURAS Pay dashboard, set the webhook URL to:
   `https://YOUR-STORE/store-api/auraspay/webhook`
5. Assign **AURAS Pay (Crypto)** to the desired sales channel under its payment
   methods and make it active.
6. Place a test order and choose AURAS Pay at checkout.

## Security

The browser never receives the merchant API key or webhook secret. A return
from hosted checkout does not prove payment. Only a valid signed webhook,
followed by an authenticated payment lookup that matches the Shopware
transaction, can mark it paid.

## Compatibility

- Shopware 6.7.x
- PHP version supported by the target Shopware release

## Support

- API documentation: https://auraspay.com/api-docs
- Website: https://auraspay.com
