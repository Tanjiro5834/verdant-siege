<?php
// engine/WaveManager.php

class WaveManager {
    private int $currentWave = 1;
    private float $spawnTimer = 0;
    private float $timeBetweenWaves = 5.0; // seconds
    private bool $waveInProgress = false;
    private array $currentWaveZombies = [];
    
    private array $waveDefinitions = [
        1 => [
            'basic_zombie' => [0, 2, 4]  // lanes
        ],
        2 => [
            'basic_zombie' => [0, 1, 2, 3, 4]
        ],
        3 => [
            'basic_zombie' => [0, 1, 2, 3, 4],
            'conehead' => [2]
        ],
        4 => [
            'basic_zombie' => [0, 1, 2, 3, 4],
            'conehead' => [1, 3]
        ],
        5 => [
            'basic_zombie' => [0, 1, 2, 3, 4],
            'conehead' => [0, 2, 4],
            'buckethead' => [2]
        ]
        // Add more waves up to 10+
    ];
    
    public function update(float $deltaTime): void {
        if (!$this->waveInProgress) {
            $this->spawnTimer += $deltaTime;
        }
    }
    
    public function shouldSpawnWave(): bool {
        return !$this->waveInProgress && $this->spawnTimer >= $this->timeBetweenWaves;
    }
    
    public function getNextWave(): array {
        $wave = $this->waveDefinitions[$this->currentWave] ?? $this->waveDefinitions[1];
        $this->waveInProgress = true;
        $this->spawnTimer = 0;
        $this->currentWaveZombies = $this->expandWaveDefinition($wave);
        
        return $this->currentWaveZombies;
    }
    
    private function expandWaveDefinition(array $wave): array {
        $expanded = [];
        foreach ($wave as $zombieType => $lanes) {
            foreach ($lanes as $lane) {
                $expanded[$lane][] = $zombieType;
            }
        }
        return $expanded;
    }
    
    public function onWaveComplete(): void {
        $this->waveInProgress = false;
        $this->currentWave++;
    }
    
    public function getCurrentWave(): int {
        return $this->currentWave;
    }
}