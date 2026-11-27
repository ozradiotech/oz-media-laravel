<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\IssueApiTokenAction;
use App\Actions\Auth\IssuedToken;
use App\Actions\Auth\RegisterListenerAction;
use App\Actions\Auth\ResolveGoogleListenerAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Throwable;

/**
 * Token auth for the mobile app. The website uses Fortify sessions instead,
 * but both go through the same App\Actions\Auth classes.
 */
class AuthController extends Controller
{
    public function register(Request $request, RegisterListenerAction $register, IssueApiTokenAction $tokens): JsonResponse
    {
        $request->validate(['device_name' => ['required', 'string', 'max:100']]);

        $user = $register->handle($request->only(['name', 'email', 'password', 'password_confirmation', 'age_range', 'gender', 'location']));

        return $this->tokenResponse($tokens->for($user, $request->string('device_name')->toString()), 201);
    }

    public function login(Request $request, IssueApiTokenAction $tokens): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        return $this->tokenResponse($tokens->withPassword(
            $credentials['email'],
            $credentials['password'],
            $credentials['device_name'],
            (string) $request->ip(),
        ));
    }

    /**
     * The app signs in with Google natively and sends us the Google access token.
     */
    public function google(Request $request, ResolveGoogleListenerAction $resolve, IssueApiTokenAction $tokens): JsonResponse
    {
        $data = $request->validate([
            'access_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        try {
            // Any app's Google token would otherwise sign in as its owner here: only accept ours.
            $info = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $data['access_token']]);
            $clients = array_filter([config('services.google.client_id'), ...config('services.google.app_client_ids')]);
            $issuedToUs = $info->ok() && array_intersect([$info->json('aud'), $info->json('azp')], $clients) !== [];

            /** @var AbstractProvider $driver */
            $driver = Socialite::driver('google');
            $google = $issuedToUs ? $driver->stateless()->userFromToken($data['access_token']) : null;
        } catch (Throwable) {
            $google = null;
        }

        if ($google === null) {
            throw ValidationException::withMessages(['access_token' => 'Google did not accept this sign-in. Please try again.']);
        }

        return $this->tokenResponse($tokens->for($resolve->handle($google), $data['device_name']));
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): AccountResource
    {
        return AccountResource::make($request->user());
    }

    private function tokenResponse(IssuedToken $issued, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $issued->plainTextToken,
            'token_type' => 'Bearer',
            'user' => AccountResource::make($issued->user)->resolve(),
        ], $status);
    }
}
