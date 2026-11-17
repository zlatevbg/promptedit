<?php

namespace App\Enums;

enum WebhookEventType: string
{
    case GenerationProcessing = 'generation.processing';
    case GenerationSucceeded = 'generation.succeeded';
    case GenerationFailed = 'generation.failed';
    case Ping = 'ping';
}
