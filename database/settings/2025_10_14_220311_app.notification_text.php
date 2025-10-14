<?php

use App\Filament\Pages\AppSettingsPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrator->add('app.notification_text', AppSettingsPage::DEFAULT_NOTIFICATION_TEXT);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->migrator->delete('app.notification_text');
    }
};
