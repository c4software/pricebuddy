<?php

namespace App\Filament\Pages;

use App\Enums\Icons;
use App\Enums\IntegratedServices;
use App\Enums\NotificationMethods;
use App\Filament\Actions\Notifications\TestAppriseAction;
use App\Filament\Actions\Notifications\TestNotificationContent;
use App\Filament\Traits\FormHelperTrait;
use App\Models\UrlResearch;
use App\Services\DatabaseBackupService;
use App\Services\Helpers\CurrencyHelper;
use App\Services\Helpers\LocaleHelper;
use App\Services\SearchService;
use App\Settings\AppSettings;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Once;
use Throwable;

class AppSettingsPage extends SettingsPage
{
    use FormHelperTrait;

    const NOTIFICATION_SERVICES_KEY = 'notification_services';

    const INTEGRATED_SERVICES_KEY = 'integrated_services';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $title = 'Settings';

    protected static ?string $navigationGroup = 'System';

    protected static string $settings = AppSettings::class;

    protected static ?int $navigationSort = 100;

    public function save(): void
    {
        parent::save();

        Cache::flush();
        Once::flush();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Scrape Settings')
                    ->description(__('Settings for scraping'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('scrape_cache_ttl')
                            ->label('Scrape cache ttl')
                            ->hintIcon(Icons::Help->value, 'After a page is scraped, how many minutes will be the page html be cached for')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('sleep_seconds_between_scrape')
                            ->label('Seconds to wait before fetching next page')
                            ->hintIcon(Icons::Help->value, 'It is recommended to wait a few seconds between fetching pages to prevent being blocked')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('max_attempts_to_scrape')
                            ->label('Max scrape attempts')
                            ->hintIcon(Icons::Help->value, 'How many times to attempt to scrape a page before giving up')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ]),

                Section::make('Locale')
                    ->description(__('Default region and locale settings'))
                    ->columns(2)
                    ->schema(self::getLocaleFormFields('default_locale_settings')),

                Section::make('Logging')
                    ->description(__('Settings for logging'))
                    ->columns(2)
                    ->schema([
                        Select::make('log_retention_days')
                            ->label('Log retention days')
                            ->options([
                                7 => '7 days',
                                14 => '14 days',
                                30 => '30 days',
                                90 => '90 days',
                                180 => '180 days',
                                365 => '365 days',
                            ])
                            ->hintIcon(Icons::Help->value, 'How many days to keep logs for')
                            ->required(),
                    ]),

                self::makeFormHeading('Notifications'),
                $this->getAppriseSettings(),
                $this->getNotificationTextSettings(),

                self::makeFormHeading('Integrations'),
                $this->getSearXngSettings(),

                self::makeFormHeading('Database'),
                $this->getDatabaseBackupSection(),
            ]);
    }

    protected function getNotificationTextSettings(): Section
    {
        return Section::make('Notification text')
            ->headerActions([
                // Test notification action
                TestNotificationContent::make()->setSettings(fn() => [
                    "notification_text" => data_get($this->form->getState(), 'notification_text'),
                    "apprise" => data_get($this->form->getState(), 'notification_services.apprise', [])
                ]),

                // Reset to default action
                Action::make('reset_notification_text')
                    ->label('Reset to default')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (callable $set) {
                        $set('notification_text', AppSettings::DEFAULT_NOTIFICATION_TEXT);
                    }),
            ])
            ->description(new HtmlString('Available variables : <code>{evolution}</code>, <code>{previousPrice}</code>, <code>{newPrice}</code>, <code>{min}</code>, <code>{max}</code>, <code>{url}</code>'))->schema([
                MarkdownEditor::make('notification_text')
                    ->disableAllToolbarButtons()
                    ->label('Notification text')
                    ->hintIcon(Icons::Help->value, 'The text that will be sent in the notification. See above for available variables')
            ]);
    }

    protected function getAppriseSettings(): Section
    {
        return self::makeSettingsSection(
            'Apprise',
            self::NOTIFICATION_SERVICES_KEY,
            NotificationMethods::Apprise->value,
            [
                TextInput::make('url')
                    ->label('Apprise service URL')
                    ->placeholder('Example : tgram://bottoken/ChatID')
                    ->helperText(str('You can use any of the [supported services](https://github.com/caronc/apprise) with Apprise. Just enter the full URL here.')->inlineMarkdown()->toHtmlString())
                    ->suffixAction(
                        TestAppriseAction::make()
                            ->setSettings(fn() => data_get($this->form->getState(), 'notification_services.apprise', [])),
                    )
                    ->required(),
            ],
            __('Push notifications via Apprise')
        );
    }

    protected function getDatabaseBackupSection(): Section
    {
        return Section::make('Database backup')
            ->description(__('Export or import your products and their price history.'))
            ->headerActions([
                Action::make('export_database')
                    ->label(__('Export'))
                    ->icon(Icons::Database->value)
                    ->color('gray')
                    ->action(function () {
                        $json = app(DatabaseBackupService::class)->export();
                        $fileName = 'pricebuddy-backup-'.now()->format('Y-m-d_H-i-s').'.json';

                        return response()->streamDownload(
                            fn () => print($json),
                            $fileName,
                            ['Content-Type' => 'application/json']
                        );
                    }),
                Action::make('import_database')
                    ->label(__('Import'))
                    ->icon(Icons::Import->value)
                    ->color('gray')
                    ->modalHeading(__('Import database backup'))
                    ->modalSubmitActionLabel(__('Import'))
                    ->form([
                        FileUpload::make('backup')
                            ->label(__('Backup file'))
                            ->disk('local')
                            ->directory('imports/backups')
                            ->acceptedFileTypes(['application/json', 'text/json'])
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $path = data_get($data, 'backup');

                        if (! $path || ! Storage::disk('local')->exists($path)) {
                            return;
                        }

                        $storage = Storage::disk('local');
                        $contents = $storage->get($path);
                        $storage->delete($path);

                        try {
                            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                            app(DatabaseBackupService::class)->import($payload, auth()->user());
                            Cache::flush();
                            Once::flush();

                            Notification::make()
                                ->title('Backup imported successfully')
                                ->success()
                                ->send();

                            redirect("/");
                        } catch (Throwable $exception) {
                            report($exception);
                            // Make notification failure
                            Notification::make()
                                ->title('Unable to import backup')
                                ->body('The backup file is invalid or could not be imported.')
                                ->danger()
                                ->send();
                        }
                    })
                    ->successNotificationTitle(__('Backup imported'))
                    ->failureNotificationTitle(__('Unable to import backup')),
            ])
            ->schema([
                Placeholder::make('database_backup_description')
                    ->content(__('Use the actions above to import or export your data.'))
                    ->columnSpanFull(),
            ]);
    }

    protected function getSearXngSettings(): Section
    {
        return self::makeSettingsSection(
            'SearXng',
            self::INTEGRATED_SERVICES_KEY,
            IntegratedServices::SearXng->value,
            [
                TextInput::make('url')
                    ->label('SearXng url')
                    ->placeholder('https://searxng.homelab.com/search')
                    ->hintIcon(Icons::Help->value, __('Url of your SearXng instance, including the search path'))
                    ->required(),
                TextInput::make('search_prefix')
                    ->label('Search prefix')
                    ->placeholder('Buy')
                    ->hintIcon(Icons::Help->value, __('Text to prepend to the product name when searching'))
                    ->nullable(),
                Select::make('prune_days')
                    ->label('Cache duration')
                    ->required()
                    ->hintIcon(Icons::Help->value, __('How long to keep the parsed search results in the cache'))
                    ->options([
                        1 => '1 day',
                        7 => '7 days',
                        14 => '14 days',
                        30 => '30 days',
                        90 => '90 days',
                        180 => '180 days',
                        365 => '365 days',
                    ])
                    ->default(UrlResearch::DEFAULT_PRUNE_DAYS),
                Select::make('max_pages')
                    ->label('How many pages of results to fetch')
                    ->required()
                    ->hintIcon(Icons::Help->value, __('The more pages you fetch, the longer it will take to search'))
                    ->options(options: [
                        1 => '1 page',
                        2 => '2 pages',
                        3 => '3 pages',
                        4 => '4 pages',
                        5 => '5 pages',
                        10 => '10 pages',
                        20 => '20 pages',
                        50 => '50 pages',
                        100 => '100 pages',
                    ])
                    ->default(SearchService::DEFAULT_MAX_PAGES),
            ],
            new HtmlString('Automatically search for additional products urls via <a href="https://searxng.org/" target="_blank">SearXng</a>')
        );
    }

    public static function getLocaleFormFields(string $settingsKey, bool $required = true): array
    {
        return [
            Select::make($settingsKey . '.locale')
                ->label('Locale')
                ->searchable()
                ->options(LocaleHelper::getAllLocalesAsOptions())
                ->hintIcon(Icons::Help->value, 'Primarily used when extracting and displaying prices. Help translate this app on GitHub')
                ->required($required)
                ->default(CurrencyHelper::getLocale()),
            Select::make($settingsKey . '.currency')
                ->label('Currency')
                ->searchable()
                ->options(LocaleHelper::getAllCurrencyLocalesAsOptions())
                ->hintIcon(Icons::Help->value, 'Default currency for extracting and displaying prices')
                ->required($required)
                ->default(CurrencyHelper::getCurrency()),
        ];
    }
}
