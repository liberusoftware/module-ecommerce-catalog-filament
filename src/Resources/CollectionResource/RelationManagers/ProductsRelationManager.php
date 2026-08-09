<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\RelationManagers;

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
use Liberu\Ecommerce\Catalog\Enums\ProductStatus;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Filament\Support\Reachability;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductCollection;

/**
 * What is in this collection, in the order a storefront will render it.
 *
 * The same two domain actions the product's own collections relation manager
 * uses, from the other end. No `AttachAction`: the pivot carries a position the
 * action appends, and `ProductAddedToCollection` is dispatched only when the
 * product was not already in — attaching by id would write the row, leave the
 * position at zero and tell nobody.
 *
 * The reachability column is here as well as on the product list because it is
 * where the question actually gets asked: a merchant building a campaign
 * collection wants to know which of the things they just put in it a shopper
 * can see.
 */
class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $title = 'Products';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        /** @var ProductCollection $collection */
        $collection = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn ($query) => $query->with('publications'))
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('reachability')
                    ->label('Shoppers see')
                    ->badge()
                    ->state(fn (Product $record): string => Reachability::of($record)->label())
                    ->color(fn (Product $record): string => Reachability::of($record)->color())
                    ->description(fn (Product $record): string => Reachability::of($record)->summary()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label()),
                TextColumn::make('pivot.position')
                    ->label('Position')
                    ->placeholder('End'),
            ])
            ->headerActions([
                Action::make('addProduct')
                    ->label('Add a product')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => Gate::allows('update', $collection))
                    ->schema([
                        Select::make('product')
                            ->label('Product')
                            ->options(fn (): array => PanelTeam::scope(Product::query())
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('position')
                            ->helperText('Leave empty to append.')
                            ->numeric(),
                    ])
                    ->action(function (array $data) use ($collection): void {
                        $product = Product::query()->findOrFail($data['product']);

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
                Action::make('removeProduct')
                    ->label('Remove')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => Gate::allows('update', $collection))
                    ->action(function (Product $record) use ($collection): void {
                        app(RemoveProductFromCollection::class)->handle($collection, $record);

                        Notification::make()
                            ->title('Removed from collection')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
