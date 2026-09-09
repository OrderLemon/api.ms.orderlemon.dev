<?php

declare(strict_types=1);

namespace Plugins\Whatsapp\Controllers;

use Plugins\Whatsapp\Support\WhatsappAiClient;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Support\Logger;

/**
 * Inbound WhatsApp receiver — a pure pass-through. 
 *
 * This service IS the "client webhook" the WhatsApp gateway
 * (api.wa.fabulor.io) forwards to: the gateway's send_payload_to_client()
 * POSTs an { "a": "incoming", ... } envelope to whatever URL is stored in that
 * account's `accounts.webhook` column, which for this project is this repo's
 *
 *   POST /v2/whatsapp
 *
 * This service has no database, no local state, and resolves nothing itself —
 * no shop lookup, no client upsert, no conversation tracking, no transcript,
 * no audio transcription. It does the bare minimum HTTP-level validation to
 * fail fast on a malformed envelope, then hands the whole body to the "AI +
 * gateway" microservice via {@see WhatsappAiClient} and relays back whatever
 * that service reports. All of the actual business logic — shop/client
 * resolution, Marvin, sending, transcript logging — lives there now.
 */
final class WhatsappController
{
    public function __construct(
        private readonly WhatsappAiClient $ai,
        private readonly Logger $logger,
    ) {}

    /**
     * POST /v2/whatsapp
     *
     * Body (from the gateway's send_payload_to_client envelope):
     *   { "a": "incoming", "phonenumber": "<account>", "sender_phone": "<user>",
     *     "message_type": "text|image|...", "data": { ... }, ... }
     */
    public function receive(Request $request): Response
    {
        $body = $request->body;

        $errors = $this->validateEnvelope($body);
        if ($errors !== []) {
            $this->logger->error('whatsapp: inbound message errors', $errors);
            throw new ValidationException($errors);
        }

        $this->logger->info('whatsapp: inbound message received', [
            'account' => $body['phonenumber'] ?? null,
            'sender' => $body['sender_phone'] ?? null,
            'message_type' => $body['message_type'] ?? null,
            'message_id' => $body['message_id'] ?? null,
            'provider' => $body['data_provider'] ?? null,
        ]);

        $response = $this->ai->handleInbound($body);

        return Response::ok($response);
    }

    /**
     * Bare-minimum shape check — enough to reject an obviously malformed
     * envelope before spending a network hop on it. Anything about the
     * shop/client/message content itself is the AI + gateway service's job.
     *
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function validateEnvelope(array $body): array
    {
        $errors = [];

        if (trim((string) ($body['a'] ?? '')) !== 'incoming') {
            $errors['a'] = 'Only the "incoming" action is handled here';
        }

        if (trim((string) ($body['sender_phone'] ?? '')) === '') {
            $errors['sender_phone'] = 'The sender phonenumber was not received!';
        }

        if (trim((string) ($body['phonenumber'] ?? '')) === '') {
            $errors['phonenumber'] = 'The shop phonenumber was not received!';
        }

        return $errors;
    }
}
