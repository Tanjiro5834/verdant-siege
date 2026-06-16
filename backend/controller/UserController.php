<?php

require_once __DIR__ . '/../service/UserService.php';
require_once __DIR__ . '/../dto/request/UserRequest.php';
require_once __DIR__ . '/../dto/response/UserResponse.php';

class UserController {
    private UserService $userService;
    
    public function __construct(PDO $db) {
        $this->userService = new UserService($db);
    }
    
    // POST /api/users/register
    public function register(): void {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new UserRequest($data);
            $response = $this->userService->registerUser($request);
            
            $this->sendResponse(201, [
                'success' => true,
                'message' => 'User registered successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(409, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/login
    public function login(): void {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['usernameOrEmail']) || empty($data['password'])) {
                throw new InvalidArgumentException('Username/email and password are required');
            }
            
            $response = $this->userService->authenticateUser(
                $data['usernameOrEmail'],
                $data['password']
            );
            
            // Start session and store user info
            session_start();
            $_SESSION['user_id'] = $response->id;
            $_SESSION['username'] = $response->username;
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Login successful',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(401, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/logout
    public function logout(): void {
        session_start();
        session_destroy();
        
        $this->sendResponse(200, [
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }
    
    // GET /api/users/:id
    public function getById(int $id): void {
        try {
            $response = $this->userService->getUserById($id);
            
            if (!$response) {
                $this->sendError(404, 'User not found');
                return;
            }
            
            $this->sendResponse(200, [
                'success' => true,
                'data' => $response->toArray()
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // GET /api/users/username/:username
    public function getByUsername(string $username): void {
        try {
            $response = $this->userService->getUserByUsername($username);
            
            if (!$response) {
                $this->sendError(404, 'User not found');
                return;
            }
            
            $this->sendResponse(200, [
                'success' => true,
                'data' => $response->toArray()
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // PUT /api/users/:id
    public function update(int $id): void {
        try {
            $this->checkAuthentication($id);
            
            $data = json_decode(file_get_contents('php://input'), true);
            $request = new UserRequest($data);
            
            $response = $this->userService->updateUserProfile($id, $request);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(403, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/coins/add
    public function addCoins(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['amount'])) {
                throw new InvalidArgumentException('Amount is required');
            }
            
            $response = $this->userService->addCoins($id, (int)$data['amount']);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Coins added successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/coins/deduct
    public function deductCoins(int $id): void {
        try {
            $this->checkAuthentication($id);
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['amount'])) {
                throw new InvalidArgumentException('Amount is required');
            }
            
            $response = $this->userService->deductCoins($id, (int)$data['amount']);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Coins deducted successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/gems/add
    public function addGems(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['amount'])) {
                throw new InvalidArgumentException('Amount is required');
            }
            
            $response = $this->userService->addGems($id, (int)$data['amount']);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Gems added successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/gems/deduct
    public function deductGems(int $id): void {
        try {
            $this->checkAuthentication($id);
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['amount'])) {
                throw new InvalidArgumentException('Amount is required');
            }
            
            $response = $this->userService->deductGems($id, (int)$data['amount']);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Gems deducted successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/match
    public function recordMatch(int $id): void {
        try {
            $this->checkAuthentication($id);
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['result'])) {
                throw new InvalidArgumentException('Result is required');
            }
            
            $response = $this->userService->recordMatch($id, $data['result']);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Match recorded successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // GET /api/users/leaderboard
    public function getLeaderboard(): void {
        try {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $leaderboard = $this->userService->getLeaderboard($limit);
            
            $this->sendResponse(200, [
                'success' => true,
                'data' => array_map(function($user) {
                    return $user->toArray();
                }, $leaderboard)
            ]);
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // GET /api/users/active
    public function getActiveUsers(): void {
        try {
            $this->checkAdminAccess();
            
            $users = $this->userService->getActiveUsers();
            
            $this->sendResponse(200, [
                'success' => true,
                'data' => array_map(function($user) {
                    return $user->toArray();
                }, $users)
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(403, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // GET /api/users/banned
    public function getBannedUsers(): void {
        try {
            $this->checkAdminAccess();
            
            $users = $this->userService->getBannedUsers();
            
            $this->sendResponse(200, [
                'success' => true,
                'data' => array_map(function($user) {
                    return $user->toArray();
                }, $users)
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(403, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/ban
    public function banUser(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['reason'])) {
                throw new InvalidArgumentException('Ban reason is required');
            }
            
            $expiresAt = $data['expires_at'] ?? null;
            $response = $this->userService->banUser($id, $data['reason'], $expiresAt);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'User banned successfully',
                'data' => $response->toArray()
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, $e->getMessage());
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/unban
    public function unbanUser(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $response = $this->userService->unbanUser($id);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'User unbanned successfully',
                'data' => $response->toArray()
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/deactivate
    public function deactivateUser(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $response = $this->userService->deactivateUser($id);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'User deactivated successfully',
                'data' => $response->toArray()
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // POST /api/users/:id/activate
    public function activateUser(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $response = $this->userService->activateUser($id);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'User activated successfully',
                'data' => $response->toArray()
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(404, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // DELETE /api/users/:id
    public function deleteUser(int $id): void {
        try {
            $this->checkAdminAccess();
            
            $result = $this->userService->deleteUser($id);
            
            if (!$result) {
                $this->sendError(404, 'User not found');
                return;
            }
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (RuntimeException $e) {
            $this->sendError(403, $e->getMessage());
        } catch (Exception $e) {
            $this->sendError(500, 'An unexpected error occurred');
        }
    }
    
    // Helper: Check if user is authenticated and has permission
    private function checkAuthentication(int $userId): void {
        session_start();
        
        if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
            throw new RuntimeException('Unauthorized access');
        }
    }
    
    // Helper: Check if user has admin access
    private function checkAdminAccess(): void {
        session_start();
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
            throw new RuntimeException('Admin access required');
        }
    }
    
    // Helper: Send JSON response
    private function sendResponse(int $statusCode, array $data): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    // Helper: Send JSON error
    private function sendError(int $statusCode, string $message): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}