<?php

use App\Enums\AdminRole;
use App\Models\User;
use App\Support\ReturnPath;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Factory as Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

function fakeGoogle(string $id = 'g-123', string $email = 'rina@gmail.com', string $name = 'Rina', string $audience = 'oz-android'): void
{
    config(['services.google.app_client_ids' => ['oz-android']]);
    Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['aud' => $audience, 'azp' => $audience])]);

    $google = (new GoogleUser)->map([
        'id' => $id,
        'email' => $email,
        'name' => $name,
        'avatar' => 'https://lh3.googleusercontent.com/a/rina',
    ]);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('userFromToken')->andReturn($google);
    $provider->shouldReceive('user')->andReturn($google);

    $factory = Mockery::mock(Socialite::class);
    $factory->shouldReceive('driver')->with('google')->andReturn($provider);
    app()->instance(Socialite::class, $factory);
}

describe('email and password (mobile)', function () {
    it('registers a listener and returns a token', function () {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Rina',
            'email' => 'rina@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_name' => 'Pixel 9',
        ])
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.is_staff', false);

        expect(User::where('email', 'rina@example.com')->first()->roles)->toBeEmpty();
    });

    it('issues a token for valid credentials and works with /me', function () {
        User::factory()->create(['email' => 'rina@example.com']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'rina@example.com',
            'password' => 'password',
            'device_name' => 'iPhone',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'rina@example.com')
            ->assertJsonPath('data.has_password', true);
    });

    it('rejects a wrong password and throttles guessing', function () {
        User::factory()->create(['email' => 'rina@example.com']);

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.com', 'password' => 'nope', 'device_name' => 'x'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('email');
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'rina@example.com', 'password' => 'password', 'device_name' => 'x'])
            ->assertUnprocessable();
    });

    it('revokes the current token on logout', function () {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        expect($user->tokens()->count())->toBe(0);
    });
});

describe('Google', function () {
    it('creates a password-less, verified listener from a Google token (mobile)', function () {
        fakeGoogle();

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.x', 'device_name' => 'Pixel'])
            ->assertOk()
            ->assertJsonPath('user.email', 'rina@gmail.com')
            ->assertJsonPath('user.has_password', false)
            ->assertJsonPath('user.has_google', true);

        $user = User::where('email', 'rina@gmail.com')->first();
        expect($user->password)->toBeNull()
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->avatar_url)->toBe('https://lh3.googleusercontent.com/a/rina');
    });

    it('links Google to an existing listener with the same email', function () {
        $existing = User::factory()->create(['email' => 'rina@gmail.com']);
        fakeGoogle();

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.x', 'device_name' => 'Pixel'])->assertOk();

        expect($existing->refresh()->google_id)->toBe('g-123')
            ->and(User::count())->toBe(1);
    });

    it('refuses a Google token issued to another app', function () {
        User::factory()->create(['email' => 'rina@gmail.com']);
        fakeGoogle(audience: 'someone-elses-app');

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.x', 'device_name' => 'Pixel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('access_token');
    });

    it('refuses a token Google does not recognise', function () {
        config(['services.google.app_client_ids' => ['oz-android']]);
        Http::fake(['oauth2.googleapis.com/tokeninfo*' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->postJson('/api/v1/auth/google', ['access_token' => 'bogus', 'device_name' => 'Pixel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('access_token');
    });

    it('locks out whoever pre-registered an unverified account with the Google email', function () {
        $squatter = User::factory()->unverified()->create(['email' => 'rina@gmail.com']);
        $squatter->createToken('squatter-phone');
        fakeGoogle();

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.x', 'device_name' => 'Pixel'])->assertOk();

        $squatter->refresh();
        expect($squatter->password)->toBeNull()
            ->and($squatter->email_verified_at)->not->toBeNull()
            ->and($squatter->tokens()->pluck('name')->all())->toBe(['Pixel']);
    });

    it('refuses Google sign-in for staff accounts', function () {
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['email' => 'editor@ozmedianet.com'])->assignRole(AdminRole::Editorial->value);
        fakeGoogle(email: 'editor@ozmedianet.com');

        $this->postJson('/api/v1/auth/google', ['access_token' => 'ya29.x', 'device_name' => 'Pixel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    });

    it('logs a listener in on the website and returns them to the station', function () {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        fakeGoogle();

        $this->withSession(['url.intended' => url('/oz-bandung')])
            ->get('/auth/google/callback')
            ->assertRedirect(url('/oz-bandung'));

        $this->assertAuthenticatedAs(User::where('email', 'rina@gmail.com')->first());
    });

    it('hides the web Google routes until they are configured', function () {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertNotFound();
    });
});

it('remembers only same-site return paths on the login page', function (string $path, bool $kept) {
    $this->get('/login?return='.urlencode($path))->assertOk();

    expect(session('url.intended'))->toBe($kept ? url($path) : null);
})->with([
    'station' => ['/oz-bandung/news', true],
    'external' => ['https://evil.example', false],
    'scheme relative' => ['//evil.example', false],
    'backslash' => ['/\\evil.example', false],
]);

it('treats return paths safely', function () {
    expect(ReturnPath::isSafe('/oz-bandung'))->toBeTrue()
        ->and(ReturnPath::isSafe('//x'))->toBeFalse();
});
