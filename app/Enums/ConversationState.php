<?php

namespace App\Enums;

enum ConversationState: string
{
    case Idle = 'idle';
    case SelectingService = 'selecting_service';
    case SelectingVehicle = 'selecting_vehicle';
    case SelectingDate = 'selecting_date';
    case SelectingTime = 'selecting_time';
    case ConfirmingAppointment = 'confirming_appointment';
}
