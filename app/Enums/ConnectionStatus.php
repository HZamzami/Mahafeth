<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Failed = 'failed';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Connected => __('Connected'),
            self::Failed => __('Failed'),
            self::Disconnected => __('Disconnected'),
        };
    }
}
