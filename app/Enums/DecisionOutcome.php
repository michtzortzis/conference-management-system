<?php

namespace App\Enums;

enum DecisionOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case MinorRevision = 'minor_revision';
    case MajorRevision = 'major_revision';
}
