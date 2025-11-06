# Lavendel Hygiene Internal
Lavendel Hygiene Internal is two WordPress extensions for a custom webstore.

The extensions are as follows:
- Lavendel Hygiene Core: 
  - Custom registration
  - Manual customer approval
  - Status Emails
  - Gating, i.e. prevent non-approved users from viewing product prices
- LavendelHygiene Tripletex
  - Connects webstore with Tripletex API (accounting software)
  - Provide settings for setting URLs and tokens
  - Sync customers with tripletex counterpart
  - Sync product prices
  - Automatic order generation in tripletex on webstore checkout

## Project structure

```
custom-extension/
├─ core/
│  └─ lavendelhygiene-core.php            
└─ tripletex-api/
   ├─ lavendelhygiene-tripletex.php       
   └─ includes/
      ├─ api.php
      ├─ services.php
      ├─ settings-page.php
      └─ webhooks.php
```

## Requirements

- WordPress 6.x, WooCommerce active
- PHP 7.4+ 
- Tripletex consumer + employee tokens, and optional company id (0 = employee token’s company)

## Installation

1) Copy both plugin folders into wp-content/plugins:
- custom-extension/core --> wp-content/plugins/lavendelhygiene-core
- custom-extension/tripletex-api --> wp-content/plugins/lavendelhygiene-tripletex

2) Activate in WP Admin / Plugins:
- Lavendel Hygiene Core
- LavendelHygiene Tripletex

3) Configure Tripletex (WP Admin --> WooCommerce --> Tripletex):
- Base URL: https://tripletex.no/v2
- Consumer token, Employee token
- Company ID (0 for default), Webhook secret
- Click Test connection to verify and cache a session token.

## Usage

**User approval flow:** 
- Users register via Woo; they become pending
- Admins Manage users
  - Tripletex linking:
    - Manually enter “Tripletex ID” in field, or
    - If user does not exist you can click “Create in Tripletex”
  - Then click approve/deny
- User notified if approved/denied


![Digram of user approval flow](docs/new-user-path.png)

**Checkout:**
- Approved customer places an order
- A Tripletex order is created:
  - With correct product selected
  - With correct customer selected
  - TODO: anything more?
- Tripletex ID stored on the Woo order

**Products:**
- Set _tripletex_product_id on Woo products to enable price sync.

## Webhooks

Endpoint: https://your-site.tld/wp-json/lh-ttx/v1/webhooks/event  
Auth: Authorization: Bearer <webhook_secret> (or X-Tripletex-Token / ?token=)

Handles:
- product.create / product.update → pulls price into matching Woo product
- product.delete → logged

Quick test:

```
curl -X POST \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_SECRET" \
  -d '{"subscriptionId":1,"event":"product.update","id":123,"value":{}}' \
  https://your-site.tld/wp-json/lh-ttx/v1/webhooks/event
```

## Troubleshooting

- Logs: 
  - WooCommerce / Status / Logs --> source “lavendelhygiene-tripletex”.
- Session/token issues: 
  - re-enter tokens on the Tripletex settings page and “Clear session token”, then “Test connection”.
- Orders not created: 
  - Ensure the customer is approved and has a Tripletex link.