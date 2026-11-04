<?php

declare(strict_types=1);

namespace Plugins\Auth\Guest;

use Pmsrapi\V2\Cache\QueryCache;
use Pmsrapi\V2\Cluster\ServiceClient;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Support\Logger;

// Public push settings for the guest session config, owned by notifications.ms
// (notifications_config): {app_id} or null. Cached for an hour, so a page load
// doesn't cost a call. If notifications.ms is unreachable the frontend just gets
// no `onesignal` block (and skips push setup); sign-in keeps working, and the
// failure isn't cached.
final class PushConfig
{
    private const string FUNCTION = 'notifications_config';
    private const int TTL_SECONDS = 3600;

    public function __construct(
        private readonly ServiceClient $services,
        private readonly QueryCache $cache,
        private readonly Logger $logger,
    ) {}

    public function onesignal(): ?array
    {
        try {
            $settings = $this->cache->remember(
                'notifications_config',
                ['public'],
                fn(): array => $this->services->call(self::FUNCTION),
                self::TTL_SECONDS,
            );
        } catch (ServiceException $e) {
            $this->logger->warning('Push config unavailable', ['error' => $e->getMessage()]);

            return null;
        }

        $appId = is_array($settings) ? ($settings['onesignal']['app_id'] ?? null) : null;

        return is_string($appId) && $appId !== '' ? ['app_id' => $appId] : null;
    }
}
