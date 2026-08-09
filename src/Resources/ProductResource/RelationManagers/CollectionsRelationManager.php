<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\AddProductToCollection;
use Liberu\Ecommerce\Catalog\Actions\RemoveProductFromCollection;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductCollection;

/**
 * The merchandised groupings this product is in.
 *
 * A category is where a product *is* and a collection is where a merchant *put*
 * it this month, so a product sits in one category and any number of
 * collections. Membership carries a position, because a collection is an
 * ordering as much as a set.
 *
 * The same two actions serve the collection's own products relation manager
 * from the other end. Both are idempotent in the domain, so a double-click is
 * not an incident.
 */
class CollectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'collections';

    protected static ?string $title = 'Collections';

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
                    ->searchable(),
                TextColumn::make('pivot.position')
                    ->label('Position in collection')
                    ->placeholder('End'),
            ])
            ->headerActions([
                Action::make('addToCollection')
                    ->label('Add to collection')
                    ->icon('heroicon-o-rectangle-stack')
                    ->visible(fn (): bool => Gate::allows('update', $product))
                    ->schema([
                        Select::make('collection')
                            ->label('Collection')
                            // Scoped to the actor's team, like every other
                            // select in this package: offering somebody else's
                            // collection offers a write its policy refuses.
                            ->options(fn (): array => PanelTeam::scope(ProductCollection::query())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('position')
                            ->helperText('Leave empty to append.')
                            ->numeric(),
                    ])
                    ->action(function (array $data) use ($product): void {
                        $collection = ProductCollection::query()->findOrFail($data['collection']);

                        $position = $data['position'] ?? null;

                        app(AddProductToCollection::class)->handle(
                            $collection,
                            $product,
                            $position === null || $position === '' ? null : (int) $position,
                        );

                        Notification::make()
                            ->title('Added to collection')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('removeFromCollection')
                    ->label('Remove')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('update', $product))
                    ->action(function (ProductCollection $record) use ($product): void {
                        app(RemoveProductFromCollection::class)->handle($record, $product);

                        Notification::make()
                            ->title('Removed from collection')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('name');
    }
}
