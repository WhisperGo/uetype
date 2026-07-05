<?php

namespace App\Enums;

enum ClanWarStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Ongoing = 'ongoing';
    case Finished = 'finished';
    case Declined = 'declined';
}
