<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!function_exists('seller_order_request_debug_log')) {
    function seller_order_request_debug_log(string $event, array $context = []): void
    {
        $paths = [
            dirname(__DIR__, 2) . '/private_html/seller_order_detail_debug.log',
            dirname(__DIR__, 2) . '/seller_order_detail_debug.log',
            __DIR__ . '/../../seller_order_detail_debug.log',
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
    }
}

if (!function_exists('seller_order_request_pdo')) {
    function seller_order_request_pdo(): ?PDO
    {
        foreach (['pdo', 'db', 'conn', 'database'] as $key) {
            if (isset($GLOBALS[$key]) && $GLOBALS[$key] instanceof PDO) {
                return $GLOBALS[$key];
            }
        }
        return null;
    }
}

if (!function_exists('seller_order_request_table_exists')) {
    function seller_order_request_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1');
            $stmt->execute([':table_name' => $table]);
            $cache[$key] = (bool)$stmt->fetchColumn();
            return $cache[$key];
        } catch (Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }
}

if (!function_exists('seller_order_request_column_exists')) {
    function seller_order_request_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = strtolower($table . '.' . $column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if (!seller_order_request_table_exists($pdo, $table)) {
            $cache[$key] = false;
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1');
            $stmt->execute([
                ':table_name' => $table,
                ':column_name' => $column,
            ]);
            $cache[$key] = (bool)$stmt->fetchColumn();
            return $cache[$key];
        } catch (Throwable $e) {
            $cache[$key] = false;
            return false;
        }
    }
}

if (!function_exists('seller_order_request_current_seller_id')) {
    function seller_order_request_current_seller_id(): int
    {
        $candidateValues = [
            'seller.id' => $_SESSION['seller']['id'] ?? null,
            'seller_id' => $_SESSION['seller_id'] ?? null,
            'auth.seller_id' => $_SESSION['auth']['seller_id'] ?? null,
            'user.seller_id' => $_SESSION['user']['seller_id'] ?? null,
            'member.seller_id' => $_SESSION['member']['seller_id'] ?? null,
        ];

        foreach ($candidateValues as $key => $value) {
            if (is_numeric($value) && (int)$value > 0) {
                seller_order_request_debug_log('session identities discovered', [
                    'source' => 'seller_id',
                    'selected_key' => $key,
                    'selected_value' => (int)$value,
                    'candidates' => $candidateValues,
                ]);
                return (int)$value;
            }
        }

        seller_order_request_debug_log('session identities discovered', [
            'source' => 'seller_id',
            'selected_key' => null,
            'selected_value' => 0,
            'candidates' => $candidateValues,
        ]);

        return 0;
    }
}

if (!function_exists('seller_order_request_current_user_id')) {
    function seller_order_request_current_user_id(): int
    {
        $candidateValues = [
            'seller.user_id' => $_SESSION['seller']['user_id'] ?? null,
            'seller_user_id' => $_SESSION['seller_user_id'] ?? null,
            'auth.seller_user_id' => $_SESSION['auth']['seller_user_id'] ?? null,
            'user.id' => $_SESSION['user']['id'] ?? null,
            'auth_user.id' => $_SESSION['auth_user']['id'] ?? null,
            'member.id' => $_SESSION['member']['id'] ?? null,
            'user_id' => $_SESSION['user_id'] ?? null,
            'member_id' => $_SESSION['member_id'] ?? null,
        ];

        foreach ($candidateValues as $key => $value) {
            if (is_numeric($value) && (int)$value > 0) {
                $resolved = (int)$value;
                seller_order_request_debug_log('seller_order_request_current_user_id resolved', [
                    'selected_key' => $key,
                    'selected_value' => $resolved,
                    'candidates' => $candidateValues,
                ]);
                return $resolved;
            }
        }

        seller_order_request_debug_log('seller_order_request_current_user_id resolved', [
            'selected_key' => null,
            'selected_value' => 0,
            'candidates' => $candidateValues,
        ]);

        return 0;
    }
}

if (!function_exists('seller_order_request_owner_where')) {
    function seller_order_request_owner_where(PDO $pdo, int $sellerUserId): array
    {
        $sellerId = seller_order_request_current_seller_id();
        $parts = [];
        $params = [
            ':seller_user_id' => $sellerUserId,
            ':seller_id' => $sellerId,
        ];

        foreach (['seller_user_id', 'owner_user_id', 'user_id'] as $column) {
            if (seller_order_request_column_exists($pdo, 'order_items', $column)) {
                $parts[] = 'oi.' . $column . ' = :seller_user_id';
            }
        }

        if (seller_order_request_column_exists($pdo, 'order_items', 'seller_id')) {
            if ($sellerId > 0) {
                $parts[] = 'oi.seller_id = :seller_id';
            }
        }

        if (seller_order_request_table_exists($pdo, 'listings') && seller_order_request_column_exists($pdo, 'listings', 'seller_id')) {
            if ($sellerId > 0) {
                $parts[] = 'l.seller_id = :seller_id';
            }
            if ($sellerUserId > 0) {
                $parts[] = 'l.seller_id = :seller_user_id';
            }
        }

        if ($parts === []) {
            $parts[] = '1 = 0';
        }

        return [$parts, $params, $sellerId];
    }
}

if (!function_exists('seller_order_request_get_order_context')) {
    function seller_order_request_get_order_context(int $orderId, int $sellerUserId): ?array
    {
        $pdo = seller_order_request_pdo();
        if (!$pdo instanceof PDO || $orderId <= 0 || $sellerUserId <= 0) {
            seller_order_request_debug_log('seller_order_request_get_order_context early return', [
                'reason' => 'invalid_input_or_missing_pdo',
                'order_id' => $orderId,
                'seller_user_id' => $sellerUserId,
            ]);
            return null;
        }

        [$parts, $params, $sellerId] = seller_order_request_owner_where($pdo, $sellerUserId);

        $sql = 'SELECT o.* FROM orders o
                WHERE o.id = :order_id
                  AND EXISTS (
                    SELECT 1
                    FROM order_items oi
                    LEFT JOIN listings l ON l.id = oi.listing_id
                    WHERE oi.order_id = o.id
                      AND (' . implode(' OR ', $parts) . ')
                  )
                LIMIT 1';

        $params[':order_id'] = $orderId;

        seller_order_request_debug_log('seller_order_request_get_order_context sql', [
            'sql' => preg_replace('/\s+/', ' ', $sql),
            'params' => $params,
            'seller_user_id' => $sellerUserId,
            'seller_id' => $sellerId,
        ]);

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                seller_order_request_debug_log('seller_order_request_get_order_context empty', [
                    'reason' => 'no_owned_order_match',
                    'order_id' => $orderId,
                    'seller_user_id' => $sellerUserId,
                    'seller_id' => $sellerId,
                ]);
                return null;
            }
            return $row;
        } catch (Throwable $e) {
            seller_order_request_debug_log('seller_order_request_get_order_context empty', [
                'reason' => 'query_exception',
                'order_id' => $orderId,
                'seller_user_id' => $sellerUserId,
                'seller_id' => $sellerId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

if (!function_exists('seller_order_request_get_cancel_by_order_id')) {
    function seller_order_request_get_cancel_by_order_id(int $orderId, int $sellerUserId): ?array
    {
        $pdo = seller_order_request_pdo();
        if (!$pdo instanceof PDO || !seller_order_request_table_exists($pdo, 'order_cancellations')) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM order_cancellations WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':order_id' => $orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('seller_order_request_get_refund_by_order_id')) {
    function seller_order_request_get_refund_by_order_id(int $orderId, int $sellerUserId): ?array
    {
        $pdo = seller_order_request_pdo();
        if (!$pdo instanceof PDO || !seller_order_request_table_exists($pdo, 'order_refunds')) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM order_refunds WHERE order_id = :order_id ORDER BY id DESC LIMIT 1');
            $stmt->execute([':order_id' => $orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('seller_order_request_detect_primary_type')) {
    function seller_order_request_detect_primary_type(?array $cancelRow, ?array $refundRow): string
    {
        if (is_array($cancelRow) && $cancelRow !== []) {
            return 'cancel';
        }
        if (is_array($refundRow) && $refundRow !== []) {
            return 'refund';
        }
        return '';
    }
}

if (!function_exists('seller_order_request_get_request_bundle')) {
    function seller_order_request_get_request_bundle(int $orderId, int $sellerUserId): array
    {
        $order = seller_order_request_get_order_context($orderId, $sellerUserId);
        if (!is_array($order) || $order === []) {
            seller_order_request_debug_log('seller_order_request_get_request_bundle empty', [
                'reason' => 'order_context_empty',
                'order_id' => $orderId,
                'seller_user_id' => $sellerUserId,
            ]);
            return [];
        }

        $cancel = seller_order_request_get_cancel_by_order_id($orderId, $sellerUserId);
        $refund = seller_order_request_get_refund_by_order_id($orderId, $sellerUserId);
        $primaryType = seller_order_request_detect_primary_type($cancel, $refund);

        seller_order_request_debug_log('seller_order_request_get_request_bundle sql/logic', [
            'logic' => 'bundle built from owned order context + latest cancel/refund rows',
            'order_id' => $orderId,
            'seller_user_id' => $sellerUserId,
            'has_cancel' => is_array($cancel),
            'has_refund' => is_array($refund),
            'primary_type' => $primaryType,
        ]);

        return [
            'order' => $order,
            'cancel' => $cancel,
            'refund' => $refund,
            'primary_type' => $primaryType,
            'seller_can_approve_cancel' => is_array($cancel),
            'seller_can_reject_cancel' => is_array($cancel),
            'seller_can_approve_refund' => is_array($refund),
            'seller_can_reject_refund' => is_array($refund),
        ];
    }
}
