<?php

declare(strict_types=1);

namespace App\Enum;

enum OutputFormat: string
{
    case Json = 'json';
    case Xml  = 'xml';
}
