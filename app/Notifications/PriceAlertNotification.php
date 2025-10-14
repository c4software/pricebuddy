<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\NotificationsHelper;
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
        $min = data_get($this->product?->price_aggregates, 'min', null);
        $max = data_get($this->product?->price_aggregates, 'max', null);

        $hasChanges = $this->url->prices()->count() > 1;
        $newPrice = $this->url->latest_price_formatted;
        $previousPrice = $this->url->previous_price_formatted;
        $evolution = "";
        if ($newPrice > $previousPrice) {
            $evolution = "📈";
        } elseif ($newPrice < $previousPrice) {
            $evolution = "📉";
        } else {
            $evolution = "➖";
        }

        $url = $this->getUrl();

        // If we have changes and both prices, show the change
        if ($hasChanges && $newPrice && $previousPrice) {
            $text = <<<EOT
{$evolution} price changed from *{$previousPrice}* to *{$newPrice}*.


Min: {$min} Max: {$max}.


{$url}
EOT;
        } elseif ($newPrice) {
            // New price, but no previous price (Case when tracking a new URL)
            $text = <<<EOT
Tracking new price {$newPrice}.

{$url}
EOT;

        } else {
            // Fallback, should not really happen
            $text = <<<EOT
Price updated.

{$url}
EOT;
        }

        return $text;
    }

    protected function getUrl(): string
    {
        return $this->url->buy_url;
    }
}
