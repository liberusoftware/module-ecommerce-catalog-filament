<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\SetProductOption;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductOption;

/**
 * The axes a product varies along, and the choices on each.
 *
 * Declaring the axis here is what lets the variants relation manager render a
 * picker per axis instead of three free-text boxes, so this table is usually
 * filled in first.
 *
 * There is **no delete action**, and that is a deliberate absence rather than an
 * oversight: the domain publishes `SetProductOption` and nothing that removes
 * one. Deleting the row through Eloquent would leave variants still carrying
 * values on an axis that no longer exists and would dispatch no event at all.
 * Recorded in docs/presentation.md with the condition that resolves it.
 *
 * `ProductOption` has no policy, so every action here gates explicitly on the
 * product rather than letting an unanswered ability decide.
 */
class OptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    protected static ?string $title = 'Options';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Axis')
                    ->searchable(),
                TextColumn::make('values')
                    ->label('Choices')
                    ->placeholder('None')
                    ->state(fn (ProductOption $record): ?string => $record->values === []
                        ? null
                        : implode(', ', $record->values)),
                TextColumn::make('position')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('setOption')
                    ->label('Declare an axis')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => Gate::allows('manageVariants', $product))
                    ->schema([
                        TextInput::make('name')
                            ->label('Axis')
                            ->helperText('Size, Colour, Length. Declaring the same axis twice edits it rather than adding a second.')
                            ->required()
                            ->maxLength(255),
                        TagsInput::make('values')
                            ->label('Choices')
                            ->helperText('Duplicates are folded away and the order you enter is the order kept.'),
                    ])
                    ->action(function (array $data) use ($product): void {
                        app(SetProductOption::class)->handle(
                            $product,
                            (string) $data['name'],
                            self::values($data['values'] ?? []),
                        );

                        Notification::make()
                            ->title('Option set')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                // Keyed on the name, which is what makes this an edit rather
                // than a second option: `SetProductOption` upserts on
                // (product, name). The name is shown and not editable, because
                // changing it here would silently declare a new axis and leave
                // the old one behind.
                Action::make('setValues')
                    ->label('Edit choices')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => Gate::allows('manageVariants', $product))
                    ->fillForm(fn (ProductOption $record): array => ['values' => $record->values])
                    ->schema([
                        TagsInput::make('values')
                            ->label('Choices'),
                    ])
                    ->action(function (ProductOption $record, array $data) use ($product): void {
                        app(SetProductOption::class)->handle(
                            $product,
                            $record->name,
                            self::values($data['values'] ?? []),
                            $record->position,
                        );

                        Notification::make()
                            ->title('Option set')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('position');
    }

    /**
     * @return list<string>
     */
    private static function values(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = trim($value);
            }
        }

        return $strings;
    }
}
