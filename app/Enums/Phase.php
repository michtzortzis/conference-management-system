<?php

namespace App\Enums;

/**
 * The three dated conference phases. Each owns a pair of track boundaries, and
 * they are ordered: submission -> review -> decision. Bidding is not a case
 * here because it has no boundary of its own — it opens when the CFP closes
 * and shuts again when review opens.
 */
enum Phase: string
{
    case Submission = 'submission';
    case Review = 'review';
    case Decision = 'decision';

    /**
     * The track column holding the instant this phase opens.
     */
    public function openColumn(): string
    {
        return "{$this->value}_open_at";
    }

    /**
     * The track column holding the instant this phase closes.
     */
    public function closeColumn(): string
    {
        return "{$this->value}_close_at";
    }
}
