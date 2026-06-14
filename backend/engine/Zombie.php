<?php

class Zombie {
    private string $id;
    private string $type;
    private int $lane;
    private float $position;
    private float $health;
    private float $maxHealth;
    private int $damage;
    private float $speed;
    private float $attackCooldown;
    private float $currentCooldown = 0;
    private bool $isAttacking = false;
    
    public function __construct(string $type, int $lane, float $position, array $stats) {
        $this->id = uniqid();
        $this->type = $type;
        $this->lane = $lane;
        $this->position = $position;
        $this->health = $stats['health'];
        $this->maxHealth = $stats['health'];
        $this->damage = $stats['damage'];
        $this->speed = $stats['speed'];
        $this->attackCooldown = $stats['cooldown'];
    }
    
    public function update(float $deltaTime): void {
        if ($this->currentCooldown > 0) {
            $this->currentCooldown -= $deltaTime;
        }
    }
    
    public function attack(Unit $target): void {
        if ($this->currentCooldown <= 0) {
            $target->takeDamage($this->damage);
            $this->currentCooldown = $this->attackCooldown;
            $this->isAttacking = true;
        }
    }
    
    public function takeDamage(int $amount): void {
        $this->health -= $amount;
    }
    
    public function isDead(): bool {
        return $this->health <= 0;
    }
    
    public function getHealthPercent(): float {
        return $this->health / $this->maxHealth;
    }
    
    // Getters/Setters
    public function getId(): string { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getLane(): int { return $this->lane; }
    public function getPosition(): float { return $this->position; }
    public function setPosition(float $position): void { $this->position = $position; }
    public function getDamage(): int { return $this->damage; }
    public function getSpeed(): float { return $this->speed; }
    public function isAttacking(): bool { return $this->isAttacking; }
    public function setAttacking(bool $attacking): void { $this->isAttacking = $attacking; }
}