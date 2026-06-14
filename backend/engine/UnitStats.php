<?php
// engine/UnitStats.php

class UnitStats {
    private static array $units = [
        // Plants
        'peashooter' => [
            'side' => 'plants',
            'cost' => 50,
            'health' => 10,
            'damage' => 5,
            'cooldown' => 0.5,
            'range' => 5,
            'speed' => 0
        ],
        'sunflower' => [
            'side' => 'plants',
            'cost' => 50,
            'health' => 5,
            'damage' => 0,
            'cooldown' => 0,
            'range' => 0,
            'speed' => 0
        ],
        'wallnut' => [
            'side' => 'plants',
            'cost' => 50,
            'health' => 30,
            'damage' => 0,
            'cooldown' => 0,
            'range' => 0,
            'speed' => 0
        ],
        'repeater' => [
            'side' => 'plants',
            'cost' => 100,
            'health' => 10,
            'damage' => 5,
            'cooldown' => 0.25,
            'range' => 5,
            'speed' => 0
        ],
        
        // Zombies
        'basic_zombie' => [
            'side' => 'zombies',
            'cost' => 50,
            'health' => 10,
            'damage' => 5,
            'cooldown' => 0.5,
            'range' => 0,
            'speed' => 0.5
        ],
        'conehead' => [
            'side' => 'zombies',
            'cost' => 75,
            'health' => 20,
            'damage' => 5,
            'cooldown' => 0.5,
            'range' => 0,
            'speed' => 0.4
        ],
        'buckethead' => [
            'side' => 'zombies',
            'cost' => 125,
            'health' => 40,
            'damage' => 5,
            'cooldown' => 0.5,
            'range' => 0,
            'speed' => 0.3
        ]
    ];
    
    public static function get(string $unitType): ?array {
        return self::$units[$unitType] ?? null;
    }
    
    public static function exists(string $unitType): bool {
        return isset(self::$units[$unitType]);
    }
    
    public static function getCost(string $unitType): int {
        return self::$units[$unitType]['cost'] ?? 0;
    }
    
    public static function getSide(string $unitType): string {
        return self::$units[$unitType]['side'] ?? '';
    }
}