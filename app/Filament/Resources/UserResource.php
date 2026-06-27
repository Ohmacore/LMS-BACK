<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Services\NotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Utilisateurs';

    protected static ?string $navigationGroup = 'Gestion';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informations Personnelles')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nom complet')
                            ->required(),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true),

                        Forms\Components\TextInput::make('password')
                            ->label('Mot de passe')
                            ->password()
                            ->dehydrateStateUsing(fn($state) => $state ? bcrypt($state) : null)
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Rôle et Statut')
                    ->schema([
                        Forms\Components\Select::make('role')
                            ->label('Rôle')
                            ->options([
                                'student' => 'Étudiant',
                                'teacher' => 'Enseignant',
                                'admin' => 'Administrateur',
                            ])
                            ->required()
                            ->reactive(),

                        Forms\Components\Select::make('status')
                            ->label('Statut')
                            ->options([
                                'pending' => 'En attente',
                                'active' => 'Actif',
                                'blocked' => 'Bloqué',
                            ])
                            ->required()
                            ->default('active'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Informations Enseignant')
                    ->schema([
                        Forms\Components\TextInput::make('teacher.pseudo')
                            ->label('Pseudo'),

                        Forms\Components\TextInput::make('teacher.domain_of_interest')
                            ->label('Domaine'),

                        Forms\Components\Select::make('teacher.status')
                            ->label('Statut Enseignant')
                            ->options([
                                'pending' => 'En attente',
                                'approved' => 'Approuvé',
                                'rejected' => 'Rejeté',
                            ]),
                    ])
                    ->visible(fn(Forms\Get $get) => $get('role') === 'teacher')
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\BadgeColumn::make('role')
                    ->label('Rôle')
                    ->colors([
                        'primary' => 'student',
                        'success' => 'teacher',
                        'danger' => 'admin',
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'student' => 'Étudiant',
                        'teacher' => 'Enseignant',
                        'admin' => 'Admin',
                        default => $state,
                    }),

                Tables\Columns\BadgeColumn::make('teacher.status')
                    ->label('Statut Teacher')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'approved' => 'Approuvé',
                        'rejected' => 'Rejeté',
                        default => '-',
                    })
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('teacher.domain_of_interest')
                    ->label('Domaine')
                    ->placeholder('-')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Statut Compte')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'active',
                        'danger' => 'blocked',
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'pending' => 'En attente',
                        'active' => 'Actif',
                        'blocked' => 'Bloqué',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('Rôle')
                    ->options([
                        'student' => 'Étudiant',
                        'teacher' => 'Enseignant',
                        'admin' => 'Admin',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut Compte')
                    ->options([
                        'pending' => 'En attente',
                        'active' => 'Actif',
                        'blocked' => 'Bloqué',
                    ]),

                Tables\Filters\Filter::make('pending_teachers')
                    ->label('Enseignants en attente')
                    ->query(
                        fn(Builder $query): Builder => $query
                            ->where('role', 'teacher')
                            ->whereHas('teacher', fn($q) => $q->where('status', 'pending'))
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('approve_teacher')
                    ->label('Approuver')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(
                        fn(User $record): bool =>
                        $record->role === 'teacher' &&
                        $record->teacher?->status === 'pending'
                    )
                    ->action(function (User $record) {
                        $record->teacher()->update(['status' => 'approved']);
                        $record->update(['status' => 'active']);

                        app(NotificationService::class)->notifyUser(
                            $record,
                            'teacher_approved',
                            'Compte enseignant approuve',
                            'Votre compte enseignant est actif. Vous pouvez publier et gerer vos modules.',
                            [],
                            '/teacher/dashboard'
                        );
                    })
                    ->successNotificationTitle('Enseignant approuvé avec succès'),

                Tables\Actions\Action::make('reject_teacher')
                    ->label('Rejeter')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(
                        fn(User $record): bool =>
                        $record->role === 'teacher' &&
                        $record->teacher?->status === 'pending'
                    )
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Raison du rejet')
                            ->required(),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->teacher()->update([
                            'status' => 'rejected',
                            'notes' => $data['rejection_reason'],
                        ]);
                        $record->update(['status' => 'blocked']);

                        app(NotificationService::class)->notifyUser(
                            $record,
                            'teacher_rejected',
                            'Compte enseignant rejete',
                            $data['rejection_reason'],
                            [],
                            '/'
                        );
                    })
                    ->successNotificationTitle('Enseignant rejeté'),

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('ban')
                    ->label('Bannir')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn(User $record): bool => $record->status !== 'blocked')
                    ->action(fn(User $record) => $record->update(['status' => 'blocked']))
                    ->successNotificationTitle('Utilisateur bloqué'),
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
