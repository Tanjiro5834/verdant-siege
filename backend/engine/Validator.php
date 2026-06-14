<?php
// engine/Validator.php
// Defensive programming: validate EVERYTHING

class Validator {
    private array $actionHistory = [];
    private array $collectionHistory = [];
    
    // Rate limiting: max actions per second
    private const MAX_ACTIONS_PER_SECOND = 5;
    private const MIN_COLLECTION_INTERVAL = 2.0; // seconds
    
    /**
     * Validate action structure has required fields
     */
    public function validateActionStructure(array $action): bool {
        $required = ['type'];
        
        foreach ($required as $field) {
            if (!isset($action[$field])) {
                return false;
            }
        }
        
        // Type-specific validation
        switch ($action['type']) {
            case 'place_unit':
                return isset($action['unitType'], $action['row'], $action['col']) &&
                       is_string($action['unitType']) &&
                       is_int($action['row']) && $action['row'] >= 0 && $action['row'] < 5 &&
                       is_int($action['col']) && $action['col'] >= 0 && $action['col'] < 9;
            
            case 'collect_resource':
            case 'end_turn':
                return true;
            
            default:
                return false;
        }
    }
    
    /**
     * Rate limiting to prevent action spam
     */
    public function checkRateLimit(int $playerId, string $actionType): bool {
        $now = microtime(true);
        $key = "{$playerId}_{$actionType}";
        
        if (!isset($this->actionHistory[$key])) {
            $this->actionHistory[$key] = [];
        }
        
        // Clean old actions (older than 1 second)
        $this->actionHistory[$key] = array_filter(
            $this->actionHistory[$key],
            fn($time) => ($now - $time) < 1.0
        );
        
        // Check limit
        if (count($this->actionHistory[$key]) >= self::MAX_ACTIONS_PER_SECOND) {
            return false;
        }
        
        $this->actionHistory[$key][] = $now;
        return true;
    }
    
    /**
     * Prevent resource farming abuse
     */
    public function canCollectResource(int $playerId): bool {
        $now = microtime(true);
        
        if (!isset($this->collectionHistory[$playerId])) {
            $this->collectionHistory[$playerId] = 0;
            return true;
        }
        
        $timeSinceLast = $now - $this->collectionHistory[$playerId];
        return $timeSinceLast >= self::MIN_COLLECTION_INTERVAL;
    }
    
    public function recordCollection(int $playerId): void {
        $this->collectionHistory[$playerId] = microtime(true);
    }
    
    /**
     * Server-authoritative position validation
     * Prevents client from teleporting units
     */
    public function validateMovement(float $oldPos, float $newPos, float $maxSpeed, float $deltaTime): bool {
        $maxDelta = $maxSpeed * $deltaTime;
        $actualDelta = abs($newPos - $oldPos);
        return $actualDelta <= $maxDelta + 0.01; // Small epsilon for floating point
    }
    
    /**
     * Validate resource gain isn't impossible
     */
    public function validateResourceGain(int $previousAmount, int $newAmount, float $timePassed): bool {
        $maxGainPerSecond = 100;
        $maxPossibleGain = $maxGainPerSecond * $timePassed;
        $actualGain = $newAmount - $previousAmount;
        
        return $actualGain <= $maxPossibleGain;
    }
}