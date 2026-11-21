<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('throttle:5,1');
});

test('unauthenticated access returns 401 json, never a redirect', function () {
    $response = $this->get('/api/dashboard');

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);
});

test('login success returns user and token', function () {
    $user = User::factory()->create([
        'email' => 'cashier@novapos.test',
        'password' => Hash::make('password'),
        'role' => 'cashier',
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'cashier@novapos.test',
        'password' => 'password',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'user' => ['id', 'name', 'email', 'role'],
            'token',
        ])
        ->assertJson([
            'message' => 'Login successful.',
            'user' => [
                'email' => 'cashier@novapos.test',
                'role' => 'cashier',
            ],
        ]);
});

test('login with wrong password returns generic error', function () {
    User::factory()->create([
        'email' => 'user@novapos.test',
        'password' => Hash::make('correct_password'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'user@novapos.test',
        'password' => 'wrong_password',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'The provided credentials are incorrect.',
        ]);
});

test('login with unknown email returns the same generic error', function () {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'unknown@novapos.test',
        'password' => 'any_password',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'message' => 'The provided credentials are incorrect.',
        ]);
});

test('login endpoint is rate limited to 5 attempts per minute', function () {
    User::factory()->create([
        'email' => 'throttle@novapos.test',
        'password' => Hash::make('password'),
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', [
            'email' => 'throttle@novapos.test',
            'password' => 'wrong',
        ]);
    }

    $response = $this->postJson('/api/auth/login', [
        'email' => 'throttle@novapos.test',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429);
});

test('logout revokes current token', function () {
    $user = User::factory()->create([
        'email' => 'logout@novapos.test',
        'password' => Hash::make('password'),
    ]);

    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/auth/logout');

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Logged out successfully.',
        ]);

    expect($user->tokens()->count())->toBe(0);
});
