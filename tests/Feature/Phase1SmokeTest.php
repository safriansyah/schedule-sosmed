<?php
use App\Models\User;

it('redirects guests to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('renders the login page with branding', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Masuk ke akun Anda')
        // The name is an admin setting now, falling back to config when
        // unset — so the page shows whatever SiteBranding resolves, and
        // asserting the raw config value would fail the moment it is changed.
        ->assertSee(app(\App\Services\SiteBranding::class)->name());
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
