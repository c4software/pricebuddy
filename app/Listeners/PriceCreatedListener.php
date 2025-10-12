<?php

namespace App\Listeners;

use App\Events\PriceCreatedEvent;
use App\Notifications\PriceAlertNotification;
use Exception;

class PriceCreatedListener
{
    public function __construct() {}

    public function handle(PriceCreatedEvent $event): void
    {
        // Need a product proceed.
        if (! $product = $event->price->product) {
            return;
        }

        // Need a url proceed.
        if (! $url = $event->price->url) {
            return;
        }

        try {
            // Notify of not already notified and should notify.
            if (!$event->price->notified) {
                $product->user?->notify(new PriceAlertNotification($url));
                $event->price->update(['notified' => true]);
            }
        } catch (Exception $e) {
            // Log the error.
            logger()->error('Error sending price alert notification: ' . $e->getMessage(), [
                'product' => $product->title,
                'product_id' => $product->getKey(),
                'url' => $event->price->url,
                'url_id' => $event->price->getKey(),
            ]);
        }
    }
}
