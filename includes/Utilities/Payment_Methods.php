<?php

namespace WC_P24\Utilities;

use WC_P24\API\Resources\Payment_Methods_Resource;

defined('ABSPATH') || exit;

class Payment_Methods
{
    const BLIK = ['Blik'];
    const BANK_TRANSFERS = ['FastTransfers', 'eTransfer'];
    const CREDIT_CARDS = ['Credit Card'];
    const WALLETS = ['Wallet'];

    const PAYWALL_PAYMENT = 0;
    const BLIK_PAYMENT = 181;           // Dedicated in-shop BLIK Level 0 (code + One Click)
    const BLIK_LEVEL0 = 181;            // Alias for BLIK_PAYMENT - for clarity
    const BLIK_PAYMENT_ALT = [181, 342]; // In-shop BLIK Level 0 methods (181 legacy, 342 new payout mechanism)
    const BLIK_PAYOUT_ALT = [154, 341]; // Alternative BLIK payout methods (paywall, no Level 0)
    const BLIK_LEVEL0_CANDIDATES = [181, 342]; // Priority-ordered candidates for auto-resolution
    const BLIK_LEVEL0_METHOD_OPTION = '_p24_blik_level0_method_id'; // WP option storing resolved method ID
    const CARD_PAYMENT = 218;
    const CARD_PAYMENT_ALT = [147, 218, 220, 241, 242];
    const GOOGLE_PAY = 229;
    const GOOGLE_PAY_ALT = [229, 238, 240, 264, 265];
    const APPLE_PAY = 252;
    const APPLE_PAY_ALT = [232, 239, 252, 253];
    const P24_INSTALLMENTS = 303;
    const EXCLUDED_METHODS = [181, 342]; // In-shop BLIK methods (not shown in online payments list)
    const CLICK_TO_PAY_METHODS = [241, 242];

    // Cache transient TTL - 10 minutes (600 seconds)
    const PAYMENT_METHODS_CACHE_TTL = 10 * 60;
    const PAYMENT_METHODS_CACHE_PREFIX = '_p24_methods_cache_';

    public static $already_requested = [];

    /**
     * Check if method ID is a BLIK Level 0 in-shop payment method
     * Covers both legacy (181) and new (342) implementations
     */
    public static function is_blik_level0(int $method_id): bool
    {
        return in_array($method_id, self::BLIK_PAYMENT_ALT);
    }

    private static function get_groups(array $methods): array
    {
        return array_map(function ($method) {
            return strtolower($method);
        }, $methods);
    }

    public static function get_methods_by_group(array $type, array $payment_methods): array
    {
        $available = array_filter($payment_methods, function ($method) use ($type) {
            $group = strtolower($method['group']);
            return in_array($group, self::get_groups($type));
        });

        return array_values($available);
    }

    public static function get_available_methods(): array
    {
        if (empty($methods = get_transient('_p24_available_methods'))) {
            $methods = self::get_payment_methods();
            // Cached array of available methods for 24 hours only for initial create Virtual Gateways, they are filtered later in Gateways_Manages/filter_gateways.php depends on cart value
            !empty($methods) && set_transient('_p24_available_methods', $methods, 60 * 60 * 24);
        }

        return $methods;
    }

    public static function get_popular_methods_icons(array $available_methods): array
    {
        $methods_icons = [];

        $blik = self::get_methods_by_group(self::BLIK, $available_methods);
        $credit_cards = self::get_methods_by_group(self::CREDIT_CARDS, $available_methods);
        $wallets = self::get_methods_by_group(self::WALLETS, $available_methods);

        if (!empty($blik)) {
            $methods_icons[] = self::convert($blik[0]);
        }

        if (!empty($credit_cards)) {
            $methods_icons[] = self::convert($credit_cards[0]);
        }

        if (count($methods_icons) < 2 && count($wallets)) {
            $methods_icons[] = self::convert($wallets[0]);
        }

        return $methods_icons;
    }

    public static function convert(array $method): array
    {
        return ['src' => $method['imgUrl'], 'name' => $method['name'], 'id' => $method['id'], 'type' => $method['group']];
    }

    public static function get_payment_methods(?int $value = null, ?string $currency = null): array
    {
        $request_key = $value . '_' . $currency;

        // Step 1: Check static memory cache (for same request)
        if (!empty(self::$already_requested[$request_key])) {
            return self::$already_requested[$request_key];
        }

        // Step 2: Check transient cache (for same value and currency across requests)
        $cache_key = self::PAYMENT_METHODS_CACHE_PREFIX . md5($request_key);
        $cached_methods = get_transient($cache_key);

        if (!empty($cached_methods)) {
            // Populate static cache for this request
            self::$already_requested[$request_key] = $cached_methods;
            return $cached_methods;
        }

        // Step 3: Call API if nothing in cache
        $client = new Payment_Methods_Resource();
        $response = $client->get_payment_methods($value, $currency);

        if (!empty($response['error'])) {
            return [];
        }

        $methods = $response['data'] ?? [];
        if ($methods === [] && isset($response[0]) && is_array($response[0])) {
            $methods = $response;
        }

        // Save to cache only if response is not empty (avoid caching errors)
        if (!empty($methods)) {
            // Save to static memory (current request)
            self::$already_requested[$request_key] = $methods;
            // Save to transient cache (cross-request, TTL 10 minutes)
            set_transient($cache_key, $methods, self::PAYMENT_METHODS_CACHE_TTL);
        }

        return $methods;
    }

    public static function prepare_methods(array $methods, ?string $order = '', bool $exclude = false): array
    {
        if ($exclude) {
            $methods = array_filter($methods, function ($method) {
                return !in_array($method['id'] ?? null, self::EXCLUDED_METHODS);
            });
        }

        $match = preg_match('/([\d,]+)?:([\d,]+)?/', $order, $matches);

        if (!$match) {
            return array_map(function ($method) {
                $method['featured'] = false;

                return $method;
            }, $methods) ?: [];
        }

        $featured = array_filter(explode(',', $matches[1] ?? ''));
        $ids = array_map(function ($m) {
            return $m['id'] ?? null;
        }, $methods);

        $methods_by_id = array_combine($ids, $methods);
        $merge_all = array_merge(explode(',', $matches[2] ?? ''), array_keys($methods_by_id));
        $order = array_unique(array_filter($merge_all));

        return array_values(array_filter(array_map(function ($id) use ($methods_by_id, $featured) {
            $item = null;

            if (isset($methods_by_id[$id])) {
                $item = $methods_by_id[$id];
                $item['featured'] = in_array($id, $featured);
            }

            return $item;
        }, $order)));
    }
    public static function get_group_name($method_id): string
    {
        $available_methods = self::get_available_methods();

        foreach ($available_methods as $method) {
            if ((int)$method['id'] === (int)$method_id) {
                return $method['group'] ?? 'Other';
            }
        }

        return 'Other';
    }

    /**
     * Query API for available BLIK Level 0 methods on this account.
     * Returns the first matching candidate ID from BLIK_LEVEL0_CANDIDATES, or null if none found.
     * Uses the 10-min transient cache so repeated calls within a request are cheap.
     */
    public static function resolve_blik_level0_method_id(): ?int
    {
        $methods = self::get_payment_methods(null, 'PLN');

        $found_ids = [];
        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $id = isset($method['id']) ? (int) $method['id'] : 0;
            if (!in_array($id, self::BLIK_LEVEL0_CANDIDATES, true)) {
                continue;
            }
            $status = array_key_exists('status', $method) ? (bool) $method['status'] : true;
            if ($status) {
                $found_ids[] = $id;
            }
        }

        foreach (self::BLIK_LEVEL0_CANDIDATES as $candidate) {
            if (in_array($candidate, $found_ids, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Run resolver and persist result to DB.
     * Stores the resolved ID, or 0 when account has no Level 0 method.
     * Skips update if API returns empty (no credentials yet or network error).
     */
    public static function sync_blik_level0_method_id(): void
    {
        $methods = self::get_payment_methods(null, 'PLN');
        if (empty($methods)) {
            return;
        }

        $resolved = self::resolve_blik_level0_method_id();
        update_option(self::BLIK_LEVEL0_METHOD_OPTION, $resolved ?? 0);
    }

    /**
     * Return the auto-resolved BLIK Level 0 method ID.
     * Returns null when sync confirmed no Level 0 on the account (stored=0).
     * Returns 181 only as a backward-compatible fallback when sync has never run.
     */
    public static function get_blik_level0_method_id(): ?int
    {
        $stored = get_option(self::BLIK_LEVEL0_METHOD_OPTION, false);

        // Never synced — use 181 as backward-compatible default
        if ($stored === false) {
            return self::BLIK_PAYMENT;
        }

        // Synced and found a valid Level 0 method
        if (self::is_blik_level0((int) $stored)) {
            return (int) $stored;
        }

        // Synced but confirmed unavailable (stored=0) — do not pretend 181 works
        return null;
    }

    /**
     * Returns true when Level 0 is confirmed available, false when confirmed unavailable,
     * null when sync has never run.
     */
    public static function is_blik_level0_available(): ?bool
    {
        $stored = get_option(self::BLIK_LEVEL0_METHOD_OPTION, false);

        if ($stored === false) {
            return null;
        }

        return self::is_blik_level0((int) $stored);
    }

    /**
     * Clear all payment methods cache (both transient and static memory)
     * Use this when plugin settings change or when you need to force API refresh
     */
    public static function clear_payment_methods_cache(): void
    {
        // Clear static memory cache
        self::$already_requested = [];
        
        // Clear all transient caches with our prefix
        // Since WordPress doesn't provide bulk transient deletion, we need to clear them individually
        // However, we can use a global approach if needed
        global $wpdb;
        
        // Delete all transients with our cache prefix
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('_transient_' . self::PAYMENT_METHODS_CACHE_PREFIX) . '%'
            )
        );
        
        // Also clear the non-transient available methods cache
        delete_transient('_p24_available_methods');
    }

}
