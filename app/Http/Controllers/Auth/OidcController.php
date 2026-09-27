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
     * Log the user out of the Laravel application and construct
    * the OIDC/Keycloak logout URL for the frontend.
    *
    * IMPORTANT:
    * This method immediately logs the user out of the local
    * Laravel application/session.
    *
    * It does NOT directly log the user out of the OIDC/Keycloak
    * SSO session. Instead, it discovers Keycloak's end-session
    * endpoint and returns a logout URL to the frontend.
    *
    * The frontend must redirect the user's browser to the
    * returned logout_url for the Keycloak/SSO logout to actually
    * take place.
    */
    public function logout(Request $request)
    {
        /*
        * Log the user out of Laravel's local authentication session.
        *
        * The 'web' guard is the session-based authentication guard
        * used by the application.
        *
        * This logs the user out of the Laravel application itself.
        * It does NOT log the user out of Keycloak/SSO.
        */
        Auth::guard('web')->logout();

        /*
        * Invalidate the current Laravel session so that the existing
        * session can no longer be used.
        */
        $request->session()->invalidate();

        /*
        * Generate a new CSRF token after invalidating the session.
        *
        * This ensures the application does not continue using the
        * previous session's CSRF token.
        */
        $request->session()->regenerateToken();

        /*
        * Start with no Keycloak logout URL.
        *
        * If Keycloak is unavailable or its discovery document does
        * not contain an end-session endpoint, the user will still
        * be logged out of Laravel. In that case, this remains null.
        */
        $logoutUrl = null;

        try {
            /*
            * Get the OIDC issuer URL.
            *
            * This is typically the base URL of the Keycloak realm,
            * for example:
            *
            * https://keycloak.example.com/realms/my-realm
            */
            $issuer = config('oidc.default.issuer_url');

            /*
            * Get the OIDC client ID used by this application.
            */
            $clientId = config('oidc.default.client_id');

            /*
            * Only attempt Keycloak logout URL construction if the
            * required OIDC configuration values are available.
            */
            if ($issuer && $clientId) {

                /*
                * Request the OIDC discovery document from Keycloak.
                *
                * The discovery document contains the provider's
                * supported OIDC endpoints, including the endpoint
                * used to perform logout.
                *
                * The 5-second timeout prevents the logout request
                * from hanging indefinitely if Keycloak is unavailable.
                */
                $response = \Illuminate\Support\Facades\Http::timeout(5)
                    ->get($issuer . '/.well-known/openid-configuration');

                /*
                * Only process the discovery document if Keycloak
                * returned a successful HTTP response.
                */
                if ($response->successful()) {

                    /*
                    * Get Keycloak's OIDC end-session endpoint.
                    *
                    * This is the endpoint that can be used to
                    * terminate the user's Keycloak/SSO session.
                    *
                    * IMPORTANT:
                    * We are only retrieving the endpoint here.
                    * We have NOT called the logout endpoint itself.
                    */
                    $endSessionEndpoint = $response->json('end_session_endpoint');

                    if ($endSessionEndpoint) {

                        /*
                        * Build the query parameters for the Keycloak
                        * logout URL.
                        *
                        * post_logout_redirect_uri:
                        * --------------------------------
                        * Tells Keycloak where the user's browser
                        * should be redirected after Keycloak logout.
                        *
                        * client_id:
                        * --------------------------------
                        * Identifies the OIDC client/application
                        * requesting the logout.
                        */
                        $params = http_build_query([
                            'post_logout_redirect_uri' => config('app.frontend_url'),
                            'client_id' => $clientId,
                        ]);

                        /*
                        * Construct the complete Keycloak logout URL.
                        *
                        * IMPORTANT:
                        * This only constructs the URL. Laravel is
                        * NOT making a request to this endpoint.
                        *
                        * The frontend must redirect the user's
                        * browser to this URL for Keycloak to process
                        * the SSO logout.
                        */
                        $logoutUrl = $endSessionEndpoint . '?' . $params;
                    }
                }
            }
        } catch (\Throwable $e) {

            /*
            * If Keycloak is unavailable, the discovery request
            * fails, or another error occurs while constructing
            * the logout URL, log the error.
            *
            * The local Laravel logout has already happened above,
            * so we do not want a Keycloak problem to prevent the
            * user from being logged out of the Laravel application.
            */
            \Illuminate\Support\Facades\Log::error(
                'Logout URL construction failed',
                [
                    'message' => $e->getMessage(),
                ]
            );
        }

        /*
        * Return the result to the frontend.
        *
        * At this point:
        *
        * 1. The user has been logged out of Laravel.
        * 2. The Laravel session has been invalidated.
        * 3. A new CSRF token has been generated.
        * 4. $logoutUrl contains a Keycloak logout URL if one
        *    could be successfully constructed.
        *
        * IMPORTANT:
        * Returning logout_url does NOT log the user out of
        * Keycloak automatically.
        *
        * The frontend must explicitly redirect the browser to
        * logout_url if it wants to terminate the Keycloak/SSO
        * session as well.
        */
        return response()->json([
            'message' => 'Logged out',
            'logout_url' => $logoutUrl,
        ]);
    }

}