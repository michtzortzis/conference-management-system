<?php

namespace App\Enums;

enum ReviewAssignmentStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
}
