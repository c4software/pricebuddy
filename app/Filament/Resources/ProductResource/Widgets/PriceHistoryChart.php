<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\Product;
use App\Models\Url;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @property Product $record
 */
class PriceHistoryChart extends ChartWidget
{
    const CHART_COLORS = [
        AdminPanelProvider::PRIMARY_COLOR,
        Color::Pink,
        Color::Yellow,
        Color::Purple,
        Color::Emerald,
        Color::Violet,
        Color::Sky,
        Color::Amber,
        Color::Blue,
    ];

    public Model|Product|null $record = null;

    protected static ?string $maxHeight = '300px';

    protected static ?string $heading = 'Store price history';

    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $history = $this->record->getPriceHistoryPerHour();
        $datasets = [];

        $urls = Url::findMany($history->keys())->values();

        foreach ($urls as $idx => $url) {
            $datasets[] = [
                'label' => $url->store?->name,
                'data' => $history->get($url->id),
                'backgroundColor' => 'rgba('.$this->getDatasetColor($idx).', 0.4)',
                'borderColor' => 'rgba('.$this->getDatasetColor($idx).', 0.9)',
                'fill' => true,
                'tension' => 0.2,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $this->getLabels($history),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getLabels(Collection $history): array
    {
        return $history->map(fn ($prices) => $prices->keys())
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    protected function getDatasetColor(int $idx)
    {
        if (isset(self::CHART_COLORS[$idx])) {
            return self::CHART_COLORS[$idx][500];
        } else {
            return Color::Gray[500];
        }
    }
}
