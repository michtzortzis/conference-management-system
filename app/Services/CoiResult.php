<?php

namespace App\Services;

/**
 * Result of a conflict-of-interest check. `level` is null when there is no
 * conflict, otherwise one of: declared | hard | soft.
 */
class CoiResult
{
    public function __construct(
        public readonly ?string $level = null,
        public readonly ?string $reason = null,
        public readonly bool $overridable = false,
        public readonly int $score = 0,
        public readonly ?int $authorId = null,
        public readonly ?int $coauthorId = null,
    ) {}

    public function hasConflict(): bool
    {
        return $this->level !== null;
    }

    public function isHard(): bool
    {
        return $this->level === 'hard';
    }

    public function isSoft(): bool
    {
        return $this->level === 'soft';
    }

    public function isDeclared(): bool
    {
        return $this->level === 'declared';
    }
}
