<?php

use App\Enums\IntegratedServices;
use App\Models\UrlResearch;
use App\Services\Helpers\IntegrationHelper;
use App\Services\SearchService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $settings = IntegrationHelper::getSettings();

        data_set($settings, IntegratedServices::SearXng->value.'.prune_days', UrlResearch::DEFAULT_PRUNE_DAYS);
        data_set($settings, IntegratedServices::SearXng->value.'.max_pages', SearchService::DEFAULT_MAX_PAGES);

        IntegrationHelper::setSettings($settings);
    }
};
