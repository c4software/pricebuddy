<?php

namespace App\Notifications\Channels;

use App\Enums\NotificationMethods;
use App\Models\User;
use App\Notifications\Messages\GenericNotificationMessage;
use App\Services\Helpers\NotificationsHelper;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

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
        $isLocal = isset($settings['token']) && $settings['token'] === 'local';
        $targetUrl = $settings['url'];
        $tag = $settings['tags'] ?? 'all';

        $result = self::sendRequest(
            $targetUrl,
            $message->title,
            $message->content,
            $tag,
            $isLocal
        );

        if ($isLocal) {
            if ($result['success'] === false) {
                throw new \RuntimeException('Erreur lors de l\'envoi de la notification locale Apprise : ' . $result['output']);
            }
        } else {
            $result->throw();
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

        return self::makeUrl($settings['url'], $settings['token']);
    }

    public static function getSettings($notifiable): array
    {
        $settings = NotificationsHelper::getSettings(NotificationMethods::Apprise);
        $userSettings = $notifiable->getNotificationSettings(NotificationMethods::Apprise);
        $settings['tags'] = empty($userSettings['tags']) ? 'all' : $userSettings['tags'];
        $settings['token'] = empty($userSettings['token']) ? ($settings['token'] ?? '') : $userSettings['token'];

        return $settings;
    }

    public static function makeUrl(string $apiUrl, string $token): string
    {
        return rtrim($apiUrl, '/').'/notify/'.$token;
    }

    public static function sendRequest(string $targetUrl, string $title, string $message, string $tag = 'all', bool $isLocal = false)
    {
        if ($isLocal) {
            // Utilisation du binaire local apprise
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
        // Appel HTTP classique
        return Http::post($targetUrl, [
            'title' => $title,
            'body' => $message,
            'tags' => $tag,
        ]);
    }
}
