<?php

declare(strict_types=1);

namespace Plugins\Notifications;

use Plugins\Notifications\Webhooks\MailgunSignature;
use Plugins\Notifications\Webhooks\MailgunWebhookHandler;
use Plugins\Notifications\Webhooks\OneSignalWebhookHandler;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Support\Logger;

// Provider webhooks for notifications.ms. No routes: the providers call the
// plugin's hooks/mailgun.php and hooks/onesignal.php directly (no Bearer token).
final class NotificationsPlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            MailgunSignature::class,
            static fn(Container $c): MailgunSignature => new MailgunSignature(
                $c->get(RedisClient::class),
                $c->get(Logger::class),
            ),
        );

        $registrar->singleton(
            MailgunWebhookHandler::class,
            static fn(Container $c): MailgunWebhookHandler => new MailgunWebhookHandler(
                $c->get(Config::class),
                $c->get(MailgunSignature::class),
                $c->get(ServiceClient::class),
                $c->get(RateLimiter::class),
                $c->get(Logger::class),
            ),
        );

        $registrar->singleton(
            OneSignalWebhookHandler::class,
            static fn(Container $c): OneSignalWebhookHandler => new OneSignalWebhookHandler(
                $c->get(Config::class),
                $c->get(ServiceClient::class),
                $c->get(RateLimiter::class),
                $c->get(Logger::class),
            ),
        );
    }
}
