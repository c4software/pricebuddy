@php
    use App\Services\Helpers\CurrencyHelper;
    $product = $product ?? $getRecord();
    $latestPrice = $product->getPriceCache()->first();
    $isOutOfStock = $latestPrice && CurrencyHelper::isOutOfStock($latestPrice->getPrice());
@endphp
@if (! $product->is_last_scrape_successful || $product->is_notified_price || $isOutOfStock)
    <div {{ $attributes }}>
        @if ($isOutOfStock)
            <div class="mt-1">
                @include('components.icon-badge', [
                    'hoverText' => __('product.out_of_stock'),
                    'label' => __('product.out_of_stock'),
                    'color' => 'warning',
                    'icon' => 'heroicon-m-archive-box-x-mark'
                ])
            </div>
        @endif

        @if (! $product->is_last_scrape_successful)
            <div class="mt-1">
                @include('components.icon-badge', [
                    'hoverText' => __('One or more urls failed last scrape'),
                    'label' => __('Scrape error'),
                    'color' => 'warning',
                ])
            </div>
        @endif

        @if ($product->is_notified_price)
            <div class="mt-1">
                @include('components.icon-badge', [
                'hoverText' => __('Price matches your target'),
                'label' => __('Notify match'),
                'color' => 'success',
                'icon' => 'heroicon-m-shopping-bag'
            ])
            </div>
        @endif
    </div>
@endif
