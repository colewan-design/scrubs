<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Google sign-in (§3).
 *
 * The behaviour that matters before credentials exist is that an unconfigured
 * provider is inert and says so, rather than throwing. The rest proves the
 * account-linking rules, which are the part that is expensive to get wrong:
 * a customer must never end up with two accounts and a split order history.
 */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'http://localhost:8001/api/v1/auth/google/callback',
        ]);
    }

    protected function fakeGoogleUser(string $id, string $email, string $name = 'Dana Reyes'): void
    {
        $oauthUser = (new SocialiteUser)->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => 'https://example.test/avatar.png',
        ]);

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('user')->andReturn($oauthUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_an_unconfigured_provider_redirects_back_with_a_reason(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get('/api/v1/auth/google/redirect')
            ->assertRedirectContains('error=unavailable');
    }

    public function test_an_unknown_provider_is_a_404(): void
    {
        $this->get('/api/v1/auth/facebook/redirect')->assertNotFound();
    }

    public function test_a_first_time_google_user_gets_an_account(): void
    {
        $this->configureGoogle();
        $this->fakeGoogleUser('google-1', 'dana@clinic.ca');

        $this->get('/api/v1/auth/google/callback')->assertRedirect();

        $user = User::where('email', 'dana@clinic.ca')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->password, 'A provider account should carry no password.');
        $this->assertNotNull($user->email_verified_at, 'Google has already verified the address.');
        $this->assertSame('customer', $user->role);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'google-1',
            'user_id' => $user->id,
        ]);
    }

    /** Signing in twice must not create a second account. */
    public function test_returning_users_reuse_their_account(): void
    {
        $this->configureGoogle();
        $this->fakeGoogleUser('google-1', 'dana@clinic.ca');

        $this->get('/api/v1/auth/google/callback');
        $this->post('/api/v1/auth/logout');
        $this->get('/api/v1/auth/google/callback');

        $this->assertSame(1, User::where('email', 'dana@clinic.ca')->count());
        $this->assertSame(1, SocialAccount::count());
    }

    /**
     * The customer registered with a password, then later clicks Continue with
     * Google. Google has proven they own the address, so it is the same person.
     */
    public function test_google_links_to_an_existing_password_account(): void
    {
        $this->configureGoogle();

        $existing = User::factory()->create(['email' => 'dana@clinic.ca']);

        $this->fakeGoogleUser('google-1', 'dana@clinic.ca');
        $this->get('/api/v1/auth/google/callback');

        $this->assertSame(1, User::where('email', 'dana@clinic.ca')->count());
        $this->assertAuthenticatedAs($existing);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $existing->id,
            'provider_user_id' => 'google-1',
        ]);
        // Their password still works — linking must not remove a credential.
        $this->assertNotNull($existing->fresh()->password);
    }

    /** The provider link wins over the email, so a changed address still lands home. */
    public function test_a_changed_email_still_resolves_by_provider_id(): void
    {
        $this->configureGoogle();

        $user = User::factory()->create(['email' => 'old@clinic.ca']);
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-1',
        ]);

        $this->fakeGoogleUser('google-1', 'new@clinic.ca');
        $this->get('/api/v1/auth/google/callback');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_a_suspended_account_cannot_sign_in(): void
    {
        $this->configureGoogle();

        $user = User::factory()->create(['email' => 'dana@clinic.ca', 'status' => 'suspended']);
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-1',
        ]);

        $this->fakeGoogleUser('google-1', 'dana@clinic.ca');

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirectContains('error=suspended');

        $this->assertGuest();
    }

    /**
     * Checkout needs an account, so a shopper who signs in with Google from
     * checkout has to land back on checkout rather than the account home.
     */
    public function test_google_sign_in_returns_the_customer_to_where_they_started(): void
    {
        $this->configureGoogle();

        $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/o/oauth2/auth'));
        $oauthUser = (new SocialiteUser)->map(['id' => 'google-1', 'name' => 'Dana Reyes', 'email' => 'dana@clinic.ca']);
        $provider->shouldReceive('user')->andReturn($oauthUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        // A browser navigation from the storefront — what gives Sanctum's
        // stateful API a session to remember the return path in.
        $frontend = rtrim(config('app.frontend_url'), '/');
        $this->withHeader('Referer', $frontend.'/checkout');

        $this->get('/api/v1/auth/google/redirect?redirect=/checkout')
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth')
            ->assertSessionHas('social_auth.return_to', '/checkout');

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirect($frontend.'/checkout');
    }

    /** The return path must never become an open redirect off our domain. */
    public function test_a_return_path_off_the_storefront_is_ignored(): void
    {
        $this->configureGoogle();
        $this->fakeGoogleUser('google-1', 'dana@clinic.ca');

        $frontend = rtrim(config('app.frontend_url'), '/');

        foreach (['//evil.test/x', 'https://evil.test', '/\\evil.test'] as $target) {
            $this->withHeader('Referer', $frontend.'/checkout')
                ->withSession(['social_auth.return_to' => $target])
                ->get('/api/v1/auth/google/callback')
                ->assertRedirect($frontend.'/account');
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
