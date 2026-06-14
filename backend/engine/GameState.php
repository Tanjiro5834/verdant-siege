<?php
// engine/GameState.php
// Single source of truth - all game data

class GameState {
    private array $grid;          // 5x9 grid of units
    private array $lanes;         // Zombie positions per lane
    private array $resources;     // ['plants' => 150, 'zombies' => 100]
    private string $currentTurn;  // 'plants' or 'zombies'
    private int $currentWave = 1;
    private int $plantBaseHealth = 10;
    private bool $gameOver = false;
    private ?string $winner = null;
    private float $turnStartTime;
    private array $playerSides;    // Maps player IDs to 'plants' or 'zombies'
    private WaveManager $waveManager;
    
    // NEW: Boost system for power-ups
    private array $activeBoosts = [];  // ['plants' => ['damage_boost' => expiry_time], 'zombies' => []]
    
    // NEW: Track last action times for rate limiting
    private array $lastActionTime = [];
    
    public function __construct() {
        $this->initializeEmptyGrid();
        $this->initializeEmptyLanes();
        $this->resources = ['plants' => 150, 'zombies' => 100];
        $this->currentTurn = 'plants';
        $this->turnStartTime = microtime(true);
        $this->waveManager = new WaveManager();
        $this->activeBoosts = ['plants' => [], 'zombies' => []];
    }
    
    /**
     * Get a sanitized version for clients (no internal data)
     */
    public function getClientState(): array {
        return [
            'grid' => $this->sanitizeGridForClient(),
            'lanes' => $this->sanitizeLanesForClient(),
            'resources' => $this->resources,
            'currentTurn' => $this->currentTurn,
            'currentWave' => $this->currentWave,
            'plantBaseHealth' => $this->plantBaseHealth,
            'gameOver' => $this->gameOver,
            'winner' => $this->winner,
            'activeBoosts' => $this->getActiveBoostsForClient()  // NEW
        ];
    }
    
    /**
     * Check if a player has an active boost
     */
    public function hasActiveBoost(int $playerId): bool {
        $side = $this->playerSides[$playerId] ?? null;
        if (!$side) return false;
        
        $now = microtime(true);
        
        // Clean expired boosts first
        foreach ($this->activeBoosts[$side] as $boostType => $expiry) {
            if ($expiry < $now) {
                unset($this->activeBoosts[$side][$boostType]);
            }
        }
        
        return !empty($this->activeBoosts[$side]);
    }
    
    /**
     * Check if player has a specific boost type
     */
    public function hasSpecificBoost(int $playerId, string $boostType): bool {
        $side = $this->playerSides[$playerId] ?? null;
        if (!$side) return false;
        
        $now = microtime(true);
        
        if (isset($this->activeBoosts[$side][$boostType]) && 
            $this->activeBoosts[$side][$boostType] > $now) {
            return true;
        }
        
        // Clean up if expired
        if (isset($this->activeBoosts[$side][$boostType])) {
            unset($this->activeBoosts[$side][$boostType]);
        }
        
        return false;
    }
    
    /**
     * Apply a boost to a player
     */
    public function applyBoost(int $playerId, string $boostType, float $durationSeconds): void {
        $side = $this->playerSides[$playerId] ?? null;
        if (!$side) return;
        
        $expiry = microtime(true) + $durationSeconds;
        $this->activeBoosts[$side][$boostType] = $expiry;
    }
    
    /**
     * Get boost multiplier for a specific stat
     */
    public function getBoostMultiplier(int $playerId, string $statType): float {
        $side = $this->playerSides[$playerId] ?? null;
        if (!$side) return 1.0;
        
        $multiplier = 1.0;
        $now = microtime(true);
        
        // Damage boost
        if ($statType === 'damage' && isset($this->activeBoosts[$side]['damage_boost'])) {
            if ($this->activeBoosts[$side]['damage_boost'] > $now) {
                $multiplier *= 1.5;  // 50% damage boost
            }
        }
        
        // Attack speed boost
        if ($statType === 'attack_speed' && isset($this->activeBoosts[$side]['speed_boost'])) {
            if ($this->activeBoosts[$side]['speed_boost'] > $now) {
                $multiplier *= 0.5;  // Attack twice as fast (lower cooldown)
            }
        }
        
        return $multiplier;
    }
    
    /**
     * Get active boosts formatted for client
     */
    private function getActiveBoostsForClient(): array {
        $result = ['plants' => [], 'zombies' => []];
        $now = microtime(true);
        
        foreach (['plants', 'zombies'] as $side) {
            foreach ($this->activeBoosts[$side] as $boostType => $expiry) {
                if ($expiry > $now) {
                    $result[$side][$boostType] = round($expiry - $now, 1);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Check if a cell is empty and valid
     */
    public function isCellEmpty(int $row, int $col): bool {
        // Boundary check first (defensive programming)
        if ($row < 0 || $row >= 5 || $col < 0 || $col >= 9) {
            return false;
        }
        
        if (!isset($this->grid[$row][$col])) {
            return false;
        }
        
        return $this->grid[$row][$col] === null;
    }
    
    /**
     * Get unit at specific position
     */
    public function getUnitAt(int $row, int $col): ?Unit {
        if ($row < 0 || $row >= 5 || $col < 0 || $col >= 9) {
            return null;
        }
        
        return $this->grid[$row][$col] ?? null;
    }
    
    /**
     * Place a unit on the grid
     */
    public function placeUnit(Unit $unit, int $row, int $col): void {
        if ($this->isCellEmpty($row, $col)) {
            $this->grid[$row][$col] = $unit;
            $unit->setPosition($row, $col);
        }
    }
    
    /**
     * Remove a unit from the grid
     */
    public function removeUnit(int $row, int $col): void {
        if (isset($this->grid[$row][$col])) {
            $this->grid[$row][$col] = null;
        }
    }
    
    /**
     * Add a zombie to a lane
     */
    public function addZombie(int $lane, Zombie $zombie): void {
        if ($lane >= 0 && $lane < 5) {
            $this->lanes[$lane][] = $zombie;
        }
    }
    
    /**
     * Remove a zombie from a lane
     */
    public function removeZombie(int $lane, int $index): void {
        if (isset($this->lanes[$lane][$index])) {
            unset($this->lanes[$lane][$index]);
            $this->lanes[$lane] = array_values($this->lanes[$lane]);
        }
    }
    
    /**
     * Check if player has enough resources
     */
    public function hasEnoughResources(int $playerId, string $unitType): bool {
        $side = $this->playerSides[$playerId] ?? null;
        if (!$side) return false;
        
        $cost = UnitStats::getCost($unitType);
        return $this->resources[$side] >= $cost;
    }
    
    /**
     * Deduct resources from player
     */
    public function deductResources(int $playerId, int $amount): void {
        $side = $this->playerSides[$playerId] ?? null;
        if ($side && $this->resources[$side] >= $amount) {
            $this->resources[$side] -= $amount;
        }
    }
    
    /**
     * Add resources to player
     */
    public function addResources(int $playerId, int $amount): void {
        $side = $this->playerSides[$playerId] ?? null;
        if ($side) {
            $this->resources[$side] += $amount;
            // Cap at reasonable maximum (999)
            $this->resources[$side] = min(999, $this->resources[$side]);
        }
    }
    
    /**
     * Add resources by side name (for internal use)
     */
    public function addResourcesBySide(string $side, int $amount): void {
        if (isset($this->resources[$side])) {
            $this->resources[$side] += $amount;
            $this->resources[$side] = min(999, $this->resources[$side]);
        }
    }
    
    /**
     * Get player's current resources
     */
    public function getResources(int $playerId): int {
        $side = $this->playerSides[$playerId] ?? null;
        return $side ? $this->resources[$side] : 0;
    }
    
    /**
     * Get resources by side name
     */
    public function getResourcesBySide(string $side): int {
        return $this->resources[$side] ?? 0;
    }
    
    /**
     * Check if it's the player's turn
     */
    public function isPlayerTurn(int $playerId): bool {
        if ($this->gameOver) return false;
        
        $playerSide = $this->playerSides[$playerId] ?? null;
        return $playerSide === $this->currentTurn;
    }
    
    /**
     * Switch turn to the other player
     */
    public function switchTurn(): void {
        $this->currentTurn = ($this->currentTurn === 'plants') ? 'zombies' : 'plants';
        $this->turnStartTime = microtime(true);
        
        // Reset last action time for the new turn holder
        // This prevents carry-over of rate limits
    }
    
    /**
     * Check if player can end their turn
     */
    public function canEndTurn(int $playerId): bool {
        if ($this->gameOver) return false;
        
        $playerSide = $this->playerSides[$playerId] ?? null;
        if ($playerSide !== $this->currentTurn) return false;
        
        // Can always end turn, but might have minimum action requirement
        // For now, just check it's their turn
        return true;
    }
    
    /**
     * Damage the plant base
     */
    public function damagePlantBase(int $amount): void {
        $this->plantBaseHealth -= $amount;
        
        if ($this->plantBaseHealth <= 0) {
            $this->plantBaseHealth = 0;
            $this->endGame('zombies');
        }
    }
    
    /**
     * Get current plant base health
     */
    public function getPlantBaseHealth(): int {
        return $this->plantBaseHealth;
    }
    
    /**
     * End the game with a winner
     */
    public function endGame(string $winner): void {
        $this->gameOver = true;
        $this->winner = $winner;
    }
    
    /**
     * Check if game is over
     */
    public function isGameOver(): bool {
        return $this->gameOver;
    }
    
    /**
     * Get winner (null if game not over)
     */
    public function getWinner(): ?string {
        return $this->winner;
    }
    
    /**
     * Get current turn ('plants' or 'zombies')
     */
    public function getCurrentTurn(): string {
        return $this->currentTurn;
    }
    
    /**
     * Get turn start timestamp
     */
    public function getTurnStartTime(): float {
        return $this->turnStartTime;
    }
    
    /**
     * Get current wave number
     */
    public function getCurrentWave(): int {
        return $this->currentWave;
    }
    
    /**
     * Increment wave number
     */
    public function incrementWave(): void {
        $this->currentWave++;
    }
    
    /**
     * Get wave manager instance
     */
    public function getWaveManager(): WaveManager {
        return $this->waveManager;
    }
    
    /**
     * Get player's side
     */
    public function getPlayerSide(int $playerId): string {
        return $this->playerSides[$playerId] ?? '';
    }
    
    /**
     * Set player's side
     */
    public function setPlayerSide(int $playerId, string $side): void {
        if ($side === 'plants' || $side === 'zombies') {
            $this->playerSides[$playerId] = $side;
        }
    }
    
    /**
     * Get the full grid (for internal use)
     */
    public function getGrid(): array {
        return $this->grid;
    }
    
    /**
     * Get all lanes with zombies (for internal use)
     */
    public function getLanes(): array {
        return $this->lanes;
    }
    
    /**
     * Get a specific lane
     */
    public function getLane(int $lane): array {
        return $this->lanes[$lane] ?? [];
    }
    
    /**
     * Record an action timestamp for rate limiting
     */
    public function recordAction(int $playerId): void {
        $this->lastActionTime[$playerId] = microtime(true);
    }
    
    /**
     * Get time since last action
     */
    public function getTimeSinceLastAction(int $playerId): float {
        if (!isset($this->lastActionTime[$playerId])) {
            return PHP_FLOAT_MAX;
        }
        return microtime(true) - $this->lastActionTime[$playerId];
    }
    
    /**
     * Clean up expired boosts (call this periodically)
     */
    public function cleanExpiredBoosts(): void {
        $now = microtime(true);
        
        foreach (['plants', 'zombies'] as $side) {
            foreach ($this->activeBoosts[$side] as $boostType => $expiry) {
                if ($expiry < $now) {
                    unset($this->activeBoosts[$side][$boostType]);
                }
            }
        }
    }
    
    /**
     * Remove the grid data and replace with empty grid
     */
    private function initializeEmptyGrid(): void {
        $this->grid = [];
        for ($row = 0; $row < 5; $row++) {
            $this->grid[$row] = [];
            for ($col = 0; $col < 9; $col++) {
                $this->grid[$row][$col] = null;
            }
        }
    }
    
    /**
     * Initialize empty lanes for zombies
     */
    private function initializeEmptyLanes(): void {
        $this->lanes = [];
        for ($i = 0; $i < 5; $i++) {
            $this->lanes[$i] = [];
        }
    }
    
    /**
     * Sanitize grid for client (remove internal data)
     */
    private function sanitizeGridForClient(): array {
        $sanitized = [];
        for ($row = 0; $row < 5; $row++) {
            $sanitized[$row] = [];
            for ($col = 0; $col < 9; $col++) {
                $unit = $this->grid[$row][$col] ?? null;
                if ($unit) {
                    $sanitized[$row][$col] = [
                        'type' => $unit->getType(),
                        'health' => $unit->getHealthPercent(),
                        'isAttacking' => $unit->isAttacking()
                    ];
                } else {
                    $sanitized[$row][$col] = null;
                }
            }
        }
        return $sanitized;
    }
    
    /**
     * Sanitize lanes for client (remove internal data)
     */
    private function sanitizeLanesForClient(): array {
        $sanitized = [];
        foreach ($this->lanes as $laneIdx => $zombies) {
            $sanitized[$laneIdx] = [];
            foreach ($zombies as $zombie) {
                $sanitized[$laneIdx][] = [
                    'type' => $zombie->getType(),
                    'position' => $zombie->getPosition(),
                    'health' => $zombie->getHealthPercent()
                ];
            }
        }
        return $sanitized;
    }
}