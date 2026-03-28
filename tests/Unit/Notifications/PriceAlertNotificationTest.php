<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_gotify_formats_message_correctly()
    {
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            99.99
        );

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toGotify($user);

        $this->assertInstanceOf(GenericNotificationMessage::class, $gotifyMessage);
        $this->assertEquals('Price drop: Test Product ($99.99)', $gotifyMessage->title);
        $this->assertStringContainsString('Test Store has had a price drop for Test Product - $99.99', $gotifyMessage->content);
        $this->assertEquals(5, $gotifyMessage->priority); // Default priority set in the notification
        $this->assertEquals('https://example.com/product', $gotifyMessage->url);
    }

    public function test_to_apprise_formats_message_correctly()
    {
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            99.99
        );

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toApprise($user);

        $this->assertInstanceOf(GenericNotificationMessage::class, $gotifyMessage);
        $this->assertEquals('Price drop: Test Product ($99.99)', $gotifyMessage->title);
        $this->assertStringContainsString('Test Store has had a price drop for Test Product - $99.99', $gotifyMessage->content);
        $this->assertEquals('https://example.com/product', $gotifyMessage->url);
    }


    public function test_notification_summary_for_unavailable_product_state()
    {
        app()->setLocale('fr');
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            99.99
        );

        $url->prices()->create([
            'price' => -1,
            'store_id' => $store->id,
            'created_at' => now()->addMinute(),
        ]);

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toGotify($user);

        $this->assertSame(__('notifications.product_unavailable'), $gotifyMessage->content);
    }

    public function test_notification_summary_for_available_again_state()
    {
        app()->setLocale('fr');
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            -1
        );

        $url->prices()->create([
            'price' => 89.99,
            'store_id' => $store->id,
            'created_at' => now()->addMinute(),
        ]);

        $notification = new PriceAlertNotification($url);
        $gotifyMessage = $notification->toGotify($user);

        $this->assertStringContainsString('Produit à nouveau disponible +', $gotifyMessage->content);
        $this->assertStringContainsString('$89.99', $gotifyMessage->content);
    }

    public function test_notification_displays_correct_min_max_with_empty_history()
    {
        $user = User::factory()->create();

        [$store, $product, $url] = $this->createStoreProductAndPrice(
            'Test Store',
            'Test Product',
            $user->getKey(),
            50.00
        );

        // Simulate empty history by setting price_cache with empty history arrays
        $product->update([
            'price_cache' => [
                [
                    'price' => 50.00,
                    'history' => [],
                    'store_name' => 'Test Store',
                    'store_id' => $store->id,
                    'url_id' => $url->id,
                ],
            ],
        ]);

        // Create a second price to trigger notification
        $url->prices()->create([
            'price' => 45.00,
            'store_id' => $store->id,
            'created_at' => now()->addMinute(),
        ]);

        $notification = new PriceAlertNotification($url);
        $summary = $notification->toArray($user);

        // Verify that min/max are not displayed as $0.00
        $gotifyMessage = $notification->toGotify($user);
        $this->assertStringNotContainsString('$0.00', $gotifyMessage->content);

        // The content should contain either valid prices or N/A, but not $0.00
        $this->assertMatchesRegularExpression('/\$\d+\.\d{2}/', $gotifyMessage->content);
    }

    protected function createStoreProductAndPrice(string $storeName, string $productTitle, int $userId, float $price): array
    {
        $store = Store::factory()->create([
            'name' => $storeName,
            'settings' => [
                'locale_settings' => [
                    'locale' => 'en_US',
                    'currency' => 'USD',
                ],
            ],
        ]);

        $product = Product::factory()->create(['title' => $productTitle, 'user_id' => $userId]);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.com/product',
        ]);

        // Create associated price
        $url->prices()->create([
            'price' => $price,
            'store_id' => $store->id,
        ]);

        return [$store, $product, $url];
    }
}
