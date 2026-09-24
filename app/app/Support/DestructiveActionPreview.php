<?php

namespace App\Support;

/**
 * Generic, reusable "impact preview" for a destructive action — what the
 * confirmation screen needs to show before a mutation happens. Deliberately
 * a plain DTO (no framework magic): each resource builds one by hand from
 * its own relationships, then the shared Blade component renders it.
 */
class DestructiveActionPreview
{
    /**
     * @param  string  $resourceLabel  Human label of the target (e.g. "olt_teste", "POP-SP-01").
     * @param  array<int, array{label: string, count: int}>  $dependencies  Related records that exist.
     * @param  array<int, string>  $preserved  What stays untouched (shown to reassure the operator).
     * @param  array<int, string>  $removed  What actually gets removed/changed.
     * @param  array<int, string>  $blockers  Non-empty means the action must be disabled.
     * @param  array<int, string>  $warnings  Non-blocking notices (e.g. "arquivo físico ausente").
     */
    public function __construct(
        public readonly string $resourceLabel,
        public readonly array $dependencies = [],
        public readonly ?int $filesCount = null,
        public readonly ?int $filesBytes = null,
        public readonly array $preserved = [],
        public readonly array $removed = [],
        public readonly array $blockers = [],
        public readonly array $warnings = [],
    ) {
    }

    public function hasBlockers(): bool
    {
        return $this->blockers !== [];
    }
}
