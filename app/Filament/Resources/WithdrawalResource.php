<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalResource\Pages;
use App\Models\Withdrawal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Retraits Enseignants';

    protected static ?string $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations du Retrait')
                    ->schema([
                        Forms\Components\Select::make('teacher_id')
                            ->label('Enseignant')
                            ->relationship('teacher.user', 'name')
                            ->searchable()
                            ->preload()
                            ->disabled()
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('amount')
                            ->label('Montant (DZD)')
                            ->numeric()
                            ->suffix('DZD')
                            ->disabled(),

                        Forms\Components\TextInput::make('payment_method')
                            ->label('Méthode de Paiement')
                            ->disabled(),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'pending'      => 'En attente',
                                'in_treatment' => 'En traitement',
                                'transferred'  => 'Transféré',
                                'rejected'     => 'Rejeté',
                            ])
                            ->required(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Détails de Paiement')
                    ->schema([
                        Forms\Components\Textarea::make('payment_details')
                            ->label('Coordonnées de Paiement (RIB/RIP)')
                            ->rows(3)
                            ->disabled()
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Notes Admin')
                            ->rows(3)
                            ->placeholder('Raison du refus, informations complémentaires...')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('teacher.user.name')
                    ->label('Enseignant')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('DZD', locale: 'fr')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Méthode')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'CCP'       => 'info',
                        'Baridimob' => 'warning',
                        'BaridiPay' => 'success',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('payment_details')
                    ->label('Coordonnées')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->payment_details),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'warning' => 'pending',
                        'primary' => 'in_treatment',
                        'success' => 'transferred',
                        'danger'  => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'      => 'En attente',
                        'in_treatment' => 'En traitement',
                        'transferred'  => 'Transféré',
                        'rejected'     => 'Rejeté',
                        default        => $state,
                    }),

                Tables\Columns\TextColumn::make('admin_notes')
                    ->label('Notes Admin')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date demande')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending'      => 'En attente',
                        'in_treatment' => 'En traitement',
                        'transferred'  => 'Transféré',
                        'rejected'     => 'Rejeté',
                    ]),

                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Méthode de paiement')
                    ->options([
                        'CCP'       => 'CCP',
                        'Baridimob' => 'Baridimob',
                        'BaridiPay' => 'BaridiPay',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('mark_in_treatment')
                    ->label('En traitement')
                    ->icon('heroicon-o-clock')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Marquer en traitement')
                    ->modalDescription('Confirmer que cette demande est en cours de traitement ?')
                    ->visible(fn (Withdrawal $record): bool => $record->status === 'pending')
                    ->action(fn (Withdrawal $record) => $record->update(['status' => 'in_treatment']))
                    ->successNotificationTitle('Demande marquée en traitement'),

                Tables\Actions\Action::make('mark_transferred')
                    ->label('Marquer Transféré')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmer le transfert')
                    ->modalDescription('Confirmer que le virement a bien été effectué ?')
                    ->visible(fn (Withdrawal $record): bool => $record->status === 'in_treatment')
                    ->action(fn (Withdrawal $record) => $record->update(['status' => 'transferred']))
                    ->successNotificationTitle('Retrait marqué comme transféré ✓'),

                Tables\Actions\Action::make('reject')
                    ->label('Rejeter')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Withdrawal $record): bool => in_array($record->status, ['pending', 'in_treatment']))
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Raison du rejet')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Withdrawal $record, array $data) {
                        $record->update([
                            'status'      => 'rejected',
                            'admin_notes' => $data['admin_notes'],
                        ]);
                    })
                    ->successNotificationTitle('Demande rejetée'),

                Tables\Actions\Action::make('add_note')
                    ->label('Ajouter une note')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Note Admin')
                            ->rows(3),
                    ])
                    ->action(function (Withdrawal $record, array $data) {
                        $record->update(['admin_notes' => $data['admin_notes']]);
                    })
                    ->successNotificationTitle('Note enregistrée'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawals::route('/'),
            'view'  => Pages\ViewWithdrawal::route('/{record}'),
            'edit'  => Pages\EditWithdrawal::route('/{record}/edit'),
        ];
    }
}
