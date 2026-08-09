<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\ChangeProductStatus;
use Liberu\Ecommerce\Catalog\Actions\ScheduleAvailability;
use Liberu\Ecommerce\Catalog\Actions\SetProductVisibility;
use Liberu\Ecommerce\Catalog\Enums\ProductStatus;
use Liberu\Ecommerce\Catalog\Enums\Visibility;
use Liberu\Ecommerce\Catalog\Exceptions\InvalidAvailabilityWindow;
use Liberu\Ecommerce\Catalog\Exceptions\InvalidStatusTransition;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\CreateProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\ListProducts;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\CollectionsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\OptionsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\PublicationsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\TagsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Support\Instant;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Filament\Support\Reachability;
use Liberu\Ecommerce\Catalog\Models\Brand;
use Liberu\Ecommerce\Catalog\Models\Category;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\Vendor;
use UnitEnum;

/**
 * Products, and everything hanging off one.
 *
 * The form carries the descriptive fields and nothing the domain has a rule
 * about. Three fields are conspicuously absent and each has its own action:
 * `status` moves through {@see ChangeProductStatus} along the transitions the
 * enum admits, `visibility` through {@see SetProductVisibility}, and the
 * effective dates through {@see ScheduleAvailability}. A form saving any of
 * them through Filament's default `handleRecordUpdate` would write the column
 * and dispatch nothing, which is precisely the bypass the domain's own docs
 * warn about. `slug` is absent too — `CreateProduct` derives it, and an editable
 * one silently breaks every URL already pointing at the product.
 *
 * The list leads with what those three facts add up to; see
 * {@see Reachability}.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Filament's tenancy would scope through a relation resolving the
     * *application's* team model from configuration. This package does not
     * require the panel to be tenant-aware at all, so the scope is applied
     * against the `team_id` column instead — see {@see self::getEloquentQuery()}.
     */
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * A product nobody may act on is a product nobody should be reading either.
     *
     * `publications` is eager loaded because {@see Reachability} reports how
     * many channels carry the product and refuses to lazily fetch the relation
     * to find out; without this the column would simply stay silent about
     * channels.
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelTeam::scope(parent::getEloquentQuery())
            ->with(['category', 'brand', 'publications']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            // Create only. `CreateProduct` takes the store as a named argument
            // rather than in its attribute spread, precisely so that nothing can
            // decide who owns the row by accident — and the domain publishes no
            // action for moving a product between stores, so there is nothing
            // for an edit form to delegate to.
            TextInput::make('store_id')
                ->label('Store')
                ->helperText('The numeric id of the store this product sells in. Stores belong to another module, so there is nothing here to list.')
                ->numeric()
                ->visibleOn('create'),
            Select::make('category_id')
                ->label('Category')
                ->options(fn (): array => self::optionsFor(Category::class))
                ->searchable()
                ->helperText('A product sits in exactly one node of the tree.'),
            Select::make('brand_id')
                ->label('Brand')
                ->options(fn (): array => self::optionsFor(Brand::class))
                ->searchable(),
            Select::make('vendor_id')
                ->label('Vendor')
                ->options(fn (): array => self::optionsFor(Vendor::class))
                ->searchable()
                ->helperText('Who the merchant buys it from, which is routinely not the brand.'),
            Textarea::make('short_description')
                ->label('Short description')
                ->rows(2),
            Textarea::make('description')
                ->rows(4),
            Textarea::make('long_description')
                ->label('Long description')
                ->rows(6),
            TextInput::make('featured_image')
                ->label('Featured image')
                ->helperText('A path or URL. This package stores no files of its own.')
                ->maxLength(255),
            TextInput::make('meta_title')
                ->label('Meta title')
                ->maxLength(255),
            Textarea::make('meta_description')
                ->label('Meta description')
                ->rows(2),
            TextInput::make('meta_keywords')
                ->label('Meta keywords')
                ->maxLength(255),
            Toggle::make('is_featured')
                ->label('Featured'),
            TextInput::make('position')
                ->helperText('Lower sorts first in a storefront listing.')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                // The whole point of this screen. Status, visibility and the
                // dates are each a column of their own below, and each answers a
                // question nobody asked; this one answers the question they came
                // with, and its description names the fact standing in the way.
                TextColumn::make('reachability')
                    ->label('Shoppers see')
                    ->badge()
                    ->state(fn (Product $record): string => Reachability::of($record)->label())
                    ->color(fn (Product $record): string => Reachability::of($record)->color())
                    ->description(fn (Product $record): string => Reachability::of($record)->summary()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (Visibility $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Category')
                    ->placeholder('Unfiled')
                    ->toggleable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variants_count')
                    ->label('Variants')
                    ->counts('variants'),
                TextColumn::make('available_from')
                    ->label('Available from')
                    ->dateTime()
                    ->placeholder('Always')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('available_until')
                    ->label('Available until')
                    ->dateTime()
                    ->placeholder('Indefinitely')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('visibility')
                    ->options(self::visibilityOptions()),
            ])
            ->recordActions([
                self::changeStatusAction(),
                self::setVisibilityAction(),
                self::scheduleAvailabilityAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('id');
    }

    /**
     * The product's lifecycle, offered as exactly the moves the enum admits.
     *
     * The options come from `allowedTransitions()` rather than from a second
     * copy of the transition table kept here, so a release that changes the
     * lifecycle changes this surface with it.
     *
     * `InvalidStatusTransition` is still caught. The action should never be able
     * to raise it — the options came from the same enum that decides — but a
     * product whose status moved in another tab between render and submit
     * reaches exactly that case, and an operator deserves a notification rather
     * than a 500 for losing a race.
     */
    public static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change status')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (Product $record): bool => Gate::allows('changeStatus', $record))
            ->schema(fn (Product $record): array => [
                Select::make('status')
                    ->label('New status')
                    ->options(self::transitionOptions($record->status))
                    ->helperText('Archived is terminal, and there is no way back to Draft.')
                    ->required(),
            ])
            ->action(function (Product $record, array $data): void {
                try {
                    app(ChangeProductStatus::class)->handle($record, ProductStatus::from((string) $data['status']));

                    Notification::make()
                        ->title('Product status changed')
                        ->success()
                        ->send();
                } catch (InvalidStatusTransition $exception) {
                    Notification::make()
                        ->title('Status not changed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Visibility, changed on its own and never as a side effect of a save.
     *
     * No transition table, because the domain publishes none: unlike status,
     * visibility describes the present rather than a history, and hiding
     * something is always allowed. The current value is left out of the options
     * — the action is idempotent, so offering it would be a button that does
     * nothing and reports success.
     */
    public static function setVisibilityAction(): Action
    {
        return Action::make('setVisibility')
            ->label('Change visibility')
            ->icon('heroicon-o-eye')
            ->visible(fn (Product $record): bool => Gate::allows('update', $record))
            ->schema(fn (Product $record): array => [
                Select::make('visibility')
                    ->label('New visibility')
                    ->options(self::visibilityOptions($record->visibility))
                    ->helperText('Unlisted stays reachable by direct link but leaves listings, search and feeds. Hidden is reachable by nobody.')
                    ->required(),
            ])
            ->action(function (Product $record, array $data): void {
                app(SetProductVisibility::class)->handle($record, Visibility::from((string) $data['visibility']));

                Notification::make()
                    ->title('Product visibility changed')
                    ->success()
                    ->send();
            });
    }

    /**
     * The window the product is offered in, both ends optional.
     *
     * One action for setting and clearing, because the domain has one: a
     * separate "clear" would be a second place for the ordering check not to
     * happen. A window entirely in the past is accepted by the domain and so by
     * this — it is how a campaign is recorded after the fact.
     */
    public static function scheduleAvailabilityAction(): Action
    {
        return Action::make('scheduleAvailability')
            ->label('Schedule availability')
            ->icon('heroicon-o-calendar-days')
            ->visible(fn (Product $record): bool => Gate::allows('update', $record))
            ->fillForm(fn (Product $record): array => [
                'available_from' => $record->available_from,
                'available_until' => $record->available_until,
            ])
            ->schema([
                DateTimePicker::make('available_from')
                    ->label('Available from')
                    ->helperText('Leave empty for "already".'),
                DateTimePicker::make('available_until')
                    ->label('Available until')
                    ->helperText('Leave empty for "indefinitely".'),
            ])
            ->action(function (Product $record, array $data): void {
                try {
                    app(ScheduleAvailability::class)->handle(
                        $record,
                        Instant::from($data['available_from'] ?? null),
                        Instant::from($data['available_until'] ?? null),
                    );

                    Notification::make()
                        ->title('Availability scheduled')
                        ->success()
                        ->send();
                } catch (InvalidAvailabilityWindow $exception) {
                    Notification::make()
                        ->title('Availability not changed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
            OptionsRelationManager::class,
            TagsRelationManager::class,
            CollectionsRelationManager::class,
            PublicationsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (ProductStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function visibilityOptions(?Visibility $except = null): array
    {
        $options = [];

        foreach (Visibility::cases() as $visibility) {
            if ($visibility !== $except) {
                $options[$visibility->value] = $visibility->label();
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function transitionOptions(ProductStatus $from): array
    {
        $options = [];

        foreach ($from->allowedTransitions() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /**
     * The actor's own categories, brands or vendors, as select options.
     *
     * Scoped the same way the resources are. Offering another team's brand in a
     * select is offering a write the policy on that brand would refuse, and the
     * resulting product would carry a foreign key its owner cannot open.
     *
     * @param  class-string<Model>  $model
     * @return array<int|string, string>
     */
    private static function optionsFor(string $model): array
    {
        return PanelTeam::scope($model::query())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
