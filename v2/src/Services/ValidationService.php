<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Services;

/**
 * Field-shape validation for a request body. Each rule call accumulates two
 * messages per field on the service itself: a technical one (raw column
 * name, precise reason — for logs only) and a friendly one (a human label,
 * no internal detail — for the client-facing response).
 *
 * Registered as a shared singleton (see bootstrap.php) — safe because the
 * whole container is rebuilt fresh on every HTTP request here, so there is
 * never a second request to leak into. Even so, always call reset() right
 * after reading hasErrors()/technicalErrors()/friendlyErrors(), so reuse
 * within the same request (or from a future persistent worker) never sees
 * stale state from a previous check.
 *
 * Usage:
 *   public function __construct(private readonly ValidationService $validator) {}
 *
 *   $this->validator->required($body, 'name', 'Name');
 *   $this->validator->numeric($body, 'unit_price', 'Price', required: true);
 *
 *   $hasErrors = $this->validator->hasErrors();
 *   $technical = $this->validator->technicalErrors();
 *   $friendly = $this->validator->friendlyErrors();
 *   $this->validator->reset();
 *
 *   if ($hasErrors) {
 *       throw new ValidationException($friendly);
 *       // log $technical separately
 *   }
 */
final class ValidationService
{
    /** @var array<string, string> */
    private array $technical = [];

    /** @var array<string, string> */
    private array $friendly = [];

    /** Field must be present and, if a string, non-empty after trimming. */
    public function required(array $body, string $field, string $label): void
    {
        $value = $body[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->addError($field, "{$field} is required", "{$label} is required.");
        }
    }

    /**
     * Field's key must exist in the body at all (unlike required(), a
     * present `false` or `0` counts as satisfied) — for flags where the
     * client must explicitly state a value, e.g. booleans.
     */
    public function requiredPresence(array $body, string $field, string $label): void
    {
        if (!array_key_exists($field, $body)) {
            $this->addError($field, "{$field} is required", "{$label} is required.");
        }
    }

    public function maxLength(array $body, string $field, int $max, string $label): void
    {
        $value = $body[$field] ?? null;
        if (is_string($value) && mb_strlen($value) > $max) {
            $this->addError(
                $field,
                "{$field} must be at most {$max} characters",
                "{$label} is too long (max {$max} characters).",
            );
        }
    }

    /** @param bool $required also flag when the field is absent */
    public function numeric(array $body, string $field, string $label, bool $required = false): void
    {
        if (!array_key_exists($field, $body)) {
            if ($required) {
                $this->addError(
                    $field,
                    "{$field} is required and must be numeric",
                    "{$label} is required and must be a number.",
                );
            }

            return;
        }

        if (!is_numeric($body[$field])) {
            $this->addError($field, "{$field} must be numeric", "{$label} must be a number.");
        }
    }

    /** @param bool $required also flag when the field is absent */
    public function positiveInt(array $body, string $field, string $label, bool $required = false): void
    {
        if (!array_key_exists($field, $body)) {
            if ($required) {
                $this->addError(
                    $field,
                    "{$field} is required and must be a positive integer",
                    "{$label} is required.",
                );
            }

            return;
        }

        $value = filter_var($body[$field], FILTER_VALIDATE_INT);
        if ($value === false || $value < 1) {
            $this->addError($field, "{$field} must be a positive integer", "{$label} is invalid.");
        }
    }

    /** @param bool $required also flag when the field is absent */
    public function integer(array $body, string $field, string $label, bool $required = false): void
    {
        if (!array_key_exists($field, $body)) {
            if ($required) {
                $this->addError(
                    $field,
                    "{$field} is required and must be an integer",
                    "{$label} is required.",
                );
            }

            return;
        }

        if (filter_var($body[$field], FILTER_VALIDATE_INT) === false) {
            $this->addError($field, "{$field} must be an integer", "{$label} must be a whole number.");
        }
    }

    public function boolean(array $body, string $field, string $label): void
    {
        if (array_key_exists($field, $body)
            && filter_var($body[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null
        ) {
            $this->addError($field, "{$field} must be a boolean", "{$label} must be either on or off.");
        }
    }

    public function json(array $body, string $field, string $label): void
    {
        $value = $body[$field] ?? null;
        if ($value !== null && !json_validate((string) $value)) {
            $this->addError($field, "{$field} must be valid JSON", "{$label} format is invalid.");
        }
    }

    public function hasErrors(): bool
    {
        return $this->technical !== [];
    }

    /** @return array<string, string> raw, technical detail — for logs only */
    public function technicalErrors(): array
    {
        return $this->technical;
    }

    /** @return array<string, string> non-technical, client-facing detail */
    public function friendlyErrors(): array
    {
        return $this->friendly;
    }

    /** Clear accumulated state. Call after reading results, before any reuse. */
    public function reset(): void
    {
        $this->technical = [];
        $this->friendly = [];
    }

    private function addError(string $field, string $technicalMessage, string $friendlyMessage): void
    {
        // Keep the first (usually most specific) message per field.
        $this->technical[$field] ??= $technicalMessage;
        $this->friendly[$field] ??= $friendlyMessage;
    }
}
