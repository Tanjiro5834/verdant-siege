<?php
// engine/CombatSystem.php
// Handles all damage calculations and projectile management

class CombatSystem {
    private GameState $state;
    private array $projectiles = [];
    
    public function __construct(GameState $state) {
        $this->state = $state;
    }
    
    /**
     * Resolve all combat for this tick
     * Order matters: plants attack first, then zombies
     */
    public function resolveAll(float $deltaTime): void {
        $this->processPlantAttacks();
        $this->processProjectiles($deltaTime);
        // Zombie attacks happen in EntityManager during update
    }
    
    private function processPlantAttacks(): void {
        $grid = $this->state->getGrid();
        $lanes = $this->state->getLanes();
        
        for ($row = 0; $row < 5; $row++) {
            for ($col = 0; $col < 9; $col++) {
                $plant = $grid[$row][$col] ?? null;
                if (!$plant || !$plant->canAttack()) continue;
                
                // Find nearest zombie in same lane
                $target = $this->findNearestZombie($row, $col, $lanes[$row]);
                
                if ($target && $this->isInRange($plant, $target, $col)) {
                    $this->createProjectile($plant, $target);
                    $plant->resetAttackCooldown();
                }
            }
        }
    }
    
    /**
     * Find closest zombie that's ahead of the plant
     */
    private function findNearestZombie(int $row, int $col, array $zombies): ?Zombie {
        $closest = null;
        $minDistance = INF;
        
        foreach ($zombies as $zombie) {
            $distance = $zombie->getPosition() - $col;
            // Only target zombies in front (distance > 0)
            if ($distance > 0 && $distance < $minDistance) {
                $minDistance = $distance;
                $closest = $zombie;
            }
        }
        
        return $closest;
    }
    
    private function isInRange(Unit $plant, Zombie $zombie, int $plantCol): bool {
        $distance = $zombie->getPosition() - $plantCol;
        return $distance <= $plant->getRange();
    }
    
    private function createProjectile(Unit $source, Zombie $target): void {
        $this->projectiles[] = [
            'damage' => $source->getDamage(),
            'sourceRow' => $source->getRow(),
            'position' => $source->getCol(),
            'target' => $target->getId(),
            'speed' => 5.0, // cells per second
            'active' => true
        ];
    }
    
    private function processProjectiles(float $deltaTime): void {
        foreach ($this->projectiles as $index => &$projectile) {
            if (!$projectile['active']) continue;
            
            // Move projectile
            $projectile['position'] += $projectile['speed'] * $deltaTime;
            
            // Find target zombie
            $target = $this->findZombieById($projectile['target']);
            
            // Check if projectile reached target
            if ($target && $projectile['position'] >= $target->getPosition()) {
                $target->takeDamage($projectile['damage']);
                $projectile['active'] = false;
            }
            
            // Remove if out of bounds
            if ($projectile['position'] > 10) {
                $projectile['active'] = false;
            }
        }
        
        // Clean up inactive projectiles
        $this->projectiles = array_filter($this->projectiles, fn($p) => $p['active']);
    }
    
    private function findZombieById(string $id): ?Zombie {
        foreach ($this->state->getLanes() as $lane) {
            foreach ($lane as $zombie) {
                if ($zombie->getId() === $id) {
                    return $zombie;
                }
            }
        }
        return null;
    }
}