<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Store details and policy pages (§1, §11, and §7 of the materials request).
 *
 * All of it is admin-editable settings rather than hardcoded copy, because
 * every one of these is outstanding client material. The site can therefore
 * ship today and become complete by someone pasting text into admin — no
 * deployment, and no placeholder legal wording invented by a developer.
 */
class ContentController extends Controller
{
    public function __construct(protected Settings $settings) {}

    /** Contact details and social links for the footer and contact page. */
    public function store(): JsonResponse
    {
        $social = array_filter([
            'instagram' => $this->settings->string('store.social.instagram'),
            'facebook' => $this->settings->string('store.social.facebook'),
            'tiktok' => $this->settings->string('store.social.tiktok'),
        ]);

        return response()->json([
            'email' => $this->settings->string('store.email') ?: null,
            'phone' => $this->settings->string('store.phone') ?: null,
            'address' => $this->settings->string('store.address') ?: null,
            'hours' => $this->settings->string('store.hours') ?: null,
            'about' => $this->settings->string('content.about') ?: null,
            // The footer simply omits social links until they are supplied.
            'social' => $social,
            'gst_number' => $this->settings->string('tax.gst_number') ?: null,
            'pickup' => $this->settings->bool('pickup.enabled') ? [
                'address' => $this->settings->string('pickup.address') ?: null,
                'hours' => $this->settings->string('pickup.hours') ?: null,
                'lead_time' => $this->settings->string('pickup.lead_time') ?: null,
            ] : null,
            'policies' => collect(Settings::POLICIES)
                ->map(fn (string $title, string $slug) => [
                    'slug' => $slug,
                    'title' => $title,
                    'published' => $this->settings->string("policy.{$slug}") !== '',
                ])
                ->values(),
        ]);
    }

    /**
     * One policy. An unpublished policy returns 200 with `published: false`
     * rather than 404 — the page exists and must say plainly that the wording
     * is being finalised, which is more honest than a missing page.
     */
    public function policy(string $slug): JsonResponse
    {
        abort_unless(array_key_exists($slug, Settings::POLICIES), 404);

        $body = $this->settings->string("policy.{$slug}");

        return response()->json([
            'slug' => $slug,
            'title' => Settings::POLICIES[$slug],
            'published' => $body !== '',
            'body' => $body ?: null,
            'contact_email' => $this->settings->string('store.email') ?: null,
        ]);
    }
}
