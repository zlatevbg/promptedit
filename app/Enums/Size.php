<?php

namespace App\Enums;

enum Size: string
{
    case Square = '1024x1024';
    case Landscape = '1536x1024';
    case Portrait = '1024x1536';
}
