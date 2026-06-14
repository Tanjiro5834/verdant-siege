<?php
require_once './backend/engine/GameEngine.php';
require_once './backend/engine/GameState.php'; 

class GameController {
    private PDO $db;
    private array $activeGames = [];
    private Redis $redis;
    
    public function __construct() {
        session_start();
        $this->initDatabase();
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }
    
    private function initDatabase(): void {
        // $this->db = new PDO(...);
    }
    
    public function handleRequest(): void {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET');
        header('Access-Control-Allow-Headers: Content-Type');

        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        
        switch ($action) {
            case 'create_match':
                $this->createMatch();
                break;
            case 'join_match':
                $this->joinMatch();
                break;
            case 'get_state':
                $this->getGameState();
                break;
            case 'send_action':
                $this->sendAction();
                break;
            case 'tick':
                $this->processTick();
                break;
            default:
                $this->error('Unknown action');
        }
    }
    
    private function createMatch(): void {
        $matchId = uniqid('match_', true) . '_' . md5(mt_rand() . microtime());
        $playerId = $this->getPlayerId();

        $engine = new GameEngine();
        $engine->getState()->setPlayerSide($playerId, 'plants');
        $this->saveMatch($matchId, $engine);

        echo json_encode(['success' => true, 'matchId' => $matchId, 'side' => 'plants']);
    }
    
    private function joinMatch(): void {
        $matchId = $_POST['match_id'] ?? '';
        $playerId = $this->getPlayerId();

        $engine = $this->loadMatch($matchId);
        if (!$engine) { $this->error('Match not found'); return; }

        $engine->getState()->setPlayerSide($playerId, 'zombies');
        $this->saveMatch($matchId, $engine);

        echo json_encode(['success' => true, 'matchId' => $matchId, 'side' => 'zombies']);
    }
    
    private function getGameState(): void {
        $matchId = $_GET['match_id'] ?? '';

        $engine = $this->loadMatch($matchId);
        if (!$engine) { $this->error('Match not found'); return; }

        echo json_encode([
            'success' => true,
            'currentTick' => $engine->getCurrentTick(),
            'gameState' => $engine->getState()->getClientState()
        ]);
    }
    
    private function sendAction(): void {
        $matchId = $_POST['match_id'] ?? '';
        $playerId = $this->getPlayerId();
        $action = json_decode($_POST['action'] ?? '{}', true);

        $engine = $this->loadMatch($matchId);
        if (!$engine) { $this->error('Match not found'); return; }

        $result = $engine->executeAction($playerId, $action);
        $this->saveMatch($matchId, $engine);

        echo json_encode($result);
    }
        
    private function processTick(): void {
        $keys = $this->redis->keys('match:*');
        $processed = 0;

        foreach ($keys as $key) {
            $matchId = str_replace('match:', '', $key);
            $engine = $this->loadMatch($matchId);
            if (!$engine) continue;

            $engine->tick();
            $this->saveMatch($matchId, $engine);
            $processed++;
        }

        echo json_encode(['success' => true, 'processed' => $processed]);
    }
    
    private function getPlayerId(): int {
        return $_SESSION['user_id'] ?? 1;
    }
    
    private function error(string $message): void {
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    private function saveMatch(string $matchId, GameEngine $engine): void {
        $this->redis->setex('match:' . $matchId, 3600, serialize($engine));
    }

    private function loadMatch(string $matchId): ?GameEngine {
        $data = $this->redis->get('match:' . $matchId);
        return $data ? unserialize($data) : null;
    }
}

$controller = new GameController();
$controller->handleRequest();