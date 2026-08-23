<?php
use App\Models\User;

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('renders the login page with branding', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Masuk ke akun Anda')
        ->assertSee(config('app.name'));
});

it('logs a user in and shows the dashboard', function () {
    $user = User::firstOrCreate(['email' => 'admin@example.com'],
        ['name' => 'Admin', 'password' => bcrypt('password')]);

    $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Total Konten')
        ->assertSee('Akan Terbit');
});

it('rejects bad credentials', function () {
    $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');
});
