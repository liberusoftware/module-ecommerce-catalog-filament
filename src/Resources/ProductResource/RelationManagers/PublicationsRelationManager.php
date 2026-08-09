<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Catalog\Actions\PublishToChannel;
use Liberu\Ecommerce\Catalog\Actions\UnpublishFromChannel;
use Liberu\Ecommerce\Catalog\Exceptions\InvalidStatusTransition;
use Liberu\Ecommerce\Catalog\Filament\Support\Instant;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductPublication;

/**
 * Which storefronts carry this product, and between when and when.
 *
 * The publication's window is not the product's. The product's says whether the
 * thing is sellable anywhere; this says whether this storefront carries it,
 * which is how a line goes live on the outlet channel a fortnight after the main
 * one. The narrower one wins, so a row reading "Live" here is a necessary and
 * not a sufficient condition — the page's subheading answers the rest.
 *
 * A channel is a **number** in this table and not a name. Channels belong to
 * `liberusoftware/ecommerce-commerce-core`, which this package does not depend
 * on; `ProductPublication::channel()` resolves a class from configuration and
 * throws when the host has not named one, so a column that used it would be a
 * panel that crashes on every deployment that runs the catalogue without
 * Commerce Core.
 *
 * `ProductPublication` has no policy. Authorization is `publish` on the
 * product — an ability the domain separates from `update` on purpose, because
 * editing a description and putting something in front of shoppers are
 * different-sized mistakes.
 */
class PublicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'publications';

    protected static ?string $title = 'Channel publication';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('channel_id')
            ->columns([
                TextColumn::make('channel_id')
                    ->label('Channel')
                    ->sortable(),
                // Words rather than a coloured dot, and the word says which of
                // the three states the window is in — "not live" would leave an
                // operator unable to tell a season staged for next month from
                // one that ended last week.
                TextColumn::make('window_state')
                    ->label('State')
                    ->badge()
                    ->state(fn (ProductPublication $record): string => self::stateOf($record))
                    ->color(fn (ProductPublication $record): string => match (self::stateOf($record)) {
                        'Live' => 'success',
                        'Scheduled' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('published_at')
                    ->label('From')
                    ->dateTime()
                    ->placeholder('Immediately'),
                TextColumn::make('unpublished_at')
                    ->label('Until')
                    ->dateTime()
                    ->placeholder('Indefinitely'),
            ])
            ->headerActions([
                Action::make('publish')
                    ->label('Publish to a channel')
                    ->icon('heroicon-o-signal')
                    ->visible(fn (): bool => Gate::allows('publish', $product))
                    ->schema([
                        TextInput::make('channel_id')
                            ->label('Channel')
                            ->helperText('The numeric id of the channel. Channels belong to another module, so there is nothing here to list.')
                            ->numeric()
                            ->required(),
                        DateTimePicker::make('published_at')
                            ->label('From')
                            ->helperText('Leave empty to carry it from the moment this is saved.'),
                        DateTimePicker::make('unpublished_at')
                            ->label('Until')
                            ->helperText('Leave empty for indefinitely.'),
                    ])
                    ->action(fn (array $data) => self::publish($product, $data)),
            ])
            ->recordActions([
                // Re-publishing rewrites the window rather than failing, which
                // is the domain's own behaviour and is why rescheduling is the
                // same action rather than an update through the model.
                Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-calendar-days')
                    ->visible(fn (): bool => Gate::allows('publish', $product))
                    ->fillForm(fn (ProductPublication $record): array => [
                        'channel_id' => $record->channel_id,
                        'published_at' => $record->published_at,
                        'unpublished_at' => $record->unpublished_at,
                    ])
                    ->schema([
                        DateTimePicker::make('published_at')->label('From'),
                        DateTimePicker::make('unpublished_at')->label('Until'),
                    ])
                    ->action(fn (ProductPublication $record, array $data) => self::publish(
                        $product,
                        [...$data, 'channel_id' => $record->channel_id],
                    )),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('A publication that is live is closed with today\'s date rather than deleted, because when it stopped being on the site is the question asked afterwards.')
                    ->visible(fn (): bool => Gate::allows('publish', $product))
                    ->action(function (ProductPublication $record) use ($product): void {
                        app(UnpublishFromChannel::class)->handle($product, (int) $record->channel_id);

                        Notification::make()
                            ->title('Unpublished')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('channel_id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function publish(Product $product, array $data): void
    {
        try {
            app(PublishToChannel::class)->handle(
                $product,
                (int) $data['channel_id'],
                Instant::from($data['published_at'] ?? null),
                Instant::from($data['unpublished_at'] ?? null),
            );

            Notification::make()
                ->title('Published')
                ->success()
                ->send();
        } catch (InvalidStatusTransition $exception) {
            // Publishing a draft is allowed on purpose — a season is staged by
            // publishing everything and then flipping the products live. What
            // the domain refuses is an archived product, which is somebody
            // resurrecting a record through the back door.
            Notification::make()
                ->title('Not published')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function stateOf(ProductPublication $publication): string
    {
        if ($publication->isLive()) {
            return 'Live';
        }

        return $publication->published_at !== null && $publication->published_at > now()
            ? 'Scheduled'
            : 'Ended';
    }
}
