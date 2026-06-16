<?php
/**
 * Verdant Siege — Profile Service
 * Uses UpdateProfileRequestDTO (input) and ProfileResponse (output).
 */

require_once __DIR__ . '/../service/UserService.php';
require_once __DIR__ . '/../repository/MatchRepository.php';
require_once __DIR__ . '/../dto/request/UpdateProfileRequest.php';
require_once __DIR__ . '/../dto/response/ProfileResponse.php';
require_once __DIR__ . '/../dto/request/UserRequest.php';

const AVATAR_UPLOAD_DIR = __DIR__ . '/../../uploads/avatars/';
const AVATAR_BASE_URL   = 'http://localhost/verdant-siege/uploads/avatars/';
const AVATAR_MAX_BYTES  = 2 * 1024 * 1024;
const AVATAR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

class ProfileService {
    private UserService     $userService;
    private MatchRepository $matchRepo;

    public function __construct(PDO $db) {
        $this->userService = new UserService($db);
        $this->matchRepo   = new MatchRepository($db);
    }

    /**
     * Full profile — returns ProfileResponse with match history.
     */
    public function getProfile(int $userId): ProfileResponse {
        $user = $this->userService->getUserById($userId);
        if (!$user) throw new RuntimeException('User not found');

        $history = $this->matchRepo->findMatchHistory($userId, 10);
        $rankPos = $this->matchRepo->getRankPosition($user->eloRating);
        $total   = $user->wins + $user->losses;
        $winRate = $total > 0 ? round(($user->wins / $total) * 100, 1) : 0.0;

        $data = [
            'id'             => $user->id,
            'username'       => $user->username,
            'email'          => $user->email,
            'display_name'   => $user->displayName,
            'bio'            => $user->bio,
            'avatar_url'     => $user->avatarUrl,
            'favorite_unit'  => $user->favoriteUnit,
            'elo_rating'     => $user->eloRating,
            'rank_position'  => $rankPos,
            'total_matches'  => $user->totalMatches,
            'wins'           => $user->wins,
            'losses'         => $user->losses,
            'draws'          => $user->draws,
            'win_rate'       => $winRate,
            'current_streak' => $user->currentStreak,
            'best_streak'    => $user->bestStreak,
            'coins'          => $user->coins,
            'gems'           => $user->gems,
            'created_at'     => $user->createdAt,
            'last_login_at'  => $user->lastLoginAt,
        ];

        return new ProfileResponse($data, $history);
    }

    /**
     * Update display_name, bio, favorite_unit.
     * Accepts UpdateProfileRequestDTO, returns updated ProfileResponse.
     */
    public function updateBio(int $userId, UpdateProfileRequest $request): ProfileResponse {
        if ($request->displayName !== null && strlen($request->displayName) > 50) {
            throw new InvalidArgumentException('Display name max 50 chars');
        }
        if ($request->bio !== null && strlen($request->bio) > 300) {
            throw new InvalidArgumentException('Bio max 300 chars');
        }

        // Delegate update to UserService using UserRequestDTO
        $userRequest = new UserRequestDTO(
            username:     '',
            email:        '',
            password:     '',
            displayName:  $request->displayName,
            bio:          $request->bio,
            favoriteUnit: $request->favoriteUnit,
        );

        $this->userService->updateUserProfile($userId, $userRequest);

        // Return fresh profile
        return $this->getProfile($userId);
    }

    /**
     * Handle avatar upload.
     * Returns updated ProfileResponse with new avatar_url.
     */
    public function uploadAvatar(int $userId, array $file): ProfileResponse {
        if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed — no file received');
        }
        if ($file['size'] > AVATAR_MAX_BYTES) {
            throw new InvalidArgumentException('File too large — max 2 MB');
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, AVATAR_MIME_TYPES, true)) {
            throw new InvalidArgumentException('Invalid file type — JPG, PNG, WebP or GIF only');
        }

        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'jpg',
        };

        if (!is_dir(AVATAR_UPLOAD_DIR)) {
            mkdir(AVATAR_UPLOAD_DIR, 0755, true);
        }

        // Remove old avatar
        foreach (glob(AVATAR_UPLOAD_DIR . $userId . '.*') ?: [] as $old) {
            unlink($old);
        }

        $filename = $userId . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], AVATAR_UPLOAD_DIR . $filename)) {
            throw new RuntimeException('Could not save file — check directory permissions');
        }

        $avatarUrl = AVATAR_BASE_URL . $filename . '?v=' . time();

        // Persist via UpdateProfileRequestDTO
        $request     = new UpdateProfileRequest(avatarUrl: $avatarUrl);
        $userRequest = new UserRequestDTO(
            username:  '',
            email:     '',
            password:  '',
            avatarUrl: $request->avatarUrl,
        );
        $this->userService->updateUserProfile($userId, $userRequest);

        return $this->getProfile($userId);
    }
}