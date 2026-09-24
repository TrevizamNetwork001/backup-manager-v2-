<?php

namespace App\Support;

/**
 * One check's outcome. `metadata` must already be sanitized by the caller —
 * this class does not scrub anything itself (see docs/ENGINE_HEALTH.md,
 * "Segurança/sanitização").
 */
final class HealthCheckResult
{
    public function __construct(
        public readonly string $check,
        public readonly HealthStatus $status,
        public readonly string $code,
        public readonly string $message,
        public readonly \DateTimeInterface $checkedAt,
        public readonly array $metadata = [],
    ) {
    }

    public static function make(string $check, HealthStatus $status, string $code, string $message, array $metadata = []): self
    {
        return new self($check, $status, $code, $message, now(), $metadata);
    }

    /** A check that itself threw — never let one exception take down the whole report. */
    public static function unknown(string $check, string $code, string $message): self
    {
        return self::make($check, HealthStatus::Unknown, $code, $message);
    }

    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'status' => $this->status->value,
            'code' => $this->code,
            'message' => $this->message,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}
