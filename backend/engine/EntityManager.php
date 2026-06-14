<?php
// engine/EntityManager.php
// Handles all unit creation, updates, and spawning

class EntityManager {
    private GameState $state;
    private array $units = [];      // Cache of all active units
    private array $zombies = [];    // Cache of all active zombies
    
    public function __construct(GameState $state) {
        $this->state = $state;
    }
    
    /**
     * Update all entities with delta time
     * Called every game tick
     */
    public function updateAll(float $deltaTime): void {
        $this->updatePlants($deltaTime);
        $this->updateZombies($deltaTime);
        $this->removeDeadUnits();
    }
    
    private function updatePlants(float $deltaTime): void {
        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 9; $col++) {
                $plant = $this->state->getGrid()[$row][$col] ?? null;
                if ($plant) {
                    $plant->update($deltaTime);
                }
            }
        }
    }
    
    private function updateZombies(float $deltaTime): void {
        $lanes = $this->state->getLanes();
        foreach ($lanes as $laneIdx => $zombies) {
            foreach ($zombies as $zombieIdx => $zombie) {
                $zombie->update($deltaTime);
                
                // Check for collision with plants
                $frontCol = (int)floor($zombie->getPosition());
                if ($frontCol >= 0 && $frontCol < 9) {
                    $blockingPlant = $this->state->getGrid()[$laneIdx][$frontCol] ?? null;
                    if ($blockingPlant) {
                        $zombie->setAttacking(true);
                        $zombie->attack($blockingPlant);
                    } else {
                        $zombie->setAttacking(false);
                        // Move forward
                        $newPos = $zombie->getPosition() - ($zombie->getSpeed() * $deltaTime);
                        $zombie->setPosition(max(0, $newPos));
                    }
                }
                
                // Check if zombie reached base
                if ($zombie->getPosition() <= 0) {
                    $this->state->removeZombie($laneIdx, $zombieIdx);
                    $this->state->damagePlantBase($zombie->getDamage());
                }
            }
        }
    }
    
    private function removeDeadUnits(): void {
        // Remove dead plants
        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 9; $col++) {
                $plant = $this->state->getGrid()[$row][$col] ?? null;
                if ($plant && $plant->isDead()) {
                    $this->state->removeUnit($row, $col);
                }
            }
        }
        
        // Remove dead zombies
        $lanes = $this->state->getLanes();
        foreach ($lanes as $laneIdx => $zombies) {
            foreach ($zombies as $zombieIdx => $zombie) {
                if ($zombie->isDead()) {
                    $this->state->removeZombie($laneIdx, $zombieIdx);
                }
            }
        }
    }
    
    public function placeUnit(string $unitType, int $row, int $col): void {
        $unit = $this->createUnit($unitType, $row, $col);
        $this->state->placeUnit($unit, $row, $col);
    }
    
    private function createUnit(string $type, int $row, int $col): Unit {
        $stats = UnitStats::get($type);
        return new Unit($type, $row, $col, $stats);
    }
    
    public function spawnWave(array $wave): void {
        foreach ($wave as $lane => $zombieTypes) {
            foreach ($zombieTypes as $zombieType) {
                $zombie = $this->createZombie($zombieType, $lane);
                $this->state->addZombie($lane, $zombie);
            }
        }
    }
    
    private function createZombie(string $type, int $lane): Zombie {
        $stats = UnitStats::get($type);
        return new Zombie($type, $lane, 9.0, $stats);
    }
    
    public function unitExists(string $unitType): bool {
        return UnitStats::exists($unitType);
    }
    
    public function getUnitCost(string $unitType): int {
        return UnitStats::getCost($unitType);
    }
    
    public function getUnitSide(string $unitType): string {
        return UnitStats::getSide($unitType);
    }
    
    public function getUnitsByType(string $type): array {
        $found = [];
        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 9; $col++) {
                $unit = $this->state->getGrid()[$row][$col] ?? null;
                if ($unit && $unit->getType() === $type) {
                    $found[] = $unit;
                }
            }
        }
        return $found;
    }
    
    public function getZombieCount(): int {
        $count = 0;
        foreach ($this->state->getLanes() as $lane) {
            $count += count($lane);
        }
        return $count;
    }
}