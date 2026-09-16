<?php

namespace App\Enums;

enum UserRole: string
{
    case Teacher = 'teacher';
    case Registrar = 'registrar';
    case Admin = 'admin';
    case Student = 'student';
    case It = 'it';
    case Stakeholder = 'stakeholder';

    public function label(): string
    {
        return match ($this) {
            self::Teacher => 'Teacher',
            self::Registrar => 'Registrar',
            self::Admin => 'Admin',
            self::Student => 'Student',
            self::It => 'IT',
            self::Stakeholder => 'Stakeholder',
        };
    }

    public function isStaff(): bool
    {
        return in_array($this, [
            self::Teacher,
            self::Registrar,
            self::Admin,
            self::It,
            self::Stakeholder,
        ], true);
    }

    public function canMonitor(): bool
    {
        return in_array($this, [self::Admin, self::Stakeholder], true);
    }
}
