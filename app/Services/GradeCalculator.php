<?php

namespace App\Services;

use App\Enums\AcademicLevel;

class GradeCalculator
{
    public function computeFinalGrade(?float $prelim, ?float $midterm, ?float $semiFinal, ?float $final): ?float
    {
        if ($prelim === null || $midterm === null || $semiFinal === null || $final === null) {
            return null;
        }

        return round(($prelim + $midterm + $semiFinal + $final) / 4, 2);
    }

    public function computeRemarks(?float $finalGrade, AcademicLevel $level): ?string
    {
        if ($finalGrade === null) {
            return null;
        }

        if ($level === AcademicLevel::Shs) {
            $minimum = (float) config('grading.shs_pass_minimum', 75);

            return $finalGrade >= $minimum ? 'PASSED' : 'FAILED';
        }

        $maximum = (float) config('grading.college_pass_maximum', 3.00);

        return $finalGrade <= $maximum ? 'PASSED' : 'FAILED';
    }

    public function validatePeriodGrade(float $value, AcademicLevel $level): bool
    {
        if ($level === AcademicLevel::Shs) {
            return $value >= 0 && $value <= 100;
        }

        return $value >= 1.0 && $value <= 5.0;
    }
}
