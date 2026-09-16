<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throttles repeated failed login attempts for the same email', function () {
    $this->seed();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', [
            'email' => 'teacher@westprime.edu',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/login', [
        'email' => 'teacher@westprime.edu',
        'password' => 'wrong-password',
    ])->assertStatus(429);

    // A different email from the same request burst is unaffected by the
    // per-email limiter (still governed by the looser per-IP limiter).
    $this->postJson('/api/login', [
        'email' => 'registrar@westprime.edu',
        'password' => 'password',
    ])->assertOk();
});
