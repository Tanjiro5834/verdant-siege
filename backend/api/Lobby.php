<?php
/**
 * Verdant Siege — Lobby API Endpoint
 * Returns all data needed for lobby.html in one request.
 *
 * GET ?action=data   → user profile + recent matches + leaderboard
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/AutoAccessors.php';
require_once __DIR__ . '/../entity/User.php';
require_once __DIR__ . '/../dto/request/UserRequest.php';
require_once __DIR__ . '/../dto/response/UserResponse.php';
require_once __DIR__ . '/../repository/UserRepository.php';
require_once __DIR__ . '/../repository/MatchRepository.php';
require_once __DIR__ . '/../service/UserService.php';
require_once __DIR__ . '/../service/AuthService.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$db          = Database::getInstance()->getConnection();
$authService = new AuthService($db);

// Auth
$token = AuthService::extractToken();
if (!$token) respond(401, ['error' => 'No token provided']);

try {
    $player = $authService->verifyToken($token);
} catch (Throwable $e) {
    respond(401, ['error' => 'Invalid or expired token']);
}

$userId = (int) $player['user_id'];

try {
    $userRepo  = new UserRepository($db);
    $matchRepo = new MatchRepository($db);

    // 1. User profile
    $userData = $userRepo->getById($userId);
    if (!$userData) respond(404, ['error' => 'User not found']);

    $total   = (int)$userData['wins'] + (int)$userData['losses'];
    $winRate = $total > 0 ? round(($userData['wins'] / $total) * 100, 1) : 0;

    // 2. Recent matches (last 5)
    $recentMatches = $matchRepo->findMatchHistory($userId, 5);

    // 3. Leaderboard (top 10)
    $userService = new UserService($db);
    $leaderboard = $userService->getLeaderboard(10);

    // 4. Current user rank
    $rankPos = $matchRepo->getRankPosition((int)$userData['elo_rating']);

    respond(200, [
        'success' => true,
        'user'    => [
            'id'             => $userData['id'],
            'username'       => $userData['username'],
            'display_name'   => $userData['display_name'] ?? $userData['username'],
            'avatar_url'     => $userData['avatar_url'],
            'elo_rating'     => $userData['elo_rating'],
            'rank_position'  => $rankPos,
            'wins'           => $userData['wins'],
            'losses'         => $userData['losses'],
            'draws'          => $userData['draws'],
            'win_rate'       => $winRate,
            'current_streak' => $userData['current_streak'],
            'best_streak'    => $userData['best_streak'],
            'coins'          => $userData['coins'],
            'gems'           => $userData['gems'],
            'total_matches'  => $userData['total_matches'],
        ],
        'recent_matches' => $recentMatches,
        'leaderboard'    => array_map(fn($u) => [
            'id'         => $u->id,
            'username'   => $u->username,
            'elo_rating' => $u->eloRating,
            'wins'       => $u->wins,
            'losses'     => $u->losses,
            'is_me'      => $u->id === $userId,
        ], $leaderboard),
    ]);

} catch (Throwable $e) {
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}

function respond(int $code, array $payload): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}