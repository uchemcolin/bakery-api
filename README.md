================================================================================
BAKERY API — Laravel 12 + Keycloak (OIDC BFF)
================================================================================

A secure Laravel 12 API that acts as both an OpenID Connect (OIDC) client and Backend-for-Frontend (BFF) for a Nuxt 4 single-page application (SPA), using Keycloak as the identity provider.

Laravel handles the entire OIDC authentication flow, including initiating login, exchanging the OIDC authorization code for tokens, mapping the external Keycloak identity to a local user, and creating and managing the authenticated server-side Laravel session. The API also serves as the resource server for the Nuxt frontend.

The Nuxt SPA never communicates directly with Keycloak and never receives, stores, or processes OIDC access tokens, ID tokens, or refresh tokens. It stores no authentication secrets and has no responsibility for the OIDC flow. Instead, it communicates exclusively with the Laravel BFF and can call endpoints such as /api/user to retrieve the currently authenticated user. Laravel returns the authenticated user when a valid server-side session exists, or 401 Unauthorized when the user is not authenticated.

This architecture keeps all OIDC tokens and authentication logic on the server while providing the Nuxt SPA with a simple, secure session-based authentication interface.

Architecture
Browser
   │
   ├───────────────┐
   │               │
   ▼               ▼
Nuxt SPA       Laravel 12 BFF
                   │
                   │ OpenID Connect
                   ▼
                Keycloak
                   │
                   ▼
             Local User DB

Authentication flow

The user starts login from the Nuxt SPA.

The browser is redirected to Laravel's /sso/redirect endpoint.

Laravel redirects the browser to Keycloak.

Keycloak authenticates the user and redirects back to Laravel.

Laravel exchanges the authorization code with Keycloak.

Laravel maps the OIDC issuer + subject to a local user.

Laravel creates a server-side authenticated session.

The browser is redirected back to the Nuxt SPA.

The SPA calls /api/user using the session cookie.

Laravel resolves and returns the authenticated user.

Security model

Keycloak owns authentication and passwords.

Laravel owns the application session and authorization.

OIDC tokens remain server-side.

The browser never receives the client secret.

The SPA does not authenticate users itself.

Session-based authentication is handled through Laravel Sanctum.

Federated logout terminates both the Laravel and Keycloak sessions.

Tech Stack

Backend: Laravel 12, PHP, Laravel Sanctum
Identity: Keycloak, OpenID Connect, OAuth 2.0
Database: SQLite for development; PostgreSQL/MySQL supported for production
Frontend: Nuxt 4
Architecture: Backend-for-Frontend (BFF), server-side sessions

================================================================================
TABLE OF CONTENTS
================================================================================

  1. What This Project Does
  2. Architecture
  3. Requirements
  4. Setup Steps
  5. Directory Structure
  6. Configuration Reference
  7. Key Files (Full Source)
  8. Routes
  9. Testing the API
 10. Common Errors and Fixes
 11. Production Migration Checklist
 12. Security Notes
 13. Related Projects

================================================================================
1. WHAT THIS PROJECT DOES
================================================================================

- Implements OIDC login against Keycloak as a confidential client
- Receives the authorization code at /sso/callback, exchanges it for tokens
- Maps the OIDC identity (issuer + subject) to a local user record
- Creates its own Laravel session (cookie) after login
- Shares that session with the Nuxt SPA running on a different port
- Exposes /api/user and /api/logout behind auth:sanctum
- Builds the Keycloak federated logout URL on logout
- Returns 401 for unauthenticated requests, 403 for unauthorized ones

The Nuxt frontend is a thin client: it renders pages and calls the API. It
never talks to Keycloak, never handles tokens, never stores secrets.

================================================================================
2. ARCHITECTURE
================================================================================

    Browser  -->  Nuxt (localhost:3000)
                    |
                    | GET  /api/user     (session cookie)
                    | POST /api/logout   (session cookie)
                    | GET  /sso/redirect (browser navigation)
                    v
                  Laravel (localhost:8000)
                    |
                    | OIDC Authorization Code + PKCE
                    v
                  Keycloak (localhost:9000)
                    |
                    v
                  users table (SQLite)
                  sessions table (SQLite)

Two independent sessions exist:

  1. Keycloak's SSO session (cookie on localhost:9000)
     Owned by Keycloak. Determines whether the user is already logged in.

  2. Laravel's session (cookie on localhost, shared with the SPA)
     Owned by this API. Determines whether the app recognizes the user.

The two are independent. Clearing one does not clear the other.
Federated logout clears both.

================================================================================
3. REQUIREMENTS
================================================================================

- PHP 8.2 or newer (PHP 8.5 tested)
- Composer
- SQLite (default) or PostgreSQL/MySQL
- Keycloak running on http://localhost:9000
  with realm "myapp" and a confidential client "nuxt-laravel-bakery"
- curl for testing endpoints

Check your PHP version:

    php -v

================================================================================
4. SETUP STEPS
================================================================================

4.1  Create the Laravel project

    composer create-project laravel/laravel bakery-api
    cd bakery-api

4.2  Set up SQLite

    touch database/database.sqlite

    In .env:

      DB_CONNECTION=sqlite

4.3  Install required packages

    composer require jeffersongoncalves/laravel-oidc
    composer require laravel/sanctum

    php artisan install:api
    php artisan vendor:publish --tag="oidc-config"

4.4  Configure .env

    Full values are in Section 6.

4.5  Register Sanctum's stateful middleware

    Edit bootstrap/app.php. Full contents are in Section 7.

4.6  Update the users migration

    Edit database/migrations/0001_01_01_000000_create_users_table.php.
    Remove the password column. Add oidc_issuer and oidc_subject.
    Full contents in Section 7.

4.7  Update the User model

    Edit app/Models/User.php. Add HasApiTokens trait and the OIDC fields.
    Full contents in Section 7.

4.8  Create the OIDC controller

    app/Http/Controllers/Auth/OidcController.php
    Full contents in Section 7.

4.9  Define routes

    routes/web.php and routes/api.php. Full contents in Section 8.

4.10 Run migrations

    php artisan migrate:fresh

4.11 Start the server

    php artisan serve

    Laravel listens on http://localhost:8000.

4.12 Ensure Keycloak and Nuxt are running

    In separate terminals:

      cd ~/keycloak/keycloak-26.x.x && bin/kc.sh start-dev --http-port=9000
      cd ../bakery-spa && npm run dev

================================================================================
5. DIRECTORY STRUCTURE
================================================================================

    bakery-api/
      .env
      bootstrap/
        app.php                    Middleware + routing config
      app/
        Http/Controllers/Auth/
          OidcController.php       OIDC client logic
        Models/
          User.php                 User model with OIDC fields
      database/
        database.sqlite            The database file
        migrations/
          0001_01_01_000000_create_users_table.php
          0001_01_01_000001_create_cache_table.php
          0001_01_01_000002_create_jobs_table.php
      routes/
        web.php                    OIDC redirect + callback
        api.php                    /api/user, /api/logout
      config/
        oidc.php                   Published by the package
        auth.php                   Guard configuration
        cors.php                   Cross-origin config
        sanctum.php                Stateful domains
        session.php                Session driver + cookie config

================================================================================
6. CONFIGURATION REFERENCE
================================================================================

6.1  .env — Full configuration

    APP_NAME=Laravel
    APP_ENV=local
    APP_KEY=base64:...                (generated by composer install)
    APP_DEBUG=true
    APP_URL=http://localhost:8000

    DB_CONNECTION=sqlite

    SESSION_DRIVER=database
    SESSION_LIFETIME=120
    SESSION_ENCRYPT=false
    SESSION_PATH=/
    SESSION_DOMAIN=localhost          ← critical for cross-origin cookies
    SESSION_SECURE_COOKIE=false       (true in production)

    CACHE_STORE=database
    QUEUE_CONNECTION=database

    FRONTEND_URL=http://localhost:3000

    OIDC_ISSUER_URL=http://localhost:9000/realms/myapp
    OIDC_CLIENT_ID=nuxt-laravel-bakery
    OIDC_CLIENT_SECRET=<paste-secret-from-keycloak>
    OIDC_REDIRECT_URI=http://localhost:8000/sso/callback
    OIDC_ALLOW_INSECURE_URLS=true     (local only — remove in production)

    SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000

6.2  Why each value matters

    SESSION_DOMAIN=localhost
      Allows the session cookie to be shared between localhost:3000 (SPA)
      and localhost:8000 (API). Without this, the SPA cannot send the
      cookie to the API.

    SANCTUM_STATEFUL_DOMAINS
      Tells Sanctum which origins are allowed to make session-based API
      requests. Must include the SPA origin AND the API's own origin.

    OIDC_ALLOW_INSECURE_URLS=true
      The OIDC package requires HTTPS for issuer URLs by default. Local
      Keycloak runs on HTTP, so this flag must be true locally. In
      production, remove this line entirely.

    SESSION_ENCRYPT=false
      Keep this false during development. Encryption can mask session
      issues behind confusing decryption errors.

6.3  Ports

    Keycloak    http://localhost:9000
    Laravel     http://localhost:8000
    Nuxt        http://localhost:3000

    Use localhost consistently (not 127.0.0.1). Cookie domains must match
    between the SPA origin and the API origin.

================================================================================
7. KEY FILES (FULL SOURCE)
================================================================================

--------------------------------------------------------------------------------
7.1  bootstrap/app.php
--------------------------------------------------------------------------------

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable Sanctum's stateful (session-cookie) authentication for
        // API routes. Without this, /api/user always returns 401 because
        // Sanctum only looks for Bearer tokens.
        $middleware->statefulApi();

        // Exempt API routes from CSRF verification. Sanctum handles their
        // protection via HttpOnly + SameSite cookies.
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

--------------------------------------------------------------------------------
7.2  database/migrations/0001_01_01_000000_create_users_table.php
--------------------------------------------------------------------------------

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('oidc_issuer')->nullable()->index();
            $table->string('oidc_subject')->nullable()->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['oidc_issuer', 'oidc_subject']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};

Note: There is NO password column. In a BFF setup, Laravel never stores
passwords. Keycloak owns authentication.

--------------------------------------------------------------------------------
7.3  app/Models/User.php
--------------------------------------------------------------------------------

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'oidc_issuer',
        'oidc_subject',
        'name',
        'email',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}

--------------------------------------------------------------------------------
7.4  app/Http/Controllers/Auth/OidcController.php
--------------------------------------------------------------------------------

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

            return redirect(
                config('app.frontend_url') . '/login?error=auth_failed'
            );
        }

        // The identity mapping: issuer + subject uniquely identifies the
        // user in the organization's identity provider.
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

        // Create the Laravel session
        Auth::login($user, true);
        $request->session()->regenerate();

        // Send the browser back to the Nuxt SPA
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
     * Log out of the Laravel session and the Keycloak SSO session.
     */
    public function logout(Request $request)
    {
        // Use the 'web' guard explicitly. The route uses auth:sanctum,
        // which resolves to Sanctum's RequestGuard — a class without a
        // logout() method. The 'web' guard owns the session.
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $logoutUrl = null;

        try {
            $issuer      = config('oidc.default.issuer_url');
            $clientId    = config('oidc.default.client_id');
            $frontendUrl = config('app.frontend_url');

            if ($issuer && $clientId) {
                // Discover the IdP's end_session_endpoint
                $response = \Illuminate\Support\Facades\Http::timeout(5)
                    ->get($issuer . '/.well-known/openid-configuration');

                if ($response->successful()) {
                    $discovery = $response->json();
                    $endSessionEndpoint = $discovery['end_session_endpoint'] ?? null;

                    if ($endSessionEndpoint) {
                        $params = http_build_query([
                            'post_logout_redirect_uri' => $frontendUrl,
                            'client_id' => $clientId,
                        ]);

                        $logoutUrl = $endSessionEndpoint . '?' . $params;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Do not fail the request if the URL cannot be built.
            // The local session is already cleared at this point.
            Log::error('Logout URL construction failed', [
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message'    => 'Logged out',
            'logout_url' => $logoutUrl,
        ]);
    }
}

--------------------------------------------------------------------------------
7.5  routes/web.php
--------------------------------------------------------------------------------

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\OidcController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sso/redirect', [OidcController::class, 'redirect'])
    ->name('oidc.redirect');

Route::get('/sso/callback', [OidcController::class, 'callback'])
    ->name('oidc.callback');

--------------------------------------------------------------------------------
7.6  routes/api.php
--------------------------------------------------------------------------------

<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [OidcController::class, 'user']);
    Route::post('/logout', [OidcController::class, 'logout']);
});

================================================================================
8. ROUTES
================================================================================

  Method   Path              Middleware       Purpose
  ----------------------------------------------------------------------------
  GET      /sso/redirect     web              Starts the OIDC flow
  GET      /sso/callback     web              Handles Keycloak's redirect
  GET      /api/user         auth:sanctum     Returns the current user
  POST     /api/logout       auth:sanctum     Ends session + builds logout URL

Verify the routes exist:

    php artisan route:list | grep -E 'sso|api/user|api/logout'

Expected output:

    GET|HEAD  sso/callback    oidc.callback
    GET|HEAD  sso/redirect    oidc.redirect
    GET|HEAD  api/user        Auth\OidcController@user
    POST      api/logout      Auth\OidcController@logout

================================================================================
9. TESTING THE API
================================================================================

9.1  Verify Laravel is running

    curl -sI http://localhost:8000/sso/redirect | head -5

    Should return:

      HTTP/1.1 302 Found
      Location: http://localhost:9000/realms/myapp/protocol/openid-connect/auth?...

    If you see the Location header pointing to Keycloak, the OIDC client
    is configured correctly.

9.2  Verify unauthenticated requests return 401

    curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/user

    Expected: 401

9.3  Verify the database schema

    php artisan tinker

    > Schema::getColumnListing('users')
    > DB::table('sessions')->count()
    > DB::table('users')->count()

    The users table should NOT have a password column.

9.4  Verify the discovery document is reachable

    curl -s http://localhost:9000/realms/myapp/.well-known/openid-configuration \
      | python3 -m json.tool | grep end_session

    Expected:

      "end_session_endpoint": "http://localhost:9000/realms/myapp/protocol/openid-connect/logout"

================================================================================
10. COMMON ERRORS AND FIXES
================================================================================

10.1  SQLSTATE[23000]: NOT NULL constraint failed: users.password

Cause:
  The default Laravel users table has a password column, but SSO users
  have no password.

Fix:
  Remove the password column from the users migration. See Section 7.2.
  Run php artisan migrate:fresh.

10.2  Invalid parameter: redirect_uri

Cause:
  The redirect URI sent by Laravel does not exactly match what is
  registered in Keycloak. "127.0.0.1" and "localhost" are treated as
  different strings.

Fix:
  Standardize on "localhost". In .env:

    OIDC_REDIRECT_URI=http://localhost:8000/sso/callback

  And register the same value in Keycloak's "Valid redirect URIs".

10.3  Invalid redirect uri (on logout)

Cause:
  The post_logout_redirect_uri sent by Laravel does not exactly match
  what is registered in Keycloak. "http://localhost:3000" and
  "http://localhost:3000/" are treated as different strings.

Fix:
  In Keycloak → Clients → nuxt-laravel-bakery → Settings →
  Valid post logout redirect URIs, ensure the entry matches FRONTEND_URL
  in Laravel's .env exactly.

10.4  419 unknown status on POST /api/logout

Cause:
  Sanctum's statefulApi() adds the web middleware group (including CSRF
  verification) to API routes. Cross-origin POSTs from the SPA do not
  carry the CSRF header.

Fix:
  Exempt API routes from CSRF in bootstrap/app.php:

    $middleware->validateCsrfTokens(except: ['api/*']);

10.5  Method Illuminate\Auth\RequestGuard::logout does not exist

Cause:
  The /api/logout route uses auth:sanctum. When the request is
  authenticated, the resolved guard is Sanctum's RequestGuard — which
  has no logout() method.

Fix:
  Call the session guard explicitly:

    Auth::guard('web')->logout();

  Note: 'web' is the guard name from config/auth.php, not the
  routes/web.php file.

10.6  /api/user returns 401 despite a valid session cookie

Cause:
  Sanctum's stateful middleware is not registered on the API group.
  The route has no session handling, so Sanctum looks for a Bearer
  token and finds none.

Fix:
  In bootstrap/app.php, inside withMiddleware:

    $middleware->statefulApi();

  Then clear config and restart:

    php artisan config:clear
    php artisan route:clear

10.7  Session cookie missing from the browser

Cause:
  SESSION_DOMAIN in .env is null or set to 127.0.0.1 while the browser
  is on localhost.

Fix:
  In .env:

    SESSION_DOMAIN=localhost
    APP_URL=http://localhost:8000
    FRONTEND_URL=http://localhost:3000

  Then:

    php artisan config:clear

10.8  Database is locked

Cause:
  H2 or SQLite write contention during concurrent requests, or a
  previous php artisan serve process still holding the file.

Fix:
  Stop all running php artisan serve processes. Restart one.

    # On macOS
    lsof -iTCP:8000 -sTCP:LISTEN
    kill -9 <PID>

================================================================================
11. PRODUCTION MIGRATION CHECKLIST
================================================================================

When moving this API from local development to production, only the
following values change. No source code changes are required.

11.1  .env diff

  Variable                      Local                              Production
  ------------------------------------------------------------------------------
  APP_ENV                       local                              production
  APP_DEBUG                     true                               false
  APP_URL                       http://localhost:8000              https://api.company.com
  FRONTEND_URL                  http://localhost:3000              https://app.company.com
  OIDC_ISSUER_URL               http://localhost:9000/realms/myapp https://login.company.com/realms/myapp
  OIDC_CLIENT_ID                nuxt-laravel-bakery                <production-client-id>
  OIDC_CLIENT_SECRET            <local-secret>                     <production-secret>
  OIDC_REDIRECT_URI             http://localhost:8000/sso/callback https://api.company.com/sso/callback
  OIDC_ALLOW_INSECURE_URLS      true                               <remove entirely>
  SESSION_DOMAIN                localhost                          .company.com
  SESSION_SECURE_COOKIE         false                              true
  SANCTUM_STATEFUL_DOMAINS      localhost:3000,localhost:8000      app.company.com,api.company.com

11.2  Additional production changes

  - Force HTTPS. In AppServiceProvider:

      public function boot(): void
      {
          if ($this->app->environment('production')) {
              \URL::forceScheme('https');
          }
      }

  - Trust your reverse proxy. In bootstrap/app.php:

      $middleware->trustProxies(at: '*');

    Or specify CIDR ranges if the proxy IPs are known.

  - Replace SQLite with PostgreSQL or MySQL. Update DB_CONNECTION,
    DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD.

  - Consider JWKS-based token validation. The current configuration uses
    the OIDC package's discovery and JWKS caching automatically — verify
    it is enabled in production.

  - Set session timeouts deliberately. In config/session.php:

      'lifetime' => 120,   // minutes

    Coordinate with the identity team on the Keycloak-side session
    timeout (Realm settings → Sessions).

  - Back up both databases. Laravel's and Keycloak's.

11.3  What does NOT change

  - app/Http/Controllers/Auth/OidcController.php
  - app/Models/User.php
  - routes/web.php and routes/api.php
  - The user mapping logic (oidc_issuer + oidc_subject)
  - The 401 / 403 distinction
  - The middleware registration

The architecture is production-shaped. Only the values change.

================================================================================
12. SECURITY NOTES
================================================================================

Rule 1 — Keycloak owns authentication

  Laravel never stores passwords, never sees passwords, and never
  validates passwords. All of that belongs to Keycloak.

Rule 2 — Laravel owns authorization

  Roles, permissions, and business data belong to this API. They are
  the reason a local user table exists.

Rule 3 — Decode is not validate

  If Laravel receives a JWT, decoding it does not prove authenticity.
  Signature verification via the IdP's JWKS is required. The OIDC
  package handles this automatically.

Rule 4 — 401 vs 403

  401 Unauthorized = "We cannot establish who you are."
  403 Forbidden    = "We know who you are. You are not allowed to do this."

  A 403 after a successful SSO login is not a bug. It means
  authentication worked and the authorization layer did its job.

Rule 5 — Do not trust the frontend

  The Nuxt SPA does not tell Laravel who the user is. It sends a
  session cookie. Laravel independently resolves the user from that.

Rule 6 — Keep the client secret secret

  The OIDC_CLIENT_SECRET must live only in the API's .env. Never ship
  it to the browser. Never commit it to version control.

Rule 7 — Session cookies must be HttpOnly and Secure

  In production, SESSION_SECURE_COOKIE=true and the session cookie is
  HttpOnly (default). Together these prevent JavaScript access and
  require HTTPS.

================================================================================
13. RELATED PROJECTS
================================================================================

  bakery-spa      Nuxt 4 SPA that consumes this API.
                  Delegates all authentication. Never handles tokens.
                  See its README for full frontend setup.

  Keycloak        The Identity Provider. Realm "myapp", confidential
                  client "nuxt-laravel-bakery". See the project
                  documentation for the Keycloak setup.

================================================================================
END OF DOCUMENT
================================================================================