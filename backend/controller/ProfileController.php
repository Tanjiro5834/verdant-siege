<?php
require_once __DIR__ . '/../service/ProfileService.php';
require_once __DIR__ . '/../service/AuthService.php';
require_once __DIR__ . '/../dto/request/UpdateProfileRequest.php';
require_once __DIR__ . '/../dto/response/ProfileResponse.php';

class ProfileController {
    private ProfileService $profileService;
    private AuthService    $authService;

    public function __construct(PDO $db) {
        $this->profileService = new ProfileService($db);
        $this->authService    = new AuthService($db);
    }

    // GET ?action=profile
    public function getProfile(): void {
        try {
            $userId   = $this->requireAuth();
            $response = $this->profileService->getProfile($userId);

            $this->sendResponse(200, [
                'success'       => true,
                'profile'       => $response->toArray(),
                'match_history' => $response->matchHistory,
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }

    // POST ?action=update_bio
    public function updateBio(): void {
        try {
            $userId = $this->requireAuth();
            $data   = $this->jsonBody();

            $request = new UpdateProfileRequest(
                displayName:  $data['display_name']  ?? null,
                bio:          $data['bio']            ?? null,
                favoriteUnit: $data['favorite_unit']  ?? null,
            );

            $response = $this->profileService->updateBio($userId, $request);

            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Profile updated! 🌻',
                'profile' => $response->toArray(),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }

    // POST ?action=upload_avatar  (multipart/form-data)
    public function uploadAvatar(): void {
        try {
            $userId   = $this->requireAuth();
            $response = $this->profileService->uploadAvatar($userId, $_FILES['avatar'] ?? []);

            $this->sendResponse(200, [
                'success'    => true,
                'avatar_url' => $response->avatarUrl,
                'message'    => 'Avatar updated! 🌻',
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(500, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }

    // ── PRIVATE ──────────────────────────────────────────────────

    private function requireAuth(): int {
        $token = AuthService::extractToken();
        if (!$token) $this->sendError(401, 'No token provided');

        try {
            $payload = $this->authService->verifyToken($token);
            return (int) $payload['user_id'];
        } catch (Throwable $e) {
            $this->sendError(401, 'Invalid or expired token');
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