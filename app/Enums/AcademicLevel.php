<?php

namespace App\Enums;

enum AcademicLevel: string
{
    case Shs = 'shs';
    case College = 'college';

    public function label(): string
    {
        return match ($this) {
            self::Shs => 'Senior High School',
            self::College => 'College',
        };
    }
}
