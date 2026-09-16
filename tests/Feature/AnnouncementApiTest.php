<?php

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function announcementRegistrar(): User
{
    return User::factory()->create(['role' => UserRole::Registrar, 'is_active' => true]);
}

function announcementStudent(): User
{
    return User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
}

it('lets a registrar create a draft announcement', function () {
    $registrar = announcementRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->postJson('/api/announcements', [
            'title' => 'Enrollment reminder',
            'body' => 'Enrollment for the next term closes on Friday.',
        ])
        ->assertCreated()
        ->assertJsonPath('is_published', false)
        ->assertJsonPath('published_at', null);

    expect(Announcement::where('title', 'Enrollment reminder')->exists())->toBeTrue();
});

it('stamps published_at when a registrar creates a published announcement', function () {
    $registrar = announcementRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->postJson('/api/announcements', [
            'title' => 'Portal is live',
            'body' => 'Grades are now viewable online.',
            'is_published' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('is_published', true);

    expect(Announcement::first()->published_at)->not->toBeNull();
});

it('stamps published_at the first time a draft is published via update', function () {
    $registrar = announcementRegistrar();
    $announcement = Announcement::create([
        'title' => 'Draft note',
        'body' => 'Draft body.',
        'is_published' => false,
        'created_by' => $registrar->id,
    ]);

    $this->actingAs($registrar, 'sanctum')
        ->putJson("/api/announcements/{$announcement->id}", [
            'title' => 'Draft note',
            'body' => 'Draft body.',
            'is_published' => true,
        ])
        ->assertOk()
        ->assertJsonPath('is_published', true);

    expect($announcement->fresh()->published_at)->not->toBeNull();
});

it('validates required title and body', function () {
    $registrar = announcementRegistrar();

    $this->actingAs($registrar, 'sanctum')
        ->postJson('/api/announcements', ['title' => '', 'body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'body']);
});

it('lets a registrar delete an announcement', function () {
    $registrar = announcementRegistrar();
    $announcement = Announcement::create([
        'title' => 'Temp',
        'body' => 'Temp body.',
        'created_by' => $registrar->id,
    ]);

    $this->actingAs($registrar, 'sanctum')
        ->deleteJson("/api/announcements/{$announcement->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Announcement deleted.');

    expect(Announcement::find($announcement->id))->toBeNull();
});

it('forbids non-registrars from managing announcements', function () {
    $announcement = Announcement::create(['title' => 'X', 'body' => 'Y']);

    foreach ([UserRole::Teacher, UserRole::Student, UserRole::Admin] as $role) {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/announcements', ['title' => 'Nope', 'body' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/announcements/{$announcement->id}", ['title' => 'Nope', 'body' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/announcements/{$announcement->id}")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/announcements')
            ->assertForbidden();
    }
});

it('returns only published announcements to the student feed, newest first', function () {
    $registrar = announcementRegistrar();
    Announcement::create([
        'title' => 'Older published',
        'body' => 'Older.',
        'is_published' => true,
        'published_at' => now()->subDays(3),
        'created_by' => $registrar->id,
    ]);
    Announcement::create([
        'title' => 'Newer published',
        'body' => 'Newer.',
        'is_published' => true,
        'published_at' => now()->subDay(),
        'created_by' => $registrar->id,
    ]);
    Announcement::create([
        'title' => 'Hidden draft',
        'body' => 'Draft.',
        'is_published' => false,
        'created_by' => $registrar->id,
    ]);

    $response = $this->actingAs(announcementStudent(), 'sanctum')
        ->getJson('/api/announcements/feed')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.title', 'Newer published')
        ->assertJsonPath('data.1.title', 'Older published');

    expect(collect($response->json('data'))->pluck('title'))->not->toContain('Hidden draft');
});

it('forbids non-students from the announcement feed', function () {
    $teacher = User::factory()->create(['role' => UserRole::Teacher, 'is_active' => true]);

    $this->actingAs($teacher, 'sanctum')
        ->getJson('/api/announcements/feed')
        ->assertForbidden();
});

it('shows drafts and a summary in the registrar management list', function () {
    $registrar = announcementRegistrar();
    Announcement::create(['title' => 'Pub', 'body' => 'b', 'is_published' => true, 'published_at' => now(), 'created_by' => $registrar->id]);
    Announcement::create(['title' => 'Draft', 'body' => 'b', 'is_published' => false, 'created_by' => $registrar->id]);

    $this->actingAs($registrar, 'sanctum')
        ->getJson('/api/announcements?page=1&per_page=10')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('summary.total', 2)
        ->assertJsonPath('summary.published', 1)
        ->assertJsonPath('summary.draft', 1);
});
