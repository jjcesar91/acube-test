<?php

declare(strict_types=1);

namespace App\Enum;

enum InputFormat: string
{
    case Csv  = 'csv';
    case Json = 'json';
    case Xlsx = 'xlsx';
    case Ods  = 'ods';

    /** @return string[] */
    public static function allowedMimeTypes(): array
    {
        return [
            self::Csv->value  => 'text/csv',
            self::Json->value => 'application/json',
            self::Xlsx->value => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Ods->value  => 'application/vnd.oasis.opendocument.spreadsheet',
        ];
    }
}
