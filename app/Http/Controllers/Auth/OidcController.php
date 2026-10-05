<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OidcController extends Controller
{
    /**
     * Kick off the OIDC authorization code flow.
     */
    public function redirect()
    {
        return Socialite::driver('oidc')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    /**
     * Handle the callback from Keycloak.
     *
     * The user is authenticated by Keycloak, then a Laravel
     * Sanctum personal access token is created.
     *
     * The actual token is NOT placed in the redirect URL.
     * Instead, a short-lived one-time exchange code is created.
     */
    public function callback(Request $request)
    {
        try {
            $oidcUser = Socialite::driver('oidc')->user();
        } catch (\Throwable $e) {
            Log::error('OIDC callback failed', [
                'message' => $e->getMessage(),
            ]);

            return redirect(
                config('app.frontend_url') . '/login?error=auth_failed'
            );
        }

        $issuer = $this->getIssuerUrl();
        $subject = $oidcUser->getId();

        if (empty($issuer) || empty($subject)) {
            Log::error('OIDC callback: missing issuer or subject');

            return redirect(
                config('app.frontend_url') . '/login?error=invalid_identity'
            );
        }

        /*
         * The OIDC issuer + subject uniquely identifies the local user.
         *
         * This deliberately preserves the identity mapping from your
         * original controller.
         */
        try {
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
        } catch (\Throwable $e) {
            Log::error('OIDC user create/update failed', [
                'message'      => $e->getMessage(),
                'exception'    => get_class($e),
                'oidc_issuer'  => $issuer,
                'oidc_subject' => $subject,
            ]);

            return redirect(
                config('app.frontend_url') .
                '/login?error=user_create_update_failed'
            );
        }

        /*
         * Do NOT use Auth::login() here.
         *
         * Authentication for the Nuxt application is now handled
         * through a Sanctum personal access token.
         */

        /*
         * Create the personal access token.
         *
         * Sanctum stores only a hashed representation of the token.
         * The plain-text token is available here and only here.
         */
        $token = $user
            ->createToken('nuxt-app', ['*'])
            ->plainTextToken;

        /*
         * Generate a short-lived, one-time exchange code.
         *
         * The real Sanctum token is never exposed in the browser URL.
         */
        $exchangeCode = Str::random(64);

        Cache::put(
            'oidc_exchange:' . hash('sha256', $exchangeCode),
            [
                'token'   => $token,
                'user_id' => $user->id,
            ],
            now()->addMinute()
        );

        /*
         * Record the successful login.
         */
        AuditLog::record('voter_login', $user->ir_no ?? null, [
            'email'        => $user->email,
            'oidc_subject' => $user->oidc_subject,
        ]);

        /*
         * Redirect to Nuxt.
         *
         * Only the short-lived exchange code is placed in the URL.
         */
        return redirect(
            config('app.frontend_url') .
            '/oauth/callback?code=' .
            urlencode($exchangeCode)
        );
    }

    /**
     * Exchange the short-lived OIDC authentication code for
     * the Laravel Sanctum personal access token.
     */
    public function exchangeToken(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $key = 'oidc_exchange:' .
            hash('sha256', $request->input('code'));

        /*
         * Cache::pull() retrieves AND deletes the value.
         *
         * Therefore the exchange code can only be used once.
         */
        $data = Cache::pull($key);

        if (! $data) {
            return response()->json([
                'message' => 'Invalid or expired authentication code.',
            ], 401);
        }

        return response()->json([
            'token' => $data['token'],
        ]);
    }

    /**
     * Return the currently authenticated user.
     *
     * Authentication is provided by Sanctum through the Bearer token.
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Log the user out of Laravel/Sanctum and construct the
     * Keycloak federated logout URL.
     *
     * The current Sanctum personal access token is revoked immediately.
     *
     * Keycloak logout is completed by the browser redirecting to
     * the returned logout_url.
     */
    public function logout(Request $request)
    {
        /*
         * Revoke only the token that authenticated this request.
         */
        $request->user()
            ?->currentAccessToken()
            ?->delete();

        $logoutUrl = null;

        try {
            $issuer = $this->getIssuerUrl();
            $clientId = $this->getClientId();

            if ($issuer && $clientId) {
                /*
                 * Discover the OIDC end-session endpoint.
                 */
                $response = Http::timeout(5)
                    ->get(
                        rtrim($issuer, '/') .
                        '/.well-known/openid-configuration'
                    );

                if ($response->successful()) {
                    $endSessionEndpoint = $response->json(
                        'end_session_endpoint'
                    );

                    if ($endSessionEndpoint) {
                        /*
                         * Tell Keycloak where to send the browser
                         * after the federated logout completes.
                         */
                        $params = http_build_query([
                            'post_logout_redirect_uri' =>
                                config('app.frontend_url'),

                            'client_id' => $clientId,
                        ]);

                        $logoutUrl =
                            $endSessionEndpoint . '?' . $params;
                    }
                }
            }
        } catch (\Throwable $e) {
            /*
             * The Sanctum token has already been revoked.
             *
             * A Keycloak discovery failure should therefore not
             * prevent the application logout from succeeding.
             */
            Log::error('Logout URL construction failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message'    => 'Logged out',
            'logout_url' => $logoutUrl,
        ]);
    }

    /**
     * Read the OIDC issuer URL regardless of the configuration
     * nesting used by the Socialite OIDC package.
     */
    private function getIssuerUrl(): ?string
    {
        return config('oidc.default.issuer_url')
            ?? config('oidc.providers.oidc.issuer_url')
            ?? config('oidc.issuer_url')
            ?? env('OIDC_ISSUER_URL');
    }

    /**
     * Read the OIDC client ID.
     */
    private function getClientId(): ?string
    {
        return config('oidc.default.client_id')
            ?? config('oidc.providers.oidc.client_id')
            ?? config('oidc.client_id')
            ?? env('OIDC_CLIENT_ID');
    }
}
