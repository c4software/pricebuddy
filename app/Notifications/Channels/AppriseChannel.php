<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Notifications\Notification;

class AppriseChannel
{
    /**
     * @param  User  $notifiable
     */
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toApprise')) {
            return;
        }

        $message = $notification->toApprise($notifiable);

        if (! $message instanceof GenericNotificationMessage) {
            return;
        }

        $settings = self::getSettings($notifiable);
        $tag = $settings['tags'] ?? 'all';

        $result = self::sendRequest(
            $settings['url'],
            $message->title,
            $message->content,
            $tag
        );

        if ($result['success'] === false) {
            throw new \RuntimeException('Erreur lors de l\'envoi de la notification locale Apprise : ' . $result['output']);
        }
    }

    /**
     * Get the URL for the notifiable.
     *
     * @param  User  $notifiable
     */
    protected function getUrl($notifiable): string
    {
        $settings = self::getSettings($notifiable);

        return self::makeUrl($settings['url']);
    }

    public static function getSettings($notifiable): array
    {
        $settings = NotificationsHelper::getSettings(NotificationMethods::Apprise);
        $userSettings = $notifiable->getNotificationSettings(NotificationMethods::Apprise);
        $settings['tags'] = empty($userSettings['tags']) ? 'all' : $userSettings['tags'];

        return $settings;
    }

    public static function makeUrl(string $apiUrl): string
    {
        return rtrim($apiUrl, '/');
    }

    public static function sendRequest(string $targetUrl, string $title, string $message, string $tag = 'all')
    {
        // Utilisation du binaire local apprise uniquement
        $cmd = sprintf(
            'apprise %s -t %s -b %s -g %s',
            escapeshellarg($targetUrl),
            escapeshellarg($title),
            escapeshellarg($message),
            escapeshellarg($tag)
        );
        exec($cmd . ' 2>&1', $output, $returnVar);
        return [
            'success' => $returnVar === 0,
            'output' => implode("\n", $output),
        ];
    }
}
