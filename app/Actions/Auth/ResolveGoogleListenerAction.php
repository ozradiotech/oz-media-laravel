<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Finds or creates the listener behind a Google account.
 *
 * Matches on google_id first, then links an existing account with the same
 * (Google-verified) email, otherwise creates a new password-less listener.
 * Staff accounts are refused.
 */
class ResolveGoogleListenerAction
{
    /**
     * @throws ValidationException when Google returns no email address
     */
    public function handle(SocialiteUser $google): User
    {
        $email = $google->getEmail();

        if (blank($email)) {
            throw ValidationException::withMessages([
                'email' => 'Your Google account did not share an email address.',
            ]);
        }

        $user = User::query()->where('google_id', $google->getId())->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User(['email' => $email, 'name' => $google->getName() ?: str($email)->before('@')]);

        // The site and /admin share one session: Google must never be a way
        // around a staff member's password and two-factor authentication.
        if ($user->exists && $user->isStaff()) {
            throw ValidationException::withMessages([
                'email' => 'Staff accounts sign in with email and password.',
            ]);
        }

        // Nobody proved they own this email before: whoever set the password may not be
        // the Google account's owner, so their password and app tokens stop working.
        if ($user->exists && $user->email_verified_at === null) {
            $user->forceFill(['password' => null, 'remember_token' => null]);
            $user->tokens()->delete();
        }

        $user->forceFill([
            'google_id' => $google->getId(),
            'avatar_url' => $user->avatar_url ?? $google->getAvatar(),
            // Google has verified this address.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }
}
