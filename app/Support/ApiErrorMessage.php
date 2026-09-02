<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

class ApiErrorMessage
{
    public static function fromThrowable(Throwable $e, string $fallback = 'Something went wrong. Please try again.'): string
    {
        if ($e instanceof QueryException) {
            return self::fromQueryException($e);
        }

        $message = trim((string) $e->getMessage());
        if ($message !== '' && self::isFriendly($message)) {
            return $message;
        }

        return $fallback;
    }

    public static function fromQueryException(QueryException $e): string
    {
        $raw = $e->getMessage();

        if (stripos($raw, 'UNIQUE') !== false || stripos($raw, 'Duplicate entry') !== false) {
            if (stripos($raw, 'email') !== false) {
                return 'This email or username is already in use.';
            }
            if (stripos($raw, 'student_no') !== false) {
                return 'This student number is already in use.';
            }
            if (stripos($raw, 'admission_number') !== false || stripos($raw, 'admission') !== false) {
                return 'This admission number is already in use.';
            }

            return 'This record already exists. Please use a different value.';
        }

        if (stripos($raw, 'FOREIGN KEY') !== false || stripos($raw, 'foreign key constraint') !== false) {
            return 'A related record is missing or invalid. Please refresh and try again.';
        }

        if (stripos($raw, 'NOT NULL') !== false) {
            return 'Unable to save the record because a required value is missing. Please refresh and try again.';
        }

        return 'Unable to save your changes. Please check your input and try again.';
    }

    public static function fieldErrorsFromQueryException(QueryException $e): array
    {
        $raw = $e->getMessage();
        $errors = [];

        if (stripos($raw, 'UNIQUE') !== false || stripos($raw, 'Duplicate entry') !== false) {
            if (stripos($raw, 'email') !== false) {
                $errors['email'] = ['This email or username is already in use.'];
            } elseif (stripos($raw, 'student_no') !== false) {
                $errors['student_no'] = ['This student number is already in use.'];
            }
        }

        return $errors;
    }

    protected static function isFriendly(string $message): bool
    {
        if (strlen($message) > 220) {
            return false;
        }

        $patterns = [
            '/sqlstate/i',
            '/\bSQL:/i',
            '/sqlite/i',
            '/Connection:/i',
            '/insert into/i',
            '/Illuminate\\\\/i',
            '/QueryException/i',
            '/PDOException/i',
            '/vendor[\\\\\\/]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return false;
            }
        }

        return true;
    }
}
