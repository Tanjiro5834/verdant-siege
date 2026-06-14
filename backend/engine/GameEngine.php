<?php
// engine/GameEngine.php
// AUTHORITATIVE: All game logic runs here, client only visualizes

class GameEngine {
    private GameState $state;
    private CombatSystem $combat;
    private EntityManager $entities;
    private Validator $validator;
    private float $lastTickTime;
    private int $currentTick = 0;
    
    // Tick rate: 20ms = 50 calculations per second
    private const TICK_RATE = 0.02;
    
    public function __construct() {
        $this->state = new GameState();
        $this->combat = new CombatSystem($this->state);
        $this->entities = new EntityManager($this->state);
        $this->validator = new Validator();
        $this->lastTickTime = microtime(true);
    }
    
    /**
     * Main game loop - called by cron job or background process
     * Each tick processes a slice of game time
     */
    public function tick(): array {
        $now = microtime(true);
        $deltaTime = min(0.1, $now - $this->lastTickTime);
        $this->lastTickTime = $now;
        $this->currentTick++;
        
        // Skip if game is over
        if ($this->state->isGameOver()) {
            return ['gameOver' => true, 'winner' => $this->state->getWinner()];
        }
        
        // Process each game system in order
        $this->processResources($deltaTime);
        $this->entities->updateAll($deltaTime);
        $this->combat->resolveAll($deltaTime);
        $this->checkWinConditions();
        $this->manageWaves($deltaTime);
        
        // Auto-switch turn after timeout
        $this->handleTurnTimeouts();
        
        return [
            'success' => true,
            'tick' => $this->currentTick,
            'state' => $this->state->getClientState() // Only what client needs
        ];
    }
    
    /**
     * Validate and execute player action
     * All actions are validated server-side before execution
     */
    public function executeAction(int $playerId, array $action): array {
        // Defensive: Validate action structure
        if (!$this->validator->validateActionStructure($action)) {
            return $this->error('Invalid action structure');
        }
        
        // Defensive: Check player turn
        if (!$this->state->isPlayerTurn($playerId)) {
            return $this->error('Not your turn');
        }
        
        // Defensive: Check game is active
        if ($this->state->isGameOver()) {
            return $this->error('Game already ended');
        }
        
        // Route to appropriate action handler
        switch ($action['type']) {
            case 'place_unit':
                return $this->handlePlaceUnit($playerId, $action);
            case 'collect_resource':
                return $this->handleCollectResource($playerId);
            case 'end_turn':
                return $this->handleEndTurn($playerId);
            default:
                return $this->error('Unknown action type');
        }
    }
    
    /**
     * Handle unit placement with multiple validation layers
     */
    private function handlePlaceUnit(int $playerId, array $action): array {
        $unitType = $action['unitType'] ?? null;
        $row = $action['row'] ?? -1;
        $col = $action['col'] ?? -1;
        
        // Layer 1: Basic validation
        $validations = [
            'unit_exists' => $this->entities->unitExists($unitType),
            'valid_row' => $row >= 0 && $row < 5,
            'valid_col' => $col >= 0 && $col < 9,
            'cell_empty' => $this->state->isCellEmpty($row, $col),
            'has_resources' => $this->state->hasEnoughResources($playerId, $unitType),
            'correct_side' => $this->isCorrectUnitSide($playerId, $unitType)
        ];
        
        foreach ($validations as $check => $passed) {
            if (!$passed) {
                return $this->error("Validation failed: $check");
            }
        }
        
        // Layer 2: Anti-cheat - Rate limiting
        if (!$this->validator->checkRateLimit($playerId, 'place_unit')) {
            return $this->error('Action rate limit exceeded');
        }
        
        // Execute placement
        $cost = $this->entities->getUnitCost($unitType);
        $this->state->deductResources($playerId, $cost);
        $this->entities->placeUnit($unitType, $row, $col);
        
        // Log for replay system
        $this->logAction($playerId, $action);
        
        return [
            'success' => true,
            'action' => 'unit_placed',
            'unit' => $unitType,
            'position' => ['row' => $row, 'col' => $col],
            'remaining_resources' => $this->state->getResources($playerId)
        ];
    }
    
    /**
     * Handle resource collection with anti-farming protection
     */
    private function handleCollectResource(int $playerId): array {
        // Anti-farming: Check collection rate
        if (!$this->validator->canCollectResource($playerId)) {
            return $this->error('Cannot collect resources yet');
        }
        
        $baseAmount = 25;
        $bonus = $this->state->hasActiveBoost($playerId) ? 10 : 0;
        $totalGain = $baseAmount + $bonus;
        
        $this->state->addResources($playerId, $totalGain);
        $this->validator->recordCollection($playerId);
        
        return [
            'success' => true,
            'amount' => $totalGain,
            'new_total' => $this->state->getResources($playerId)
        ];
    }
    
    private function handleEndTurn(int $playerId): array {
        if (!$this->state->canEndTurn($playerId)) {
            return $this->error('Cannot end turn yet');
        }
        
        $this->state->switchTurn();
        
        return [
            'success' => true,
            'next_turn' => $this->state->getCurrentTurn()
        ];
    }
    
    /**
     * Process passive resource generation
     */
    private function processResources(float $deltaTime): void {
        // Sunflowers generate sun over time
        $sunflowers = $this->entities->getUnitsByType('sunflower');
        $generationRate = 0.5; // 0.5 sun per second per sunflower
        
        foreach ($sunflowers as $flower) {
            $amount = $generationRate * $deltaTime;
            $this->state->addResources('plants', $amount);
        }
    }
    
    /**
     * Manage zombie wave spawning
     */
    private function manageWaves(float $deltaTime): void {
        if ($this->state->getCurrentTurn() !== 'zombies') {
            return;
        }
        
        $waveManager = $this->state->getWaveManager();
        $waveManager->update($deltaTime);
        
        if ($waveManager->shouldSpawnWave()) {
            $wave = $waveManager->getNextWave();
            $this->entities->spawnWave($wave);
        }
    }

    public function getState(): GameState {
        return $this->state;
    }
    
    private function checkWinConditions(): void {
        // Plants win: Survived all waves
        if ($this->state->getCurrentWave() > 10 && $this->entities->getZombieCount() === 0) {
            $this->state->endGame('plants');
        }
        
        // Zombies win: Destroyed plant base
        if ($this->state->getPlantBaseHealth() <= 0) {
            $this->state->endGame('zombies');
        }
    }
    
    private function handleTurnTimeouts(): void {
        $turnStartTime = $this->state->getTurnStartTime();
        $turnLimit = 30; // 30 seconds per turn
        
        if (microtime(true) - $turnStartTime > $turnLimit) {
            $this->state->switchTurn();
        }
    }

    public function getCurrentTick(): int {
        return $this->currentTick;
    }
    
    private function logAction(int $playerId, array $action): void {
        // Store for replay system and anti-cheat
        $log = [
            'tick' => $this->currentTick,
            'player' => $playerId,
            'action' => $action,
            'timestamp' => microtime(true)
        ];
        // In production: save to database
    }
    
    private function error(string $message): array {
        return ['success' => false, 'error' => $message];
    }
    
    private function isCorrectUnitSide(int $playerId, string $unitType): bool {
        $playerSide = $this->state->getPlayerSide($playerId);
        $unitSide = $this->entities->getUnitSide($unitType);
        return $playerSide === $unitSide;
    }

    public function __sleep(): array {
        // Only serialize what's needed to reconstruct
        return ['state', 'currentTick', 'lastTickTime'];
    }

    public function __wakeup(): void {
        // Rebuild dependents from the single $state source of truth
        $this->lastTickTime = microtime(true);
        $this->combat = new CombatSystem($this->state);
        $this->entities = new EntityManager($this->state);
        $this->validator = new Validator();
    }
}