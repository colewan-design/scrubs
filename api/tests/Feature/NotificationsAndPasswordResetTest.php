<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\NewOrderAlert;
use App\Notifications\OrderConfirmation;
use App\Notifications\OrderShipped;
use App\Notifications\PaymentReceived;
use App\Notifications\ResetPasswordLink;
use App\Services\Orders\OrderService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Order email (§10) and password reset (§8).
 *
 * Email delivery is a later phase, so the behaviour that matters most right now
 * is the gate: with notifications off, nothing sends and nothing breaks. The
 * rest proves the wiring is genuinely finished, so switching it on is a
 * settings change and not a build.
 */
class NotificationsAndPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function enableEmail(bool $on = true): void
    {
        $settings = app(Settings::class);
        $settings->set('notifications.enabled', $on ? '1' : '0');
        $settings->flush();
    }

    protected function order(): Order
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->state(['retail_price_cents' => 5000]))
            ->create(['stock_qty' => 20, 'reserved_qty' => 0]);

        $order = Order::create([
            'order_number' => 'BSD-'.random_int(10000, 99999),
            'email' => 'dana@clinic.ca',
            'status' => Order::STATUS_PENDING_PAYMENT,
            'payment_status' => Order::PAYMENT_PENDING,
            'fulfillment_status' => Order::FULFILLMENT_UNFULFILLED,
            'fulfillment_type' => Order::TYPE_SHIP,
            'subtotal_cents' => 10000,
            'discount_cents' => 0,
            'shipping_cents' => 0,
            'tax_cents' => 0,
            'grand_total_cents' => 10000,
            'currency' => 'CAD',
            'placed_at' => now(),
        ]);

        $order->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => 'Scrub Set',
            'variant_sku' => $variant->sku,
            'qty' => 2,
            'unit_retail_cents' => 5000,
            'unit_price_cents' => 5000,
            'line_discount_cents' => 0,
            'line_total_cents' => 10000,
        ]);

        $order->payments()->create([
            'provider' => Payment::PROVIDER_ETRANSFER,
            'status' => Payment::STATUS_PENDING,
            'amount_cents' => 10000,
            'currency' => 'CAD',
        ]);

        $variant->increment('reserved_qty', 2);

        return $order->fresh('items');
    }

    // ---- the gate ---------------------------------------------------------

    public function test_no_email_is_sent_while_notifications_are_off(): void
    {
        Notification::fake();
        $this->enableEmail(false);

        app(OrderService::class)->markPaid($this->order());

        Notification::assertNothingSent();
    }

    public function test_marking_paid_emails_the_customer_once_enabled(): void
    {
        Notification::fake();
        $this->enableEmail();

        $order = $this->order();
        app(OrderService::class)->markPaid($order);

        Notification::assertSentOnDemand(
            PaymentReceived::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'dana@clinic.ca',
        );
    }

    public function test_shipping_emails_the_customer(): void
    {
        Notification::fake();
        $this->enableEmail();

        $order = app(OrderService::class)->markPaid($this->order());
        app(OrderService::class)->transitionFulfillment($order, Order::FULFILLMENT_SHIPPED);

        Notification::assertSentOnDemand(OrderShipped::class);
    }

    /** A guest checks out with no account, and still needs their receipt. */
    public function test_the_customer_is_addressed_by_email_not_by_account(): void
    {
        Notification::fake();
        $this->enableEmail();

        $order = $this->order();
        $this->assertNull($order->user_id);

        app(OrderService::class)->markPaid($order);

        Notification::assertSentOnDemand(PaymentReceived::class);
    }

    public function test_the_admin_alert_falls_back_to_the_first_admin(): void
    {
        Notification::fake();
        $this->enableEmail();

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => 'ops@bulkscrubs.test',
        ]);

        app(\App\Services\Notifications\OrderNotifier::class)->orderPlaced($this->order());

        Notification::assertSentOnDemand(
            NewOrderAlert::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $admin->email,
        );
        Notification::assertSentOnDemand(OrderConfirmation::class);
    }

    // ---- password reset ---------------------------------------------------

    public function test_forgot_password_is_refused_while_email_is_off(): void
    {
        Notification::fake();
        $this->enableEmail(false);

        User::factory()->create(['email' => 'dana@clinic.ca']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@clinic.ca'])
            ->assertStatus(503)
            ->assertJson(['available' => false]);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_sends_a_link_once_email_is_on(): void
    {
        Notification::fake();
        $this->enableEmail();

        $user = User::factory()->create(['email' => 'dana@clinic.ca']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dana@clinic.ca'])
            ->assertOk()
            ->assertJson(['available' => true]);

        Notification::assertSentTo($user, ResetPasswordLink::class);
    }

    /** The response must not reveal whether an address is registered. */
    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        Notification::fake();
        $this->enableEmail();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@nowhere.test'])
            ->assertOk()
            ->assertJson(['available' => true]);

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        $this->enableEmail();
        $user = User::factory()->create(['email' => 'dana@clinic.ca']);

        $token = app('auth.password.broker')->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'dana@clinic.ca',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dana@clinic.ca',
            'password' => 'a-much-better-password',
        ])->assertOk();
    }

    public function test_a_bad_token_is_rejected(): void
    {
        $this->enableEmail();
        User::factory()->create(['email' => 'dana@clinic.ca']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'dana@clinic.ca',
            'password' => 'a-much-better-password',
            'password_confirmation' => 'a-much-better-password',
        ])->assertStatus(422);
    }

    /** The link has to reach the storefront, not the API, which renders no pages. */
    public function test_the_reset_link_points_at_the_storefront(): void
    {
        $user = User::factory()->create(['email' => 'dana@clinic.ca']);

        $mail = (new ResetPasswordLink('tok123'))->toMail($user);

        $this->assertStringContainsString(
            rtrim(config('app.frontend_url'), '/').'/account/reset-password',
            $mail->actionUrl,
        );
        $this->assertStringContainsString('tok123', $mail->actionUrl);
    }
}
