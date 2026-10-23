<?php

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function announcementAuthor(UserRole $role = UserRole::It): User
{
    return User::factory()->create(['role' => $role, 'is_active' => true]);
}

function announcementStudent(): User
{
    return User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
}

it('lets IT create a draft announcement', function () {
    $it = announcementAuthor();

    $this->actingAs($it, 'sanctum')
        ->postJson('/api/announcements', [
            'title' => 'Enrollment reminder',
            'body' => 'Enrollment for the next term closes on Friday.',
        ])
        ->assertCreated()
        ->assertJsonPath('is_published', false)
        ->assertJsonPath('published_at', null);

    expect(Announcement::where('title', 'Enrollment reminder')->exists())->toBeTrue();
});

it('lets a stakeholder create and publish an announcement', function () {
    $stakeholder = announcementAuthor(UserRole::Stakeholder);

    $this->actingAs($stakeholder, 'sanctum')
        ->postJson('/api/announcements', [
            'title' => 'Portal is live',
            'body' => 'Grades are now viewable online.',
            'is_published' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('is_published', true);

    expect(Announcement::first()->published_at)->not->toBeNull()
        ->and(Announcement::first()->created_by)->toBe($stakeholder->id);
});

it('stamps published_at the first time a draft is published via update', function () {
    $it = announcementAuthor();
    $announcement = Announcement::create([
        'title' => 'Draft note',
        'body' => 'Draft body.',
        'is_published' => false,
        'created_by' => $it->id,
    ]);

    $this->actingAs($it, 'sanctum')
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
    $it = announcementAuthor();

    $this->actingAs($it, 'sanctum')
        ->postJson('/api/announcements', ['title' => '', 'body' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'body']);
});

it('lets IT and stakeholders delete an announcement', function () {
    foreach ([UserRole::It, UserRole::Stakeholder] as $role) {
        $user = announcementAuthor($role);
        $announcement = Announcement::create([
            'title' => 'Temp',
            'body' => 'Temp body.',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Announcement deleted.');

        expect(Announcement::find($announcement->id))->toBeNull();
    }
});

it('forbids everyone except IT and stakeholders from managing announcements', function () {
    $announcement = Announcement::create(['title' => 'X', 'body' => 'Y']);

    foreach ([UserRole::Registrar, UserRole::Teacher, UserRole::Student, UserRole::Admin] as $role) {
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

    expect(Announcement::count())->toBe(1);
});

it('returns only published announcements to the student feed, newest first', function () {
    $it = announcementAuthor();
    Announcement::create([
        'title' => 'Older published',
        'body' => 'Older.',
        'is_published' => true,
        'published_at' => now()->subDays(3),
        'created_by' => $it->id,
    ]);
    Announcement::create([
        'title' => 'Newer published',
        'body' => 'Newer.',
        'is_published' => true,
        'published_at' => now()->subDay(),
        'created_by' => $it->id,
    ]);
    Announcement::create([
        'title' => 'Hidden draft',
        'body' => 'Draft.',
        'is_published' => false,
        'created_by' => $it->id,
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

it('shows drafts and a summary in the IT and stakeholder management list', function () {
    $it = announcementAuthor();
    Announcement::create(['title' => 'Pub', 'body' => 'b', 'is_published' => true, 'published_at' => now(), 'created_by' => $it->id]);
    Announcement::create(['title' => 'Draft', 'body' => 'b', 'is_published' => false, 'created_by' => $it->id]);

    foreach ([UserRole::It, UserRole::Stakeholder] as $role) {
        $this->actingAs(announcementAuthor($role), 'sanctum')
            ->getJson('/api/announcements?page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.published', 1)
            ->assertJsonPath('summary.draft', 1);
    }
});
