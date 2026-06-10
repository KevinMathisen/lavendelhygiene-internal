<?php
/**
 * LavendelHygiene Tripletex: Services & Mappers
 *
 */

if (!defined('ABSPATH')) exit;

/** Meta keys (define here if not defined in your bootstrap) */
if (!defined('LH_TTX_META_TRIPLETEX_ID'))     define('LH_TTX_META_TRIPLETEX_ID', 'tripletex_customer_id');
if (!defined('LH_TTX_META_TTX_ORDER_ID'))     define('LH_TTX_META_TTX_ORDER_ID', '_tripletex_order_id');
if (!defined('LH_TTX_META_TTX_STATUS'))       define('LH_TTX_META_TTX_STATUS',   '_tripletex_status');
if (!defined('LH_TTX_META_TTX_LAST_SYNC_AT')) define('LH_TTX_META_TTX_LAST_SYNC_AT', '_tripletex_last_sync_at');


// Load service files
require_once __DIR__ . '/services/customers.php';
require_once __DIR__ . '/services/orders.php';
require_once __DIR__ . '/services/products.php';
require_once __DIR__ . '/services/discounts.php';