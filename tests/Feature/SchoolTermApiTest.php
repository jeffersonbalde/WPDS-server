<?php

use App\Enums\UserRole;
use App\Models\ClassSection;
use App\Models\SchoolTerm;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function schoolTermRegistrar(): User
{
    return User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);
}

it('lists school terms with pagination and summary', function () {
    $registrar = schoolTermRegistrar();
    SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    SchoolTerm::create([
        'name' => 'SUMMER, 2024-2025',
        'term_type' => 'summer',
        'school_year' => '2024-2025',
        'is_active' => false,
    ]);

    $response = $this->actingAs($registrar)->getJson('/api/school-terms?page=1&per_page=10');

    $response->assertOk()
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.active', 1)
        ->assertJsonPath('summary.inactive', 1)
        ->assertJsonCount(2, 'data');
});

it('creates a school term for registrar', function () {
    $registrar = schoolTermRegistrar();

    $response = $this->actingAs($registrar)->postJson('/api/school-terms', [
        'name' => 'SECOND SEMESTER, 2025-2026',
        'term_type' => 'second_semester',
        'school_year' => '2025-2026',
    ]);

    $response->assertCreated()
        ->assertJsonPath('name', 'SECOND SEMESTER, 2025-2026')
        ->assertJsonPath('term_type', 'second_semester')
        ->assertJsonPath('is_active', true);

    $this->assertDatabaseHas('school_terms', [
        'name' => 'SECOND SEMESTER, 2025-2026',
        'school_year' => '2025-2026',
    ]);
});

it('updates a school term', function () {
    $registrar = schoolTermRegistrar();
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);

    $response = $this->actingAs($registrar)->putJson("/api/school-terms/{$term->id}", [
        'name' => 'FIRST SEMESTER, 2025-2026 (UPDATED)',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('name', 'FIRST SEMESTER, 2025-2026 (UPDATED)')
        ->assertJsonPath('is_active', false);
});

it('filters school terms by search and status', function () {
    $registrar = schoolTermRegistrar();
    SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    SchoolTerm::create([
        'name' => 'SUMMER, 2024-2025',
        'term_type' => 'summer',
        'school_year' => '2024-2025',
        'is_active' => false,
    ]);

    $this->actingAs($registrar)
        ->getJson('/api/school-terms?page=1&per_page=10&status=active')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($registrar)
        ->getJson('/api/school-terms?page=1&per_page=10&search=SUMMER')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('blocks duplicate school term type and school year on create', function () {
    $registrar = schoolTermRegistrar();
    SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);

    $this->actingAs($registrar)
        ->postJson('/api/school-terms', [
            'name' => 'FIRST SEM, 2025-2026',
            'term_type' => 'first_semester',
            'school_year' => '2025-2026',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['term_type']);

    $this->assertDatabaseCount('school_terms', 1);
});

it('blocks duplicate school term name on create', function () {
    $registrar = schoolTermRegistrar();
    SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);

    $this->actingAs($registrar)
        ->postJson('/api/school-terms', [
            'name' => 'FIRST SEMESTER, 2025-2026',
            'term_type' => 'second_semester',
            'school_year' => '2025-2026',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);

    $this->assertDatabaseCount('school_terms', 1);
});

it('allows updating a school term without duplicate conflict with itself', function () {
    $registrar = schoolTermRegistrar();
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);

    $this->actingAs($registrar)
        ->putJson("/api/school-terms/{$term->id}", [
            'name' => 'FIRST SEMESTER, 2025-2026',
            'term_type' => 'first_semester',
            'school_year' => '2025-2026',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('is_active', false);
});

it('exports school terms to excel with active filters', function () {
    $registrar = schoolTermRegistrar();
    SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    SchoolTerm::create([
        'name' => 'SUMMER, 2024-2025',
        'term_type' => 'summer',
        'school_year' => '2024-2025',
        'is_active' => false,
    ]);

    $response = $this->actingAs($registrar, 'sanctum')
        ->get('/api/school-terms/export?status=active')
        ->assertOk();

    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml');
    expect($response->headers->get('content-disposition'))
        ->toContain('school-terms-export-');
});

it('deletes an unused school term', function () {
    $registrar = schoolTermRegistrar();
    $term = SchoolTerm::create([
        'name' => 'TEST TERM',
        'term_type' => 'summer',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);

    $this->actingAs($registrar)
        ->deleteJson("/api/school-terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('message', 'School term deleted.');

    $this->assertDatabaseMissing('school_terms', ['id' => $term->id]);
});

it('blocks deleting a school term that is in use', function () {
    $registrar = schoolTermRegistrar();
    $term = SchoolTerm::create([
        'name' => 'FIRST SEMESTER, 2025-2026',
        'term_type' => 'first_semester',
        'school_year' => '2025-2026',
        'is_active' => true,
    ]);
    $subject = Subject::create([
        'code' => 'TEST 101',
        'title' => 'TEST SUBJECT',
        'units' => 3,
        'academic_level' => 'college',
        'is_active' => true,
    ]);
    ClassSection::create([
        'school_term_id' => $term->id,
        'subject_id' => $subject->id,
        'section' => 'A',
        'schedule_day' => 'MWF',
        'schedule_time' => '8:00 AM - 9:00 AM',
        'room' => 'ROOM 201',
    ]);

    $this->actingAs($registrar)
        ->getJson("/api/school-terms/{$term->id}/usage")
        ->assertOk()
        ->assertJsonPath('in_use', true)
        ->assertJsonPath('class_sections_count', 1);

    $this->actingAs($registrar)
        ->deleteJson("/api/school-terms/{$term->id}")
        ->assertStatus(422)
        ->assertJsonPath('usage.in_use', true);

    $this->assertDatabaseHas('school_terms', ['id' => $term->id]);
});
