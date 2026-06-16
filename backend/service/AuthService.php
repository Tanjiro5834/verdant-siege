<?php
/**
 * Verdant Siege — AuthService
 * Handles JWT creation/validation and user_sessions table management.
 *
 * Sits between auth.php (endpoint) and UserService (business logic).
 * Uses firebase/php-jwt v7.x via Composer autoload.
 */

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService {

    // ── CONFIG ────────────────────────────────────────────────
    // Change JWT_SECRET to a long random string before production.
    // Generate one with: php -r "echo bin2hex(random_bytes(32));"
    private const JWT_SECRET  = 'vs_change_this_to_a_long_random_secret_before_deploy';
    private const JWT_ALGO    = 'HS256';
    private const JWT_TTL     = 86400;      // 24 hours (seconds)
    private const REFRESH_TTL = 604800;     // 7 days

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────
    // CREATE TOKEN
    // Called after successful login or register.
    // Returns [ 'token' => string, 'expires_at' => int ]
    // ─────────────────────────────────────────────────────────
    public function createToken(array $user): array {
        $issuedAt  = time();
        $expiresAt = $issuedAt + self::JWT_TTL;

        $payload = [
            'iss'      => 'verdant-siege',
            'iat'      => $issuedAt,
            'exp'      => $expiresAt,
            // Claims used by matchmaking.js and game.html
            'user_id'  => $user['id'],
            'username' => $user['username'],
            'elo'      => $user['elo_rating'],
            'coins'    => $user['coins'],
        ];

        $token = JWT::encode($payload, self::JWT_SECRET, self::JWT_ALGO);

        // Persist to user_sessions (stores hash, not raw token)
        $this->saveSession($user['id'], $token, $expiresAt);

        return [
            'token'      => $token,
            'expires_at' => $expiresAt,
        ];
    }

    // ─────────────────────────────────────────────────────────
    // VERIFY TOKEN
    // Returns decoded payload array or throws on failure.
    // ─────────────────────────────────────────────────────────
    public function verifyToken(string $token): array {
        // Decode JWT (throws on expiry / bad signature)
        $decoded = JWT::decode($token, new Key(self::JWT_SECRET, self::JWT_ALGO));
        $payload = (array) $decoded;

        // Check session is still active in DB (logout invalidates it)
        $hash = $this->hashToken($token);
        $stmt = $this->db->prepare("
            SELECT id FROM user_sessions
            WHERE token_hash = ?
              AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$hash]);

        if (!$stmt->fetch()) {
            throw new RuntimeException('Session expired or logged out');
        }

        return $payload;
    }

    // ─────────────────────────────────────────────────────────
    // INVALIDATE TOKEN (logout)
    // ─────────────────────────────────────────────────────────
    public function invalidateToken(string $token): void {
        $hash = $this->hashToken($token);
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE token_hash = ?");
        $stmt->execute([$hash]);
    }

    // ─────────────────────────────────────────────────────────
    // INVALIDATE ALL SESSIONS FOR USER (logout everywhere)
    // ─────────────────────────────────────────────────────────
    public function invalidateAllSessions(int $userId): void {
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
    }

    // ─────────────────────────────────────────────────────────
    // EXTRACT TOKEN FROM REQUEST
    // Checks Authorization: Bearer <token> header first,
    // then falls back to JSON body token field.
    // ─────────────────────────────────────────────────────────
    public static function extractToken(): ?string {
        // Authorization header (preferred)
        $header = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? '';

        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        // JSON body fallback
        $raw = file_get_contents('php://input');
        if ($raw) {
            $body = json_decode($raw, true);
            if (isset($body['token'])) return $body['token'];
        }

        // Query string fallback (for GET verify calls)
        return $_GET['token'] ?? null;
    }

    // ─────────────────────────────────────────────────────────
    // PURGE EXPIRED SESSIONS (run occasionally, not every request)
    // ─────────────────────────────────────────────────────────
    public function purgeExpiredSessions(): int {
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE expires_at <= NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────
    private function saveSession(int $userId, string $token, int $expiresAt): void {
        $hash = $this->hashToken($token);

        // Clean up old sessions for this user beyond 5 active (keep it tidy)
        $this->db->prepare("
            DELETE FROM user_sessions
            WHERE user_id = ?
              AND id NOT IN (
                  SELECT id FROM (
                      SELECT id FROM user_sessions
                      WHERE user_id = ?
                      ORDER BY created_at DESC
                      LIMIT 4
                  ) AS recent
              )
        ")->execute([$userId, $userId]);

        $stmt = $this->db->prepare("
            INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE expires_at = FROM_UNIXTIME(?)
        ");
        $stmt->execute([
            $userId,
            $hash,
            $_SERVER['REMOTE_ADDR']     ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
            $expiresAt,
            $expiresAt,
        ]);
    }

    private function hashToken(string $token): string {
        // SHA-256 hex of raw token — matches schema CHAR(64)
        return hash('sha256', $token);
    }
}