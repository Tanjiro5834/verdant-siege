<?php
/**
 * Verdant Siege — Game API
 * ─────────────────────────────────────────────────────────────
 * Handles all in-game actions and real-time state sync via SSE.
 *
 * Actions:
 *   POST init         → load match from matchmaking, init board in DB
 *   POST place_unit   → validate + write unit to game_units
 *   POST end_turn     → run combat, advance zombies, switch turn
 *   POST surrender    → end match, write winner
 *   GET  state        → full board snapshot (reconnect / initial load)
 *   GET  stream       → SSE stream — pushes state changes to both players
 *
 * Auth: every request must include Authorization: Bearer <token>
 *       OR ?token=<jwt> as query param (same fallback as Auth.php)
 *
 * Real-time strategy:
 *   - No WebSockets needed. SSE holds a GET connection open.
 *   - On each POST action, PHP writes a "tick" file to cache/game/
 *   - The SSE loop polls that file every 500ms and pushes if changed
 *   - Both players' SSE connections pick up the change simultaneously
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../service/AuthService.php';

// ── CONFIG ────────────────────────────────────────────────────
const GAME_CACHE_DIR = __DIR__ . '/../cache/game/';
const SSE_TIMEOUT    = 55;    // seconds before SSE reconnects (keep < Apache timeout)
const SSE_POLL_MS    = 500;   // milliseconds between tick file checks
const TURN_TIME      = 30;    // seconds per turn

// ── BOOTSTRAP ─────────────────────────────────────────────────
if (!is_dir(GAME_CACHE_DIR)) mkdir(GAME_CACHE_DIR, 0755, true);

$db          = Database::getInstance()->getConnection();
$authService = new AuthService($db);

// Auth — every request needs a valid token
$token = AuthService::extractToken();
if (!$token) respond(401, ['error' => 'No token provided']);

try {
    $player = $authService->verifyToken($token);
} catch (Throwable $e) {
    respond(401, ['error' => 'Invalid or expired token']);
}

// Read body once
$body   = jsonBody();
$action = $_GET['action'] ?? $_POST['action'] ?? $body['action'] ?? '';

try {
    match ($action) {
        'init'       => actionInit($body, $player, $db),
        'place_unit' => actionPlaceUnit($body, $player, $db),
        'end_turn'   => actionEndTurn($body, $player, $db),
        'surrender'  => actionSurrender($body, $player, $db),
        'state'      => actionState($player, $db),
        'stream'     => actionStream($player, $db),
        default      => respond(400, ['error' => "Unknown action: {$action}"])
    };
} catch (Throwable $e) {
    respond(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}


// ═════════════════════════════════════════════════════════════
// ACTIONS
// ═════════════════════════════════════════════════════════════

/**
 * POST init
 * Body: { match_id }
 * Called once by each player when board.html loads.
 * Loads the match record, determines the player's side,
 * and initialises game_units if not already done.
 */
function actionInit(array $body, array $player, PDO $db): void
{
    $matchId = sanitize($body['match_id'] ?? $_GET['match_id'] ?? '');
    if (!$matchId) respond(400, ['error' => 'Missing match_id']);

    $game = getGame($matchId, $db);
    if (!$game) respond(404, ['error' => 'Match not found']);

    $userId = (int) $player['user_id'];
    $side   = determineSide($game, $userId);
    if (!$side) respond(403, ['error' => 'You are not a player in this match']);

    // Mark game as active if still pending
    if ($game['status'] === 'pending') {
        $db->prepare("UPDATE games SET status='active', started_at=NOW() WHERE match_uuid=?")
           ->execute([$matchId]);
    }

    // Return full game state for this player
    $units = getUnits((int)$game['id'], $db);

    respond(200, [
        'success'      => true,
        'match_id'     => $matchId,
        'game_id'      => $game['id'],
        'side'         => $side,
        'current_turn' => $game['current_turn'],
        'wave'         => $game['current_wave'],
        'mode'         => $game['mode'],
        'plant_player' => ['user_id' => $game['plant_player_id'], 'username' => $game['plant_username']],
        'zombie_player'=> ['user_id' => $game['zombie_player_id'],'username' => $game['zombie_username']],
        'units'        => $units,
    ]);
}


/**
 * POST place_unit
 * Body: { match_id, unit_key, row, col }
 */
function actionPlaceUnit(array $body, array $player, PDO $db): void
{
    $matchId = sanitize($body['match_id'] ?? '');
    $unitKey = sanitize($body['unit_key'] ?? '');
    $row     = intval($body['row'] ?? -1);
    $col     = intval($body['col'] ?? -1);

    if (!$matchId || !$unitKey || $row < 0 || $col < 0) {
        respond(400, ['error' => 'Missing match_id, unit_key, row, or col']);
    }

    $game   = getGame($matchId, $db);
    if (!$game) respond(404, ['error' => 'Match not found']);
    if ($game['status'] !== 'active') respond(400, ['error' => 'Game is not active']);

    $userId = (int) $player['user_id'];
    $side   = determineSide($game, $userId);
    if (!$side) respond(403, ['error' => 'Not your game']);

    // Turn check
    if ($game['current_turn'] !== $side) {
        respond(400, ['error' => "Not your turn — waiting for {$game['current_turn']} player"]);
    }

    // Zone check
    if ($side === 'plants'  && $col > 3) respond(400, ['error' => 'Plants go in columns 0–3']);
    if ($side === 'zombies' && $col < 5) respond(400, ['error' => 'Zombies go in columns 5–8']);

    // Cell occupied check
    $occupied = $db->prepare("
        SELECT id FROM game_units
        WHERE game_id=? AND row_pos=? AND col_pos=? AND is_alive=1
    ");
    $occupied->execute([(int)$game['id'], $row, $col]);
    if ($occupied->fetch()) respond(400, ['error' => 'Cell already occupied']);

    // Get unit stats
    $unitStats = getUnitStats($unitKey);
    if (!$unitStats) respond(400, ['error' => "Unknown unit: {$unitKey}"]);

    // Insert unit
    $db->prepare("
        INSERT INTO game_units (game_id, unit_key, side, row_pos, col_pos, hp, max_hp, placed_at_turn)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        (int)$game['id'], $unitKey, $side, $row, $col,
        $unitStats['hp'], $unitStats['hp'], $game['turn_number']
    ]);

    // Log action
    logAction((int)$game['id'], $game['turn_number'], 'place_unit', $side, [
        'unit_key' => $unitKey, 'row' => $row, 'col' => $col
    ], $db);

    // Push state update to SSE clients
    pushTick($matchId, (int)$game['id'], $db);

    respond(200, [
        'success' => true,
        'unit'    => ['unit_key' => $unitKey, 'row' => $row, 'col' => $col, 'side' => $side],
        'units'   => getUnits((int)$game['id'], $db),
    ]);
}


/**
 * POST end_turn
 * Body: { match_id }
 * Runs combat phase, advances zombies, switches turn.
 */
function actionEndTurn(array $body, array $player, PDO $db): void
{
    $matchId = sanitize($body['match_id'] ?? '');
    if (!$matchId) respond(400, ['error' => 'Missing match_id']);

    $game   = getGame($matchId, $db);
    if (!$game) respond(404, ['error' => 'Match not found']);
    if ($game['status'] !== 'active') respond(400, ['error' => 'Game not active']);

    $userId = (int) $player['user_id'];
    $side   = determineSide($game, $userId);
    if ($game['current_turn'] !== $side) {
        respond(400, ['error' => 'Not your turn']);
    }

    $combatLog = [];

    // ── 1. Plants attack zombies ──────────────────────────
    if ($side === 'plants') {
        $combatLog = runPlantAttacks((int)$game['id'], $db);

        // Sunflower income
        $sunIncome = countSunflowers((int)$game['id'], $db) * 25;

        // Switch to zombie turn
        $db->prepare("
            UPDATE games SET current_turn='zombies', turn_number=turn_number+1 WHERE id=?
        ")->execute([$game['id']]);

    } else {
        // ── 2. Zombies attack + move ──────────────────────
        $combatLog = runZombiePhase((int)$game['id'], $db);

        // Check base damage → game over
        $baseHp = getBaseHp((int)$game['id'], $db);
        if ($baseHp <= 0) {
            endGame((int)$game['id'], $matchId, 'zombies', $db);
            pushTick($matchId, (int)$game['id'], $db);
            respond(200, ['success' => true, 'game_over' => true, 'winner' => 'zombies', 'combat_log' => $combatLog]);
        }

        // Check wave clear
        $zombiesLeft = countAliveZombies((int)$game['id'], $db);
        if ($zombiesLeft === 0) {
            $newWave = $game['current_wave'] + 1;
            if ($newWave > $game['max_waves']) {
                endGame((int)$game['id'], $matchId, 'plants', $db);
                pushTick($matchId, (int)$game['id'], $db);
                respond(200, ['success' => true, 'game_over' => true, 'winner' => 'plants', 'combat_log' => $combatLog]);
            }
            $db->prepare("UPDATE games SET current_wave=? WHERE id=?")->execute([$newWave, $game['id']]);
            spawnWave((int)$game['id'], $newWave, $db);
        }

        // Switch back to plant turn
        $db->prepare("
            UPDATE games SET current_turn='plants', turn_number=turn_number+1 WHERE id=?
        ")->execute([$game['id']]);
    }

    logAction((int)$game['id'], (int)$game['turn_number'], 'end_turn', $side, ['combat_log' => $combatLog], $db);
    pushTick($matchId, (int)$game['id'], $db);

    respond(200, [
        'success'    => true,
        'combat_log' => $combatLog,
        'units'      => getUnits((int)$game['id'], $db),
        'game_state' => getGameMeta((int)$game['id'], $db),
    ]);
}


/**
 * POST surrender
 * Body: { match_id }
 */
function actionSurrender(array $body, array $player, PDO $db): void
{
    $matchId = sanitize($body['match_id'] ?? '');
    if (!$matchId) respond(400, ['error' => 'Missing match_id']);

    $game   = getGame($matchId, $db);
    if (!$game) respond(404, ['error' => 'Match not found']);

    $userId = (int) $player['user_id'];
    $side   = determineSide($game, $userId);
    $winner = $side === 'plants' ? 'zombies' : 'plants';

    logAction((int)$game['id'], (int)$game['turn_number'], 'surrender', $side, [], $db);
    endGame((int)$game['id'], $matchId, $winner, $db);
    pushTick($matchId, (int)$game['id'], $db);

    respond(200, ['success' => true, 'winner' => $winner]);
}


/**
 * GET state
 * Returns full board snapshot for reconnect.
 * Params: match_id
 */
function actionState(array $player, PDO $db): void
{
    $matchId = sanitize($_GET['match_id'] ?? '');
    if (!$matchId) respond(400, ['error' => 'Missing match_id']);

    $game = getGame($matchId, $db);
    if (!$game) respond(404, ['error' => 'Match not found']);

    $userId = (int) $player['user_id'];
    $side   = determineSide($game, $userId);

    respond(200, [
        'success'      => true,
        'match_id'     => $matchId,
        'side'         => $side,
        'current_turn' => $game['current_turn'],
        'wave'         => $game['current_wave'],
        'status'       => $game['status'],
        'winner'       => $game['winner_side'],
        'base_hp'      => getBaseHp((int)$game['id'], $db),
        'units'        => getUnits((int)$game['id'], $db),
        'game_meta'    => getGameMeta((int)$game['id'], $db),
    ]);
}


/**
 * GET stream
 * Server-Sent Events — holds connection, pushes board state on change.
 * Params: match_id
 */
function actionStream(array $player, PDO $db): void
{
    $matchId = sanitize($_GET['match_id'] ?? '');

    // Set SSE headers FIRST before anything else
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    @ob_end_clean();

    if (!$matchId) {
        echo "event: error\ndata: " . json_encode(['error' => 'Missing match_id']) . "\n\n";
        flush(); exit();
    }

    $game = getGame($matchId, $db);
    if (!$game) {
        echo "event: error\ndata: " . json_encode(['error' => 'Match not found']) . "\n\n";
        flush(); exit();
    }

    set_time_limit(SSE_TIMEOUT + 5);

    $tickFile = tickFile($matchId);
    $lastTick = '';
    $started  = time();

    $state = buildStatePayload((int)$game['id'], $matchId, $db);
    sendSSE('state', $state);

    while (true) {
        if (connection_aborted()) break;

        if (file_exists($tickFile)) {
            $tick = file_get_contents($tickFile);
            if ($tick !== $lastTick) {
                $lastTick = $tick;
                $game     = getGame($matchId, $db);
                $state    = buildStatePayload((int)$game['id'], $matchId, $db);
                sendSSE('state', $state);
            }
        }

        if ((time() - $started) % 15 === 0) {
            sendSSE('ping', ['t' => time()]);
        }

        if (time() - $started >= SSE_TIMEOUT) {
            sendSSE('reconnect', ['reason' => 'timeout']);
            break;
        }

        usleep(SSE_POLL_MS * 1000);
    }
    exit();
}


// ═════════════════════════════════════════════════════════════
// COMBAT ENGINE
// ═════════════════════════════════════════════════════════════

function runPlantAttacks(int $gameId, PDO $db): array
{
    $log   = [];
    $units = getUnitsRaw($gameId, $db);

    // Group by row
    $byRow = [];
    foreach ($units as $u) {
        $byRow[$u['row_pos']][$u['col_pos']] = $u;
    }

    for ($r = 0; $r < 5; $r++) {
        if (!isset($byRow[$r])) continue;
        ksort($byRow[$r]); // sort by col

        foreach ($byRow[$r] as $col => $unit) {
            if ($unit['side'] !== 'plants') continue;
            $stats = getUnitStats($unit['unit_key']);
            if (!$stats || $stats['atk'] <= 0) continue;

            // Find nearest zombie in same row to the right
            for ($tc = $col + 1; $tc < 9; $tc++) {
                if (!isset($byRow[$r][$tc])) continue;
                $target = $byRow[$r][$tc];
                if ($target['side'] !== 'zombies') continue;

                // Range check
                if (($tc - $col) > $stats['range']) break;

                // Apply damage
                $newHp = $target['hp'] - $stats['atk'];
                $log[] = [
                    'type'    => 'attack',
                    'from'    => ['row' => $r, 'col' => $col, 'unit' => $unit['unit_key']],
                    'to'      => ['row' => $r, 'col' => $tc,  'unit' => $target['unit_key']],
                    'damage'  => $stats['atk'],
                    'hp_left' => max(0, $newHp),
                ];

                if ($newHp <= 0) {
                    $db->prepare("UPDATE game_units SET is_alive=0, hp=0 WHERE id=?")
                       ->execute([$target['id']]);
                    $log[count($log)-1]['killed'] = true;
                    unset($byRow[$r][$tc]); // remove from local map
                } else {
                    $db->prepare("UPDATE game_units SET hp=? WHERE id=?")
                       ->execute([$newHp, $target['id']]);
                    $byRow[$r][$tc]['hp'] = $newHp;
                }
                break; // each plant attacks one target per turn
            }
        }
    }
    return $log;
}

function runZombiePhase(int $gameId, PDO $db): array
{
    $log   = [];
    $units = getUnitsRaw($gameId, $db);

    // Group by row
    $byRow = [];
    foreach ($units as $u) {
        if ($u['is_alive']) $byRow[$u['row_pos']][$u['col_pos']] = $u;
    }

    for ($r = 0; $r < 5; $r++) {
        if (!isset($byRow[$r])) continue;
        krsort($byRow[$r]); // right to left (zombies move left)

        foreach ($byRow[$r] as $col => $unit) {
            if ($unit['side'] !== 'zombies') continue;
            $stats = getUnitStats($unit['unit_key']);
            $speed = $stats['speed'] ?? 1;

            for ($step = 1; $step <= $speed; $step++) {
                $nc = $col - $step;

                if ($nc < 0) {
                    // Reached base — damage it
                    damageBase($gameId, $stats['atk'], $db);
                    $db->prepare("UPDATE game_units SET is_alive=0 WHERE id=?")->execute([$unit['id']]);
                    $log[] = ['type' => 'base_damage', 'damage' => $stats['atk'], 'row' => $r];
                    unset($byRow[$r][$col]);
                    break;
                }

                if (isset($byRow[$r][$nc]) && $byRow[$r][$nc]['side'] === 'plants') {
                    // Attack plant
                    $plant  = $byRow[$r][$nc];
                    $newHp  = $plant['hp'] - $stats['atk'];
                    $log[]  = [
                        'type'   => 'zombie_attack',
                        'from'   => ['row' => $r, 'col' => $col],
                        'to'     => ['row' => $r, 'col' => $nc],
                        'damage' => $stats['atk'],
                    ];
                    if ($newHp <= 0) {
                        $db->prepare("UPDATE game_units SET is_alive=0, hp=0 WHERE id=?")->execute([$plant['id']]);
                        unset($byRow[$r][$nc]);
                    } else {
                        $db->prepare("UPDATE game_units SET hp=? WHERE id=?")->execute([$newHp, $plant['id']]);
                        $byRow[$r][$nc]['hp'] = $newHp;
                    }
                    break; // zombie stops when hitting a plant
                }

                // Move zombie
                $db->prepare("UPDATE game_units SET col_pos=? WHERE id=?")->execute([$nc, $unit['id']]);
                $byRow[$r][$nc] = $byRow[$r][$col];
                $byRow[$r][$nc]['col_pos'] = $nc;
                unset($byRow[$r][$col]);
                $col = $nc;
                $log[] = ['type' => 'zombie_move', 'row' => $r, 'from_col' => $col + $step, 'to_col' => $nc];
            }
        }
    }
    return $log;
}

function spawnWave(int $gameId, int $wave, PDO $db): void
{
    $zombiePool = ['basic','basic','cone','cone','bucket','flag','imp'];
    $count      = 2 + $wave;

    for ($i = 0; $i < $count; $i++) {
        $zKey = $zombiePool[min($wave - 1, count($zombiePool) - 1)];
        $stats = getUnitStats($zKey);

        // Find empty right-side cell
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $r = rand(0, 4);
            $c = rand(7, 8);
            $exists = $db->prepare("
                SELECT id FROM game_units WHERE game_id=? AND row_pos=? AND col_pos=? AND is_alive=1
            ");
            $exists->execute([$gameId, $r, $c]);
            if (!$exists->fetch()) {
                $db->prepare("
                    INSERT INTO game_units (game_id, unit_key, side, row_pos, col_pos, hp, max_hp, placed_at_turn)
                    VALUES (?, ?, 'zombies', ?, ?, ?, ?, 0)
                ")->execute([$gameId, $zKey, $r, $c, $stats['hp'], $stats['hp']]);
                break;
            }
        }
    }
}


// ═════════════════════════════════════════════════════════════
// GAME HELPERS
// ═════════════════════════════════════════════════════════════

function getGame(string $matchId, PDO $db): ?array
{
    $stmt = $db->prepare("
        SELECT g.*,
               p.username AS plant_username,
               z.username AS zombie_username
        FROM games g
        LEFT JOIN users p ON p.id = g.plant_player_id
        LEFT JOIN users z ON z.id = g.zombie_player_id
        WHERE g.match_uuid = ?
        LIMIT 1
    ");
    $stmt->execute([$matchId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($game) return $game;

    // Not in DB yet — check matchmaking JSON cache and insert
    $cacheFile = __DIR__ . '/../cache/queue/match_' . $matchId . '.json';
    if (!file_exists($cacheFile)) return null;

    $match = json_decode(file_get_contents($cacheFile), true);
    if (!$match) return null;

    // Insert into games table
    $db->prepare("
        INSERT IGNORE INTO games (
            match_uuid, mode, status,
            plant_player_id, zombie_player_id,
            max_waves, current_wave, current_turn,
            base_hp, started_at
        ) VALUES (?, ?, 'active', ?, ?, 5, 1, 'plants', 30, NOW())
    ")->execute([
        $matchId,
        $match['mode'] ?? 'casual',
        $match['plant_player']['user_id'],
        $match['zombie_player']['user_id'],
    ]);

    // Re-fetch with usernames
    $stmt->execute([$matchId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getGameMeta(int $gameId, PDO $db): array
{
    $stmt = $db->prepare("SELECT current_turn, current_wave, max_waves, status, winner_side FROM games WHERE id=?");
    $stmt->execute([$gameId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getUnits(int $gameId, PDO $db): array
{
    $stmt = $db->prepare("
        SELECT unit_key, side, row_pos, col_pos, hp, max_hp
        FROM game_units
        WHERE game_id = ? AND is_alive = 1
    ");
    $stmt->execute([$gameId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUnitsRaw(int $gameId, PDO $db): array
{
    $stmt = $db->prepare("SELECT * FROM game_units WHERE game_id=? AND is_alive=1");
    $stmt->execute([$gameId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countAliveZombies(int $gameId, PDO $db): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM game_units WHERE game_id=? AND side='zombies' AND is_alive=1");
    $stmt->execute([$gameId]);
    return (int) $stmt->fetchColumn();
}

function countSunflowers(int $gameId, PDO $db): int
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM game_units WHERE game_id=? AND unit_key='sunflower' AND is_alive=1");
    $stmt->execute([$gameId]);
    return (int) $stmt->fetchColumn();
}

function getBaseHp(int $gameId, PDO $db): int
{
    // base_hp stored in games table meta JSON or we track it separately
    // For simplicity: store in a game_meta key-value or in games directly
    // We'll use a base_hp column — add to games table if not exists
    $stmt = $db->prepare("SELECT base_hp FROM games WHERE id=?");
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    return $row ? (int)($row['base_hp'] ?? 30) : 30;
}

function damageBase(int $gameId, int $dmg, PDO $db): void
{
    $db->prepare("UPDATE games SET base_hp = GREATEST(0, COALESCE(base_hp,30) - ?) WHERE id=?")
       ->execute([$dmg, $gameId]);
}

function determineSide(array $game, int $userId): ?string
{
    // Cast to int — DB might return strings
    if ((int)$game['plant_player_id']  === $userId) return 'plants';
    if ((int)$game['zombie_player_id'] === $userId) return 'zombies';
    return null;
}

function endGame(int $gameId, string $matchId, string $winner, PDO $db): void
{
    $db->prepare("
        UPDATE games SET status='completed', winner_side=?, ended_at=NOW(),
        duration_secs = TIMESTAMPDIFF(SECOND, started_at, NOW())
        WHERE id=?
    ")->execute([$winner, $gameId]);

    // Fetch game for history writing
    $stmt = $db->prepare("SELECT * FROM games WHERE id=?");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) return;

    $loser = $winner === 'plants' ? 'zombies' : 'plants';

    // Write history for winner
    $db->prepare("
        INSERT IGNORE INTO match_history
            (game_id, user_id, opponent_id, side, result, elo_change, elo_after)
        VALUES (?, ?, ?, ?, 'win', 0,
            (SELECT elo_rating FROM users WHERE id=?))
    ")->execute([
        $gameId,
        $winner === 'plants' ? $game['plant_player_id'] : $game['zombie_player_id'],
        $winner === 'plants' ? $game['zombie_player_id'] : $game['plant_player_id'],
        $winner,
        $winner === 'plants' ? $game['plant_player_id'] : $game['zombie_player_id'],
    ]);

    // Write history for loser
    $db->prepare("
        INSERT IGNORE INTO match_history
            (game_id, user_id, opponent_id, side, result, elo_change, elo_after)
        VALUES (?, ?, ?, ?, 'loss', 0,
            (SELECT elo_rating FROM users WHERE id=?))
    ")->execute([
        $gameId,
        $loser === 'plants' ? $game['plant_player_id'] : $game['zombie_player_id'],
        $loser === 'plants' ? $game['zombie_player_id'] : $game['plant_player_id'],
        $loser,
        $loser === 'plants' ? $game['plant_player_id'] : $game['zombie_player_id'],
    ]);
}

function logAction(int $gameId, int $turn, string $type, string $side, array $payload, PDO $db): void
{
    $db->prepare("
        INSERT INTO game_actions (game_id, turn_number, action_type, actor_side, payload)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$gameId, (int)$turn, $type, $side, json_encode($payload)]);
}

function buildStatePayload(int $gameId, string $matchId, PDO $db): array
{
    $meta = getGameMeta($gameId, $db);
    return [
        'match_id'     => $matchId,
        'current_turn' => $meta['current_turn'],
        'wave'         => $meta['current_wave'],
        'max_waves'    => $meta['max_waves'],
        'status'       => $meta['status'],
        'winner'       => $meta['winner_side'],
        'base_hp'      => getBaseHp($gameId, $db),
        'units'        => getUnits($gameId, $db),
        'tick'         => time(),
    ];
}


// ═════════════════════════════════════════════════════════════
// UNIT STATS (mirrors board.js PLANTS/ZOMBIES)
// ═════════════════════════════════════════════════════════════

function getUnitStats(string $key): ?array
{
    $stats = [
        // Plants
        'peashooter' => ['hp'=>3,  'atk'=>1, 'range'=>9, 'speed'=>0, 'sunGen'=>0],
        'sunflower'  => ['hp'=>2,  'atk'=>0, 'range'=>0, 'speed'=>0, 'sunGen'=>25],
        'wallnut'    => ['hp'=>8,  'atk'=>0, 'range'=>0, 'speed'=>0, 'sunGen'=>0],
        'chomper'    => ['hp'=>4,  'atk'=>3, 'range'=>1, 'speed'=>0, 'sunGen'=>0],
        'snowpea'    => ['hp'=>3,  'atk'=>1, 'range'=>9, 'speed'=>0, 'sunGen'=>0, 'slow'=>true],
        'cherrybomb' => ['hp'=>1,  'atk'=>4, 'range'=>1, 'speed'=>0, 'sunGen'=>0, 'splash'=>true],
        // Zombies
        'basic'      => ['hp'=>5,  'atk'=>1, 'range'=>1, 'speed'=>1],
        'cone'       => ['hp'=>9,  'atk'=>1, 'range'=>1, 'speed'=>1],
        'bucket'     => ['hp'=>14, 'atk'=>2, 'range'=>1, 'speed'=>1],
        'flag'       => ['hp'=>4,  'atk'=>1, 'range'=>1, 'speed'=>2],
        'imp'        => ['hp'=>3,  'atk'=>1, 'range'=>1, 'speed'=>2],
    ];
    return $stats[$key] ?? null;
}


// ═════════════════════════════════════════════════════════════
// SSE TICK FILE
// ═════════════════════════════════════════════════════════════

function tickFile(string $matchId): string
{
    return GAME_CACHE_DIR . "tick_{$matchId}.txt";
}

function pushTick(string $matchId, int $gameId, PDO $db): void
{
    // Write a new tick — SSE loop detects change and pushes state
    file_put_contents(tickFile($matchId), microtime(true) . '_' . $gameId, LOCK_EX);
}

function sendSSE(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    ob_flush();
    flush();
}


// ═════════════════════════════════════════════════════════════
// UTILITIES
// ═════════════════════════════════════════════════════════════

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

function respond(int $code, array $payload): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}