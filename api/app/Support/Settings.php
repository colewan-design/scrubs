<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to the settings table — §9's "manageable without code changes".
 *
 * Everything the client needs to change without a developer lives here:
 * MOQ, free-shipping threshold, tax numbers, pickup details, contact details.
 */
class Settings
{
    protected const CACHE_KEY = 'settings.all';

    /** Sensible defaults so a fresh install is functional before seeding. */
    public const DEFAULTS = [
        'wholesale.min_order_cents' => 20000,        // $200 (§3)
        'shipping.free_threshold_cents' => 60000,    // $600 (§3, §5)
        'shipping.provider' => 'table_rate',
        'orders.reservation_ttl_minutes' => 20,
        'orders.etransfer_enabled' => false,
        'orders.etransfer_instructions' => '',

        // §4. PayPal is wired but held behind this switch, so credentials in the
        // environment are not on their own enough to start charging cards — the
        // same two-gate arrangement the Stallion rates and order emails use.
        // Off by default: a live key deployed by accident must not take money.
        'payments.paypal_enabled' => false,
        'orders.number_prefix' => 'BSD-',
        'orders.number_start' => 10000,
        'inventory.low_stock_threshold' => 5,
        'tax.gst_number' => '',

        // §10. Every notification is built and wired, but nothing sends until
        // this is switched on — email delivery is a later phase, and a half
        // configured mailer that throws mid-checkout is worse than silence.
        'notifications.enabled' => false,
        'notifications.admin_email' => '',
        'pickup.enabled' => true,
        // Pickup is taxed at the store's own province, since that is where the
        // customer takes delivery.
        'pickup.province' => 'ON',
        'pickup.address' => '',
        'pickup.hours' => '',
        'pickup.lead_time' => '',
        'store.email' => '',
        'store.phone' => '',
        'store.address' => '',
        'store.hours' => '',
        'store.social.instagram' => '',
        'store.social.facebook' => '',
        'store.social.tiktok' => '',

        // §11 About Us. Editable rather than hardcoded for the same reason the
        // policies are: the final wording is the client's to write.
        'content.about' => '',

        // §7 of the materials request: the four policies are the item most
        // likely to hold up launch, and the wording must come from the client.
        // They live in settings so publishing one is a paste into admin, not a
        // deployment — and so the site can honestly say a policy is pending
        // rather than inventing legal text.
        'policy.returns' => '',
        'policy.shipping' => '',
        'policy.privacy' => '',
        'policy.terms' => '',
    ];

    /** The four policies §7 requires published before the site can take payments. */
    public const POLICIES = [
        'returns' => 'Returns & Refunds',
        'shipping' => 'Shipping',
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms & Conditions',
    ];

    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            // A fresh database (or one mid-migration) must not fatal the app.
            try {
                $stored = Setting::query()->pluck('value', 'key')->all();
            } catch (\Throwable) {
                $stored = [];
            }

            return array_merge(self::DEFAULTS, $stored);
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key) ?? $default, FILTER_VALIDATE_BOOL);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) ($this->get($key) ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ---- Named accessors for the values the pricing path depends on ----

    /** §3 — the opening wholesale minimum. */
    public function minWholesaleOrderCents(): int
    {
        return $this->int('wholesale.min_order_cents', 20000);
    }

    /** §5 — editable free-shipping threshold. Measured on the retail subtotal. */
    public function freeShippingThresholdCents(): int
    {
        return $this->int('shipping.free_threshold_cents', 60000);
    }

    public function reservationTtlMinutes(): int
    {
        return $this->int('orders.reservation_ttl_minutes', 20);
    }
}
