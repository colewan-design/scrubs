<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The admin's view of a customer — §9's "manually modify a customer's account".
 *
 * Two rules this form exists to enforce:
 *
 *  1. Password is optional when editing. It is generated, not scaffolded: a
 *     required password field would mean an administrator cannot change a
 *     customer's phone number without also resetting their password, and it
 *     makes Google-created accounts (which have no password at all) unsavable.
 *
 *  2. Role and status are Selects, not free text. `status` decides whether the
 *     account can hold a session and `role` decides whether it can open this
 *     panel — both are too load-bearing to be a typo away from broken.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(190)
                            ->unique(ignoreRecord: true),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(30),

                        TextInput::make('business_name')
                            ->label('Business name')
                            ->maxLength(150)
                            ->helperText('Optional. Wholesale eligibility is set by order size, not by this.'),

                        TextInput::make('city')
                            ->maxLength(100),

                        Select::make('province')
                            ->options(array_combine(User::PROVINCES, User::PROVINCES))
                            ->searchable(),
                    ]),

                Section::make('Access')
                    ->columns(2)
                    ->schema([
                        Select::make('role')
                            ->options([
                                'customer' => 'Customer',
                                'staff' => 'Staff',
                                'admin' => 'Administrator',
                            ])
                            ->required()
                            ->default('customer')
                            ->helperText('Staff and administrators can open this admin panel.'),

                        Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'suspended' => 'Suspended',
                            ])
                            ->required()
                            ->default('active')
                            ->helperText('A suspended account cannot sign in, and loses any session it is already holding.'),

                        /*
                         * dehydrated(false) on a filled-in field would drop the
                         * value; the pair here is "leave it out of the payload
                         * when blank", which is what keeps an edit from wiping
                         * an existing password — or setting one on a Google
                         * account that is not supposed to have one.
                         */
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule(PasswordRule::default())
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'create'
                                ? 'The customer can change this later.'
                                : 'Leave blank to keep the current password.'),

                        DateTimePicker::make('email_verified_at')
                            ->label('Email confirmed at')
                            ->helperText('Clear this to mark the address unconfirmed again.'),
                    ]),

                Section::make('Pricing and history')
                    ->columns(2)
                    ->schema([
                        Select::make('customer_price_list_id')
                            ->label('Custom price list')
                            ->relationship('customerPriceList', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Overrides the standard wholesale tiers for this customer (§13).'),

                        DateTimePicker::make('last_login_at')
                            ->label('Last signed in')
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
