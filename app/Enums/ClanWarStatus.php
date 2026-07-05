<?php

namespace App\Enums;

enum ClanWarStatus: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Finished = 'finished';
}
