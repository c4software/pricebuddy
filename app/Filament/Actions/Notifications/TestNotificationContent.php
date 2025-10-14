<?php

namespace App\Filament\Actions\Notifications;

use App\Notifications\Channels\AppriseChannel;
use Closure;
use Exception;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;

class TestNotificationContent extends Action
{
    public Closure $settingsCallback;

    public static function getDefaultName(): ?string
    {
        return 'testNotificationContent';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Test'));

        $this->successNotificationTitle(__('Test notification sent successfully'));

        $this->failureNotificationTitle(__('Error'));

        $this->icon('heroicon-m-bell');

        $this->color('gray');

        $this->action(fn () => $this->testSendingNotification());
    }

    public function setSettings(Closure $settingsCallback): self
    {
        $this->settingsCallback = $settingsCallback;

        return $this;
    }

    protected function testSendingNotification(): void
    {
        $settings = call_user_func($this->settingsCallback);
        $baseUrl = data_get($settings["apprise"], 'url');
        $text = $settings["notification_text"];

        if (empty($baseUrl)) {
            Notification::make()
                ->title('Apprise URL is not configured')
                ->body('Please configure the Apprise URL in settings before testing the notification.')
                ->danger()
                ->send();

            return;
        }

        try {
            $response = AppriseChannel::sendRequest(
                AppriseChannel::makeUrl($baseUrl),
                'Test PriceBuddy notification',
                $text,
                url('/')
            );
            $this->success();
        } catch (Exception $e) {
            Notification::make()
                ->title('Failed to send test notification')
                ->body('Error: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
