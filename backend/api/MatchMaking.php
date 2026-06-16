<?php

declare(strict_types=1);

const QUEUE_DIR       = __DIR__ . '/../cache/queue/';
const MATCH_TTL       = 300;
const QUEUE_TTL       = 120;
const ELO_WINDOW_BASE = 100;
const ELO_WINDOW_GROW = 50;
const ELO_WINDOW_MAX  = 400;
const POLL_TIMEOUT    = 25;
const POLL_INTERVAL   = 1;
const CONFIRM_TIMEOUT = 60;

// ── BOOTSTRAP ──────────────────────────────────────────────
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Cache-Control: no-store, no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!is_dir(QUEUE_DIR)) {
    mkdir(QUEUE_DIR, 0755, true);
}

// Read body ONCE here — php://input is a read-once stream
$body = jsonBody();

// Action comes from: GET param → POST param → JSON body
$action = $_GET['action'] ?? $_POST['action'] ?? $body['action'] ?? '';

// Route — pass $body explicitly to POST actions
try {
    match ($action) {
        'join'         => actionJoin($body),
        'leave'        => actionLeave($body),
        'confirm'      => actionConfirm($body),
        'poll'         => actionPoll(),
        'queue_status' => actionQueueStatus(),
        'match_state'  => actionMatchState(),
        default        => respond(400, ['error' => "Unknown action: {$action}"])
    };
} catch (Throwable $e) {
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}


// ═══════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════

function actionJoin(array $body): void   // ← accepts $body as parameter
{
    $userId   = intval($body['user_id']   ?? 0);
    $username = sanitize($body['username'] ?? '');
    $elo      = intval($body['elo']        ?? 1200);
    $mode     = in_array($body['mode'] ?? '', ['casual', 'ranked']) ? $body['mode'] : 'casual';
    $token    = sanitize($body['token']    ?? '');

    if (!$userId || !$username || !$token) {
        respond(400, ['error' => 'Missing required fields: user_id, username, token']);
    }

    $queueFile = queueFile($mode);

    withFileLock($queueFile, function () use ($queueFile, $userId, $username, $elo, $mode, $token) {
        $queue = readQueue($queueFile);
        $queue = purgeStale($queue);
        $queue = array_values(array_filter($queue, fn($e) => $e['user_id'] !== $userId));
        $queue[] = [
            'user_id'   => $userId,
            'username'  => $username,
            'elo'       => $elo,
            'mode'      => $mode,
            'token'     => $token,
            'joined_at' => time(),
        ];
        writeQueue($queueFile, $queue);
    });

    respond(200, [
        'success'    => true,
        'mode'       => $mode,
        'queue_size' => count(readQueue($queueFile)),
        'message'    => "Joined {$mode} queue",
    ]);
}


function actionLeave(array $body): void   // ← accepts $body as parameter
{
    $userId = intval($body['user_id'] ?? 0);
    $token  = sanitize($body['token'] ?? '');
    $mode   = in_array($body['mode'] ?? '', ['casual', 'ranked']) ? $body['mode'] : 'casual';

    if (!$userId || !$token) {
        respond(400, ['error' => 'Missing user_id or token']);
    }

    $queueFile = queueFile($mode);

    withFileLock($queueFile, function () use ($queueFile, $userId, $token) {
        $queue = readQueue($queueFile);
        $queue = array_values(
            array_filter($queue, fn($e) => !($e['user_id'] === $userId && $e['token'] === $token))
        );
        writeQueue($queueFile, $queue);
    });

    respond(200, ['success' => true, 'message' => 'Left queue']);
}


function actionPoll(): void
{
    $userId = intval($_GET['user_id'] ?? 0);
    $token  = sanitize($_GET['token'] ?? '');
    $mode   = in_array($_GET['mode'] ?? '', ['casual', 'ranked']) ? $_GET['mode'] : 'casual';

    if (!$userId || !$token) {
        respond(400, ['error' => 'Missing user_id or token']);
    }

    @ob_end_clean();
    set_time_limit(POLL_TIMEOUT + 5);

    $started   = time();
    $queueFile = queueFile($mode);

    while (true) {
        $elapsed = time() - $started;
        $queue   = readQueue($queueFile);
        $queue   = purgeStale($queue);
        writeQueueSafe($queueFile, $queue);

        $myEntry = findEntry($queue, $userId, $token);
        if (!$myEntry) {
            respond(200, ['status' => 'cancelled']);
        }

        $waitSeconds = time() - $myEntry['joined_at'];

        $existingMatch = findExistingMatch($userId, $mode);
        if ($existingMatch) {
            $side = $existingMatch['plant_player']['user_id'] === $userId ? 'plants' : 'zombies';
            respond(200, [
                'status'   => 'matched',
                'match_id' => $existingMatch['match_id'],
                'opponent' => $side === 'plants'
                    ? $existingMatch['zombie_player']
                    : $existingMatch['plant_player'],
                'side'     => $side,
                'mode'     => $mode,
            ]);
        }

        $eloWindow = eloWindow($waitSeconds);
        $opponent  = findOpponent($queue, $myEntry, $eloWindow);

        if ($opponent) {
            $matchId = createMatch($myEntry, $opponent, $mode);

            withFileLock($queueFile, function () use ($queueFile, $userId, $opponent) {
                $q = readQueue($queueFile);
                $q = array_values(array_filter(
                    $q,
                    fn($e) => $e['user_id'] !== $userId && $e['user_id'] !== $opponent['user_id']
                ));
                writeQueue($queueFile, $q);
            });

            $match = readMatch($matchId);
            $side  = $match['plant_player']['user_id'] === $userId ? 'plants' : 'zombies';

            respond(200, [
                'status'   => 'matched',
                'match_id' => $matchId,
                'opponent' => $side === 'plants' ? $match['zombie_player'] : $match['plant_player'],
                'side'     => $side,
                'mode'     => $mode,
            ]);
        }

        if ($elapsed >= POLL_TIMEOUT) {
            respond(200, [
                'status'       => 'waiting',
                'queue_size'   => count($queue),
                'wait_seconds' => $waitSeconds,
                'elo_window'   => $eloWindow,
            ]);
        }

        sleep(POLL_INTERVAL);
    }
}


function actionQueueStatus(): void
{
    $casual = purgeStale(readQueue(queueFile('casual')));
    $ranked = purgeStale(readQueue(queueFile('ranked')));

    respond(200, [
        'casual_count'    => count($casual),
        'ranked_count'    => count($ranked),
        'casual_wait_est' => estimateWait(count($casual)),
        'ranked_wait_est' => estimateWait(count($ranked), true),
    ]);
}


function actionConfirm(array $body): void   // ← accepts $body as parameter
{
    $matchId = sanitize($body['match_id'] ?? '');
    $userId  = intval($body['user_id']    ?? 0);
    $token   = sanitize($body['token']    ?? '');

    if (!$matchId || !$userId || !$token) {
        respond(400, ['error' => 'Missing match_id, user_id, or token']);
    }

    $matchFile = matchFile($matchId);
    if (!file_exists($matchFile)) {
        respond(404, ['error' => 'Match not found or expired']);
    }

    $result = null;

    withFileLock($matchFile, function () use ($matchFile, $userId, $token, &$result) {
        $match = readJson($matchFile);

        if ($match['status'] === 'expired') {
            $result = ['error' => 'Match confirmation window expired', 'status' => 'expired'];
            return;
        }

        $isPlant  = $match['plant_player']['user_id']  === $userId;
        $isZombie = $match['zombie_player']['user_id'] === $userId;

        if (!$isPlant && !$isZombie) {
            $result = ['error' => 'User not in this match'];
            return;
        }

        if ($isPlant)  $match['confirmed']['plants']  = true;
        if ($isZombie) $match['confirmed']['zombies'] = true;

        if (time() - $match['created_at'] > CONFIRM_TIMEOUT) {
            $match['status'] = 'expired';
            writeJson($matchFile, $match);
            $result = ['error' => 'Confirmation window expired', 'status' => 'expired'];
            return;
        }

        if ($match['confirmed']['plants'] && $match['confirmed']['zombies']) {
            $match['status']     = 'active';
            $match['started_at'] = time();
        }

        writeJson($matchFile, $match);
        $result = [
            'success'  => true,
            'status'   => $match['status'],
            'match_id' => $match['match_id'],
        ];
    });

    respond(isset($result['error']) ? 400 : 200, $result);
}


function actionMatchState(): void
{
    $matchId = sanitize($_GET['match_id'] ?? '');
    if (!$matchId) {
        respond(400, ['error' => 'Missing match_id']);
    }

    $matchFile = matchFile($matchId);
    if (!file_exists($matchFile)) {
        respond(404, ['error' => 'Match not found']);
    }

    respond(200, ['success' => true, 'match' => readMatch($matchId)]);
}


// ═══════════════════════════════════════════════════════════
// MATCHMAKING LOGIC
// ═══════════════════════════════════════════════════════════

function findOpponent(array $queue, array $seeker, int $eloWindow): ?array
{
    $isRanked   = ($seeker['mode'] === 'ranked');
    $candidates = array_filter(
        $queue,
        fn($e) => $e['user_id'] !== $seeker['user_id']
               && $e['mode']    === $seeker['mode']
               && (!$isRanked || abs($e['elo'] - $seeker['elo']) <= $eloWindow)
    );

    if (empty($candidates)) return null;

    if ($isRanked) {
        usort($candidates, fn($a, $b) =>
            abs($a['elo'] - $seeker['elo']) <=> abs($b['elo'] - $seeker['elo'])
        );
    } else {
        usort($candidates, fn($a, $b) => $a['joined_at'] <=> $b['joined_at']);
    }

    return array_values($candidates)[0];
}

function eloWindow(int $waitSeconds): int
{
    return min(ELO_WINDOW_MAX, ELO_WINDOW_BASE + (intdiv($waitSeconds, 30) * ELO_WINDOW_GROW));
}

function estimateWait(int $queueSize, bool $ranked = false): int
{
    if ($queueSize >= 2) return 10;
    if ($queueSize === 1) return $ranked ? 90 : 30;
    return $ranked ? 180 : 60;
}


// ═══════════════════════════════════════════════════════════
// MATCH RECORD HELPERS
// ═══════════════════════════════════════════════════════════

function createMatch(array $player1, array $player2, string $mode): string
{
    $matchId = generateUuid();

    if (rand(0, 1)) {
        [$player1, $player2] = [$player2, $player1];
    }

    $match = [
        'match_id'     => $matchId,
        'mode'         => $mode,
        'status'       => 'pending',
        'plant_player' => [
            'user_id'  => $player1['user_id'],
            'username' => $player1['username'],
            'elo'      => $player1['elo'],
        ],
        'zombie_player' => [
            'user_id'  => $player2['user_id'],
            'username' => $player2['username'],
            'elo'      => $player2['elo'],
        ],
        'confirmed'  => ['plants' => false, 'zombies' => false],
        'created_at' => time(),
        'started_at' => null,
        'ended_at'   => null,
        'winner'     => null,
    ];

    writeJson(matchFile($matchId), $match);
    return $matchId;
}

function findExistingMatch(int $userId, string $mode): ?array
{
    foreach (glob(QUEUE_DIR . 'match_*.json') ?: [] as $file) {
        $match = readJson($file);
        if (!$match) continue;
        if (!in_array($match['status'] ?? '', ['pending', 'active'])) continue;
        if ($match['mode'] !== $mode) continue;
        if (time() - ($match['created_at'] ?? 0) > MATCH_TTL) continue;

        $isPlayer = $match['plant_player']['user_id']  === $userId
                 || $match['zombie_player']['user_id'] === $userId;
        if ($isPlayer) return $match;
    }
    return null;
}

function readMatch(string $matchId): ?array
{
    $file = matchFile($matchId);
    return file_exists($file) ? readJson($file) : null;
}


// ═══════════════════════════════════════════════════════════
// QUEUE FILE HELPERS
// ═══════════════════════════════════════════════════════════

function queueFile(string $mode): string  { return QUEUE_DIR . "{$mode}_queue.json"; }
function matchFile(string $matchId): string { return QUEUE_DIR . "match_{$matchId}.json"; }

function readQueue(string $file): array
{
    if (!file_exists($file)) return [];
    $data = readJson($file);
    return is_array($data) ? $data : [];
}

function writeQueue(string $file, array $data): void
{
    writeJson($file, array_values($data));
}

function writeQueueSafe(string $file, array $data): void
{
    withFileLock($file, fn() => writeQueue($file, $data));
}

function purgeStale(array $queue): array
{
    $cutoff = time() - QUEUE_TTL;
    return array_values(array_filter($queue, fn($e) => ($e['joined_at'] ?? 0) >= $cutoff));
}

function findEntry(array $queue, int $userId, string $token): ?array
{
    foreach ($queue as $entry) {
        if ($entry['user_id'] === $userId && $entry['token'] === $token) return $entry;
    }
    return null;
}


// ═══════════════════════════════════════════════════════════
// LOCKING & JSON IO
// ═══════════════════════════════════════════════════════════

function withFileLock(string $targetFile, callable $callback): void
{
    $lockFile = $targetFile . '.lock';
    $fp = fopen($lockFile, 'c');
    if (!$fp) throw new RuntimeException("Cannot open lock file: {$lockFile}");
    flock($fp, LOCK_EX);
    try {
        $callback();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function readJson(string $file): mixed
{
    if (!file_exists($file)) return null;
    $raw = file_get_contents($file);
    if ($raw === false || trim($raw) === '') return null;
    return json_decode($raw, true);
}

function writeJson(string $file, mixed $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}


// ═══════════════════════════════════════════════════════════
// UTILITIES
// ═══════════════════════════════════════════════════════════

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

function sanitize(string $val): string
{
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function generateUuid(): string
{
    $data    = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function respond(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}