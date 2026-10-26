<?php

namespace App\Enums;

enum ReviewRecommendation: string
{
    case Accept = 'accept';
    case Reject = 'reject';
    case MinorRevision = 'minor_revision';
    case MajorRevision = 'major_revision';
}
