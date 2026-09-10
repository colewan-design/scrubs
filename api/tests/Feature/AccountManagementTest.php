<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Self-service account management — §8.
 *
 * The tests worth having here are the ones about ownership and about the
 * verified flag, because those are the two places a mistake is silent: an
 * address book that leaks between customers looks fine until it doesn't, and
 * an email that stays "verified" through a change looks fine forever.
 */
class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => 'customer',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function addressPayload(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Dana',
            'last_name' => 'Reyes',
            'line1' => '14 Bloor St W',
            'city' => 'Toronto',
            'province' => 'ON',
            'postal_code' => 'M4W 1A9',
        ];
    }

    // ---------------------------------------------------------------- profile

    public function test_the_account_payload_reports_status_and_verification(): void
    {
        $user = $this->customer(['status' => 'active', 'email_verified_at' => null]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.has_password', true)
            ->assertJsonPath('user.wholesale_unlocked', true);
    }

    public function test_a_customer_updates_their_contact_details(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', [
                'name' => 'Dana Reyes',
                'email' => $user->email,
                'phone' => '416-555-0134',
                'business_name' => 'Northside Clinic',
            ])
            ->assertOk()
            ->assertJsonPath('user.business_name', 'Northside Clinic')
            ->assertJsonPath('email_changed', false);

        $this->assertSame('416-555-0134', $user->fresh()->phone);
    }

    public function test_changing_the_email_clears_verification(): void
    {
        $user = $this->customer();
        $this->assertTrue($user->hasVerifiedEmail());

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', [
                'name' => $user->name,
                'email' => 'new-address@example.test',
                'phone' => '416-555-0134',
            ])
            ->assertOk()
            ->assertJsonPath('email_changed', true)
            ->assertJsonPath('user.email_verified', false);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        $user = $this->customer();
        $other = $this->customer();

        $this->actingAs($user)
            ->patchJson('/api/v1/account/profile', [
                'name' => $user->name,
                'email' => $other->email,
                'phone' => '416-555-0134',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    // --------------------------------------------------------------- password

    public function test_changing_a_password_requires_the_current_one(): void
    {
        $user = $this->customer(['password' => 'correct-horse-battery']);

        $this->actingAs($user)
            ->putJson('/api/v1/account/password', [
                'current_password' => 'not-the-password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('correct-horse-battery', $user->fresh()->password));
    }

    public function test_a_password_is_changed_with_the_correct_current_one(): void
    {
        $user = $this->customer(['password' => 'correct-horse-battery']);

        $this->actingAs($user)
            ->putJson('/api/v1/account/password', [
                'current_password' => 'correct-horse-battery',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-secret', $user->fresh()->password));
    }

    /** A Google-created account has no password, so there is none to confirm. */
    public function test_a_social_account_sets_a_first_password_without_a_current_one(): void
    {
        $user = $this->customer(['password' => null]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertJsonPath('user.has_password', false);

        $this->actingAs($user)
            ->putJson('/api/v1/account/password', [
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertOk()
            ->assertJsonPath('user.has_password', true);

        $this->assertTrue(Hash::check('a-brand-new-secret', $user->fresh()->password));
    }

    // -------------------------------------------------------------- addresses

    public function test_the_first_saved_address_becomes_the_default(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->postJson('/api/v1/account/addresses', $this->addressPayload())
            ->assertCreated()
            ->assertJsonPath('address.is_default_shipping', true)
            ->assertJsonPath('address.is_default_billing', true);
    }

    public function test_only_one_address_is_the_default_at_a_time(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->postJson('/api/v1/account/addresses', $this->addressPayload());

        $second = $this->actingAs($user)
            ->postJson('/api/v1/account/addresses', $this->addressPayload([
                'line1' => '99 King St E',
                'is_default_shipping' => true,
            ]))
            ->assertCreated()
            ->json('address.id');

        $defaults = $user->addresses()->where('is_default_shipping', true)->pluck('id');

        $this->assertCount(1, $defaults);
        $this->assertSame($second, $defaults->first());
    }

    public function test_deleting_the_default_promotes_another_address(): void
    {
        $user = $this->customer();

        $first = $this->actingAs($user)
            ->postJson('/api/v1/account/addresses', $this->addressPayload())
            ->json('address.id');

        $this->actingAs($user)->postJson('/api/v1/account/addresses', $this->addressPayload([
            'line1' => '99 King St E',
        ]));

        $this->actingAs($user)->deleteJson("/api/v1/account/addresses/{$first}")->assertOk();

        $this->assertSame(1, $user->addresses()->where('is_default_shipping', true)->count());
    }

    /** The one that would be silent if it broke. */
    public function test_a_customer_cannot_read_or_touch_another_customers_address(): void
    {
        $owner = $this->customer();
        $intruder = $this->customer();

        $address = Address::create($this->addressPayload(['user_id' => $owner->id]));

        $this->actingAs($intruder)
            ->getJson('/api/v1/account/addresses')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($intruder)
            ->patchJson("/api/v1/account/addresses/{$address->id}", $this->addressPayload([
                'line1' => 'Somewhere else entirely',
            ]))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->deleteJson("/api/v1/account/addresses/{$address->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('addresses', ['id' => $address->id, 'line1' => '14 Bloor St W']);
    }

    public function test_addresses_require_authentication(): void
    {
        $this->getJson('/api/v1/account/addresses')->assertUnauthorized();
        $this->postJson('/api/v1/account/addresses', $this->addressPayload())->assertUnauthorized();
    }

    // ------------------------------------------------------------- suspension

    public function test_a_suspended_customer_loses_the_session_they_already_hold(): void
    {
        $user = $this->customer();

        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

        $user->suspend();

        $this->actingAs($user->fresh())
            ->getJson('/api/v1/orders')
            ->assertForbidden()
            ->assertJsonPath('status', 'suspended');
    }

    // ----------------------------------------------------------- verification

    public function test_a_signed_verification_link_confirms_the_address(): void
    {
        $user = $this->customer(['email_verified_at' => null]);

        $this->get($this->verificationUrl($user))
            ->assertRedirectContains('verify=verified');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_tampered_verification_link_is_refused(): void
    {
        $user = $this->customer(['email_verified_at' => null]);

        $this->get($this->verificationUrl($user).'&expires=9999999999')
            ->assertRedirectContains('verify=link-expired');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** Changing the email must invalidate a link already in flight for the old one. */
    public function test_a_link_issued_for_a_previous_address_no_longer_works(): void
    {
        $user = $this->customer(['email_verified_at' => null]);
        $url = $this->verificationUrl($user);

        $user->forceFill(['email' => 'moved-on@example.test'])->save();

        $this->get($url)->assertRedirectContains('verify=link-invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    /** §10: nothing is sent, and the API says so instead of pretending. */
    public function test_resending_reports_honestly_while_email_is_switched_off(): void
    {
        app(Settings::class)->set('notifications.enabled', false);

        $user = $this->customer(['email_verified_at' => null]);

        $this->actingAs($user)
            ->postJson('/api/v1/account/email/resend')
            ->assertStatus(503)
            ->assertJsonPath('sent', false);
    }

    protected function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addDay(),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );
    }
}
