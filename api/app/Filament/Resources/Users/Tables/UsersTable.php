<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Support\CsvExportAction;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Password;

/**
 * The customer list — §9's "suspend, disable, or manually modify".
 *
 * Three verbs, three different things, and the distinction matters:
 *
 *   Suspend  — reversible. Sets status, kills the session on the next request
 *              (EnsureAccountIsActive), keeps every order intact.
 *   Disable  — the soft delete. The account stops existing for the storefront
 *              but its order history survives, which Canadian record-keeping
 *              requires. Restorable from the trashed filter.
 *   Modify   — EditAction, on the form next door.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (User $record) => $record->business_name),

                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->copyable(),

                IconColumn::make('email_verified_at')
                    ->label('Confirmed')
                    ->boolean()
                    ->tooltip(fn (User $record) => $record->email_verified_at
                        ? 'Address confirmed '.$record->email_verified_at->toFormattedDateString()
                        : 'Address not confirmed yet'),

                TextColumn::make('phone')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->sortable(),

                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state) => $state === 'customer' ? 'gray' : 'warning')
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->sortable(),

                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->counts('orders')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('province')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('customerPriceList.name')
                    ->label('Custom pricing')
                    ->placeholder('Standard tiers')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Registered')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Last seen')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['active' => 'Active', 'suspended' => 'Suspended']),

                SelectFilter::make('role')
                    ->options(['customer' => 'Customer', 'staff' => 'Staff', 'admin' => 'Administrator']),

                Filter::make('unverified')
                    ->label('Email not confirmed')
                    ->query(fn (Builder $query) => $query->whereNull('email_verified_at')),

                TrashedFilter::make(),
            ])
            ->headerActions([
                CsvExportAction::make('customers', [
                    'Name' => fn (User $u) => $u->name,
                    'Email' => fn (User $u) => $u->email,
                    'Phone' => fn (User $u) => $u->phone,
                    'Business' => fn (User $u) => $u->business_name,
                    'City' => fn (User $u) => $u->city,
                    'Province' => fn (User $u) => $u->province,
                    'Role' => fn (User $u) => $u->role,
                    'Status' => fn (User $u) => $u->status,
                    'Verified' => fn (User $u) => $u->email_verified_at?->toDateString(),
                    'Registered' => fn (User $u) => $u->created_at?->toDateString(),
                    'Last login' => fn (User $u) => $u->last_login_at?->toDateTimeString(),
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make(self::accountActions()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /** @return array<int, Action> */
    protected static function accountActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend account')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(
                    'They will be signed out on their next request and cannot sign in again '
                    .'until reactivated. Their orders and saved addresses are untouched.'
                )
                ->visible(fn (User $record) => ! $record->isSuspended() && ! self::isSelf($record))
                ->action(function (User $record): void {
                    $record->suspend();

                    Notification::make()->title($record->name.' has been suspended.')->success()->send();
                }),

            Action::make('reactivate')
                ->label('Reactivate account')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (User $record) => $record->isSuspended())
                ->action(function (User $record): void {
                    $record->reactivate();

                    Notification::make()->title($record->name.' can sign in again.')->success()->send();
                }),

            /*
             * Support's answer to "I can't get in". Sends the customer the same
             * link the storefront's forgot-password form would, which is safer
             * than an administrator choosing a password and reading it out.
             */
            Action::make('sendPasswordReset')
                ->label('Send password reset')
                ->icon('heroicon-o-key')
                ->requiresConfirmation()
                ->modalDescription('Emails a reset link. Requires order emails to be switched on in Store settings.')
                ->action(function (User $record): void {
                    $status = Password::sendResetLink(['email' => $record->email]);

                    $status === Password::RESET_LINK_SENT
                        ? Notification::make()->title('Reset link sent to '.$record->email)->success()->send()
                        : Notification::make()->title(__($status))->danger()->send();
                }),

            Action::make('resendVerification')
                ->label('Resend email confirmation')
                ->icon('heroicon-o-envelope')
                ->visible(fn (User $record) => $record->email_verified_at === null)
                ->action(function (User $record): void {
                    app(EmailVerificationController::class)->sendTo($record)
                        ? Notification::make()->title('Confirmation link sent.')->success()->send()
                        : Notification::make()
                            ->title('Nothing sent — order emails are switched off in Store settings.')
                            ->warning()
                            ->send();
                }),

            Action::make('markVerified')
                ->label('Mark email confirmed')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->modalDescription('Use this when you have confirmed the address another way, such as by phone.')
                ->visible(fn (User $record) => $record->email_verified_at === null)
                ->action(function (User $record): void {
                    $record->markEmailAsVerified();

                    Notification::make()->title('Address marked as confirmed.')->success()->send();
                }),
        ];
    }

    /** An administrator suspending themselves would lock the panel behind them. */
    protected static function isSelf(User $record): bool
    {
        return auth()->id() === $record->getKey();
    }
}
