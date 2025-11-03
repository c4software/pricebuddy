<?php

use App\Settings\AppSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            $this->migrator->add('app.notification_text', AppSettings::DEFAULT_NOTIFICATION_TEXT);
        } catch (\Exception $e) {
            // Setting already exists, do nothing
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
