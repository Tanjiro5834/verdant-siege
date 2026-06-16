<?php
/**
 * Verdant Siege — Match Repository
 * Raw DB queries for games and match_history tables.
 */

require_once __DIR__ . '/../utils/Database.php';

class MatchRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a completed game row (AI match).
     * Returns the new game ID.
     */
    public function saveGame(
        string $matchUuid,
        string $mode,
        int    $plantPlayerId,
        int    $waveReached,
        string $winnerSide,
        int    $durationSecs
    ): int {
        $this->db->prepare("
            INSERT INTO games (
                match_uuid, mode, status,
                plant_player_id, zombie_player_id,
                max_waves, current_wave, current_turn,
                winner_side,
                started_at, ended_at, duration_secs
            ) VALUES (
                ?, ?, 'completed',
                ?, NULL,
                5, ?, 'plants',
                ?,
                NOW() - INTERVAL ? SECOND, NOW(), ?
            )
        ")->execute([
            $matchUuid, $mode,
            $plantPlayerId,
            $waveReached,
            $winnerSide,
            $durationSecs, $durationSecs,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a match_history row for one player.
     */
    public function saveMatchHistory(
        int    $gameId,
        int    $userId,
        ?int   $opponentId,
        string $side,
        string $result,
        int    $eloChange,
        int    $eloAfter,
        int    $durationSecs
    ): void {
        $this->db->prepare("
            INSERT IGNORE INTO match_history
                (game_id, user_id, opponent_id, side, result, elo_change, elo_after, duration_secs)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $gameId, $userId, $opponentId,
            $side, $result, $eloChange, $eloAfter, $durationSecs,
        ]);
    }

    /**
     * Update user ELO, wins/losses, streak after a match.
     */
    public function updateUserStats(
        int    $userId,
        string $result,
        int    $newElo,
        int    $currentStreak,
        int    $bestStreak
    ): void {
        $this->db->prepare("
            UPDATE users SET
                elo_rating      = ?,
                wins            = wins   + ?,
                losses          = losses + ?,
                total_matches   = total_matches + 1,
                current_streak  = ?,
                best_streak     = ?,
                updated_at      = NOW()
            WHERE id = ?
        ")->execute([
            $newElo,
            $result === 'win'  ? 1 : 0,
            $result === 'loss' ? 1 : 0,
            $currentStreak,
            $bestStreak,
            $userId,
        ]);
    }

    /**
     * Fetch last N match history rows for a user.
     */
    public function findMatchHistory(int $userId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT
                mh.id,
                mh.result,
                mh.elo_change,
                mh.elo_after,
                mh.duration_secs,
                mh.played_at,
                g.mode,
                g.current_wave  AS wave_reached,
                g.winner_side,
                u.username      AS opponent_username
            FROM match_history mh
            JOIN games g   ON g.id  = mh.game_id
            LEFT JOIN users u ON u.id = mh.opponent_id
            WHERE mh.user_id = ?
            ORDER BY mh.played_at DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch rank position (how many users have higher ELO).
     */
    public function getRankPosition(int $elo): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) + 1 AS rank_pos
            FROM users
            WHERE elo_rating > ? AND is_active = 1 AND is_banned = 0
        ");
        $stmt->execute([$elo]);
        return (int) $stmt->fetchColumn();
    }
}