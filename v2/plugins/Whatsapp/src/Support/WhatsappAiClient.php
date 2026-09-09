<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Support;

use Pmsrapi\V2\Cluster\ServiceClient;

/**
 * Client for the "AI + gateway" microservice (Service B in ../../SPLIT_PLAN.md).
 *
 * This service is a pure pass-through: it has no database, no local state, and
 * resolves nothing itself (no shop lookup, no client upsert, no transcript). It
 * forwards the inbound WhatsApp webhook envelope verbatim and relays back
 * whatever Service B reports — Service B owns shop/client/conversation
 * resolution, the AI reply, sending, and transcript logging entirely.
 *
 * Requires a `whatsapp_inbound` entry in this service's secret config
 * `function_map`, resolved to Service B's universe node.
 */
final class WhatsappAiClient
{
    public function __construct(
        private readonly ServiceClient $client,
    ) {}

    /**
     * @param array<string, mixed> $envelope the raw inbound webhook body
     * @return array<string, mixed> whatever Service B reports back
     */
    public function handleInbound(array $envelope): array
    {
        return $this->client->call('whatsapp_inbound', [], $envelope);
    }
}
