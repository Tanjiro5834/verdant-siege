<?php

require_once __DIR__ . '/../entity/User.php';
require_once __DIR__ . '/../repository/UserRepository.php';
require_once __DIR__ . '/../dto/request/UserRequest.php';
require_once __DIR__ . '/../dto/response/UserResponse.php';

class UserService {
    private UserRepository $userRepository;

    public function __construct(PDO $db) {
        $this->userRepository = new UserRepository($db);
    }

    public function registerUser(UserRequestDTO $request): UserResponse {
        if (empty($request->username) || empty($request->email) || empty($request->password)) {
            throw new InvalidArgumentException("Username, email, and password are required");
        }

        if ($this->userRepository->findByUsername($request->username)) {
            throw new RuntimeException("Username already exists");
        }

        if ($this->userRepository->findByEmail($request->email)) {
            throw new RuntimeException("Email already exists");
        }

        if (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid email format");
        }

        if (strlen($request->password) < 6) {
            throw new InvalidArgumentException("Password must be at least 6 characters long");
        }

        $user = new User(
            $request->username,
            $request->email,
            password_hash($request->password, PASSWORD_DEFAULT),
            $request->displayName ?? $request->username,
            $request->bio ?? null,
            $request->avatarUrl ?? null,
            $request->favoriteUnit ?? null
        );

        $user->setLastLoginAt(date('Y-m-d H:i:s'));
        $user->setLastIp($_SERVER['REMOTE_ADDR'] ?? null);

        $savedData = $this->userRepository->save($user);

        if (!$savedData) {
            throw new RuntimeException("Failed to create user");
        }

        // Hydrate from DB row so createdAt/updatedAt are never null
        $savedUser = $this->hydrateUser($savedData);
        return $this->mapToResponse($savedUser);
    }

    public function authenticateUser(string $usernameOrEmail, string $password): UserResponse {
        $userData = $this->userRepository->findByUsername($usernameOrEmail);
        
        if (!$userData) {
            $userData = $this->userRepository->findByEmail($usernameOrEmail);
        }

        if (!$userData) {
            throw new RuntimeException("Invalid credentials");
        }

        if ($userData['is_banned']) {
            $banExpiresAt = $userData['ban_expires_at'];
            if ($banExpiresAt && strtotime($banExpiresAt) > time()) {
                throw new RuntimeException("Account is banned until " . $banExpiresAt);
            } elseif ($banExpiresAt && strtotime($banExpiresAt) <= time()) {
                $this->unbanUser($userData['id']);
            } else {
                throw new RuntimeException("Account is permanently banned");
            }
        }

        if (!$userData['is_active']) {
            throw new RuntimeException("Account is inactive");
        }

        if (!password_verify($password, $userData['password_hash'])) {
            throw new RuntimeException("Invalid credentials");
        }

        $user = $this->hydrateUser($userData);
        $user->setLastLoginAt(date('Y-m-d H:i:s'));
        $user->setLastIp($_SERVER['REMOTE_ADDR'] ?? null);
        $this->userRepository->update($user);

        return $this->mapToResponse($user);
    }

    public function getUserById(int $id): ?UserResponse {
        $userData = $this->userRepository->getById($id);
        if (!$userData) {
            return null;
        }
        
        $user = $this->hydrateUser($userData);
        return $this->mapToResponse($user);
    }

    public function getUserByUsername(string $username): ?UserResponse {
        $userData = $this->userRepository->findByUsername($username);
        if (!$userData) {
            return null;
        }
        
        $user = $this->hydrateUser($userData);
        return $this->mapToResponse($user);
    }

    public function updateUserProfile(int $userId, UserRequestDTO $request): UserResponse {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }

        $userModel = $this->hydrateUserFromResponse($user);
        
        if ($request->displayName !== null) {
            $userModel->setDisplayName($request->displayName);
        }
        if ($request->bio !== null) {
            $userModel->setBio($request->bio);
        }
        if ($request->avatarUrl !== null) {
            $userModel->setAvatarUrl($request->avatarUrl);
        }
        if ($request->favoriteUnit !== null) {
            $userModel->setFavoriteUnit($request->favoriteUnit);
        }

        if ($request->password !== null) {
            if (strlen($request->password) < 6) {
                throw new InvalidArgumentException("Password must be at least 6 characters long");
            }
            $userModel->setPasswordHash(password_hash($request->password, PASSWORD_DEFAULT));
        }

        if ($request->email !== null && $request->email !== $userModel->getEmail()) {
            if (!filter_var($request->email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Invalid email format");
            }
            
            $existingUser = $this->userRepository->findByEmail($request->email);
            if ($existingUser && $existingUser['id'] !== $userId) {
                throw new RuntimeException("Email already in use");
            }
            
            $userModel->setEmail($request->email);
        }

        if ($request->username !== null && $request->username !== $userModel->getUsername()) {
            $existingUser = $this->userRepository->findByUsername($request->username);
            if ($existingUser && $existingUser['id'] !== $userId) {
                throw new RuntimeException("Username already taken");
            }
            
            $userModel->setUsername($request->username);
        }

        $this->userRepository->update($userModel);
        
        return $this->mapToResponse($userModel);
    }

    public function addCoins(int $userId, int $amount): UserResponse {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive");
        }
        
        $this->userRepository->incrementCoins($userId, $amount);
        return $this->getUserById($userId);
    }

    public function addGems(int $userId, int $amount): UserResponse {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive");
        }
        
        $this->userRepository->incrementGems($userId, $amount);
        return $this->getUserById($userId);
    }

    public function deductCoins(int $userId, int $amount): UserResponse {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive");
        }
        
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }
        
        if ($user->coins < $amount) {
            throw new RuntimeException("Insufficient coins");
        }
        
        $this->userRepository->incrementCoins($userId, -$amount);
        return $this->getUserById($userId);
    }

    public function deductGems(int $userId, int $amount): UserResponse {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive");
        }
        
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }
        
        if ($user->gems < $amount) {
            throw new RuntimeException("Insufficient gems");
        }
        
        $this->userRepository->incrementGems($userId, -$amount);
        return $this->getUserById($userId);
    }

    public function recordMatch(int $userId, string $result): UserResponse {
        if (!in_array($result, ['win', 'loss', 'draw'])) {
            throw new InvalidArgumentException("Invalid result. Must be 'win', 'loss', or 'draw'");
        }
        
        $this->userRepository->recordMatchResult($userId, $result);
        return $this->getUserById($userId);
    }

    public function getLeaderboard(int $limit = 10): array {
        $users = $this->userRepository->findLeaderboard($limit);
        return array_map(function($userData) {
            $user = $this->hydrateUser($userData);
            return $this->mapToResponse($user);
        }, $users);
    }

    public function getActiveUsers(): array {
        $users = $this->userRepository->findActiveUsers();
        return array_map(function($userData) {
            $user = $this->hydrateUser($userData);
            return $this->mapToResponse($user);
        }, $users);
    }

    public function getBannedUsers(): array {
        $users = $this->userRepository->findBannedUsers();
        return array_map(function($userData) {
            $user = $this->hydrateUser($userData);
            return $this->mapToResponse($user);
        }, $users);
    }

    public function banUser(int $userId, string $reason, ?string $expiresAt = null): UserResponse {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }
        
        $userModel = $this->hydrateUserFromResponse($user);
        $userModel->setIsBanned(true);
        $userModel->setBanReason($reason);
        $userModel->setBanExpiresAt($expiresAt);
        
        $this->userRepository->update($userModel);
        return $this->getUserById($userId);
    }

    public function unbanUser(int $userId): UserResponse {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }
        
        $userModel = $this->hydrateUserFromResponse($user);
        $userModel->setIsBanned(false);
        $userModel->setBanReason(null);
        $userModel->setBanExpiresAt(null);
        
        $this->userRepository->update($userModel);
        return $this->getUserById($userId);
    }

    public function deactivateUser(int $userId): UserResponse {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }
        
        $userModel = $this->hydrateUserFromResponse($user);
        $userModel->setIsActive(false);
        
        $this->userRepository->update($userModel);
        return $this->getUserById($userId);
    }

    public function activateUser(int $userId): UserResponse {
        $user = $this->getUserById($userId);
        if (!$user) {
            throw new RuntimeException("User not found");
        }
        
        $userModel = $this->hydrateUserFromResponse($user);
        $userModel->setIsActive(true);
        
        $this->userRepository->update($userModel);
        return $this->getUserById($userId);
    }

    public function deleteUser(int $userId): bool {
        return $this->userRepository->deleteById($userId);
    }

    private function mapToResponse(User $user): UserResponse {
        $data = [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'display_name' => $user->getDisplayName(),
            'bio' => $user->getBio(),
            'avatar_url' => $user->getAvatarUrl(),
            'favorite_unit' => $user->getFavoriteUnit(),
            'elo_rating' => $user->getEloRating(),
            'total_matches' => $user->getTotalMatches(),
            'wins' => $user->getWins(),
            'losses' => $user->getLosses(),
            'draws' => $user->getDraws(),
            'current_streak' => $user->getCurrentStreak(),
            'best_streak' => $user->getBestStreak(),
            'coins' => $user->getCoins(),
            'gems' => $user->getGems(),
            'total_coins_earned' => $user->getTotalCoinsEarned(),
            'total_gems_earned' => $user->getTotalGemsEarned(),
            'is_active' => $user->getIsActive(),
            'is_banned' => $user->getIsBanned(),
            'ban_reason' => $user->getBanReason(),
            'ban_expires_at' => $user->getBanExpiresAt(),
            'last_login_at' => $user->getLastLoginAt(),
            'last_ip' => $user->getLastIp(),
            'created_at' => $user->getCreatedAt(),
            'updated_at' => $user->getUpdatedAt()
        ];
        
        return new UserResponse($data);
    }

    private function hydrateUser(array $data): User {
        $user = new User(
            $data['username'],
            $data['email'],
            $data['password_hash'],
            $data['display_name'],
            $data['bio'],
            $data['avatar_url'],
            $data['favorite_unit']
        );
        
        $reflection = new ReflectionClass($user);
        
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, $data['id']);
        
        $eloRatingProperty = $reflection->getProperty('eloRating');
        $eloRatingProperty->setAccessible(true);
        $eloRatingProperty->setValue($user, $data['elo_rating']);
        
        $totalMatchesProperty = $reflection->getProperty('totalMatches');
        $totalMatchesProperty->setAccessible(true);
        $totalMatchesProperty->setValue($user, $data['total_matches']);
        
        $winsProperty = $reflection->getProperty('wins');
        $winsProperty->setAccessible(true);
        $winsProperty->setValue($user, $data['wins']);
        
        $lossesProperty = $reflection->getProperty('losses');
        $lossesProperty->setAccessible(true);
        $lossesProperty->setValue($user, $data['losses']);
        
        $drawsProperty = $reflection->getProperty('draws');
        $drawsProperty->setAccessible(true);
        $drawsProperty->setValue($user, $data['draws']);
        
        $currentStreakProperty = $reflection->getProperty('currentStreak');
        $currentStreakProperty->setAccessible(true);
        $currentStreakProperty->setValue($user, $data['current_streak']);
        
        $bestStreakProperty = $reflection->getProperty('bestStreak');
        $bestStreakProperty->setAccessible(true);
        $bestStreakProperty->setValue($user, $data['best_streak']);
        
        $coinsProperty = $reflection->getProperty('coins');
        $coinsProperty->setAccessible(true);
        $coinsProperty->setValue($user, $data['coins']);
        
        $gemsProperty = $reflection->getProperty('gems');
        $gemsProperty->setAccessible(true);
        $gemsProperty->setValue($user, $data['gems']);
        
        $totalCoinsEarnedProperty = $reflection->getProperty('totalCoinsEarned');
        $totalCoinsEarnedProperty->setAccessible(true);
        $totalCoinsEarnedProperty->setValue($user, $data['total_coins_earned']);
        
        $totalGemsEarnedProperty = $reflection->getProperty('totalGemsEarned');
        $totalGemsEarnedProperty->setAccessible(true);
        $totalGemsEarnedProperty->setValue($user, $data['total_gems_earned']);
        
        $isActiveProperty = $reflection->getProperty('isActive');
        $isActiveProperty->setAccessible(true);
        $isActiveProperty->setValue($user, $data['is_active']);
        
        $isBannedProperty = $reflection->getProperty('isBanned');
        $isBannedProperty->setAccessible(true);
        $isBannedProperty->setValue($user, $data['is_banned']);
        
        $banReasonProperty = $reflection->getProperty('banReason');
        $banReasonProperty->setAccessible(true);
        $banReasonProperty->setValue($user, $data['ban_reason']);
        
        $banExpiresAtProperty = $reflection->getProperty('banExpiresAt');
        $banExpiresAtProperty->setAccessible(true);
        $banExpiresAtProperty->setValue($user, $data['ban_expires_at']);
        
        $lastLoginAtProperty = $reflection->getProperty('lastLoginAt');
        $lastLoginAtProperty->setAccessible(true);
        $lastLoginAtProperty->setValue($user, $data['last_login_at']);
        
        $lastIpProperty = $reflection->getProperty('lastIp');
        $lastIpProperty->setAccessible(true);
        $lastIpProperty->setValue($user, $data['last_ip']);
        
        $createdAtProperty = $reflection->getProperty('createdAt');
        $createdAtProperty->setAccessible(true);
        $createdAtProperty->setValue($user, $data['created_at'] ?? date('Y-m-d H:i:s'));
        
        $updatedAtProperty = $reflection->getProperty('updatedAt');
        $updatedAtProperty->setAccessible(true);
        $updatedAtProperty->setValue($user, $data['updated_at'] ?? date('Y-m-d H:i:s'));
        
        return $user;
    }

    private function hydrateUserFromResponse(UserResponse $response): User {
        $user = new User(
            $response->username,
            $response->email,
            '', // password hash will be set separately if needed
            $response->displayName,
            $response->bio,
            $response->avatarUrl,
            $response->favoriteUnit
        );
        
        $reflection = new ReflectionClass($user);
        
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, $response->id);
        
        $eloRatingProperty = $reflection->getProperty('eloRating');
        $eloRatingProperty->setAccessible(true);
        $eloRatingProperty->setValue($user, $response->eloRating);
        
        $totalMatchesProperty = $reflection->getProperty('totalMatches');
        $totalMatchesProperty->setAccessible(true);
        $totalMatchesProperty->setValue($user, $response->totalMatches);
        
        $winsProperty = $reflection->getProperty('wins');
        $winsProperty->setAccessible(true);
        $winsProperty->setValue($user, $response->wins);
        
        $lossesProperty = $reflection->getProperty('losses');
        $lossesProperty->setAccessible(true);
        $lossesProperty->setValue($user, $response->losses);
        
        $drawsProperty = $reflection->getProperty('draws');
        $drawsProperty->setAccessible(true);
        $drawsProperty->setValue($user, $response->draws);
        
        $currentStreakProperty = $reflection->getProperty('currentStreak');
        $currentStreakProperty->setAccessible(true);
        $currentStreakProperty->setValue($user, $response->currentStreak);
        
        $bestStreakProperty = $reflection->getProperty('bestStreak');
        $bestStreakProperty->setAccessible(true);
        $bestStreakProperty->setValue($user, $response->bestStreak);
        
        $coinsProperty = $reflection->getProperty('coins');
        $coinsProperty->setAccessible(true);
        $coinsProperty->setValue($user, $response->coins);
        
        $gemsProperty = $reflection->getProperty('gems');
        $gemsProperty->setAccessible(true);
        $gemsProperty->setValue($user, $response->gems);
        
        $totalCoinsEarnedProperty = $reflection->getProperty('totalCoinsEarned');
        $totalCoinsEarnedProperty->setAccessible(true);
        $totalCoinsEarnedProperty->setValue($user, $response->totalCoinsEarned);
        
        $totalGemsEarnedProperty = $reflection->getProperty('totalGemsEarned');
        $totalGemsEarnedProperty->setAccessible(true);
        $totalGemsEarnedProperty->setValue($user, $response->totalGemsEarned);
        
        $isActiveProperty = $reflection->getProperty('isActive');
        $isActiveProperty->setAccessible(true);
        $isActiveProperty->setValue($user, $response->isActive);
        
        $isBannedProperty = $reflection->getProperty('isBanned');
        $isBannedProperty->setAccessible(true);
        $isBannedProperty->setValue($user, $response->isBanned);
        
        $banReasonProperty = $reflection->getProperty('banReason');
        $banReasonProperty->setAccessible(true);
        $banReasonProperty->setValue($user, $response->banReason);
        
        $banExpiresAtProperty = $reflection->getProperty('banExpiresAt');
        $banExpiresAtProperty->setAccessible(true);
        $banExpiresAtProperty->setValue($user, $response->banExpiresAt);
        
        $lastLoginAtProperty = $reflection->getProperty('lastLoginAt');
        $lastLoginAtProperty->setAccessible(true);
        $lastLoginAtProperty->setValue($user, $response->lastLoginAt);
        
        $lastIpProperty = $reflection->getProperty('lastIp');
        $lastIpProperty->setAccessible(true);
        $lastIpProperty->setValue($user, $response->lastIp);
        
        return $user;
    }
}