<?php
/**
 * Verdant Siege — Auth API
 * ─────────────────────────────────────────────────────────────
 * Single-file endpoint. Action-based routing via ?action=xxx.
 *
 * Actions:
 *   POST register      → validate, hash pw, insert user, return JWT
 *   POST login         → verify pw, return JWT + user payload
 *   POST logout        → invalidate current session token
 *   GET  verify        → validate JWT, return user payload (middleware use)
 *   GET  me            → return full profile of current user
 *   POST logout_all    → invalidate ALL sessions for this user
 *
 * After login/register, frontend must:
 *   sessionStorage.setItem('auth_token', data.token);
 *   sessionStorage.setItem('user_id',    data.user.id);
 *   sessionStorage.setItem('username',   data.user.username);
 *   sessionStorage.setItem('elo',        data.user.elo_rating);
 *
 * These values are what matchmaking.js reads.
 */

declare(strict_types=1);

// ── AUTOLOAD ──────────────────────────────────────────────────
// Composer autoload (firebase/php-jwt)
require_once __DIR__ . '/../../vendor/autoload.php';

// Project files
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/AutoAccessors.php';
require_once __DIR__ . '/../entity/User.php';
require_once __DIR__ . '/../dto/request/UserRequest.php';
require_once __DIR__ . '/../dto/response/UserResponse.php';
require_once __DIR__ . '/../repository/UserRepository.php';
require_once __DIR__ . '/../service/UserService.php';
require_once __DIR__ . '/../service/AuthService.php';

// ── HEADERS ───────────────────────────────────────────────────
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-store, no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── BOOTSTRAP ─────────────────────────────────────────────────
$db          = Database::getInstance()->getConnection();
$userService = new UserService($db);
$authService = new AuthService($db);

// Read body once
$body   = jsonBody();
$action = $_GET['action'] ?? $_POST['action'] ?? $body['action'] ?? '';

// ── ROUTE ─────────────────────────────────────────────────────
try {
    match ($action) {
        'register'   => actionRegister($body, $userService, $authService, $db),
        'login'      => actionLogin($body, $userService, $authService, $db),
        'logout'     => actionLogout($authService),
        'logout_all' => actionLogoutAll($authService),
        'verify'     => actionVerify($authService),
        'me'         => actionMe($authService, $userService),
        default      => respond(400, ['error' => "Unknown action: {$action}"])
    };
} catch (Throwable $e) {
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}


// ═════════════════════════════════════════════════════════════
// ACTIONS
// ═════════════════════════════════════════════════════════════

/**
 * POST register
 * Body: { username, email, password, display_name? }
 */
function actionRegister(array $body, UserService $us, AuthService $as, PDO $db): void
{
    $username    = sanitize($body['username']     ?? '');
    $email       = sanitize($body['email']        ?? '');
    $password    = $body['password']              ?? '';   // never sanitize passwords
    $displayName = sanitize($body['display_name'] ?? $username);

    // Basic presence validation
    if (!$username || !$email || !$password) {
        respond(400, ['error' => 'Username, email, and password are required']);
    }
    if (strlen($username) < 3 || strlen($username) > 50) {
        respond(400, ['error' => 'Username must be 3–50 characters']);
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        respond(400, ['error' => 'Username: letters, numbers, and underscores only']);
    }
    if (strlen($password) < 6) {
        respond(400, ['error' => 'Password must be at least 6 characters']);
    }

    // Delegate to UserService (checks duplicate username/email, hashes pw, inserts)
    try {
        $request  = new UserRequestDTO($username, $email, $password, $displayName);
        $response = $us->registerUser($request);
    } catch (InvalidArgumentException $e) {
        respond(400, ['error' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        // "Username already exists" | "Email already exists"
        respond(409, ['error' => $e->getMessage()]);
    }

    // Also create user_settings row
    $db->prepare("
        INSERT IGNORE INTO user_settings (user_id) VALUES (?)
    ")->execute([$response->id]);

    // Issue JWT
    $userData = userResponseToArray($response);
    $jwt      = $as->createToken($userData);

    respond(201, [
        'success'    => true,
        'message'    => 'Account created! Welcome to Verdant Siege 🌻',
        'token'      => $jwt['token'],
        'expires_at' => $jwt['expires_at'],
        'user'       => publicProfile($userData),
    ]);
}


/**
 * POST login
 * Body: { username, password }  (username can be email too)
 */
function actionLogin(array $body, UserService $us, AuthService $as, PDO $db): void
{
    $usernameOrEmail = sanitize($body['username'] ?? $body['email'] ?? '');
    $password        = $body['password'] ?? '';

    if (!$usernameOrEmail || !$password) {
        respond(400, ['error' => 'Username/email and password are required']);
    }

    try {
        $response = $us->authenticateUser($usernameOrEmail, $password);
    } catch (RuntimeException $e) {
        // "Invalid credentials" | "Account is banned" | "Account is inactive"
        $msg = $e->getMessage();
        $code = str_contains($msg, 'banned') || str_contains($msg, 'inactive') ? 403 : 401;
        respond($code, ['error' => $msg]);
    }

    $userData = userResponseToArray($response);
    $jwt      = $as->createToken($userData);

    respond(200, [
        'success'    => true,
        'message'    => "Welcome back, {$response->username}! 🌿",
        'token'      => $jwt['token'],
        'expires_at' => $jwt['expires_at'],
        'user'       => publicProfile($userData),
    ]);
}


/**
 * POST logout
 * Header: Authorization: Bearer <token>
 */
function actionLogout(AuthService $as): void
{
    $token = AuthService::extractToken();
    if (!$token) {
        respond(400, ['error' => 'No token provided']);
    }

    try {
        $as->invalidateToken($token);
    } catch (Throwable $_) {
        // Already expired — treat as success
    }

    respond(200, ['success' => true, 'message' => 'Logged out']);
}


/**
 * POST logout_all
 * Invalidates every session for this user (all devices).
 */
function actionLogoutAll(AuthService $as): void
{
    $token = AuthService::extractToken();
    if (!$token) respond(400, ['error' => 'No token provided']);

    $payload = $as->verifyToken($token);
    $as->invalidateAllSessions((int) $payload['user_id']);

    respond(200, ['success' => true, 'message' => 'All sessions invalidated']);
}


/**
 * GET verify
 * Header: Authorization: Bearer <token>
 * Used by other endpoints to protect routes.
 * Returns the JWT payload (user_id, username, elo, coins).
 */
function actionVerify(AuthService $as): void
{
    $token = AuthService::extractToken();
    if (!$token) {
        respond(401, ['error' => 'No token provided']);
    }

    try {
        $payload = $as->verifyToken($token);
        respond(200, ['success' => true, 'payload' => $payload]);
    } catch (Throwable $e) {
        respond(401, ['error' => 'Invalid or expired token', 'detail' => $e->getMessage()]);
    }
}


/**
 * GET me
 * Returns full profile of the authenticated user.
 */
function actionMe(AuthService $as, UserService $us): void
{
    $token = AuthService::extractToken();
    if (!$token) respond(401, ['error' => 'No token provided']);

    try {
        $payload  = $as->verifyToken($token);
        $response = $us->getUserById((int) $payload['user_id']);
    } catch (Throwable $e) {
        respond(401, ['error' => 'Invalid or expired token']);
    }

    if (!$response) {
        respond(404, ['error' => 'User not found']);
    }

    respond(200, [
        'success' => true,
        'user'    => publicProfile(userResponseToArray($response)),
    ]);
}


// ═════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════

/**
 * Public profile — strips sensitive fields before sending to client.
 */
function publicProfile(array $user): array {
    return [
        'id'            => $user['id'],
        'username'      => $user['username'],
        'email'         => $user['email'],
        'display_name'  => $user['display_name']  ?? $user['displayName']  ?? null,
        'avatar_url'    => $user['avatar_url']     ?? $user['avatarUrl']    ?? null,
        'favorite_unit' => $user['favorite_unit']  ?? $user['favoriteUnit'] ?? null,
        'bio'           => $user['bio']            ?? null,
        // Stats
        'elo_rating'    => $user['elo_rating']     ?? $user['eloRating']    ?? 1200,
        'total_matches' => $user['total_matches']  ?? $user['totalMatches'] ?? 0,
        'wins'          => $user['wins']           ?? 0,
        'losses'        => $user['losses']         ?? 0,
        'draws'         => $user['draws']          ?? 0,
        'current_streak'=> $user['current_streak'] ?? $user['currentStreak'] ?? 0,
        'best_streak'   => $user['best_streak']    ?? $user['bestStreak']   ?? 0,
        // Economy
        'coins'         => $user['coins']          ?? 500,
        'gems'          => $user['gems']           ?? 0,
        // Timestamps
        'created_at'    => $user['created_at']     ?? $user['createdAt']    ?? null,
        'last_login_at' => $user['last_login_at']  ?? $user['lastLoginAt']  ?? null,
    ];
}

/**
 * Convert UserResponse object to array.
 * UserResponse uses camelCase public props — convert to snake_case for consistency.
 */
function userResponseToArray(UserResponse $r): array {
    return [
        'id'                 => $r->id,
        'username'           => $r->username,
        'email'              => $r->email,
        'display_name'       => $r->displayName,
        'bio'                => $r->bio,
        'avatar_url'         => $r->avatarUrl,
        'favorite_unit'      => $r->favoriteUnit,
        'elo_rating'         => $r->eloRating,
        'total_matches'      => $r->totalMatches,
        'wins'               => $r->wins,
        'losses'             => $r->losses,
        'draws'              => $r->draws,
        'current_streak'     => $r->currentStreak,
        'best_streak'        => $r->bestStreak,
        'coins'              => $r->coins,
        'gems'               => $r->gems,
        'total_coins_earned' => $r->totalCoinsEarned,
        'total_gems_earned'  => $r->totalGemsEarned,
        'is_active'          => $r->isActive,
        'is_banned'          => $r->isBanned,
        'ban_reason'         => $r->banReason,
        'ban_expires_at'     => $r->banExpiresAt,
        'last_login_at'      => $r->lastLoginAt,
        'last_ip'            => $r->lastIp,
        'created_at'         => $r->createdAt,
        'updated_at'         => $r->updatedAt,
    ];
}

function jsonBody(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function respond(int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}