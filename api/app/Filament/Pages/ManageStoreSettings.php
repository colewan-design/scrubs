<?php

namespace App\Filament\Pages;

use App\Filament\Support\MoneyInput;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The settings the business runs on (§9).
 *
 * Everything here already existed in Settings::DEFAULTS and was already read
 * correctly at runtime — what was missing was any way for the client to change
 * it without a deployment. The brief is explicit that the free-shipping
 * threshold and the wholesale minimum must be editable, and the four policy
 * texts cannot be written by us at all.
 *
 * Setting keys contain dots, which Livewire reads as nested state paths, so
 * field names swap them for underscores and map back on save.
 */
class ManageStoreSettings extends Page
{
    // The panel navigates in SPA mode, so leaving this page is a body swap
    // rather than a document unload and the browser has nothing to warn about.
    // Resource create/edit pages inherit this guard; a custom Page has to ask
    // for it. Livewire runs the trait's mount hook after mount() above, so the
    // baseline hash it takes is the filled form, not an empty one.
    use HasUnsavedDataChangesAlert;

    protected string $view = 'filament.pages.manage-store-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Store settings';

    protected static ?string $title = 'Store settings';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(Settings::class);

        $values = [];

        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $values[self::field($key)] = $settings->get($key);
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make()->tabs([

                    Tab::make('Wholesale')->schema([
                        Section::make('Unlock threshold')
                            ->description('What a cart has to reach before wholesale pricing applies. The tiers themselves are managed under Pricing tiers.')
                            ->schema([
                                MoneyInput::make(self::field('wholesale.min_order_cents'), 'Minimum wholesale order')
                                    ->required()
                                    ->helperText('Carts below this are charged retail. The brief\'s opening target is CA$200.'),
                            ]),
                    ]),

                    Tab::make('Shipping & pickup')->schema([
                        Section::make('Shipping')->schema([
                            MoneyInput::make(self::field('shipping.free_threshold_cents'), 'Free shipping over')
                                ->required()
                                ->helperText('Measured on the retail subtotal, before tax.'),

                            TextInput::make(self::field('shipping.provider'))
                                ->label('Rating provider')
                                ->helperText('table_rate until Stallion Express credentials are supplied.')
                                ->disabled()
                                ->dehydrated(false),
                        ])->columns(2),

                        Section::make('Local pickup')
                            ->description('Pickup is taxed at the store\'s own province, since that is where the customer takes delivery.')
                            ->schema([
                                Toggle::make(self::field('pickup.enabled'))
                                    ->label('Offer local pickup at checkout'),

                                TextInput::make(self::field('pickup.province'))
                                    ->label('Province')
                                    ->maxLength(2)
                                    ->helperText('Two-letter code, e.g. ON.'),

                                Textarea::make(self::field('pickup.address'))
                                    ->label('Pickup address')
                                    ->rows(3)
                                    ->columnSpanFull(),

                                TextInput::make(self::field('pickup.hours'))->label('Opening hours'),
                                TextInput::make(self::field('pickup.lead_time'))
                                    ->label('Lead time')
                                    ->helperText('e.g. "Ready within 2 business days".'),
                            ])->columns(2),
                    ]),

                    Tab::make('Orders')->schema([
                        Section::make('Order numbering')->schema([
                            TextInput::make(self::field('orders.number_prefix'))->label('Number prefix'),
                            TextInput::make(self::field('orders.number_start'))
                                ->label('Starting number')
                                ->numeric(),
                        ])->columns(2),

                        Section::make('Stock reservation')->schema([
                            TextInput::make(self::field('orders.reservation_ttl_minutes'))
                                ->label('Hold stock for (minutes)')
                                ->numeric()
                                ->minValue(1)
                                ->helperText('How long an in-flight checkout keeps stock reserved before it is released.'),

                            TextInput::make(self::field('inventory.low_stock_threshold'))
                                ->label('Default low-stock level')
                                ->numeric()
                                ->minValue(0),
                        ])->columns(2),

                        Section::make('Email notifications')
                            ->description('Order confirmations, payment receipts, shipping notices and the new-order alert. All of them are built and wired — this switch is what makes them send, once a mail service is configured.')
                            ->schema([
                                Toggle::make(self::field('notifications.enabled'))
                                    ->label('Send order emails'),

                                TextInput::make(self::field('notifications.admin_email'))
                                    ->label('New-order alerts to')
                                    ->email()
                                    ->placeholder('Falls back to the first admin account')
                                    ->helperText('Where to send the alert when an order comes in.'),
                            ])->columns(2),

                        Section::make('Interac e-Transfer')
                            ->description('Used while card payment is pending a merchant account. Orders are placed as Pending Payment and settled out of band.')
                            ->schema([
                                Toggle::make(self::field('orders.etransfer_enabled'))
                                    ->label('Offer e-Transfer at checkout'),

                                Textarea::make(self::field('orders.etransfer_instructions'))
                                    ->label('Instructions shown to the customer')
                                    ->rows(4)
                                    ->columnSpanFull(),
                            ]),
                    ]),

                    Tab::make('Store details')->schema([
                        Section::make('Contact')
                            ->description('Shown in the site footer and on order confirmations.')
                            ->schema([
                                TextInput::make(self::field('store.email'))->label('Email')->email(),
                                TextInput::make(self::field('store.phone'))->label('Phone'),
                                Textarea::make(self::field('store.address'))->label('Address')->rows(3)->columnSpanFull(),
                                TextInput::make(self::field('store.hours'))->label('Hours'),
                            ])->columns(2),

                        Section::make('Social')->schema([
                            TextInput::make(self::field('store.social.instagram'))->label('Instagram')->url(),
                            TextInput::make(self::field('store.social.facebook'))->label('Facebook')->url(),
                            TextInput::make(self::field('store.social.tiktok'))->label('TikTok')->url(),
                        ])->columns(3),

                        Section::make('About us')
                            ->description('The body copy on the About page. Left blank, the page falls back to a short description taken from the project brief.')
                            ->schema([
                                Textarea::make(self::field('content.about'))
                                    ->label('About copy')
                                    ->rows(8)
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Tax registration')->schema([
                            TextInput::make(self::field('tax.gst_number'))
                                ->label('GST/HST number')
                                ->helperText('Printed on invoices and receipts where required.'),
                        ]),
                    ]),

                    Tab::make('Policies')
                        ->schema([
                            Section::make('Policy pages')
                                ->description('Left blank, the storefront says the policy is pending rather than inventing legal wording. Paste the client\'s final text here to publish each page.')
                                ->schema(
                                    collect(Settings::POLICIES)
                                        ->map(fn (string $label, string $slug) => Textarea::make(self::field("policy.{$slug}"))
                                            ->label($label)
                                            ->rows(8)
                                            ->columnSpanFull())
                                        ->values()
                                        ->all()
                                ),
                        ]),
                ])->columnSpanFull(),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(Settings::class);

        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $field = self::field($key);

            if (! array_key_exists($field, $state)) {
                continue;
            }

            $value = $state[$field];

            // Toggles arrive as booleans; the settings table stores strings.
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            $settings->set($key, $value ?? '');
        }

        // Saved state is the new baseline. Without this the page still holds
        // the hash it mounted with, and the next click on the sidebar asks
        // about changes that are already in the database.
        $this->rememberData();

        Notification::make()
            ->title('Settings saved')
            ->body('Changes take effect immediately across the storefront.')
            ->success()
            ->send();
    }

    /** Setting key -> Livewire-safe field name. */
    protected static function field(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
