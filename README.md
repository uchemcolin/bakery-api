================================================================================
BAKERY API — Laravel 12 + Keycloak (OIDC BFF)
================================================================================

A secure Laravel 12 API that acts as both an OpenID Connect (OIDC) client and
Backend-for-Frontend (BFF) for a Nuxt 4 single-page application (SPA), using
Keycloak as the identity provider.

Laravel handles the server-side authentication responsibilities, including:

- Communicating with Keycloak using OpenID Connect.
- Exchanging the authorization code for OIDC tokens.
- Validating the OIDC identity.
- Mapping the external Keycloak identity to a local user.
- Creating and managing the authenticated Laravel session.
- Returning the authenticated user to the Nuxt SPA.
- Building the Keycloak federated logout URL.
- Protecting application API routes through Laravel Sanctum.

The Nuxt SPA does not communicate directly with Keycloak.

The SPA may receive the authorization callback containing the temporary OIDC
authorization code, but it does not exchange that code itself and never
receives, stores, or processes OIDC access tokens, ID tokens, refresh tokens,
or the Keycloak client secret.

The authorization code is immediately passed to Laravel, and Laravel performs
the server-side code exchange with Keycloak.

This means the important OIDC credentials and tokens remain on the server.

The resulting architecture is:

    Browser
       |
       v
    Nuxt 4 SPA
       |
       | authorization code / session requests
       v
    Laravel 12 BFF
       |
       | OpenID Connect
       v
    Keycloak

Laravel owns the application session.

Keycloak owns the user's identity and SSO session.

================================================================================
ARCHITECTURE
================================================================================

The application contains three major components:

    1. Nuxt 4 SPA
       Responsible for rendering the UI and maintaining client-side
       authentication state.

    2. Laravel 12 BFF/API
       Responsible for the OIDC integration, user mapping, server-side
       session, authorization, API endpoints, and federated logout.

    3. Keycloak
       Responsible for authentication, passwords, SSO, and the external
       OpenID Connect identity.

The browser never receives OIDC tokens.

The Laravel API is the only application component that communicates with
Keycloak using the confidential OIDC client credentials.

================================================================================
AUTHENTICATION FLOW
================================================================================

The current authentication flow is intentionally split between the browser
navigation and the Laravel server.

STEP 1 — User starts login

The user clicks:

    Sign in with Keycloak SSO

The Nuxt application redirects the browser to:

    GET /sso/redirect

on the Laravel API.

Example:

    http://localhost:8000/sso/redirect


STEP 2 — Laravel starts OIDC authentication

Laravel redirects the browser to Keycloak's authorization endpoint.

Keycloak displays its login page if the user does not already have an active
Keycloak SSO session.

If the user already has an active Keycloak SSO session, Keycloak may
authenticate the user without asking for the password again.


STEP 3 — Keycloak redirects back with an authorization code

After successful authentication, Keycloak redirects the browser back to the
application callback.

The callback contains a temporary authorization code.

Example:

    http://localhost:3000/callback?code=...


The authorization code is short-lived and is not an access token.


STEP 4 — Nuxt reads the authorization code

The Nuxt callback page reads:

    route.query.code

The callback page validates that the value exists and is a single string.

The Nuxt application then sends the authorization code to Laravel through the
authentication composable.

Example conceptual flow:

    const success = await exchangeCode(code)


STEP 5 — Laravel exchanges the authorization code

Laravel receives the authorization code from Nuxt.

Laravel then communicates directly with Keycloak and exchanges the code for the
OIDC token response.

The browser does not see the returned tokens.

Laravel validates the OIDC identity and obtains the user's:

    issuer
    subject
    name
    email

The important identity mapping is:

    oidc_issuer + oidc_subject


STEP 6 — Laravel creates or updates the local user

Laravel uses the OIDC issuer and subject to locate the local user.

If the user does not exist, Laravel creates the local user.

If the user already exists, Laravel updates the user's profile information.

The local user does not require a password.

Keycloak remains responsible for authentication.


STEP 7 — Laravel creates the application session

Laravel logs the user into the application session.

The session is stored server-side.

The browser receives the Laravel session cookie.

The SPA does not receive an OIDC access token, ID token, or refresh token.


STEP 8 — Nuxt retrieves the authenticated user

After the authorization code exchange succeeds, Nuxt navigates to:

    /dashboard

The dashboard calls:

    GET /api/user

Laravel resolves the user from the Laravel session and returns the authenticated
user as JSON.


STEP 9 — Subsequent API requests

The Nuxt SPA sends the Laravel session cookie with authenticated API requests.

For example:

    GET  /api/user
    POST /api/logout

Laravel Sanctum's stateful authentication resolves the session-backed user.

================================================================================
LOGOUT FLOW
================================================================================

Logout has two parts.

PART 1 — Laravel application logout

Nuxt calls:

    POST /api/logout

Laravel terminates the Laravel session.

The session cookie becomes invalid.

PART 2 — Keycloak federated logout

Laravel discovers Keycloak's OIDC end-session endpoint and constructs a
federated logout URL.

The response may contain:

    logout_url

Nuxt follows that URL in the browser.

Keycloak then terminates the user's Keycloak SSO session and redirects the
browser back to the Nuxt application.

This prevents the following situation:

    Laravel session cleared
              +
    Keycloak session still active
              =
    User immediately logged in again

Federated logout clears both application and identity-provider sessions.


================================================================================
SECURITY MODEL
================================================================================

Keycloak owns:

    - Passwords
    - User authentication
    - SSO sessions
    - OpenID Connect identity
    - OIDC authorization

Laravel owns:

    - Application session
    - Local user records
    - Authorization
    - Business permissions
    - Application API
    - Session-based authentication
    - Federated logout coordination

Nuxt owns:

    - User interface
    - Authentication state for rendering
    - Navigation
    - Sending requests to Laravel

Nuxt does NOT own:

    - OIDC client secrets
    - OIDC access tokens
    - OIDC ID tokens
    - OIDC refresh tokens
    - Passwords
    - Authorization decisions


================================================================================
TOKEN SECURITY
================================================================================

The most important security rule in this architecture is:

    OIDC tokens stay server-side.

The Nuxt SPA must never:

    - Store access tokens in localStorage.
    - Store ID tokens in localStorage.
    - Store refresh tokens in localStorage.
    - Put tokens into Pinia state.
    - Put tokens into useState().
    - Decode OIDC tokens for authentication.
    - Send OIDC tokens directly to Keycloak.
    - Use the OIDC client secret.
    - Implement its own token refresh logic.

Laravel performs the OIDC code exchange and keeps the resulting credentials
server-side.

The SPA receives only application-level information such as:

    {
        "id": 1,
        "name": "Test User",
        "email": "test@example.com",
        "oidc_issuer": "...",
        "oidc_subject": "..."
    }


================================================================================
TECH STACK
================================================================================

Backend:

    Laravel 12
    PHP 8.2+
    Laravel Sanctum

Identity:

    Keycloak
    OpenID Connect
    OAuth 2.0

Frontend:

    Nuxt 4
    Vue 3
    TypeScript

Database:

    SQLite for development
    PostgreSQL/MySQL supported for production

Architecture:

    Backend-for-Frontend (BFF)
    Server-side sessions
    Stateful Laravel authentication


================================================================================
TABLE OF CONTENTS
================================================================================

    1. What This Project Does
    2. Architecture
    3. Authentication Flow
    4. Requirements
    5. Setup Steps
    6. Directory Structure
    7. Configuration Reference
    8. Key Files
    9. Routes
   10. API Contract
   11. Testing the API
   12. Common Errors and Fixes
   13. Production Migration Checklist
   14. Security Notes
   15. Related Projects
   16. Author


================================================================================
1. WHAT THIS PROJECT DOES
================================================================================

This project provides the Laravel backend for the Bakery SSO demonstration.

It:

- Acts as the OIDC client for Keycloak.
- Acts as the Backend-for-Frontend for Nuxt.
- Starts the OIDC authentication flow.
- Receives authorization-code exchange requests from Nuxt.
- Exchanges authorization codes with Keycloak.
- Validates the external OIDC identity.
- Maps Keycloak identities to local users.
- Creates Laravel server-side sessions.
- Provides /api/user.
- Provides /api/logout.
- Uses Laravel Sanctum for stateful API authentication.
- Builds the Keycloak federated logout URL.
- Returns authenticated user information to Nuxt.

The Nuxt frontend remains intentionally thin.

It does not:

- Communicate directly with Keycloak.
- Exchange OIDC codes with Keycloak.
- Store OIDC tokens.
- Store the OIDC client secret.
- Validate OIDC tokens itself.
- Decide whether a user is authenticated.
- Make authorization decisions.

Laravel remains the trust boundary for application authentication and
authorization.


================================================================================
2. ARCHITECTURE
================================================================================

The development environment uses:

    Nuxt:
        http://localhost:3000

    Laravel:
        http://localhost:8000

    Keycloak:
        http://localhost:9000

The browser communicates with both Nuxt and Laravel.

Nuxt communicates with Laravel.

Laravel communicates with Keycloak.

Nuxt does not communicate directly with Keycloak.

The important request paths are:

    Browser
       |
       +----> Nuxt
       |
       +----> Laravel
                |
                +----> Keycloak


AUTHENTICATION REQUEST PATH

    Browser
       |
       v
    Nuxt
       |
       | Navigate to /sso/redirect
       v
    Laravel
       |
       | OIDC authorization request
       v
    Keycloak
       |
       | authorization code
       v
    Nuxt /callback
       |
       | exchangeCode(code)
       v
    Laravel
       |
       | server-side token exchange
       v
    Keycloak


APPLICATION SESSION PATH

    Nuxt
       |
       | GET /api/user
       | Laravel session cookie
       v
    Laravel
       |
       v
    Authenticated User


LOGOUT PATH

    Nuxt
       |
       | POST /api/logout
       v
    Laravel
       |
       | invalidate Laravel session
       |
       | build Keycloak logout URL
       v
    Nuxt Browser
       |
       v
    Keycloak
       |
       | terminate SSO session
       v
    Nuxt


================================================================================
3. AUTHENTICATION FLOW
================================================================================

The current implementation uses a callback page in Nuxt.

The relevant Nuxt route is:

    /callback

The callback receives the temporary OIDC authorization code.

Example:

    /callback?code=abc123...


The callback does NOT exchange the code directly with Keycloak.

Instead:

    Nuxt callback
          |
          v
    useAuth().exchangeCode(code)
          |
          v
    Laravel API
          |
          v
    Keycloak


The exchange therefore remains server-side.

The complete sequence is:

    1. User clicks Sign In.

    2. Nuxt redirects the browser to:
           http://localhost:8000/sso/redirect

    3. Laravel redirects the browser to Keycloak.

    4. Keycloak authenticates the user.

    5. Keycloak redirects the browser to:
           http://localhost:3000/callback?code=...

    6. Nuxt reads the code.

    7. Nuxt calls Laravel's code-exchange endpoint.

    8. Laravel sends the authorization code to Keycloak.

    9. Keycloak returns the OIDC token response to Laravel.

   10. Laravel validates the identity.

   11. Laravel creates or updates the local user.

   12. Laravel creates the Laravel session.

   13. Laravel returns success to Nuxt.

   14. Nuxt navigates to:
           /dashboard

   15. Dashboard calls:
           GET /api/user

   16. Laravel resolves the authenticated user from the session.

   17. Nuxt renders the authenticated dashboard.


================================================================================
4. REQUIREMENTS
================================================================================

Required software:

- PHP 8.2 or newer
- Composer
- Node.js 20 or newer
- npm
- SQLite for development, or PostgreSQL/MySQL
- Keycloak
- A modern browser
- curl for API testing

Recommended development versions:

    PHP 8.5
    Node.js 20+
    Laravel 12
    Nuxt 4
    Keycloak 26.x


KEYCLOAK REQUIREMENTS

Keycloak must be running at:

    http://localhost:9000

The realm is:

    myapp

The client is:

    nuxt-laravel-bakery

The client must be configured as a confidential client because the client
secret belongs to Laravel.

The OIDC redirect/callback configuration must match the current application
flow.

The browser callback is handled by Nuxt:

    http://localhost:3000/callback

The Laravel OIDC redirect endpoint remains:

    http://localhost:8000/sso/redirect


================================================================================
5. SETUP STEPS
================================================================================

5.1 Create the Laravel project

    composer create-project laravel/laravel bakery-api

    cd bakery-api


5.2 Create the SQLite database

    touch database/database.sqlite

In .env:

    DB_CONNECTION=sqlite


5.3 Install required packages

    composer require jeffersongoncalves/laravel-oidc
    composer require laravel/sanctum

Then install the API stack:

    php artisan install:api

Publish the OIDC configuration:

    php artisan vendor:publish --tag="oidc-config"


5.4 Configure .env

Use the configuration shown in Section 7.


5.5 Register Sanctum's stateful middleware

Edit:

    bootstrap/app.php

The application must call:

    $middleware->statefulApi();

This allows Sanctum to authenticate SPA requests using the Laravel session
cookie.


5.6 Configure CSRF behavior

Because this architecture uses Sanctum's stateful API middleware and the Nuxt
SPA communicates with Laravel from another origin/port, the API endpoints
used by the SPA must be configured consistently with the application's CSRF
strategy.

The current development configuration excludes API routes from Laravel's
standard CSRF middleware:

    $middleware->validateCsrfTokens(except: [
        'api/*',
    ]);

Review this carefully before production deployment.

For production, a stronger CSRF design should be considered if the API is
exposed to untrusted cross-site origins.


5.7 Configure the User model

Edit:

    app/Models/User.php

Add:

    HasApiTokens

and the OIDC fields:

    oidc_issuer
    oidc_subject
    name
    email


5.8 Configure the users migration

The local users table does not need a password because Keycloak owns
authentication.

The important fields are:

    oidc_issuer
    oidc_subject

Together they uniquely identify the external identity.


5.9 Create the OIDC controller

Create:

    app/Http/Controllers/Auth/OidcController.php

The controller is responsible for:

    - Starting OIDC authentication
    - Receiving/exchanging the authorization code
    - Mapping the OIDC identity
    - Creating the Laravel session
    - Returning the authenticated user
    - Logging out
    - Building the federated logout URL


5.10 Configure routes

The web routes handle the OIDC browser navigation.

The API routes handle the authenticated application API.


5.11 Run migrations

    php artisan migrate:fresh


5.12 Clear Laravel configuration

After changing .env:

    php artisan config:clear
    php artisan route:clear
    php artisan cache:clear


5.13 Start Laravel

    php artisan serve

Laravel:

    http://localhost:8000


5.14 Start Keycloak

Example:

    cd ~/keycloak/keycloak-26.x.x

    bin/kc.sh start-dev --http-port=9000


5.15 Start Nuxt

In the SPA project:

    npm run dev

Nuxt:

    http://localhost:3000


================================================================================
6. DIRECTORY STRUCTURE
================================================================================

    bakery-api/
    |
    +-- .env
    |
    +-- bootstrap/
    |     +-- app.php
    |
    +-- app/
    |     +-- Http/
    |     |     +-- Controllers/
    |     |           +-- Auth/
    |     |                 +-- OidcController.php
    |     |
    |     +-- Models/
    |           +-- User.php
    |
    +-- database/
    |     +-- database.sqlite
    |     +-- migrations/
    |           +-- 0001_01_01_000000_create_users_table.php
    |           +-- 0001_01_01_000001_create_cache_table.php
    |           +-- 0001_01_01_000002_create_jobs_table.php
    |
    +-- routes/
    |     +-- web.php
    |     +-- api.php
    |
    +-- config/
          +-- oidc.php
          +-- auth.php
          +-- cors.php
          +-- sanctum.php
          +-- session.php


================================================================================
7. CONFIGURATION REFERENCE
================================================================================

7.1 .env

Example local configuration:

    APP_NAME=Laravel
    APP_ENV=local
    APP_KEY=base64:...
    APP_DEBUG=true
    APP_URL=http://localhost:8000

    DB_CONNECTION=sqlite

    SESSION_DRIVER=database
    SESSION_LIFETIME=120
    SESSION_ENCRYPT=false
    SESSION_PATH=/
    SESSION_DOMAIN=localhost
    SESSION_SECURE_COOKIE=false

    CACHE_STORE=database
    QUEUE_CONNECTION=database

    FRONTEND_URL=http://localhost:3000

    OIDC_ISSUER_URL=http://localhost:9000/realms/myapp
    OIDC_CLIENT_ID=nuxt-laravel-bakery
    OIDC_CLIENT_SECRET=<paste-secret-from-keycloak>

    OIDC_REDIRECT_URI=http://localhost:3000/callback

    OIDC_ALLOW_INSECURE_URLS=true

    SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000


IMPORTANT:

The OIDC redirect URI must match the callback architecture.

If the authorization code is returned to Nuxt:

    OIDC_REDIRECT_URI=http://localhost:3000/callback

If the OIDC package itself expects Laravel to receive the authorization
callback, then the redirect URI would instead be:

    OIDC_REDIRECT_URI=http://localhost:8000/sso/callback

Do not configure both flows simultaneously.

The current Nuxt callback/exchange architecture uses:

    http://localhost:3000/callback


7.2 Why SESSION_DOMAIN matters

The Nuxt application runs at:

    localhost:3000

The Laravel API runs at:

    localhost:8000

They have different ports but the same host.

The Laravel session cookie must therefore be configured so the browser can send
it to the Laravel API.

Use:

    SESSION_DOMAIN=localhost

Do not mix:

    localhost

with:

    127.0.0.1

during development.

Treat them as different hosts.


7.3 Sanctum stateful domains

Use:

    SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000

The Nuxt application must be included because it is the SPA making stateful
requests.

The Laravel origin may also be included for consistency with the local
development setup.


7.4 OIDC_ALLOW_INSECURE_URLS

Local Keycloak is running over HTTP.

Therefore:

    OIDC_ALLOW_INSECURE_URLS=true

may be required during local development.

Do not use this setting in production.

Production must use HTTPS.


7.5 SESSION_SECURE_COOKIE

Development:

    SESSION_SECURE_COOKIE=false

Production:

    SESSION_SECURE_COOKIE=true

Production authentication should always run over HTTPS.


7.6 Ports

    Keycloak:
        http://localhost:9000

    Laravel:
        http://localhost:8000

    Nuxt:
        http://localhost:3000


================================================================================
8. KEY FILES
================================================================================

--------------------------------------------------------------------------------
8.1 bootstrap/app.php
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
            $middleware->statefulApi();

            $middleware->validateCsrfTokens(except: [
                'api/*',
            ]);
        })
        ->withExceptions(function (Exceptions $exceptions): void {
            //
        })->create();


--------------------------------------------------------------------------------
8.2 users migration
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

                $table->unique([
                    'oidc_issuer',
                    'oidc_subject',
                ]);
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
            Schema::dropIfExists('sessions');
            Schema::dropIfExists('password_reset_tokens');
            Schema::dropIfExists('users');
        }
    };


There is deliberately no password column.

Keycloak owns password authentication.


--------------------------------------------------------------------------------
8.3 app/Models/User.php
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
8.4 app/Http/Controllers/Auth/OidcController.php
--------------------------------------------------------------------------------

The controller should provide four logical operations:

    redirect()
        Starts the Keycloak authentication flow.

    exchangeCode()
        Receives the authorization code from Nuxt, exchanges it with Keycloak,
        maps the identity, and creates the Laravel session.

    user()
        Returns the currently authenticated application user.

    logout()
        Clears the Laravel session and prepares the Keycloak federated logout.


A representative controller structure is:

    <?php

    namespace App\Http\Controllers\Auth;

    use App\Http\Controllers\Controller;
    use App\Models\User;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;

    class OidcController extends Controller
    {
        public function redirect()
        {
            /*
             * Start the OIDC authorization flow.
             *
             * The exact implementation depends on the installed OIDC package.
             * The important architectural rule is that Laravel controls the
             * OIDC client configuration and client secret.
             */
        }

        public function exchangeCode(Request $request)
        {
            $validated = $request->validate([
                'code' => ['required', 'string'],
            ]);

            /*
             * Exchange $validated['code'] with Keycloak.
             *
             * The access token, ID token, and refresh token returned by
             * Keycloak remain server-side.
             *
             * Do not return the token response to Nuxt.
             */

            /*
             * After successful OIDC validation:
             *
             * $issuer  = ...
             * $subject = ...
             * $name    = ...
             * $email   = ...
             *
             * $user = User::updateOrCreate(
             *     [
             *         'oidc_issuer' => $issuer,
             *         'oidc_subject' => $subject,
             *     ],
             *     [
             *         'name' => $name,
             *         'email' => $email,
             *     ]
             * );
             *
             * Auth::login($user, true);
             * $request->session()->regenerate();
             */

            return response()->json([
                'message' => 'Authentication successful.',
            ]);
        }

        public function user(Request $request)
        {
            return response()->json($request->user());
        }

        public function logout(Request $request)
        {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $logoutUrl = null;

            try {
                /*
                 * Discover Keycloak's end_session_endpoint and construct
                 * the federated logout URL.
                 */
            } catch (\Throwable $e) {
                Log::error('Logout URL construction failed', [
                    'message' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Logged out',
                'logout_url' => $logoutUrl,
            ]);
        }
    }


IMPORTANT:

The exact OIDC package API should be treated as authoritative for the actual
implementation of the code-exchange operation.

The controller must never return the Keycloak token response to Nuxt.


--------------------------------------------------------------------------------
8.5 routes/web.php
--------------------------------------------------------------------------------

The browser-facing OIDC start route is:

    <?php

    use App\Http\Controllers\Auth\OidcController;
    use Illuminate\Support\Facades\Route;

    Route::get('/', function () {
        return view('welcome');
    });

    Route::get('/sso/redirect', [OidcController::class, 'redirect'])
        ->name('oidc.redirect');


The important change from the previous architecture is that the final OIDC
callback is handled by the Nuxt callback page.

Therefore, do not keep a second unused Laravel callback flow unless the OIDC
package specifically requires Laravel to receive the callback.


--------------------------------------------------------------------------------
8.6 routes/api.php
--------------------------------------------------------------------------------

The API routes are:

    <?php

    use App\Http\Controllers\Auth\OidcController;
    use Illuminate\Support\Facades\Route;

    Route::post('/auth/exchange', [OidcController::class, 'exchangeCode']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [OidcController::class, 'user']);
        Route::post('/logout', [OidcController::class, 'logout']);
    });


The important endpoints are:

    POST /api/auth/exchange
    GET  /api/user
    POST /api/logout


================================================================================
9. ROUTES
================================================================================

The current architecture uses these routes:

    Method       Path                  Authentication      Purpose
    --------------------------------------------------------------------------
    GET          /sso/redirect         None                Start OIDC login
    POST         /api/auth/exchange   None                Exchange auth code
    GET          /api/user             auth:sanctum        Current user
    POST         /api/logout           auth:sanctum        Logout


The browser login flow is:

    GET /sso/redirect

The Nuxt callback then sends:

    POST /api/auth/exchange

with:

    {
        "code": "..."
    }


The exchange endpoint creates the Laravel session.

After that:

    GET /api/user

returns the authenticated user.


VERIFY ROUTES

Run:

    php artisan route:list | grep -E 'sso|auth/exchange|api/user|api/logout'


Expected conceptual output:

    GET|HEAD  sso/redirect
    POST      api/auth/exchange
    GET       api/user
    POST      api/logout


================================================================================
10. API CONTRACT
================================================================================

10.1 POST /api/auth/exchange

Purpose:

    Exchange the temporary OIDC authorization code for a Laravel session.

Request:

    {
        "code": "temporary-authorization-code"
    }


Successful response:

    {
        "message": "Authentication successful."
    }


Important:

The response must NOT contain:

    access_token
    id_token
    refresh_token
    client_secret


The Laravel session cookie is the authentication mechanism used by the SPA
after the exchange.


--------------------------------------------------------------------------------
10.2 GET /api/user
--------------------------------------------------------------------------------

Purpose:

    Return the currently authenticated Laravel user.

Authentication:

    Laravel session cookie through Sanctum.


Example response:

    {
        "id": 1,
        "name": "Test User",
        "email": "test@example.com",
        "oidc_issuer": "http://localhost:9000/realms/myapp",
        "oidc_subject": "123456789"
    }


Unauthenticated response:

    HTTP 401


--------------------------------------------------------------------------------
10.3 POST /api/logout
--------------------------------------------------------------------------------

Purpose:

    End the Laravel session and prepare federated Keycloak logout.


Successful response:

    {
        "message": "Logged out",
        "logout_url": "http://localhost:9000/..."
    }


The logout_url may be null if Keycloak's discovery endpoint cannot be reached
or if an end-session endpoint is not available.

Nuxt should still clear its local authentication state.


================================================================================
11. TESTING THE API
================================================================================

11.1 Verify Laravel is running

    curl -sI http://localhost:8000/sso/redirect | head -5


Expected:

    HTTP/1.1 302 Found

The Location header should point toward Keycloak.


--------------------------------------------------------------------------------
11.2 Verify unauthenticated API access
--------------------------------------------------------------------------------

Run:

    curl -s -o /dev/null -w "%{http_code}\n" \
      http://localhost:8000/api/user


Expected:

    401


--------------------------------------------------------------------------------
11.3 Verify routes
--------------------------------------------------------------------------------

Run:

    php artisan route:list


Confirm:

    /sso/redirect
    /api/auth/exchange
    /api/user
    /api/logout


--------------------------------------------------------------------------------
11.4 Verify database schema
--------------------------------------------------------------------------------

Run:

    php artisan tinker


Then:

    Schema::getColumnListing('users')

The result should contain:

    id
    oidc_issuer
    oidc_subject
    name
    email
    email_verified_at
    remember_token
    created_at
    updated_at

There should NOT be:

    password


--------------------------------------------------------------------------------
11.5 Verify sessions
--------------------------------------------------------------------------------

Run:

    php artisan tinker

Then:

    DB::table('sessions')->count()


After a successful login, the number of sessions should reflect the active
Laravel session.


--------------------------------------------------------------------------------
11.6 Verify Keycloak discovery
--------------------------------------------------------------------------------

Run:

    curl -s \
      http://localhost:9000/realms/myapp/.well-known/openid-configuration \
      | python3 -m json.tool


Confirm the discovery document contains the required OIDC endpoints.


================================================================================
12. COMMON ERRORS AND FIXES
================================================================================

12.1 TypeScript says the callback code may be undefined

Symptom:

    Argument of type
    'LocationQueryValue$1 | LocationQueryValue$1[] | undefined'
    is not assignable to parameter of type 'string'.

Cause:

    route.query.code is not guaranteed to be a string.

Fix:

    Check the value before calling exchangeCode().

The callback should use logic equivalent to:

    const code = route.query.code

    if (typeof code !== 'string' || !code) {
        await navigateTo('/login?error=missing_auth_code', {
            replace: true,
        })
        return
    }

    const success = await exchangeCode(code)

    if (!success) {
        await navigateTo('/login?error=auth_exchange_failed', {
            replace: true,
        })
        return
    }

    await navigateTo('/dashboard', {
        replace: true,
    })


The important part is the explicit:

    return

after the failed validation/navigation.

Without it, TypeScript may still consider code potentially undefined because
navigateTo() does not automatically terminate the current function's control
flow.


--------------------------------------------------------------------------------
12.2 /api/user returns 401 after successful login
--------------------------------------------------------------------------------

Possible causes:

    - Laravel session cookie was not created.
    - SESSION_DOMAIN is incorrect.
    - Sanctum statefulApi() is missing.
    - The browser did not send credentials.
    - Nuxt SSR did not forward cookies.
    - Laravel session configuration is incorrect.


Check bootstrap/app.php:

    $middleware->statefulApi();


Check .env:

    SESSION_DOMAIN=localhost

    SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000


Check Nuxt requests:

    credentials: 'include'


For SSR requests, use:

    useRequestFetch()


--------------------------------------------------------------------------------
12.3 Session cookie is missing
--------------------------------------------------------------------------------

Cause:

    The browser is using localhost while Laravel is configured for
    127.0.0.1, or vice versa.

Fix:

    Use localhost consistently:

        http://localhost:3000
        http://localhost:8000
        http://localhost:9000


Then:

    php artisan config:clear


--------------------------------------------------------------------------------
12.4 Authorization code exchange fails
--------------------------------------------------------------------------------

Possible causes:

    - The code has already been used.
    - The code expired.
    - The redirect URI does not match.
    - Client ID is incorrect.
    - Client secret is incorrect.
    - Keycloak cannot be reached.
    - PKCE parameters do not match if PKCE is enabled.
    - The OIDC issuer is incorrect.
    - The browser callback is using a different redirect URI from the one
      registered in Keycloak.


Check:

    OIDC_ISSUER_URL
    OIDC_CLIENT_ID
    OIDC_CLIENT_SECRET
    OIDC_REDIRECT_URI


Make sure the redirect URI matches the actual flow.


--------------------------------------------------------------------------------
12.5 Invalid redirect_uri
--------------------------------------------------------------------------------

Cause:

    The redirect URI sent during the authorization flow does not exactly
    match the URI registered in Keycloak.

Do not mix:

    localhost

and:

    127.0.0.1


For the current browser callback architecture, verify the Keycloak redirect
configuration corresponds to:

    http://localhost:3000/callback


The exact value depends on the OIDC package's authorization-code flow.


--------------------------------------------------------------------------------
12.6 Invalid post_logout_redirect_uri
--------------------------------------------------------------------------------

Cause:

    Keycloak does not recognize the URI Laravel sends during federated
    logout.

For local development, register:

    http://localhost:3000

in the Keycloak client's valid post logout redirect URIs.

Make sure the value exactly matches the Laravel:

    FRONTEND_URL=http://localhost:3000


--------------------------------------------------------------------------------
12.7 419 on POST /api/logout
--------------------------------------------------------------------------------

Cause:

    Laravel's CSRF middleware is rejecting the cross-origin API request.


Current development configuration:

    $middleware->validateCsrfTokens(except: [
        'api/*',
    ]);


For production, revisit this decision and implement an explicit CSRF strategy
appropriate for the deployment architecture.


--------------------------------------------------------------------------------
12.8 Method Illuminate\Auth\RequestGuard::logout does not exist
--------------------------------------------------------------------------------

Cause:

    The request is authenticated through auth:sanctum, whose guard is not
    the session guard used for Laravel logout.


Fix:

    Auth::guard('web')->logout();


Then:

    $request->session()->invalidate();
    $request->session()->regenerateToken();


--------------------------------------------------------------------------------
12.9 Login works but user is immediately unauthenticated
--------------------------------------------------------------------------------

Cause:

    Laravel successfully created a session but the browser did not retain
    or return the Laravel session cookie.


Check:

    SESSION_DOMAIN=localhost
    SESSION_SECURE_COOKIE=false
    SESSION_PATH=/
    SANCTUM_STATEFUL_DOMAINS=localhost:3000,localhost:8000


Also verify the browser's Application/Storage tab.


--------------------------------------------------------------------------------
12.10 Logout succeeds but the next login is automatic
--------------------------------------------------------------------------------

Cause:

    Laravel's session was destroyed, but the Keycloak SSO session remained.


Fix:

    Follow the logout_url returned by Laravel.


The browser must reach Keycloak's end-session endpoint.


--------------------------------------------------------------------------------
12.11 Keycloak still logs the user in after logout
--------------------------------------------------------------------------------

Check:

    - Laravel actually returned logout_url.
    - Nuxt actually navigated to logout_url.
    - Keycloak has a valid post logout redirect URI.
    - The correct client_id was supplied.
    - The Keycloak SSO cookie was actually cleared.


--------------------------------------------------------------------------------
12.12 Configuration changes are not taking effect
--------------------------------------------------------------------------------

Run:

    php artisan config:clear
    php artisan route:clear
    php artisan cache:clear


Then restart:

    php artisan serve


================================================================================
13. PRODUCTION MIGRATION CHECKLIST
================================================================================

The production architecture should retain the same fundamental security model.

Production values should look conceptually like:

    APP_ENV=production
    APP_DEBUG=false

    APP_URL=https://api.company.com

    FRONTEND_URL=https://app.company.com

    OIDC_ISSUER_URL=https://login.company.com/realms/myapp

    OIDC_CLIENT_ID=<production-client-id>

    OIDC_CLIENT_SECRET=<production-secret>

    OIDC_REDIRECT_URI=https://app.company.com/callback

    OIDC_ALLOW_INSECURE_URLS=<remove>

    SESSION_SECURE_COOKIE=true

    SANCTUM_STATEFUL_DOMAINS=app.company.com,api.company.com


IMPORTANT:

Do not copy development secrets into production.

Generate a separate production Keycloak client secret.


--------------------------------------------------------------------------------
13.1 HTTPS
--------------------------------------------------------------------------------

Production must use HTTPS.

The following should all use HTTPS:

    Nuxt
    Laravel
    Keycloak


--------------------------------------------------------------------------------
13.2 Secure cookies
--------------------------------------------------------------------------------

Use:

    SESSION_SECURE_COOKIE=true


The Laravel session cookie should remain HttpOnly.


--------------------------------------------------------------------------------
13.3 Session domain
--------------------------------------------------------------------------------

Depending on the final deployment topology, configure the session cookie
domain deliberately.

For example:

    SESSION_DOMAIN=.company.com


Only use a parent-domain cookie when the deployment actually requires it.


--------------------------------------------------------------------------------
13.4 CORS
--------------------------------------------------------------------------------

Never use:

    Access-Control-Allow-Origin: *

together with credentials.

Explicitly allow the production SPA origin.


Example:

    https://app.company.com


--------------------------------------------------------------------------------
13.5 Trusted proxies
--------------------------------------------------------------------------------

If Laravel is behind Nginx, a load balancer, Cloudflare, Kubernetes ingress,
or another reverse proxy, configure trusted proxies correctly.

Do not blindly trust every proxy in security-sensitive deployments.

Specify the actual proxy IP ranges when possible.


--------------------------------------------------------------------------------
13.6 Database
--------------------------------------------------------------------------------

SQLite is appropriate for this demonstration.

Production should normally use:

    PostgreSQL

or:

    MySQL


Use a managed/production-grade database where appropriate.


--------------------------------------------------------------------------------
13.7 Keycloak configuration
--------------------------------------------------------------------------------

Create a dedicated production Keycloak client.

Do not reuse the local development client secret.

Register the exact production callback URI.

Register the exact production post-logout URI.


--------------------------------------------------------------------------------
13.8 Logging
--------------------------------------------------------------------------------

Never log:

    access_token
    id_token
    refresh_token
    client_secret
    session secrets

Log identifiers and error context instead.


--------------------------------------------------------------------------------
13.9 Session lifetime
--------------------------------------------------------------------------------

Coordinate:

    Laravel session lifetime

with:

    Keycloak SSO session timeout
    Keycloak idle timeout
    Keycloak maximum session lifespan


These values should be intentionally designed rather than left accidental.


--------------------------------------------------------------------------------
13.10 What should remain unchanged
--------------------------------------------------------------------------------

The following architectural rules should remain unchanged:

    - Nuxt does not hold OIDC tokens.
    - Laravel remains the OIDC client.
    - Keycloak remains the identity provider.
    - Laravel owns the application session.
    - OIDC issuer + subject identify the local user.
    - API authentication uses the Laravel session.
    - 401 means unauthenticated.
    - 403 means authenticated but unauthorized.
    - The client secret remains server-side.


================================================================================
14. SECURITY NOTES
================================================================================

RULE 1 — Keycloak owns authentication

Laravel does not authenticate the user's password.

Keycloak does.


RULE 2 — Laravel owns application authorization

Laravel decides whether an authenticated user is allowed to perform an
application action.

Do not trust the Nuxt SPA to enforce authorization.


RULE 3 — The authorization code is not a token

The temporary authorization code is exchanged with Keycloak.

The SPA must not treat it as an access token.

The code should be:

    short-lived
    single-use
    exchanged immediately


RULE 4 — OIDC tokens remain server-side

After Laravel exchanges the authorization code, token responses remain
server-side.

Never return them to Nuxt.


RULE 5 — Decode is not validate

Decoding a JWT does not prove that it is authentic.

OIDC token validation must verify the appropriate signatures, issuer,
audience, expiry, nonce/state/PKCE requirements as applicable to the flow.


RULE 6 — Never trust the frontend

The SPA does not tell Laravel:

    "I am user 123."

Instead, Laravel determines the authenticated user from the server-side
session.


RULE 7 — 401 vs 403

    401 Unauthorized

means:

    The request is not authenticated.


    403 Forbidden

means:

    The request is authenticated, but the user is not allowed to perform
    the requested operation.


RULE 8 — Keep the client secret secret

The following value must never reach Nuxt:

    OIDC_CLIENT_SECRET


It belongs only on the Laravel server.


RULE 9 — Do not store authentication state in localStorage

Do not put:

    access tokens
    refresh tokens
    ID tokens
    client secrets

into localStorage or sessionStorage.


RULE 10 — Session cookies should be protected

Production should use:

    HttpOnly
    Secure
    appropriate SameSite policy


RULE 11 — Do not mix hosts

During development choose:

    localhost

and use it consistently.

Do not alternate between:

    localhost

and:

    127.0.0.1


RULE 12 — Logout must terminate both sessions

Application logout should clear:

    Laravel session

and, where federated logout is enabled:

    Keycloak SSO session


================================================================================
15. RELATED PROJECTS
================================================================================

bakery-spa

    Nuxt 4 frontend for this API.

    Responsibilities:

        - Render the UI.
        - Start login navigation.
        - Receive the authorization callback.
        - Send the authorization code to Laravel.
        - Maintain client-side authentication state.
        - Call /api/user.
        - Call /api/logout.

    The SPA does not communicate directly with Keycloak.


Keycloak

    Identity Provider.

    Realm:

        myapp

    Client:

        nuxt-laravel-bakery

    Responsibilities:

        - Authenticate users.
        - Store passwords.
        - Maintain the SSO session.
        - Provide the OIDC identity.
        - Perform federated logout.


================================================================================
16. AUTHOR
================================================================================

This project was developed by Tochukwu Uchem.

Github:

    https://github.com/uchemcolin

Linkedin:

    https://www.linkedin.com/in/tochukwu-uchem-802888144/

Gitlab:

    https://gitlab.com/uchemcolin


================================================================================
END OF DOCUMENT
================================================================================
