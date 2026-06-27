<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Module;
use App\Services\NotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $navigationGroup = 'Finances';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Information Transaction')
                    ->schema([
                        Forms\Components\Select::make('student_id')
                            ->label('Étudiant')
                            ->relationship('student.user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn(?Transaction $record) => $record !== null),

                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options([
                                'deposit' => 'Recharge',
                                'purchase' => 'Achat Module',
                                'referral' => 'Parrainage',
                            ])
                            ->required()
                            ->disabled(fn(?Transaction $record) => $record !== null),

                        Forms\Components\TextInput::make('amount')
                            ->label('Montant (DZD)')
                            ->numeric()
                            ->required()
                            ->suffix('DZD')
                            ->disabled(fn(?Transaction $record) => $record !== null),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'pending' => 'En Attente',
                                'completed' => 'Validé',
                                'rejected' => 'Rejeté',
                            ])
                            ->required()
                            ->default('pending'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Détails Complémentaires')
                    ->schema([
                        Forms\Components\FileUpload::make('receipt_url')
                            ->label('Reçu de paiement')
                            ->image()
                            ->disk('public')
                            ->directory('receipts')
                            ->visibility('public')
                            ->downloadable()
                            ->openable(),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('module_id')
                            ->label('Module (pour achat)')
                            ->relationship('module', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn(?Transaction $record) => $record?->type === 'purchase'),

                        Forms\Components\Select::make('validated_by')
                            ->label('Validé par')
                            ->relationship('validator', 'name', fn(Builder $query) => $query->where('role', 'admin'))
                            ->searchable()
                            ->preload(),

                        Forms\Components\DateTimePicker::make('validated_at')
                            ->label('Date de validation')
                            ->displayFormat('d/m/Y H:i')
                            ->disabled(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notes Admin')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('student.user.name')
                    ->label('Étudiant')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'deposit',
                        'success' => 'purchase',
                        'warning' => 'referral',
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'deposit' => 'Recharge',
                        'purchase' => 'Achat',
                        'referral' => 'Parrainage',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Montant')
                    ->money('DZD', locale: 'fr')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'completed',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'pending' => 'En Attente',
                        'completed' => 'Validé',
                        'rejected' => 'Rejeté',
                        default => $state,
                    }),

                Tables\Columns\ImageColumn::make('receipt_url')
                    ->label('Reçu')
                    ->disk('public')
                    ->size(40)
                    ->defaultImageUrl('/images/no-receipt.png'),

                Tables\Columns\TextColumn::make('validator.name')
                    ->label('Validé par')
                    ->placeholder('Non validé')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date création')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En Attente',
                        'completed' => 'Validé',
                        'rejected' => 'Rejeté',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'deposit' => 'Recharge',
                        'purchase' => 'Achat',
                        'referral' => 'Parrainage',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('validate')
                    ->label('Valider')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn(Transaction $record): bool => $record->status === 'pending')
                    ->action(function (Transaction $record) {
                        $record->update([
                            'status' => 'completed',
                            'validated_by' => auth()->id(),
                            'validated_at' => now(),
                        ]);

                        // If it's a deposit, add to student wallet
                        if ($record->type === 'deposit') {
                            $record->student->increment('wallet_balance', $record->amount);
                            app(NotificationService::class)->notifyUser(
                                $record->student->user,
                                'wallet_recharge_approved',
                                'Recharge approuvee',
                                "Votre recharge de {$record->amount} DZD a ete approuvee.",
                                ['transaction_id' => $record->id],
                                '/student/wallet'
                            );
                        }
                    })
                    ->successNotificationTitle('Transaction validée avec succès'),

                Tables\Actions\Action::make('reject')
                    ->label('Rejeter')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn(Transaction $record): bool => $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Raison du rejet')
                            ->required(),
                    ])
                    ->action(function (Transaction $record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'validated_by' => auth()->id(),
                            'validated_at' => now(),
                            'notes' => $data['notes'],
                        ]);

                        if ($record->type === 'deposit') {
                            app(NotificationService::class)->notifyUser(
                                $record->student->user,
                                'wallet_recharge_rejected',
                                'Recharge rejetee',
                                $data['notes'],
                                ['transaction_id' => $record->id],
                                '/student/wallet'
                            );
                        }
                    })
                    ->successNotificationTitle('Transaction rejetée'),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}
