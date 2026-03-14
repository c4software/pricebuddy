<?php

namespace App\Services\Helpers;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Parser\IntlLocalizedDecimalParser;
use NumberFormatter;
use Symfony\Component\Intl\Currencies;

/**
 * Helpers to make dealing with currencies easier.
 */
class CurrencyHelper
{
    public const float OUT_OF_STOCK_PRICE = -1.0;

    public const string OUT_OF_STOCK_TRANSLATION_KEY = 'product.out_of_stock';

    public static function getLocale(): string
    {
        return SettingsHelper::getSetting(
            'default_locale_settings.locale',
            config('app.locale', 'en')
        );
    }

    public static function getCurrency(): string
    {
        return SettingsHelper::getSetting('default_locale_settings.currency', 'USD');
    }

    public static function getCurrencyFromLocale(string $locale): ?array
    {
        return once(fn () => self::getAllCurrencies()
            ->firstWhere('locale', $locale)
        );
    }

    public static function getAllCurrencies(): Collection
    {
        return collect(json_decode(
            file_get_contents(base_path('/resources/datasets/currency.json')), true)
        )
            // Normalize the locale to use underscores instead of dashes and ensure not empty.
            ->map(fn ($currency) => array_merge($currency, [
                'locale' => empty($currency['locale'])
                    ? 'none'
                    : str_replace('-', '_', $currency['locale']),
            ]));
    }

    public static function getSymbol(?string $iso = null): string
    {
        return Currencies::getSymbol($iso ?? self::getCurrency());
    }

    public static function toFloat(mixed $value, ?string $locale = null, ?string $iso = null): float
    {
        try {
            $cleaned = preg_replace('/[^0-9,.-]/', '', trim($value));

            $lastComma = strrpos($cleaned, ',');
            $lastDot = strrpos($cleaned, '.');

            if ($lastComma !== false && $lastDot !== false) {
                if ($lastComma > $lastDot) {
                    $cleaned = str_replace('.', '', $cleaned);
                    $cleaned = str_replace(',', '.', $cleaned);
                } else {
                    $cleaned = str_replace(',', '', $cleaned);
                }
            } elseif ($lastComma !== false) {
                $parts = explode(',', $cleaned);
                $lastPart = end($parts);

                if (strlen($lastPart) == 2) {
                    $cleaned = str_replace(',', '.', $cleaned);
                } else {
                    $cleaned = str_replace(',', '', $cleaned);
                }
            }

            return (float)$cleaned;
        } catch (Exception $e) {
            Logger()->error('Currency to float conversion error: ' . $e->getMessage(), [
                'value' => $value,
                'exception' => $e,
            ]);

            return 0.0;
        }
    }


    public static function toString(mixed $value, int $maxPrecision = 2, ?string $locale = null, ?string $iso = null): string
    {
        return Number::currency(
            number: round(floatval($value), $maxPrecision),
            in: ($iso ?? self::getCurrency()),
            locale: ($locale ?? self::getLocale())
        );
    }

    public static function isOutOfStock(mixed $value): bool
    {
        return round((float) $value, 2) === self::OUT_OF_STOCK_PRICE;
    }

    public static function getOutOfStockLabel(): string
    {
        return __(self::OUT_OF_STOCK_TRANSLATION_KEY);
    }

    public static function toDisplayString(mixed $value, int $maxPrecision = 2, ?string $locale = null, ?string $iso = null): string
    {
        if (self::isOutOfStock($value)) {
            return self::getOutOfStockLabel();
        }

        return self::toString($value, $maxPrecision, $locale, $iso);
    }
}
