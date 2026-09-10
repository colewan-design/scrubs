<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin panel's chrome.
 *
 * Structure and styling follow the Uniglobal admin system — see the header of
 * resources/css/filament/admin/theme.css for what was ported and what was
 * deliberately not. This file owns the parts of that system Filament expresses
 * in PHP rather than CSS: the colour ramps, the always-expanded sidebar, and
 * the navigation grouping.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('BulkScrubsDirect')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->font('Inter')
            ->colors([
                // Ink, matching the storefront's primary button. The reference
                // system uses a vivid accent for the active nav pill and
                // accepts ~2.95:1 on its white label; ink gives the same solid
                // shape at ~15.9:1 instead.
                //
                // Spelled out shade by shade rather than Color::hex('#1a1a1a'):
                // that helper keeps the seed's hue and chroma but re-spreads
                // lightness over Tailwind's standard scale, so shade 600 — the
                // one Filament fills buttons with — came back at L=0.60. A
                // washed mid-grey, not ink. The ramp below puts the brand value
                // where the framework actually reads it.
                'primary' => [
                    50 => '#f7f5f2',
                    100 => '#ebe7e2',
                    200 => '#d6d0c8',
                    300 => '#b3aca3',
                    400 => '#6b6560',
                    500 => '#3d3a37',
                    600 => '#1a1a1a',
                    700 => '#141414',
                    800 => '#0f0f0f',
                    900 => '#0a0a0a',
                    950 => '#050505',
                ],
                // Sage === wholesale, on both sides of the app. Filament's
                // `info` slot is where the pricing resources put it.
                'info' => Color::hex('#4a6b5d'),
                'success' => Color::hex('#2f6b4f'),
                'warning' => Color::hex('#9a6b18'),
                'danger' => Color::hex('#a33a2e'),
            ])
            // Labels always visible, nothing to reveal on hover — the reference
            // deleted its 72px icon rail for the same reason: once every target
            // is legible, a collapse toggle only moves them around.
            ->sidebarCollapsibleOnDesktop(false)
            ->topbar()
            // Soft navigation: every in-panel link becomes wire:navigate, so a
            // click swaps the body instead of reloading the document. The win
            // here is specific — the sidebar and topbar are fixed chrome that
            // is identical on all ten pages, and a full reload throws them away
            // and repaints them on every click. Under SPA they simply stay.
            //
            // Prefetching (->spa(hasPrefetching: true)) is deliberately off.
            // Filament attaches the hover prefetch to every anchor it renders,
            // and table cells are anchors, so a mouse crossing a 25-row product
            // list would request 25 fully rendered edit pages. The links that
            // would actually benefit are the ten in the sidebar, which are the
            // cheapest ones we have.
            //
            // Nothing in this panel links out of it today. A "View store" item
            // pointing at the storefront would need ->spaUrlExceptions(), or
            // Livewire will try to swap the shop into the admin body.
            ->spa()
            // Soft navigation costs a safety net: a stray sidebar click on a
            // half-filled form now discards it without a document unload for
            // the browser to question. Filament ships an SPA-aware guard for
            // exactly this — with alerts on it intercepts livewire:navigate and
            // asks first. Covers the create/edit pages by inheritance;
            // ManageStoreSettings opts in via the trait, since custom pages
            // don't carry it.
            ->unsavedChangesAlerts()
            // The reference admin themes light and dark because its storefront
            // does. Ours does not, and an ink primary is the one accent that
            // cannot serve both — Filament fills light buttons from shade 600
            // and draws dark-mode text from 400, so a monochrome ramp has to
            // choose. The dark tokens are written and ready in theme.css; this
            // stays off until there is a dark storefront to match.
            ->darkMode(false)
            ->maxContentWidth(Width::Full)
            // Not collapsible: the reference's groups are static labels over a
            // permanently visible list. Ten resources fit without folding, and
            // a chevron on a heading that never needs collapsing is another
            // control to ignore.
            ->navigationGroups([
                NavigationGroup::make('Catalog')->collapsible(false),
                NavigationGroup::make('Sales')->collapsible(false),
                NavigationGroup::make('Administration')->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // No AccountWidget or FilamentInfoWidget. The reference's rule —
            // every panel is backed by real data, cut anything that is not —
            // rules out both: one restates the name already in the user menu,
            // the other advertises Filament.
            ->widgets([])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render(<<<'HTML'
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500&display=swap">
                HTML),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
