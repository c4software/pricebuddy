<?php

namespace App\Services;

use App\Actions\CreateStoreAction;
use App\Models\Price;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DatabaseBackupService
{
    private const EXPORT_VERSION = 1;

    /**
     * @var array<int, string>
     */
    protected array $productColumns;

    /**
     * @var array<int, string>
     */
    protected array $urlColumns;

    /**
     * @var array<int, string>
     */
    protected array $priceColumns;

    /**
     * @var array<int, string>
     */
    protected array $storeColumns;

    public function __construct()
    {
        $this->productColumns = $this->resolveColumns('products', ['id', 'price_cache', 'current_price', 'user_id']);
        $this->urlColumns = $this->resolveColumns('urls', ['id', 'product_id', 'store_id']);
        $this->priceColumns = $this->resolveColumns('prices', ['id', 'url_id', 'store_id']);
        $this->storeColumns = $this->resolveColumns('stores', ['id', 'user_id']);
    }

    public function export(): string
    {
        $products = Product::query()
            ->with([
                'user',
                'urls' => fn ($query) => $query->orderBy('id'),
                'urls.store',
                'urls.prices' => fn ($query) => $query->orderBy('created_at'),
            ])
            ->orderBy('id')
            ->get();

        $payload = [
            'version' => self::EXPORT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'products' => $products->map(function (Product $product) {
                return [
                    'product' => Arr::only($product->toArray(), $this->productColumns),
                    'user' => $product->user ? ['email' => $product->user->email] : null,
                    'urls' => $product->urls->map(function (Url $url) {
                        return [
                            'url' => Arr::only($url->toArray(), $this->urlColumns),
                            'store' => $url->store
                                ? Arr::only($url->store->toArray(), array_merge($this->storeColumns, ['slug']))
                                : null,
                            'prices' => $url->prices->map(
                                fn (Price $price) => Arr::only($price->toArray(), $this->priceColumns)
                            )->values(),
                        ];
                    })->values(),
                ];
            })->values(),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT);
    }

    public function import(array $payload, ?User $defaultUser = null): void
    {
        $products = data_get($payload, 'products');

        if (! is_array($products)) {
            throw new InvalidArgumentException('Invalid backup payload: missing products.');
        }

        DB::transaction(function () use ($products, $defaultUser) {
            foreach ($products as $productData) {
                $productAttributes = data_get($productData, 'product');

                if (! is_array($productAttributes)) {
                    continue;
                }

                $user = $this->resolveUser(data_get($productData, 'user'), $defaultUser);

                if (! $user) {
                    throw new InvalidArgumentException('Unable to resolve user for product import.');
                }

                $product = $this->createProduct($productAttributes, $user);

                foreach (data_get($productData, 'urls', []) as $urlData) {
                    $store = $this->resolveStore(data_get($urlData, 'store', []), $user);

                    if (! $store) {
                        continue;
                    }

                    $url = $this->createUrl($product, data_get($urlData, 'url', []), $store);

                    $this->createPrices($url, $store, data_get($urlData, 'prices', []));
                }

                $product->updatePriceCache();
            }
        });
    }

    protected function resolveColumns(string $table, array $except = []): array
    {
        return collect(Schema::getColumnListing($table))
            ->diff($except)
            ->values()
            ->all();
    }

    protected function resolveUser(mixed $userData, ?User $defaultUser = null): ?User
    {
        if (is_array($userData) && $email = data_get($userData, 'email')) {
            if ($user = User::query()->where('email', $email)->first()) {
                return $user;
            }
        }

        if ($defaultUser) {
            return $defaultUser;
        }

        return User::query()->first();
    }

    protected function createProduct(array $attributes, User $user): Product
    {
        $attributes = Arr::only($attributes, $this->productColumns);
        $attributes['user_id'] = $user->getKey();

        return $this->makeModel(Product::class, $attributes);
    }

    protected function resolveStore(mixed $storeData, User $user): ?Store
    {
        if (! is_array($storeData)) {
            return null;
        }

        $store = null;
        $slug = data_get($storeData, 'slug');
        $name = data_get($storeData, 'name');

        if ($slug) {
            $store = Store::query()->where('slug', $slug)->first();
        }

        if (! $store && $name) {
            $store = Store::query()->where('name', $name)->first();
        }

        $attributes = Arr::only($storeData, ['name', 'initials', 'domains', 'scrape_strategy', 'settings', 'notes']);

        if ($store) {
            $store->fill($attributes);
            $store->user()->associate($user);
            $this->applyTimestamps($store, $storeData);
            $store->save();

            return $store;
        }

        if (empty($name)) {
            return null;
        }

        $store = (new CreateStoreAction)($attributes);

        if (! $store) {
            return null;
        }

        $store->user()->associate($user);

        if ($slug) {
            $store->forceFill(['slug' => $slug]);
        }

        $this->applyTimestamps($store, $storeData);
        $store->save();

        return $store;
    }

    protected function createUrl(Product $product, mixed $attributes, Store $store): ?Url
    {
        if (! is_array($attributes) || empty($attributes['url'])) {
            return null;
        }

        $attributes = Arr::only($attributes, $this->urlColumns);
        $attributes['product_id'] = $product->getKey();
        $attributes['store_id'] = $store->getKey();

        return $this->makeModel(Url::class, $attributes);
    }

    protected function createPrices(?Url $url, Store $store, mixed $prices): void
    {
        if (! $url || ! is_array($prices)) {
            return;
        }

        Price::withoutEvents(function () use ($prices, $url, $store) {
            foreach ($prices as $priceData) {
                if (! is_array($priceData) || ! isset($priceData['price'])) {
                    continue;
                }

                $attributes = Arr::only($priceData, $this->priceColumns);
                $attributes['url_id'] = $url->getKey();
                $attributes['store_id'] = $store->getKey();

                $this->makeModel(Price::class, $attributes);
            }
        });
    }

    protected function makeModel(string $modelClass, array $attributes): Model
    {
        /** @var Model $model */
        $model = new $modelClass();
        $timestamps = Arr::only($attributes, ['created_at', 'updated_at']);
        $model->fill(Arr::except($attributes, ['created_at', 'updated_at']));

        foreach ($timestamps as $key => $value) {
            if (! is_null($value)) {
                $model->{$key} = $value;
            }
        }

        $model->timestamps = false;
        $model->save();

        return $model;
    }

    protected function applyTimestamps(Model $model, array $attributes): void
    {
        foreach (['created_at', 'updated_at'] as $column) {
            $value = data_get($attributes, $column);

            if (! is_null($value)) {
                $model->{$column} = $value;
            }
        }

        $model->timestamps = false;
    }
}
