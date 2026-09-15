<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class OidcController extends Controller
{
    /**
     * Kick off the OIDC redirect to Keycloak.
     */
    public function redirect()
    {
        return Socialite::driver('oidc')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Handle the callback from Keycloak.
     */
    public function callback(Request $request)
    {
        try {
            $oidcUser = Socialite::driver('oidc')->user();
        } catch (\Throwable $e) {
            Log::error('OIDC callback failed', [
                'message' => $e->getMessage(),
            ]);

            return redirect(config('app.frontend_url') . '/login?error=auth_failed');
        }

        // The key mapping: issuer + subject uniquely identifies the user
        $issuer  = config('oidc.default.issuer_url');
        $subject = $oidcUser->getId();    // the 'sub' claim

        $user = User::updateOrCreate(
            [
                'oidc_issuer'  => $issuer,
                'oidc_subject' => $subject,
            ],
            [
                'name'  => $oidcUser->getName() ?? 'OIDC User',
                'email' => $oidcUser->getEmail(),
            ]
        );

        Auth::login($user, true);
        $request->session()->regenerate();

        // Redirect the browser back to Nuxt
        return redirect(config('app.frontend_url') . '/dashboard');
    }

    /**
     * Return the currently authenticated user as JSON.
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Log out of the Laravel session and OIDC session.
     */
    public function logout(Request $request)
    {
        //Auth::logout();

        // Use the 'web' guard explicitly — it's the session-based one that
        // knows how to destroy the session.
        // handles logging the user out of
        // just the app itself
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // This is for federated logout
        // will also logout the user from
        // the SSO server
        $issuer = config('oidc.default.issuer_url');
        $discovery = \Illuminate\Support\Facades\Http::get(
            $issuer . '/.well-known/openid-configuration'
        )->json();
        $endSessionEndpoint = $discovery['end_session_endpoint'] ?? null;

        if (! $endSessionEndpoint) {
            return response()->json(['message' => 'Logged out']);
        }

        $params = http_build_query([
            'post_logout_redirect_uri' => config('app.frontend_url'),
            'client_id' => config('oidc.default.client_id'),
        ]);

        return response()->json([
            'message' => 'Logged out',
            'logout_url' => $endSessionEndpoint . '?' . $params,
        ]);
    }
}