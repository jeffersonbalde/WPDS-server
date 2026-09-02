<?php

namespace App\Support;

class StudentProfileData
{
    public static function defaultEducationalBackground(): array
    {
        return [
            'primary_school' => '',
            'junior_high_school' => '',
            'senior_high_school' => '',
            'transferred_from' => '',
        ];
    }

    public static function defaultParentsGuardian(): array
    {
        $blank = [
            'name' => '',
            'occupation' => '',
            'company' => '',
            'contact' => '',
            'email' => '',
        ];

        return [
            'father' => $blank,
            'mother' => $blank,
            'guardian' => $blank,
        ];
    }

    public static function normalizeEducationalBackground(mixed $value): array
    {
        $defaults = self::defaultEducationalBackground();

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                return array_merge($defaults, ['primary_school' => $value]);
            }
        }

        if (! is_array($value)) {
            return $defaults;
        }

        return [
            'primary_school' => (string) ($value['primary_school'] ?? ''),
            'junior_high_school' => (string) ($value['junior_high_school'] ?? ''),
            'senior_high_school' => (string) ($value['senior_high_school'] ?? ''),
            'transferred_from' => (string) ($value['transferred_from'] ?? ''),
        ];
    }

    public static function normalizeParentsGuardian(mixed $value): array
    {
        $defaults = self::defaultParentsGuardian();

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                return $defaults;
            }
        }

        if (! is_array($value)) {
            return $defaults;
        }

        $out = $defaults;
        foreach (['father', 'mother', 'guardian'] as $role) {
            $row = is_array($value[$role] ?? null) ? $value[$role] : [];
            $out[$role] = [
                'name' => (string) ($row['name'] ?? ''),
                'occupation' => (string) ($row['occupation'] ?? ''),
                'company' => (string) ($row['company'] ?? ''),
                'contact' => (string) ($row['contact'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
            ];
        }

        return $out;
    }
}
