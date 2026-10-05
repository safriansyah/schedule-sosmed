<?php
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

// Logging in writes (last_login_at, the session row); inside a transaction
// none of it reaches the real database.
uses(DatabaseTransactions::class);

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
    // An account of its own, with a password the test knows. It used to log
    // in as admin@example.com with "password" — which stopped working the day
    // the real admin's password was changed, as it should.
    $user = new User;
    $user->forceFill([
        'name' => 'Uji Login',
        'email' => 'uji-login-'.uniqid().'@example.test',
        'password' => 'Rahasia-Uji-123',
        'role_id' => User::withRole(RoleName::SuperAdmin)->firstOrFail()->role_id,
        'is_active' => true,
    ])->save();

    $this->post('http://192.168.1.10:5566/login', ['email' => $user->email, 'password' => 'Rahasia-Uji-123'])
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
