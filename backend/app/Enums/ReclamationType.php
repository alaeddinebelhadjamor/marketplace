<?php

namespace App\Enums;

/** Type d'une réclamation (reclamations.type). */
enum ReclamationType: int
{
    case Notification = 0;
    case Open = 1;
    case Resolved = 2;
}
