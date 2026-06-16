<?php
/**
 * Verdant Siege — Match Service
 * Business logic for saving AI/PvP match results and updating stats.
 */

require_once __DIR__ . '/../repository/MatchRepository.php';
require_once __DIR__ . '/../repository/UserRepository.php';

class MatchService {
    private MatchRepository $matchRepo;
    private UserRepository  $userRepo;

    public function __construct(PDO $db) {
        $this->matchRepo = new MatchRepository($db);
        $this->userRepo  = new UserRepository($db);
    }

    /**
     * Save a completed AI match.
     * Updates user ELO, win/loss counts, streak.
     * Returns result summary.
     */
    public function saveAiMatch(
        int    $userId,
        string $result,        // 'win' | 'loss'
        string $difficulty,    // 'easy' | 'normal' | 'hard' | 'nightmare'
        int    $waveReached,
        int    $baseHpLeft,
        int    $durationSecs
    ): array {
        // Validate
        if (!in_array($result, ['win', 'loss'], true)) {
            throw new InvalidArgumentException("Result must be 'win' or 'loss'");
        }
        if (!in_array($difficulty, ['easy', 'normal', 'hard', 'nightmare'], true)) {
            throw new InvalidArgumentException('Invalid difficulty');
        }

        // Fetch current user stats
        $userData = $this->userRepo->getById($userId);
        if (!$userData) throw new RuntimeException('User not found');

        // Calculate ELO change
        $eloChange = $this->calculateEloChange($result, $difficulty);
        $newElo    = max(100, (int)$userData['elo_rating'] + $eloChange);

        // Calculate new streak
        [$currentStreak, $bestStreak] = $this->calculateStreak(
            (int)$userData['current_streak'],
            (int)$userData['best_streak'],
            $result
        );

        // 1. Update user stats
        $this->matchRepo->updateUserStats($userId, $result, $newElo, $currentStreak, $bestStreak);

        // 2. Save game row
        $matchUuid  = $this->generateUuid();
        $winnerSide = $result === 'win' ? 'plants' : 'zombies';
        $gameId     = $this->matchRepo->saveGame(
            $matchUuid, 'ai', $userId, $waveReached, $winnerSide, $durationSecs
        );

        // 3. Save match_history row
        $this->matchRepo->saveMatchHistory(
            $gameId, $userId, null, 'plants',
            $result, $eloChange, $newElo, $durationSecs
        );

        return [
            'result'     => $result,
            'elo_change' => $eloChange,
            'new_elo'    => $newElo,
            'streak'     => $currentStreak,
            'message'    => $result === 'win'
                ? "Victory! +{$eloChange} ELO 🌻"
                : "Defeated! {$eloChange} ELO 💀",
        ];
    }

    // ── PRIVATE ──────────────────────────────────────────────────

    /**
     * ELO shifts for AI matches (smaller than PvP).
     * Harder difficulty = bigger reward on win, smaller penalty on loss.
     */
    private function calculateEloChange(string $result, string $difficulty): int {
        $table = [
            'easy'      => ['win' => 5,  'loss' => -2],
            'normal'    => ['win' => 10, 'loss' => -5],
            'hard'      => ['win' => 18, 'loss' => -10],
            'nightmare' => ['win' => 28, 'loss' => -15],
        ];
        return $table[$difficulty][$result] ?? ($result === 'win' ? 10 : -5);
    }

    /**
     * Returns [currentStreak, bestStreak].
     * Win: increments positive streak (or resets from negative).
     * Loss: decrements (goes negative).
     */
    private function calculateStreak(int $current, int $best, string $result): array {
        if ($result === 'win') {
            $current = $current >= 0 ? $current + 1 : 1;
            $best    = max($best, $current);
        } else {
            $current = $current <= 0 ? $current - 1 : -1;
        }
        return [$current, $best];
    }

    private function generateUuid(): string {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}