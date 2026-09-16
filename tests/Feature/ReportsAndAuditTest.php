<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adminUser(): User
{
    return User::where('email', 'admin@westprime.edu')->firstOrFail();
}

function stakeholderUser(): User
{
    return User::where('email', 'stakeholder@westprime.edu')->firstOrFail();
}

it('lets admin and stakeholder view the population report', function () {
    $this->seed();

    foreach ([adminUser(), stakeholderUser()] as $user) {
        $res = $this->actingAs($user, 'sanctum')->getJson('/api/reports/population')->assertOk();
        expect($res->json('total'))->toBeGreaterThan(0)
            ->and($res->json('breakdown'))->not->toBeEmpty();
    }
});

it('lets admin view the performance report with program/subject/teacher breakdowns', function () {
    $this->seed();

    $res = $this->actingAs(adminUser(), 'sanctum')->getJson('/api/reports/performance')->assertOk();

    expect($res->json('total_graded'))->toBeGreaterThan(0)
        ->and($res->json('by_program'))->not->toBeEmpty()
        ->and($res->json('by_subject'))->not->toBeEmpty()
        ->and($res->json('by_teacher'))->not->toBeEmpty();
});

it('lets admin drill into students enrolled in a program from the population report', function () {
    $this->seed();

    $report = $this->actingAs(adminUser(), 'sanctum')->getJson('/api/reports/population')->assertOk();
    $row = collect($report->json('breakdown'))->first();
    expect($row)->not->toBeNull();

    $res = $this->actingAs(adminUser(), 'sanctum')
        ->getJson('/api/reports/population/students?program_id='.$row['program_id'].'&year_level='.$row['year_level'])
        ->assertOk();

    expect($res->json('data'))->not->toBeEmpty();
    foreach ($res->json('data') as $student) {
        expect($student['program_code'])->toBe($row['program_code']);
    }
});

it('lets stakeholder drill into students behind a performance breakdown row', function () {
    $this->seed();

    $report = $this->actingAs(stakeholderUser(), 'sanctum')->getJson('/api/reports/performance')->assertOk();
    $row = collect($report->json('by_program'))->first();
    expect($row)->not->toBeNull();

    $res = $this->actingAs(stakeholderUser(), 'sanctum')
        ->getJson('/api/reports/performance/students?program_id='.$row['program_id'])
        ->assertOk();

    expect($res->json('data'))->not->toBeEmpty();
});

it('forbids teachers and registrars from the report drill-down endpoints', function () {
    $this->seed();

    foreach (['teacher@westprime.edu', 'registrar@westprime.edu'] as $email) {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/population/students')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/performance/students')->assertForbidden();
    }
});

it('lets stakeholder view the grade operations report', function () {
    $this->seed();

    $res = $this->actingAs(stakeholderUser(), 'sanctum')->getJson('/api/reports/grade-operations')->assertOk();

    expect($res->json('submissions_by_status.pending'))->toBeGreaterThan(0);
});

it('forbids teachers, registrars and students from viewing reports', function () {
    $this->seed();

    foreach (['teacher@westprime.edu', 'registrar@westprime.edu', 'jefferson.balde@westprime.edu'] as $email) {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user, 'sanctum')->getJson('/api/reports/population')->assertForbidden();
    }
});

it('lets it, admin and stakeholder read the audit log but not other roles', function () {
    $this->seed();

    foreach (['it@westprime.edu', 'admin@westprime.edu', 'stakeholder@westprime.edu'] as $email) {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user, 'sanctum')->getJson('/api/audit-logs')->assertOk();
    }

    foreach (['teacher@westprime.edu', 'registrar@westprime.edu'] as $email) {
        $user = User::where('email', $email)->firstOrFail();
        $this->actingAs($user, 'sanctum')->getJson('/api/audit-logs')->assertForbidden();
    }
});

it('filters the audit log by action', function () {
    $this->seed();

    $res = $this->actingAs(adminUser(), 'sanctum')
        ->getJson('/api/audit-logs?action=grade.updated')
        ->assertOk();

    expect(collect($res->json('data'))->every(fn ($row) => $row['action'] === 'grade.updated'))->toBeTrue();
});
