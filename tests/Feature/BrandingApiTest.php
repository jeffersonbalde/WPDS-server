<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function brandingItUser(): User
{
    return User::factory()->create([
        'role' => UserRole::It,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    Storage::fake('public');
    $path = app(BrandingService::class)->path();
    if (is_file($path)) {
        unlink($path);
    }
});

it('exposes public branding defaults without authentication', function () {
    $this->getJson('/api/branding')
        ->assertOk()
        ->assertJsonPath('system_name', 'West Prime Horizon Institute, Inc.')
        ->assertJsonPath('tagline', 'Digital Academic Portal')
        ->assertJsonPath('has_custom_logo', false)
        ->assertJsonPath('logo_url', null);
});

it('lets it update branding texts', function () {
    $this->actingAs(brandingItUser(), 'sanctum')
        ->putJson('/api/system/branding', [
            'system_name' => 'West Prime Academy',
            'system_short_name' => 'WP Academy',
            'tagline' => 'Learning Portal',
            'login_heading' => 'Sign in',
            'login_subtitle' => 'Use your school account.',
            'footer_text' => 'West Prime Academy',
        ])
        ->assertOk()
        ->assertJsonPath('branding.system_name', 'West Prime Academy')
        ->assertJsonPath('branding.login_heading', 'Sign in');

    $this->getJson('/api/branding')
        ->assertOk()
        ->assertJsonPath('system_name', 'West Prime Academy')
        ->assertJsonPath('login_subtitle', 'Use your school account.');
});

it('lets it upload and clear a custom logo', function () {
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    $upload = $this->actingAs(brandingItUser(), 'sanctum')
        ->post('/api/system/branding/logo', ['logo' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('branding.has_custom_logo', true);

    expect($upload->json('branding.logo_url'))->not->toBeNull();

    $this->actingAs(brandingItUser(), 'sanctum')
        ->deleteJson('/api/system/branding/logo')
        ->assertOk()
        ->assertJsonPath('branding.has_custom_logo', false)
        ->assertJsonPath('branding.logo_url', null);
});

it('lets it upload a login background image', function () {
    $file = UploadedFile::fake()->image('campus.jpg', 1280, 720);

    $this->actingAs(brandingItUser(), 'sanctum')
        ->post('/api/system/branding/login-bg', ['login_bg' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('branding.has_custom_login_bg', true);

    $this->getJson('/api/branding')
        ->assertOk()
        ->assertJsonPath('has_custom_login_bg', true);
});

it('forbids a registrar from editing branding', function () {
    $registrar = User::factory()->create([
        'role' => UserRole::Registrar,
        'is_active' => true,
    ]);

    $this->actingAs($registrar, 'sanctum')
        ->putJson('/api/system/branding', [
            'system_name' => 'Hacked',
            'system_short_name' => 'Hack',
            'tagline' => 'x',
            'login_heading' => 'x',
            'login_subtitle' => 'x',
            'footer_text' => 'x',
        ])
        ->assertForbidden();
});

it('resets branding to defaults', function () {
    $it = brandingItUser();

    $this->actingAs($it, 'sanctum')
        ->putJson('/api/system/branding', [
            'system_name' => 'Temp Name',
            'system_short_name' => 'Temp',
            'tagline' => 'Temp tag',
            'login_heading' => 'Hi',
            'login_subtitle' => 'Hello',
            'footer_text' => 'Temp footer',
        ])
        ->assertOk();

    $this->actingAs($it, 'sanctum')
        ->postJson('/api/system/branding/reset')
        ->assertOk()
        ->assertJsonPath('branding.system_name', 'West Prime Horizon Institute, Inc.')
        ->assertJsonPath('branding.has_custom_logo', false);
});
