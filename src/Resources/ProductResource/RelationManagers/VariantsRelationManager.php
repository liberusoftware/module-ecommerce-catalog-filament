<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\AddVariant;
use Liberu\Ecommerce\Catalog\Actions\RemoveVariant;
use Liberu\Ecommerce\Catalog\Exceptions\SkuAlreadyClaimed;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductOption;
use Liberu\Ecommerce\Catalog\Models\ProductVariant;

/**
 * The buyable configurations of a product.
 *
 * `ProductVariant` has no policy of its own — the domain authorizes variants
 * against the product, through `ProductPolicy::manageVariants()`. Filament would
 * treat a model with no policy as one nobody has refused, so every action here
 * carries an explicit gate on the **product**; none of them relies on a default.
 *
 * Adding and removing go through the domain actions, which is what makes
 * `VariantAdded` and `VariantRemoved` fire — the two events Pricing and
 * Inventory Ledger care about most, because a new sellable id exists and
 * neither of them has a row for it yet. A `CreateAction` writing through the
 * relation would create the row and tell nobody.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('None')
                    ->searchable(),
                TextColumn::make('title')
                    ->placeholder('Untitled'),
                // The axes the variant sits on, joined in order with the unused
                // ones dropped — the model's own `optionValues()`, rather than
                // three columns two of which are usually empty.
                TextColumn::make('option_values')
                    ->label('Options')
                    ->placeholder('None')
                    ->state(fn (ProductVariant $record): ?string => $record->optionValues() === []
                        ? null
                        : implode(' / ', $record->optionValues())),
                TextColumn::make('barcode')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('weight')
                    ->placeholder('Unknown')
                    ->state(fn (ProductVariant $record): ?string => $record->weight === null
                        ? null
                        : $record->weight.' '.$record->weight_unit),
                // Words, not a tick. A tick is legible to a sighted reader and
                // silent to a screen reader, and the badge colour repeats what
                // the text says rather than carrying it.
                TextColumn::make('requires_shipping')
                    ->label('Shipping')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Ships' : 'No shipping'),
                TextColumn::make('position')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('addVariant')
                    ->label('Add variant')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => Gate::allows('manageVariants', $product))
                    ->schema([
                        TextInput::make('sku')
                            ->label('SKU')
                            ->helperText('Unique across the whole estate, not just this product. Leave empty for a product that needs no code.')
                            ->maxLength(255),
                        TextInput::make('title')
                            ->maxLength(255),
                        ...$this->optionFields($product),
                        TextInput::make('barcode')
                            ->maxLength(255),
                        TextInput::make('weight')
                            ->numeric(),
                        TextInput::make('weight_unit')
                            ->label('Weight unit')
                            ->default('kg')
                            ->maxLength(255),
                        Toggle::make('requires_shipping')
                            ->label('Requires shipping')
                            ->default(true),
                    ])
                    ->action(function (array $data) use ($product): void {
                        try {
                            app(AddVariant::class)->handle(
                                $product,
                                self::text($data['sku'] ?? null),
                                self::text($data['title'] ?? null),
                                self::optionValues($data),
                                [
                                    'barcode' => self::text($data['barcode'] ?? null),
                                    'weight' => self::text($data['weight'] ?? null),
                                    'weight_unit' => self::text($data['weight_unit'] ?? null) ?? 'kg',
                                    'requires_shipping' => (bool) ($data['requires_shipping'] ?? true),
                                ],
                            );

                            Notification::make()
                                ->title('Variant added')
                                ->success()
                                ->send();
                        } catch (SkuAlreadyClaimed $exception) {
                            // The unique index would refuse this too, and would
                            // do it as an integrity-constraint dump. The domain
                            // raises a sentence naming the code; this shows it.
                            Notification::make()
                                ->title('Variant not added')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                // The shipping and descriptive fields only. `sku` and the option
                // values are absent on purpose: `AddVariant` is the only thing
                // that claims a SKU, and the option values are what the variant
                // *is* rather than something about it. See docs/presentation.md.
                EditAction::make()
                    ->schema([
                        TextInput::make('title')->maxLength(255),
                        TextInput::make('barcode')->maxLength(255),
                        TextInput::make('weight')->numeric(),
                        TextInput::make('weight_unit')->label('Weight unit')->maxLength(255),
                        Toggle::make('requires_shipping')->label('Requires shipping'),
                    ])
                    ->visible(fn (): bool => Gate::allows('manageVariants', $product)),
                Action::make('removeVariant')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The variant is deleted outright rather than soft-deleted, so its SKU comes free again.')
                    ->visible(fn (): bool => Gate::allows('manageVariants', $product))
                    ->action(function (ProductVariant $record): void {
                        app(RemoveVariant::class)->handle($record);

                        Notification::make()
                            ->title('Variant removed')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('position');
    }

    /**
     * One field per axis the product actually declares, labelled with the axis
     * name and offering exactly the values declared on it.
     *
     * Three free-text boxes called "Option 1", "Option 2" and "Option 3" is the
     * version of this that lets somebody type Large into the colour axis. A
     * product with no options declared gets no fields, which is correct: it has
     * no axes to vary along yet.
     *
     * @return array<int, Select>
     */
    private function optionFields(Product $product): array
    {
        $fields = [];

        foreach ($product->options->take(3)->values() as $index => $option) {
            /** @var ProductOption $option */
            $values = $option->values;

            $fields[] = Select::make('option'.($index + 1))
                ->label($option->name)
                ->options(array_combine($values, $values));
        }

        return $fields;
    }

    /**
     * The submitted option values in axis order.
     *
     * Read positionally rather than filtered, because `AddVariant` takes a list
     * and maps it onto `option1`..`option3` by index: a filter that dropped an
     * empty first axis would shift the second one onto it.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function optionValues(array $data): array
    {
        $values = [];

        foreach ([1, 2, 3] as $axis) {
            $value = self::text($data['option'.$axis] ?? null);

            if ($value === null) {
                break;
            }

            $values[] = $value;
        }

        return $values;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
