<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$sellerOrderDetailDebugLogger = static function (string $event, array $context = []): void {
    $paths = [
        dirname(__DIR__) . '/private_html/seller_order_detail_debug.log',
        dirname(__DIR__) . '/seller_order_detail_debug.log',
        __DIR__ . '/../seller_order_detail_debug.log',
    ];

    $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $event;
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }
    $line .= "\n";

    foreach ($paths as $logPath) {
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            continue;
        }
        if (is_file($logPath) || is_writable($dir)) {
            @file_put_contents($logPath, $line, FILE_APPEND);
            return;
        }
    }
};

if (!function_exists('seller_order_detail_debug')) {
    function seller_order_detail_debug(string $event, array $context = []): void
    {
        $logger = $GLOBALS['sellerOrderDetailDebugLogger'] ?? null;
        if (is_callable($logger)) {
            $logger($event, $context);
        }
    }
}
$GLOBALS['sellerOrderDetailDebugLogger'] = $sellerOrderDetailDebugLogger;

$guardCandidates = [
    __DIR__ . '/_guard.php',
    dirname(__DIR__) . '/member/_guard.php',
];
foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$requiredHelper = __DIR__ . '/includes/order_request_actions.php';
if (!is_file($requiredHelper)) {
    seller_order_detail_debug('order_detail_blocked_internal_error', [
        'checkpoint' => 'required_helper_missing',
        'helper' => $requiredHelper,
    ]);
    http_response_code(500);
    echo 'Order helper unavailable.';
    exit;
}
require_once $requiredHelper;

$optionalHelpers = [
    __DIR__ . '/includes/cancel_refund_summary.php',
    dirname(__DIR__) . '/includes/order_cancel.php',
    dirname(__DIR__) . '/includes/order_refund.php',
    dirname(__DIR__) . '/order_cancel.php',
    dirname(__DIR__) . '/order_refund.php',
];
foreach ($optionalHelpers as $optionalFile) {
    if (is_file($optionalFile)) {
        require_once $optionalFile;
    }
}
if (!function_exists('seller_order_request_current_user_id')) {
    seller_order_detail_debug('order_detail_forbidden_exit', [
        'checkpoint' => 'seller_auth_unavailable',
        'reason' => 'seller_order_request_current_user_id_missing',
    ]);
    http_response_code(403);
    echo 'Seller authentication unavailable.';
    exit;
}

$resolvePdo = static function (): ?PDO {
    $candidateKeys = ['pdo', 'db', 'conn', 'database'];
    foreach ($candidateKeys as $key) {
        if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
            return $GLOBALS[$key];
        }
    }
    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof PDO) {
        return $GLOBALS['mysqli'];
    }
    return null;
};

$pdo = $resolvePdo();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    echo 'Database connection unavailable.';
    exit;
}

$tableExists = static function (PDO $pdo, string $tableName): bool {
    static $cache = [];
    $key = strtolower($tableName);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
        $stmt->execute([':table_name' => $tableName]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    } catch (Throwable $e) {
        $cache[$key] = false;
        return false;
    }
};

$columnExists = static function (PDO $pdo, string $tableName, string $columnName) use ($tableExists): bool {
    static $cache = [];
    $cacheKey = strtolower($tableName . '.' . $columnName);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if (!$tableExists($pdo, $tableName)) {
        $cache[$cacheKey] = false;
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1');
        $stmt->execute([
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        $cache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
        return false;
    }
};

$orderId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;

$currentSellerId = 0;
if (function_exists('seller_order_request_current_seller_id')) {
    $currentSellerId = (int)seller_order_request_current_seller_id();
}

$sellerUserId = (int)seller_order_request_current_user_id();
seller_order_detail_debug('seller_order_request_current_user_id returned', [
    'sellerUserId' => $sellerUserId,
]);

$sessionIdentities = [
    'user.id' => $_SESSION['user']['id'] ?? null,
    'auth_user.id' => $_SESSION['auth_user']['id'] ?? null,
    'member.id' => $_SESSION['member']['id'] ?? null,
    'seller.id' => $_SESSION['seller']['id'] ?? null,
    'user_id' => $_SESSION['user_id'] ?? null,
    'member_id' => $_SESSION['member_id'] ?? null,
];
seller_order_detail_debug('session identities discovered', [
    'session_ids' => $sessionIdentities,
    'resolvedSellerUserId' => $sellerUserId,
    'resolvedCurrentSellerId' => $currentSellerId,
    'orderId' => $orderId,
]);

if ($orderId <= 0 || $sellerUserId <= 0) {
    seller_order_detail_debug('order_detail_not_found_exit', [
        'checkpoint' => 'invalid_order_or_identity',
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

if (!isset($_SESSION['seller_order_request_csrf_token']) || !is_string($_SESSION['seller_order_request_csrf_token']) || $_SESSION['seller_order_request_csrf_token'] === '') {
    try {
        $_SESSION['seller_order_request_csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['seller_order_request_csrf_token'] = sha1((string)microtime(true) . '-' . (string)mt_rand());
    }
}
$csrfToken = (string)$_SESSION['seller_order_request_csrf_token'];

$orderContext = null;
$bundle = [
    'order' => null,
    'cancel' => null,
    'refund' => null,
    'primary_type' => '',
    'seller_can_approve_cancel' => false,
    'seller_can_reject_cancel' => false,
    'seller_can_approve_refund' => false,
    'seller_can_reject_refund' => false,
];

try {
    if (function_exists('seller_order_request_get_order_context')) {
        $orderContext = seller_order_request_get_order_context($orderId, $sellerUserId);
        seller_order_detail_debug('order_context_lookup', [
            'sellerUserId' => $sellerUserId,
            'result_empty' => !is_array($orderContext) || $orderContext === [],
        ]);
    }
    if (function_exists('seller_order_request_get_request_bundle')) {
        $loaded = seller_order_request_get_request_bundle($orderId, $sellerUserId);
        seller_order_detail_debug('request_bundle_lookup', [
            'sellerUserId' => $sellerUserId,
            'result_empty' => !is_array($loaded) || $loaded === [],
        ]);
        if (is_array($loaded)) {
            $bundle = array_merge($bundle, $loaded);
        }
    } else {
        if (function_exists('seller_order_request_get_cancel_by_order_id')) {
            $bundle['cancel'] = seller_order_request_get_cancel_by_order_id($orderId, $sellerUserId);
        }
        if (function_exists('seller_order_request_get_refund_by_order_id')) {
            $bundle['refund'] = seller_order_request_get_refund_by_order_id($orderId, $sellerUserId);
        }
     }
} catch (Throwable $e) {
    seller_order_detail_debug('order_detail_exception', [
        'message' => $e->getMessage(),
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
    ]);
    // keep safe defaults
}

if (!$orderContext && is_array($bundle['order'] ?? null)) {
    $orderContext = $bundle['order'];
}

if (!$orderContext || !is_array($orderContext)) {
   seller_order_detail_debug('order_detail_forbidden_exit', [
        'checkpoint' => 'order_context_empty_after_helper_calls',
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
    ]);
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$cancelRow = is_array($bundle['cancel'] ?? null) ? $bundle['cancel'] : null;
$refundRow = is_array($bundle['refund'] ?? null) ? $bundle['refund'] : null;

if (($bundle['primary_type'] ?? '') === '' && function_exists('seller_order_request_detect_primary_type')) {
    $bundle['primary_type'] = seller_order_request_detect_primary_type($cancelRow, $refundRow);
}
$primaryType = (string)($bundle['primary_type'] ?? '');

$canApproveCancel = !empty($bundle['seller_can_approve_cancel']);
$canRejectCancel = !empty($bundle['seller_can_reject_cancel']);
$canApproveRefund = !empty($bundle['seller_can_approve_refund']);
$canRejectRefund = !empty($bundle['seller_can_reject_refund']);

$flashSuccess = isset($_SESSION['seller_order_request_success']) ? trim((string)$_SESSION['seller_order_request_success']) : '';
$flashError = isset($_SESSION['seller_order_request_error']) ? trim((string)$_SESSION['seller_order_request_error']) : '';
unset($_SESSION['seller_order_request_success'], $_SESSION['seller_order_request_error']);

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

$money = static function ($amount, ?string $currency = null): string {
    if (function_exists('seller_order_request_money')) {
        return seller_order_request_money($amount, $currency);
    }
    if (!is_numeric($amount)) {
        return '—';
    }
    $currency = strtoupper(trim((string)$currency));
    if ($currency === '') {
        $currency = 'USD';
    }
    return number_format((float)$amount, 2) . ' ' . $currency;
};

$statusBadge = static function (string $type, string $status): array {
    if (function_exists('seller_order_request_status_badge')) {
        return seller_order_request_status_badge($type, $status);
    }
    $label = $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Unknown';
    return ['label' => $label, 'class' => 'badge-default'];
};

$paymentBadge = static function (string $status): array {
    $key = strtolower(trim($status));
    $map = [
        'paid' => ['Paid', 'offer-thread-badge-completed'],
        'completed' => ['Completed', 'offer-thread-badge-completed'],
        'authorized' => ['Authorized', 'offer-thread-badge-ready'],
        'pending' => ['Pending', 'offer-thread-badge-open'],
        'unpaid' => ['Unpaid', 'offer-thread-badge-needs-reply'],
        'failed' => ['Failed', 'offer-thread-badge-needs-reply'],
        'refunded' => ['Refunded', 'offer-thread-badge-ready'],
        'partially_refunded' => ['Partially Refunded', 'offer-thread-badge-open'],
    ];
    if (isset($map[$key])) {
        return ['label' => $map[$key][0], 'class' => $map[$key][1]];
    }
    return ['label' => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown', 'class' => 'badge-default'];
};

$deriveShippingStatus = static function (array $ctx): string {
    $candidates = [
        'shipping_status',
        'delivery_status',
        'tracking_status',
        'fulfillment_status',
        'order_shipping_status',
    ];
    foreach ($candidates as $key) {
        if (isset($ctx[$key]) && trim((string)$ctx[$key]) !== '') {
            $rawStatus = strtolower(trim((string)$ctx[$key]));
            if ($rawStatus === 'pending') {
                $orderStatus = strtolower(trim((string)($ctx['order_status'] ?? '')));
                if (in_array($orderStatus, ['paid', 'confirmed'], true)) {
                    return 'to_ship';
                }
            }
            return $rawStatus;
        }
    }

    $orderStatus = strtolower(trim((string)($ctx['order_status'] ?? '')));
    $derivedMap = [
        'paid' => 'to_ship',
        'confirmed' => 'to_ship',
        'ready_to_ship' => 'to_ship',
        'packed' => 'processing',
        'processing' => 'processing',
        'shipped' => 'shipped',
        'in_transit' => 'shipped',
        'out_for_delivery' => 'shipped',
        'delivered' => 'delivered',
        'completed' => 'delivered',
        'cancelled' => 'cancelled',
        'refunded' => 'cancelled',
    ];
    if (isset($derivedMap[$orderStatus])) {
        return $derivedMap[$orderStatus];
    }
    return 'pending';
};

$shippingBadge = static function (string $status): array {
    $key = strtolower(trim($status));
$map = [
        'to_ship' => ['To Ship', 'offer-thread-badge-ready'],
        'pending' => ['Pending', 'offer-thread-badge-open'],
        'processing' => ['Processing', 'offer-thread-badge-ready'],
        'ready_to_ship' => ['Ready to Ship', 'offer-thread-badge-ready'],
        'shipped' => ['Shipped', 'offer-thread-badge-completed'],
        'completed' => ['Completed', 'offer-thread-badge-completed'],
        'in_transit' => ['In Transit', 'offer-thread-badge-completed'],
        'out_for_delivery' => ['Out for Delivery', 'offer-thread-badge-completed'],
        'delivered' => ['Delivered', 'offer-thread-badge-completed'],
        'cancelled' => ['Closed', 'offer-thread-badge-needs-reply'],
        'refunded' => ['Closed', 'offer-thread-badge-needs-reply'],
        'closed' => ['Closed', 'offer-thread-badge-needs-reply'],
        'unknown' => ['Unknown', 'badge-default'],
        'returned' => ['Returned', 'offer-thread-badge-needs-reply'],
    ];
    if (isset($map[$key])) {
        return ['label' => $map[$key][0], 'class' => $map[$key][1]];
    }
    return ['label' => $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown', 'class' => 'badge-default'];
};

$pickDate = static function (?array $row): string {
    if (!$row) {
        return '';
    }
    foreach (['requested_at', 'created_at', 'updated_at'] as $k) {
        if (!empty($row[$k])) {
            return (string)$row[$k];
        }
    }
    return '';
};

$pickValue = static function (?array $row, array $keys): string {
    if (!$row) {
        return '';
    }
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
};

$currency = (string)($orderContext['currency'] ?? 'USD');
$orderCode = trim((string)($orderContext['order_code'] ?? ''));
$paymentStatus = trim((string)($orderContext['payment_status'] ?? ''));
$buyerName = trim((string)($orderContext['buyer_name'] ?? ''));
$listingTitle = trim((string)($orderContext['listing_title'] ?? ''));
$orderStatus = strtolower(trim((string)($orderContext['order_status'] ?? '')));
$sellerOwnershipKeys = ['seller_user_id', 'seller_id', 'owner_user_id', 'user_id'];
$getItemOwnerId = static function (array $itemRow) use ($sellerOwnershipKeys): ?int {
    foreach ($sellerOwnershipKeys as $sellerKey) {
        if (!array_key_exists($sellerKey, $itemRow)) {
            continue;
        }
        $rawValue = $itemRow[$sellerKey];
        if (is_int($rawValue)) {
            return $rawValue;
        }
        if (is_string($rawValue)) {
            $trimmed = trim($rawValue);
            if ($trimmed === '' || !preg_match('/^\d+$/', $trimmed)) {
                continue;
            }
            return (int)$trimmed;
        }
        if (is_float($rawValue) && floor($rawValue) === $rawValue) {
            return (int)$rawValue;
        }
    }
    return null;
};

$ordersExists = $tableExists($pdo, 'orders');
$orderItemsExists = $tableExists($pdo, 'order_items');
$listingsExists = $tableExists($pdo, 'listings');
if (!$ordersExists || !$orderItemsExists) {
    seller_order_detail_debug('order_detail_not_found_exit', [
        'checkpoint' => 'orders_or_order_items_table_missing',
        'ordersExists' => $ordersExists,
        'orderItemsExists' => $orderItemsExists,
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

try {
    $orderExistsStmt = $pdo->prepare('SELECT id FROM orders WHERE id = :order_id LIMIT 1');
 $orderExistsStmt->execute([':order_id' => $orderId]);
    $orderExists = (bool)$orderExistsStmt->fetchColumn();
    if (!$orderExists) {
        seller_order_detail_debug('order_detail_not_found_exit', [
            'checkpoint' => 'order_id_not_found',
            'orderId' => $orderId,
        ]);
        http_response_code(404);
        echo 'Order not found.';
        exit;
    }
} catch (Throwable $e) {
    seller_order_detail_debug('order_detail_not_found_exit', [
        'checkpoint' => 'order_exists_query_failed',
        'orderId' => $orderId,
        'error' => $e->getMessage(),
    ]);
    http_response_code(404);
    echo 'Order not found.';
    exit;
}


} catch (Throwable $e) {
    seller_order_detail_debug('order_detail_forbidden_exit', [
        'checkpoint' => 'ownership_sql_exception',
        'reason' => 'ownership_query_failed',
        'orderId' => $orderId,
        'sellerUserId' => $sellerUserId,
        'currentSellerId' => $currentSellerId,
        'error' => $e->getMessage(),
    ]);
    http_response_code(403);
    echo 'Forbidden';
    exit;
}


$optionalSelectMap = [
    'fulfillment_status' => $columnExists($pdo, 'order_items', 'fulfillment_status') ? 'oi.fulfillment_status AS fulfillment_status' : "'pending' AS fulfillment_status",
    'tracking_number' => $columnExists($pdo, 'order_items', 'tracking_number') ? 'oi.tracking_number AS tracking_number' : "'' AS tracking_number",
    'carrier' => $columnExists($pdo, 'order_items', 'carrier') ? 'oi.carrier AS carrier' : "'' AS carrier",
    'processed_at' => $columnExists($pdo, 'order_items', 'processed_at') ? 'oi.processed_at AS processed_at' : 'NULL AS processed_at',
    'shipped_at' => $columnExists($pdo, 'order_items', 'shipped_at') ? 'oi.shipped_at AS shipped_at' : 'NULL AS shipped_at',
    'completed_at' => $columnExists($pdo, 'order_items', 'completed_at') ? 'oi.completed_at AS completed_at' : 'NULL AS completed_at',
    'strain_snapshot' => $columnExists($pdo, 'order_items', 'strain_snapshot') ? 'oi.strain_snapshot AS strain_snapshot' : "'' AS strain_snapshot",
    'species_snapshot' => $columnExists($pdo, 'order_items', 'species_snapshot') ? 'oi.species_snapshot AS species_snapshot' : "'' AS species_snapshot",
    'cover_image_snapshot' => $columnExists($pdo, 'order_items', 'cover_image_snapshot') ? 'oi.cover_image_snapshot AS cover_image_snapshot' : "'' AS cover_image_snapshot",
];

$itemSelect = [
    'oi.id AS item_id',
    $columnExists($pdo, 'order_items', 'listing_id') ? 'oi.listing_id AS listing_id' : 'NULL AS listing_id',
    $columnExists($pdo, 'order_items', 'title') ? 'oi.title AS item_title' : ($columnExists($pdo, 'order_items', 'name') ? 'oi.name AS item_title' : "'' AS item_title"),
    $columnExists($pdo, 'order_items', 'qty') ? 'oi.qty AS qty' : ($columnExists($pdo, 'order_items', 'quantity') ? 'oi.quantity AS qty' : 'NULL AS qty'),
    $columnExists($pdo, 'order_items', 'quantity') ? 'oi.quantity AS quantity' : ($columnExists($pdo, 'order_items', 'qty') ? 'oi.qty AS quantity' : 'NULL AS quantity'),
    $columnExists($pdo, 'order_items', 'unit_price') ? 'oi.unit_price AS unit_price' : ($columnExists($pdo, 'order_items', 'price') ? 'oi.price AS unit_price' : 'NULL AS unit_price'),
    $columnExists($pdo, 'order_items', 'line_total') ? 'oi.line_total AS line_total' : ($columnExists($pdo, 'order_items', 'subtotal') ? 'oi.subtotal AS line_total' : 'NULL AS line_total'),
    $columnExists($pdo, 'order_items', 'currency') ? 'oi.currency AS item_currency' : ':order_currency AS item_currency',
];
foreach ($sellerOwnershipKeys as $ownerKey) {
    if ($columnExists($pdo, 'order_items', $ownerKey)) {
        $itemSelect[] = 'oi.' . $ownerKey . ' AS ' . $ownerKey;
    } else {
        $itemSelect[] = 'NULL AS ' . $ownerKey;
    }
}
foreach ($optionalSelectMap as $selectPart) {
    $itemSelect[] = $selectPart;
}
if ($listingsExists && $columnExists($pdo, 'listings', 'seller_id')) {
    $itemSelect[] = 'l.seller_id AS listing_seller_id';
} else {
    $itemSelect[] = 'NULL AS listing_seller_id';
}
if ($listingsExists && $columnExists($pdo, 'listings', 'title')) {
    $itemSelect[] = 'l.title AS listing_title_fallback';
} else {
    $itemSelect[] = "'' AS listing_title_fallback";
}

$sellerVisibleItems = [];
$allOrderRows = [];
try {
    $itemsSql = 'SELECT ' . implode(', ', $itemSelect) . ' FROM order_items oi';
    if ($listingsExists) {
        $itemsSql .= ' LEFT JOIN listings l ON l.id = oi.listing_id';
    }
    $itemsSql .= ' WHERE oi.order_id = :order_id ORDER BY oi.id ASC';
    $itemsStmt = $pdo->prepare($itemsSql);
    $itemsStmt->execute([
        ':order_id' => $orderId,
        ':order_currency' => $currency,
    ]);
    $allOrderRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $allOrderRows = [];
}

$sellerVisibleItems = [];
$observedOwnerIds = [];
$rowsWithNumericOwner = 0;
$totalItemRows = 0;
foreach ($allOrderRows as $itemRow) {
    if (!is_array($itemRow)) {
        continue;
    }
    $totalItemRows++;
    $ownerId = $getItemOwnerId($itemRow);
    if (($ownerId === null || $ownerId <= 0) && isset($itemRow['listing_seller_id']) && is_numeric($itemRow['listing_seller_id'])) {
        $ownerId = (int)$itemRow['listing_seller_id'];
    }
    if ($ownerId !== null && $ownerId > 0) {
        $observedOwnerIds[$ownerId] = true;
        $rowsWithNumericOwner++;
    }
    if ($ownerId !== null && $ownerId !== $sellerUserId) {
        continue;
    }
    if ($ownerId === null && isset($itemRow['listing_seller_id']) && is_numeric($itemRow['listing_seller_id']) && (int)$itemRow['listing_seller_id'] !== $sellerUserId) {
        continue;
    }
    if ($ownerId === null && (!isset($itemRow['listing_seller_id']) || !is_numeric($itemRow['listing_seller_id']))) {
        continue;
    }
    if ((!isset($itemRow['item_title']) || trim((string)$itemRow['item_title']) === '') && isset($itemRow['listing_title_fallback'])) {
        $itemRow['item_title'] = (string)$itemRow['listing_title_fallback'];
    }
    $sellerVisibleItems[] = $itemRow;
}
$distinctOwnerIds = array_keys($observedOwnerIds);
$isMultiSellerOrder = count($distinctOwnerIds) > 1;
$isClearlySingleSellerOrder = !$isMultiSellerOrder
    && count($distinctOwnerIds) === 1
    && (int)$distinctOwnerIds[0] === $sellerUserId
    && $totalItemRows > 0
    && $rowsWithNumericOwner === $totalItemRows;

$lineTotalForItem = static function (array $itemRow): ?float {
    foreach (['line_total', 'subtotal', 'item_total', 'total'] as $lineKey) {
        if (isset($itemRow[$lineKey]) && is_numeric($itemRow[$lineKey])) {
            return (float)$itemRow[$lineKey];
        }
    }

    $unitPrice = null;
    if (isset($itemRow['unit_price']) && is_numeric($itemRow['unit_price'])) {
        $unitPrice = (float)$itemRow['unit_price'];
    } elseif (isset($itemRow['price']) && is_numeric($itemRow['price'])) {
        $unitPrice = (float)$itemRow['price'];
    }

    $qty = null;
    if (isset($itemRow['quantity']) && is_numeric($itemRow['quantity'])) {
        $qty = (float)$itemRow['quantity'];
    } elseif (isset($itemRow['qty']) && is_numeric($itemRow['qty'])) {
        $qty = (float)$itemRow['qty'];
    }

    if ($unitPrice !== null && $qty !== null) {
        return $unitPrice * $qty;
    }
    return null;
};

$sellerItemsSubtotal = null;
if (!empty($sellerVisibleItems)) {
    $subtotal = 0.0;
    $hasRows = false;
    foreach ($sellerVisibleItems as $itemRow) {
        $ownerId = $getItemOwnerId($itemRow);
        if ($ownerId !== null && $ownerId !== $sellerUserId) {
            continue;
        }
        $lineTotal = $lineTotalForItem($itemRow);
        if ($lineTotal !== null) {
            $subtotal += $lineTotal;
            $hasRows = true;
        }
    }
    if ($hasRows) {
        $sellerItemsSubtotal = $subtotal;
    }
}

$fullOrderTotal = null;
foreach (['order_total', 'grand_total', 'total_amount', 'total', 'amount_total'] as $totalKey) {
    if (isset($orderContext[$totalKey]) && is_numeric($orderContext[$totalKey])) {
        $fullOrderTotal = (float)$orderContext[$totalKey];
        break;
    }
}

$sellerVisibleCount = count($sellerVisibleItems);
$sellerVisibleListingTitle = '';

if ($sellerVisibleCount === 1) {
    foreach (['item_title', 'title', 'name', 'item_name', 'listing_title', 'product_name'] as $titleKey) {
        if (isset($sellerVisibleItems[0][$titleKey]) && trim((string)$sellerVisibleItems[0][$titleKey]) !== '') {
            $sellerVisibleListingTitle = trim((string)$sellerVisibleItems[0][$titleKey]);
            break;
        }
    }
}
if ($sellerVisibleCount > 1) {
    $firstTitle = '';
    foreach ($sellerVisibleItems as $itemRow) {
        if (!is_array($itemRow)) {
            continue;
        }
        foreach (['item_title', 'title', 'name', 'item_name', 'listing_title', 'product_name'] as $titleKey) {
            if (isset($itemRow[$titleKey]) && trim((string)$itemRow[$titleKey]) !== '') {
                $firstTitle = trim((string)$itemRow[$titleKey]);
                break 2;
            }
        }
    }
    if ($firstTitle !== '') {
        $sellerVisibleListingTitle = $firstTitle . ' +' . (string)($sellerVisibleCount - 1) . ' more';
    } else {
        $sellerVisibleListingTitle = (string)$sellerVisibleCount . ' seller-visible items';
    }
}
if ($sellerVisibleListingTitle === '') {
    $sellerVisibleListingTitle = $listingTitle !== '' ? $listingTitle : 'Listing unavailable';
}

$shippingStatus = $deriveShippingStatus($orderContext);
if ($shippingStatus === 'pending') {
    $deriveFromOrder = [
        'confirmed' => 'to_ship',
        'paid' => 'to_ship',
        'processing' => 'to_ship',
        'shipped' => 'shipped',
        'completed' => 'completed',
        'cancelled' => 'closed',
        'refunded' => 'closed',
    ];
    $shippingStatus = $deriveFromOrder[$orderStatus] ?? 'unknown';
}
$paymentBadgeUi = $paymentBadge($paymentStatus);
$shippingBadgeUi = $shippingBadge($shippingStatus);
$orderBadgeUi = $statusBadge('order', $orderStatus);
$requestActionEndpoint = '/seller/order_request_action.php';
$requestActionEndpointExists = false;
$requestActionCandidates = [
    __DIR__ . '/order_request_action.php',
    __DIR__ . '/includes/order_request_action.php',
    dirname(__DIR__) . '/seller/order_request_action.php',
    dirname(__DIR__) . '/order_request_action.php',
];
foreach ($requestActionCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $requestActionEndpointExists = true;
        break;
    }
}

$fulfillmentActionEndpoint = '/seller/order_action.php';
$fulfillmentActionEndpointExists = false;
$fulfillmentActionCandidates = [
    __DIR__ . '/order_action.php',
    __DIR__ . '/includes/order_action.php',
    dirname(__DIR__) . '/seller/order_action.php',
    dirname(__DIR__) . '/order_action.php',
];
foreach ($fulfillmentActionCandidates as $candidatePath) {
    if (is_file($candidatePath)) {
        $fulfillmentActionEndpointExists = true;
        break;
    }
}

$returnUrl = '/seller/apply.php';
if (function_exists('seller_order_request_best_return_url')) {
    try {
        $candidate = (string)seller_order_request_best_return_url($orderId);
        if ($candidate !== '') {
            $returnUrl = $candidate;
        }
    } catch (Throwable $e) {
        $returnUrl = '/seller/apply.php';
    }
}

if ($returnUrl === '' || preg_match('~^https?://~i', $returnUrl)) {
    $returnUrl = '/seller/apply.php';
}
if ($returnUrl[0] !== '/') {
    $returnUrl = '/' . ltrim($returnUrl, '/');
}

$hasAnyRequest = $cancelRow !== null || $refundRow !== null;
$currentRow = $primaryType === 'cancel' ? $cancelRow : ($primaryType === 'refund' ? $refundRow : ($refundRow ?: $cancelRow));
$currentType = $primaryType !== '' ? $primaryType : ($refundRow ? 'refund' : ($cancelRow ? 'cancel' : 'none'));
$currentStatus = strtolower(trim((string)($currentRow['status'] ?? '')));
$currentDate = $pickDate($currentRow);
$currentReason = $pickValue($currentRow, ['cancel_reason_text', 'reason_text', 'reason', 'note', 'admin_note']);
$currentAmount = $pickValue($currentRow, ['approved_refund_amount', 'requested_refund_amount', 'actual_refunded_amount', 'refundable_amount', 'amount']);
$currentRefundMode = $pickValue($currentRow, ['refund_mode']);
$currentRefundRef = $pickValue($currentRow, ['payment_reference_snapshot', 'payment_reference', 'refund_reference', 'reference']);

$cancelBadge = $statusBadge('cancel', strtolower(trim((string)($cancelRow['status'] ?? ''))));
$refundBadge = $statusBadge('refund', strtolower(trim((string)($refundRow['status'] ?? ''))));

$paymentStatusKey = strtolower(trim((string)$paymentStatus));
$shippingStatusKey = strtolower(trim((string)$shippingStatus));
$lineFulfillmentActions = [];
foreach ($sellerVisibleItems as $idx => $sellerItemRow) {
    if (!is_array($sellerItemRow)) {
        continue;
    }
    $lineStatus = strtolower(trim((string)($sellerItemRow['fulfillment_status'] ?? 'pending')));
    if ($lineStatus === '') {
        $lineStatus = 'pending';
    }
    $actions = [];
    if ($lineStatus === 'pending' && in_array($paymentStatusKey, ['paid', 'authorized'], true) && in_array($orderStatus, ['paid', 'confirmed'], true)) {
        $actions[] = ['label' => 'Mark Processing', 'value' => 'mark_processing'];
    }
    if (in_array($lineStatus, ['processing', 'to_ship', 'pending'], true) && ($orderStatus === 'processing' || $shippingStatusKey === 'to_ship' || $shippingStatusKey === 'processing')) {
        $actions[] = ['label' => 'Mark Shipped', 'value' => 'mark_shipped'];
    }
    if ($lineStatus === 'shipped' || ($orderStatus === 'shipped' && $lineStatus !== 'completed')) {
        $actions[] = ['label' => 'Mark Completed', 'value' => 'mark_completed'];
    }
    $lineFulfillmentActions[$idx] = $actions;
}
$allowFulfillmentSubmit = !$isMultiSellerOrder && $isClearlySingleSellerOrder;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seller Order Detail</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b1020;
            --panel: #121a31;
            --panel-soft: #16203b;
            --text: #e7ecff;
            --muted: #95a1c7;
            --line: #2a3558;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: radial-gradient(1200px 700px at 20% -10%, #1a2550 0%, var(--bg) 60%);
            color: var(--text);
        }
        .wrap {
            max-width: 1100px;
            margin: 28px auto;
            padding: 0 16px 28px;
        }
        .top-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .btn-link {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            border: 1px solid var(--line);
            color: var(--text);
            background: var(--panel);
            font-weight: 600;
        }
        .card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 16px;
        }
        h1, h2, h3 {
            margin: 0 0 10px;
            line-height: 1.25;
        }
        h1 { font-size: 26px; }
        h2 { font-size: 19px; }
        h3 { font-size: 16px; color: #d4dcff; }
        .meta-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
        .meta-item {
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
        }
        .meta-item .k {
            display: block;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .meta-item .v {
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }
        .flash {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        .flash.success {
            background: rgba(46, 204, 113, 0.15);
            border: 1px solid rgba(46, 204, 113, 0.5);
            color: #baffd4;
        }
        .flash.error {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid rgba(231, 76, 60, 0.5);
            color: #ffd0cc;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid transparent;
            vertical-align: middle;
        }
        .offer-thread-badge-open { background: rgba(243, 156, 18, 0.2); border-color: rgba(243, 156, 18, 0.5); color: #ffd89a; }
        .offer-thread-badge-ready { background: rgba(127, 140, 255, 0.18); border-color: rgba(127, 140, 255, 0.5); color: #d8ddff; }
        .offer-thread-badge-completed { background: rgba(46, 204, 113, 0.18); border-color: rgba(46, 204, 113, 0.45); color: #c9ffdf; }
        .offer-thread-badge-needs-reply { background: rgba(231, 76, 60, 0.18); border-color: rgba(231, 76, 60, 0.45); color: #ffd2cd; }
        .badge-default { background: rgba(149, 161, 199, 0.18); border-color: rgba(149, 161, 199, 0.45); color: #dce4ff; }
        .stack { display: grid; gap: 10px; }
        .muted { color: var(--muted); }
        .empty {
            border: 1px dashed var(--line);
            border-radius: 12px;
            padding: 16px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.02);
        }
        .actions {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
        }
        textarea {
            width: 100%;
            min-height: 110px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #0e152c;
            color: var(--text);
            padding: 12px;
            font-size: 14px;
            resize: vertical;
        }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        button {
            appearance: none;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 11px 14px;
            font-weight: 700;
            color: var(--text);
            cursor: pointer;
            background: var(--panel-soft);
        }
        .btn-approve { border-color: rgba(46, 204, 113, 0.5); background: rgba(46, 204, 113, 0.16); }
        .btn-reject { border-color: rgba(231, 76, 60, 0.5); background: rgba(231, 76, 60, 0.16); }
        .split {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
        table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid var(--line);
            font-size: 14px;
        }
        th {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 12px;
            background: var(--panel-soft);
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top-nav">
        <a class="btn-link" href="<?= $h($returnUrl) ?>">← Back</a>
        <a class="btn-link" href="/seller/apply.php">Seller Dashboard</a>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="flash success"><?= $h($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="flash error"><?= $h($flashError) ?></div>
    <?php endif; ?>

    <section class="card">
         <h1>Seller Order Detail</h1>
        <div class="meta-grid">
            <div class="meta-item"><span class="k">Order Status</span><span class="v"><span class="badge <?= $h((string)($orderBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($orderBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Payment Status</span><span class="v"><span class="badge <?= $h((string)($paymentBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($paymentBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Shipping Status</span><span class="v"><span class="badge <?= $h((string)($shippingBadgeUi['class'] ?? 'badge-default')) ?>"><?= $h((string)($shippingBadgeUi['label'] ?? 'Unknown')) ?></span></span></div>
            <div class="meta-item"><span class="k">Buyer</span><span class="v"><?= $h($buyerName !== '' ? $buyerName : 'Unknown Buyer') ?></span></div> 
            <div class="meta-item"><span class="k">Listing</span><span class="v"><?= $h($sellerVisibleListingTitle) ?></span></div>
            <div class="meta-item"><span class="k">Your Items Subtotal</span><span class="v"><?= $h($sellerItemsSubtotal !== null ? $money($sellerItemsSubtotal, $currency) : '—') ?></span></div>
            <div class="meta-item"><span class="k">Full Order Total Snapshot</span><span class="v"><?= $h($fullOrderTotal !== null ? $money($fullOrderTotal, $currency) : '—') ?></span></div>
        </div>
    </section>

    <section class="card stack">
        <h2>Your Items</h2>
        <?php if (empty($sellerVisibleItems)): ?>
            <div class="empty">No seller-owned line items are visible for this order.</div>
        <?php else: ?>
             <table>
                <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Listing ID</th>
                    <th>Item</th>
                    <th>Snapshot</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Line Total</th>
                    <th>Status</th>
                    <th>Tracking</th>
                    <th>Carrier</th>
                    <th>Dates</th>
                    <th>Line Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sellerVisibleItems as $itemIndex => $itemRow): ?>
                    <?php
                    $itemTitle = '';
                    foreach (['item_title', 'title', 'name', 'item_name', 'listing_title', 'product_name'] as $titleKey) {
                        if (isset($itemRow[$titleKey]) && trim((string)$itemRow[$titleKey]) !== '') {
                            $itemTitle = trim((string)$itemRow[$titleKey]);
                            break;
                        }
                    }
                    $itemQty = null;
                    foreach (['quantity', 'qty'] as $qtyKey) {
                        if (isset($itemRow[$qtyKey]) && is_numeric($itemRow[$qtyKey])) {
                            $itemQty = (float)$itemRow[$qtyKey];
                            break;
                        }
                    }
                     $itemUnitPrice = null;
                    foreach (['unit_price', 'price'] as $priceKey) {
                        if (isset($itemRow[$priceKey]) && is_numeric($itemRow[$priceKey])) {
                            $itemUnitPrice = (float)$itemRow[$priceKey];
                            break;
                        }
                    }
                    $itemCurrency = isset($itemRow['item_currency']) && trim((string)$itemRow['item_currency']) !== '' ? (string)$itemRow['item_currency'] : $currency;
                    $itemLineTotal = $lineTotalForItem($itemRow);
                    $lineStatusRaw = strtolower(trim((string)($itemRow['fulfillment_status'] ?? 'pending')));
                    $lineStatusBadge = $shippingBadge($lineStatusRaw !== '' ? $lineStatusRaw : 'unknown');
                    $snapParts = [];
                    if (!empty($itemRow['strain_snapshot'])) {
                        $snapParts[] = 'Strain: ' . trim((string)$itemRow['strain_snapshot']);
                    }
                    if (!empty($itemRow['species_snapshot'])) {
                        $snapParts[] = 'Species: ' . trim((string)$itemRow['species_snapshot']);
                    }
                    if (!empty($itemRow['cover_image_snapshot'])) {
                        $snapParts[] = 'Image snap available';
                    }
                    $dateParts = [];
                    foreach (['processed_at' => 'Processed', 'shipped_at' => 'Shipped', 'completed_at' => 'Completed'] as $dKey => $dLabel) {
                        if (!empty($itemRow[$dKey])) {
                            $dateParts[] = $dLabel . ': ' . (string)$itemRow[$dKey];
                        }
                    }
                    $lineItemId = isset($itemRow['item_id']) && is_numeric($itemRow['item_id']) ? (int)$itemRow['item_id'] : 0;
                    $lineActions = $lineFulfillmentActions[$itemIndex] ?? [];
                    ?>
                    <tr>
                        <td><?= $h(isset($itemRow['item_id']) ? (string)$itemRow['item_id'] : '—') ?></td>
                        <td><?= $h(isset($itemRow['listing_id']) ? (string)$itemRow['listing_id'] : '—') ?></td>
                        <td><?= $h($itemTitle !== '' ? $itemTitle : 'Item') ?></td>
                        <td><?= $h($snapParts !== [] ? implode(' | ', $snapParts) : '—') ?></td>
                        <td><?= $h($itemQty !== null ? (string)$itemQty : '—') ?></td>
                        <td><?= $h($itemUnitPrice !== null ? $money($itemUnitPrice, $itemCurrency) : '—') ?></td>
                        <td><?= $h($itemLineTotal !== null ? $money($itemLineTotal, $itemCurrency) : '—') ?></td>
                        <td><span class="badge <?= $h((string)($lineStatusBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($lineStatusBadge['label'] ?? 'Unknown')) ?></span></td>
                        <td><?= $h(!empty($itemRow['tracking_number']) ? (string)$itemRow['tracking_number'] : '—') ?></td>
                        <td><?= $h(!empty($itemRow['carrier']) ? (string)$itemRow['carrier'] : '—') ?></td>
                        <td><?= $h($dateParts !== [] ? implode(' | ', $dateParts) : '—') ?></td>
                        <td>
                            <?php if ($lineItemId <= 0): ?>
                                <span class="muted">Unavailable</span>
                            <?php elseif ($isMultiSellerOrder): ?>
                                <span class="muted">Multi-seller order</span>
                            <?php elseif (!$fulfillmentActionEndpointExists): ?>
                                <span class="muted">Endpoint unavailable</span>
                            <?php elseif (!$allowFulfillmentSubmit): ?>
                                <span class="muted">Read-only</span>
                            <?php elseif ($lineActions === []): ?>
                                <span class="muted">No actions</span>
                            <?php else: ?>
                                <div class="stack">
                                    <div class="action-row">
                                        <?php foreach ($lineActions as $lineAction): ?>
                                            <?php $formId = 'line-form-' . $lineItemId . '-' . $lineAction['value']; ?>
                                            <form id="<?= $h($formId) ?>" method="post" action="<?= $h($fulfillmentActionEndpoint) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                                                <input type="hidden" name="order_item_id" value="<?= $h((string)$lineItemId) ?>">
                                                <input type="hidden" name="return_url" value="<?= $h($returnUrl) ?>">
                                                <input type="text" name="tracking_number" placeholder="Tracking number" value="<?= $h((string)($itemRow['tracking_number'] ?? '')) ?>">
                                                <input type="text" name="carrier" placeholder="Carrier / trans shipper" value="<?= $h((string)($itemRow['carrier'] ?? '')) ?>">
                                                <button type="submit" class="btn-approve" name="action" value="<?= $h((string)$lineAction['value']) ?>"><?= $h((string)$lineAction['label']) ?></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card stack">
        <h2>Request Status</h2>
        <?php if (!$hasAnyRequest): ?>
            <div class="empty">No cancel or refund request for this order.</div>
        <?php else: ?>
            <?php $activeBadge = $statusBadge($currentType === 'none' ? 'refund' : $currentType, $currentStatus); ?>
            <div class="meta-grid">
                <div class="meta-item"><span class="k">Request Type</span><span class="v"><?= $h($currentType !== 'none' ? ucfirst($currentType) : '—') ?></span></div>
                <div class="meta-item"><span class="k">Current Status</span><span class="v"><span class="badge <?= $h((string)($activeBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($activeBadge['label'] ?? 'Unknown')) ?></span></span></div>
                <div class="meta-item"><span class="k">Requested Date</span><span class="v"><?= $h($currentDate !== '' ? $currentDate : '—') ?></span></div>
                <div class="meta-item"><span class="k">Reason</span><span class="v"><?= $h($currentReason !== '' ? $currentReason : '—') ?></span></div>
                <div class="meta-item"><span class="k">Amount</span><span class="v"><?= $h($currentAmount !== '' && is_numeric($currentAmount) ? $money($currentAmount, $currency) : '—') ?></span></div>
                <div class="meta-item"><span class="k">Refund Mode</span><span class="v"><?= $h($currentRefundMode !== '' ? $currentRefundMode : '—') ?></span></div>
                <div class="meta-item"><span class="k">Refund Reference</span><span class="v"><?= $h($currentRefundRef !== '' ? $currentRefundRef : '—') ?></span></div>
            </div>
        <?php endif; ?>
    </section>

    <section class="split">
          <div class="card stack">
            <h3>Cancel Request</h3>
            <div class="muted">Order-level request data (may include non-seller-line context).</div>
            <?php if ($cancelRow): ?>
                <div class="meta-grid">
                    <div class="meta-item"><span class="k">Status</span><span class="v"><span class="badge <?= $h((string)($cancelBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($cancelBadge['label'] ?? 'Unknown')) ?></span></span></div>
                    <div class="meta-item"><span class="k">Source</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_source']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reason Code</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_reason_code']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reason Text</span><span class="v"><?= $h($pickValue($cancelRow, ['cancel_reason_text', 'reason_text', 'reason']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Admin Note</span><span class="v"><?= $h($pickValue($cancelRow, ['admin_note']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Refundable Amount</span><span class="v"><?= $h(($pickValue($cancelRow, ['refundable_amount']) !== '' && is_numeric($pickValue($cancelRow, ['refundable_amount']))) ? $money($pickValue($cancelRow, ['refundable_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Refund Status</span><span class="v"><?= $h($pickValue($cancelRow, ['refund_status']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested At</span><span class="v"><?= $h($pickDate($cancelRow) ?: '—') ?></span></div>
                </div>
            <?php else: ?>
                <div class="empty">No cancel request found for this order.</div>
            <?php endif; ?>
        </div>

         <div class="card stack">
            <h3>Refund Request</h3>
            <div class="muted">Order-level request data (may include non-seller-line context).</div>
            <?php if ($refundRow): ?>
                <div class="meta-grid">
                    <div class="meta-item"><span class="k">Refund Code</span><span class="v"><?= $h($pickValue($refundRow, ['refund_code']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Status</span><span class="v"><span class="badge <?= $h((string)($refundBadge['class'] ?? 'badge-default')) ?>"><?= $h((string)($refundBadge['label'] ?? 'Unknown')) ?></span></span></div>
                    <div class="meta-item"><span class="k">Refund Mode</span><span class="v"><?= $h($pickValue($refundRow, ['refund_mode']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested Amount</span><span class="v"><?= $h(($pickValue($refundRow, ['requested_refund_amount']) !== '' && is_numeric($pickValue($refundRow, ['requested_refund_amount']))) ? $money($pickValue($refundRow, ['requested_refund_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Approved Amount</span><span class="v"><?= $h(($pickValue($refundRow, ['approved_refund_amount']) !== '' && is_numeric($pickValue($refundRow, ['approved_refund_amount']))) ? $money($pickValue($refundRow, ['approved_refund_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Actual Refunded</span><span class="v"><?= $h(($pickValue($refundRow, ['actual_refunded_amount']) !== '' && is_numeric($pickValue($refundRow, ['actual_refunded_amount']))) ? $money($pickValue($refundRow, ['actual_refunded_amount']), $currency) : '—') ?></span></div>
                    <div class="meta-item"><span class="k">Payment Provider</span><span class="v"><?= $h($pickValue($refundRow, ['payment_provider']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Reference</span><span class="v"><?= $h($pickValue($refundRow, ['payment_reference_snapshot', 'payment_reference', 'refund_reference']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Admin/Internal Note</span><span class="v"><?= $h($pickValue($refundRow, ['admin_note', 'internal_note', 'note']) ?: '—') ?></span></div>
                    <div class="meta-item"><span class="k">Requested At</span><span class="v"><?= $h($pickDate($refundRow) ?: '—') ?></span></div>
                </div>
            <?php else: ?>
                <div class="empty">No refund request found for this order.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card actions">
        <h2>Take Action</h2>
        <div class="stack">
            <h3>Cancel / Refund Request Actions</h3>
            <?php if (!$requestActionEndpointExists): ?>
                <div class="empty">Read-only mode: seller request action endpoint is not available in this environment.</div>
            <?php elseif (!$canApproveCancel && !$canRejectCancel && !$canApproveRefund && !$canRejectRefund): ?>
                <div class="empty">No actions are currently available for this request state.</div>
            <?php else: ?>
                <form method="post" action="<?= $h($requestActionEndpoint) ?>" class="stack">
                    <input type="hidden" name="order_id" value="<?= $h((string)$orderId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <label for="note" class="muted">Note (optional)</label>
                    <textarea id="note" name="note" placeholder="Add a note for approval/rejection logs"></textarea>
                    <div class="action-row">
                        <?php if ($canApproveCancel): ?>
                            <button type="submit" class="btn-approve" name="action" value="approve_cancel">Approve Cancel</button>
                        <?php endif; ?>
                        <?php if ($canRejectCancel): ?>
                            <button type="submit" class="btn-reject" name="action" value="reject_cancel">Reject Cancel</button>
                        <?php endif; ?>
                        <?php if ($canApproveRefund): ?>
                            <button type="submit" class="btn-approve" name="action" value="approve_refund">Approve Refund</button>
                        <?php endif; ?>
                        <?php if ($canRejectRefund): ?>
                            <button type="submit" class="btn-reject" name="action" value="reject_refund">Reject Refund</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="stack">
             <h3>Order Fulfillment Actions</h3>
            <?php if ($isMultiSellerOrder): ?>
                <div class="empty">Multi-seller fulfillment actions are not enabled yet for this environment.</div>
            <?php elseif (!$fulfillmentActionEndpointExists): ?>
                <div class="empty">Fulfillment actions are not enabled yet for this environment.</div>
            <?php elseif (!$allowFulfillmentSubmit): ?>
                <div class="empty">Read-only mode: fulfillment actions require a clearly single-seller order in this environment.</div>
            <?php else: ?>
                <div class="empty">Use line-level controls in the “Your Items” table for safe fulfillment updates.</div>
            <?php endif; ?>
        </div>
    </section>
</div>
</body>
</html>
