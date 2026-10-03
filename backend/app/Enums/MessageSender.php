<?php

namespace App\Enums;

/** Auteur d'un message de réclamation (reclamation_messages.sender). */
enum MessageSender: int
{
    case Admin = 0;
    case Seller = 1;
}
