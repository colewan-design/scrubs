<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The one account that can open /admin on a fresh install (§12).
 *
 * Deliberately its own seeder rather than a tail-end block in CatalogSeeder:
 * that seeder is placeholder catalogue data that must never reach production,
 * while this account is precisely what production does need. Keeping them apart
 * means a deployment can seed the administrator and nothing else:
 *
 *   php artisan db:seed --class=AdminUserSeeder
 *
 * Identity and password come from config/auth.php (ADMIN_NAME, ADMIN_EMAIL,
 * ADMIN_PASSWORD) so every environment owns its own credentials. An existing
 * account's password is never rewritten — re-seeding after the handover
 * rotation refreshes the role and status but leaves the live password alone,
 * which the previous updateOrCreate() did not.
 */
class AdminUserSeeder extends Seeder
{
    /**
     * Development fallback, used only when ADMIN_PASSWORD is unset and the app
     * is running locally. Long enough not to be worth guessing and named
     * clearly enough that finding it on a live site reads as a mistake.
     */
    protected const LOCAL_PASSWORD = 'BulkScrubs!DevAdmin1';

    /** The placeholder admin this seeder replaces. Warned about, never deleted. */
    protected const RETIRED_EMAIL = 'admin@bulkscrubsdirect.test';

    public function run(): void
    {
        $email = trim((string) config('auth.admin.email'));
        $name = trim((string) config('auth.admin.name'));

        if ($email === '') {
            $this->command?->warn('ADMIN_EMAIL is empty — no administrator seeded.');

            return;
        }

        // withTrashed(): users soft-delete and the email column is unique, so a
        // previously removed administrator has to be found and restored rather
        // than inserted over the top of its own row.
        $admin = User::withTrashed()->firstWhere('email', $email) ?? new User;
        $existing = $admin->exists;

        $admin->email = $email;
        $admin->name = $name !== '' ? $name : 'Administrator';
        $admin->role = 'admin';

        // A suspended account cannot open the panel (canAccessPanel), which
        // would make seeding an administrator pointless.
        $admin->status = 'active';

        // No mailbox is verified during seeding, and an unverified admin would
        // be stuck behind the §10 verification gate on first sign-in.
        if ($admin->email_verified_at === null) {
            $admin->email_verified_at = now();
        }

        if (! $existing) {
            $admin->password = $this->resolvePassword($email);
        }

        if ($admin->trashed()) {
            $admin->deleted_at = null;
        }

        $admin->save();

        if ($existing) {
            $this->command?->info("Administrator {$email} already exists — role and status refreshed, password left untouched.");
        } else {
            $this->command?->info("Administrator {$email} created.");
        }

        $this->warnAboutRetiredAdmin($email);
    }

    /**
     * ADMIN_PASSWORD if it is set, the local fallback if not and we are in
     * development, and otherwise a generated one printed once — a shared
     * default committed to the repository must not be able to become the
     * password on a live panel.
     */
    protected function resolvePassword(string $email): string
    {
        $configured = (string) config('auth.admin.password');

        if ($configured !== '') {
            return $configured;
        }

        if (app()->environment('local', 'testing')) {
            return self::LOCAL_PASSWORD;
        }

        $generated = Str::password(24);

        $this->command?->warn("ADMIN_PASSWORD is not set. Generated a one-time password for {$email}:");
        $this->command?->warn("    {$generated}");
        $this->command?->warn('It is stored only as a hash — copy it now, sign in, and change it.');

        return $generated;
    }

    /**
     * The old placeholder administrator carried the password "password". Having
     * it linger alongside the real account is the exact hazard this seeder
     * exists to remove, but deleting a user is not a seeder's call to make.
     */
    protected function warnAboutRetiredAdmin(string $email): void
    {
        if ($email === self::RETIRED_EMAIL) {
            return;
        }

        // Deliberately not withTrashed(): a soft-deleted placeholder is retired
        // already — it cannot be retrieved to authenticate — and warning about
        // it forever would train the reader to ignore the message.
        if (! User::where('email', self::RETIRED_EMAIL)->exists()) {
            return;
        }

        $this->command?->warn('The placeholder administrator '.self::RETIRED_EMAIL.' is still in this database with a weak password.');
        $this->command?->warn('Delete it from /admin once you can sign in as '.$email.'.');
    }
}
