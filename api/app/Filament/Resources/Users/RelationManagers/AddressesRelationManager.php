<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Address;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A customer's saved addresses, from the admin side (§9).
 *
 * The same address book the customer edits at /account/addresses — this is here
 * so support can fix a typo'd postal code that is failing rating, over the
 * phone, without asking the customer to log in and do it themselves.
 *
 * Editing one of these never rewrites an order. An order freezes its own copy
 * of the address at checkout (order_addresses), so history stays history.
 */
class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    protected static ?string $title = 'Saved addresses';

    public static function getBadge($ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->addresses()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('label')
                    ->maxLength(40)
                    ->placeholder('Clinic, Home, Warehouse')
                    ->helperText('The customer\'s own name for this address.'),

                TextInput::make('company')
                    ->maxLength(120),

                TextInput::make('first_name')
                    ->required()
                    ->maxLength(80),

                TextInput::make('last_name')
                    ->required()
                    ->maxLength(80),

                TextInput::make('line1')
                    ->label('Address line 1')
                    ->required()
                    ->maxLength(160)
                    ->columnSpanFull(),

                TextInput::make('line2')
                    ->label('Address line 2')
                    ->maxLength(160)
                    ->columnSpanFull(),

                TextInput::make('city')
                    ->required()
                    ->maxLength(80),

                Select::make('province')
                    ->options(array_combine(User::PROVINCES, User::PROVINCES))
                    ->searchable()
                    ->required()
                    // The tax engine keys off this, so it is not free text.
                    ->helperText('Sets the tax rate applied at checkout.'),

                TextInput::make('postal_code')
                    ->label('Postal code')
                    ->required()
                    ->maxLength(10)
                    ->extraInputAttributes(['style' => 'text-transform:uppercase']),

                TextInput::make('phone')
                    ->tel()
                    ->maxLength(40),

                Toggle::make('is_default_shipping')
                    ->label('Default shipping address'),

                Toggle::make('is_default_billing')
                    ->label('Default billing address'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('line1')
            ->columns([
                TextColumn::make('label')
                    ->placeholder('—')
                    ->description(fn (Address $record) => trim("{$record->first_name} {$record->last_name}")),

                TextColumn::make('line1')
                    ->label('Address')
                    ->description(fn (Address $record) => $record->line2),

                TextColumn::make('city')
                    ->formatStateUsing(fn (string $state, Address $record) => "{$state}, {$record->province}  {$record->postal_code}")
                    ->label('City'),

                IconColumn::make('is_default_shipping')
                    ->label('Ships to')
                    ->boolean(),

                IconColumn::make('is_default_billing')
                    ->label('Bills to')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
