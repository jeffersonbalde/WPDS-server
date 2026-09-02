<?php

use App\Enums\AcademicLevel;
use App\Services\GradeCalculator;

it('computes final grade as average of four periods', function () {
    $calculator = new GradeCalculator;

    expect($calculator->computeFinalGrade(1.0, 1.5, 2.0, 1.5))->toBe(1.5)
        ->and($calculator->computeFinalGrade(88, 90, 85, 92))->toBe(88.75)
        ->and($calculator->computeFinalGrade(1.0, null, 2.0, 1.5))->toBeNull();
});

it('computes remarks for shs and college scales', function () {
    $calculator = new GradeCalculator;

    expect($calculator->computeRemarks(75, AcademicLevel::Shs))->toBe('PASSED')
        ->and($calculator->computeRemarks(74.99, AcademicLevel::Shs))->toBe('FAILED')
        ->and($calculator->computeRemarks(3.0, AcademicLevel::College))->toBe('PASSED')
        ->and($calculator->computeRemarks(3.01, AcademicLevel::College))->toBe('FAILED')
        ->and($calculator->computeRemarks(5.0, AcademicLevel::College))->toBe('FAILED');
});

it('validates period grades by academic level', function () {
    $calculator = new GradeCalculator;

    expect($calculator->validatePeriodGrade(94, AcademicLevel::Shs))->toBeTrue()
        ->and($calculator->validatePeriodGrade(101, AcademicLevel::Shs))->toBeFalse()
        ->and($calculator->validatePeriodGrade(1.25, AcademicLevel::College))->toBeTrue()
        ->and($calculator->validatePeriodGrade(0.5, AcademicLevel::College))->toBeFalse();
});
