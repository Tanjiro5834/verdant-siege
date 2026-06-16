<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/AutoAccessors.php';
require_once __DIR__ . '/../entity/User.php';
require_once __DIR__ . '/../dto/request/UserRequest.php';
require_once __DIR__ . '/../dto/response/UserResponse.php';
require_once __DIR__ . '/../repository/UserRepository.php';
require_once __DIR__ . '/../repository/MatchRepository.php';
require_once __DIR__ . '/../service/AuthService.php';
require_once __DIR__ . '/../service/MatchService.php';
require_once __DIR__ . '/../controller/MatchController.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: http://localhost');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { 
    http_response_code(200); 
    exit(); 
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$db = Database::getInstance()->getConnection();
$controller = new MatchController($db);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $body['action'] ?? 'save_ai';

try {
    match ($action) {
        'save_ai' => $controller->saveAiMatch(),
        default   => (function() {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit();
        })()
    };
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
}