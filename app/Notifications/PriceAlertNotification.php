<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\NotificationsHelper;
use App\Settings\AppSettings;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as DatabaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Pushover\PushoverMessage;

class PriceAlertNotification extends Notification
{
    // use Queueable;

    protected string $ctaText = 'View price history';

    protected ?Product $product;

    /**
     * Create a new notification instance.
     */
    public function __construct(protected Url $url)
    {
        $this->product = $url->product;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return NotificationsHelper::getEnabledChannels($notifiable)
            ->push('database')
            ->toArray();
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->getTitle())
            ->markdown('mail.price-change-notification', $this->toArray($notifiable));
    }

    public function toDatabase($notifiable): array
    {
        return DatabaseNotification::make()
            ->title($this->getTitle())
            ->body($this->getSummary())
            ->status('success')
            ->actions([
                Action::make('view')
                    ->url(parse_url($this->getUrl(), PHP_URL_PATH), false)
                    ->label('View product'),
                Action::make('buy')
                    ->url($this->url->buy_url, true)
                    ->label('Buy'),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->getTitle(),
            'summary' => $this->getSummary(),
            'buyUrl' => $this->url->buy_url,
            'buyText' => __('Buy now from :store', ['store' => $this->url->store_name]),
            'storeName' => $this->url->store_name,
            'productUrl' => $this->getUrl(),
            'productName' => Str::limit(($this->product->title ?? 'Unknown product'), 100),
            'imgUrl' => $this->product?->image,
            'newPrice' => $this->url->latest_price_formatted,
            'averagePrice' => data_get($this->product?->price_aggregates, 'avg', '$0.00'),
        ];
    }

    public function toPushover($notifiable)
    {
        return PushoverMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl(), $this->ctaText);
    }

    public function toGotify($notifiable)
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    public function toApprise($notifiable)
    {
        return GenericNotificationMessage::create($this->getSummary())
            ->title($this->getTitle())
            ->url($this->getUrl())
            ->priority(5);
    }

    protected function getTitle(): string
    {
        return $this->url->product_name;
    }

    protected function getSummary(): string
    {
        try {
            $minValue = $this->product?->getPriceCacheAggregate('min');
            $maxValue = $this->product?->getPriceCacheAggregate('max');
            
            $min = ($minValue && $minValue > 0) ? $minValue : null;
            $max = ($maxValue && $maxValue > 0) ? $maxValue : null;

            $prices = $this->url->prices()->orderByDesc('created_at')->take(2)->get();
            $hasChanges = $prices->count() > 1;

            $latestPrice = (float) ($prices->first()?->price ?? 0);
            $previousPriceValue = $prices->get(1)?->price;
            $previousPrice = is_null($previousPriceValue)
                ? null
                : CurrencyHelper::toDisplayString(
                    $previousPriceValue,
                    locale: $this->url->store?->locale,
                    iso: $this->url->store?->currency
                );

            $newPrice = CurrencyHelper::toDisplayString(
                $latestPrice,
                locale: $this->url->store?->locale,
                iso: $this->url->store?->currency
            );

            if (is_null($previousPriceValue)) {
                $evolution = "➖";
            } elseif ($latestPrice > (float) $previousPriceValue) {
                $evolution = "📈";
            } elseif ($latestPrice < (float) $previousPriceValue) {
                $evolution = "📉";
            } else {
                $evolution = "➖";
            }

            $url = $this->getUrl();
            $isLatestOutOfStock = CurrencyHelper::isOutOfStock($latestPrice);
            $wasPreviousOutOfStock = ! is_null($previousPriceValue) && CurrencyHelper::isOutOfStock($previousPriceValue);

            if ($hasChanges && $isLatestOutOfStock && ! $wasPreviousOutOfStock) {
                return __('notifications.product_unavailable');
            }

            if ($hasChanges && ! $isLatestOutOfStock && $wasPreviousOutOfStock) {
                return __('notifications.product_available_again', ['price' => $newPrice]);
            }

            // Get The notification text from settings
            $notificationTextTemplate = AppSettings::new()->notification_text;
            if (empty($notificationTextTemplate)) {
                $notificationTextTemplate = AppSettings::DEFAULT_NOTIFICATION_TEXT;
            }

            // If we have changes and both prices, show the change
            if ($hasChanges && $newPrice && $previousPrice) {
                // Replace placeholders in the template
                return str_replace(
                    ['{evolution}', '{previousPrice}', '{newPrice}', '{min}', '{max}', '{url}'],
                    [
                        $evolution,
                        $previousPrice,
                        $newPrice,
                        is_null($min) ? 'N/A' : CurrencyHelper::toDisplayString($min, locale: $this->url->store?->locale, iso: $this->url->store?->currency),
                        is_null($max) ? 'N/A' : CurrencyHelper::toDisplayString($max, locale: $this->url->store?->locale, iso: $this->url->store?->currency),
                        $url,
                    ],
                    $notificationTextTemplate
                );
            }

            // Fallback, should not really happen
            return "Price updated. {$url}";
        } catch (\Exception $e) {
            logger()->error('Error generating price alert notification summary: ' . $e->getMessage(), ['exception' => $e]);

            return "Price updated. {$this->getUrl()}";
        }
    }

    protected function getUrl(): string
    {
        return $this->url->buy_url;
    }
}
