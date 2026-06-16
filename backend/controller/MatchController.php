<?php
/**
 * Verdant Siege — Match Controller
 * Thin HTTP layer for saving match results.
 * Follows the exact same pattern as UserController.
 */

require_once __DIR__ . '/../service/MatchService.php';
require_once __DIR__ . '/../service/AuthService.php';

class MatchController {
    private MatchService $matchService;
    private AuthService  $authService;

    public function __construct(PDO $db) {
        $this->matchService = new MatchService($db);
        $this->authService  = new AuthService($db);
    }

    // POST save_match?action=save_ai
    public function saveAiMatch(): void {
        try {
            $userId = $this->requireAuth();
            $data   = $this->jsonBody();

            $result = $this->matchService->saveAiMatch(
                $userId,
                $data['result']        ?? '',
                $data['difficulty']    ?? 'normal',
                (int)($data['wave_reached']  ?? 1),
                (int)($data['base_hp_left']  ?? 0),
                (int)($data['duration_secs'] ?? 0)
            );

            $this->sendResponse(200, array_merge(['success' => true], $result));

        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }

    // ── PRIVATE HELPERS ──────────────────────────────────────────

    private function requireAuth(): int {
        $token = AuthService::extractToken();
        if (!$token) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'No token provided']);
            exit();
        }

        try {
            $payload = $this->authService->verifyToken($token);
            return (int) $payload['user_id'];
        } catch (Throwable $e) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
            exit();
        }
    }

    private function jsonBody(): array {
        $raw     = file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : $_POST;
    }

    private function sendResponse(int $code, array $data): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit();
    }

    private function sendError(int $code, string $message): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
        exit();
    }
}