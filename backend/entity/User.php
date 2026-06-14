<?php

class User {
    use AutoAccessors;

    // Core account
    private $id;
    private $username;
    private $email;
    private $passwordHash;

    // Profile
    private $displayName;
    private $bio;
    private $avatarUrl;
    private $favoriteUnit;

    // Ranked stats
    private $eloRating;
    private $totalMatches;
    private $wins;
    private $losses;
    private $draws;
    private $currentStreak;
    private $bestStreak;

    // Economy
    private $coins;
    private $gems;
    private $totalCoinsEarned;
    private $totalGemsEarned;

    // Account status
    private $isActive;
    private $isBanned;
    private $banReason;
    private $banExpiresAt;
    private $lastLoginAt;
    private $lastIp;

    // Timestamps
    private $createdAt;
    private $updatedAt;

    public function __construct(
        $username,
        $email,
        $passwordHash,
        $displayName = null,
        $bio = null,
        $avatarUrl = null,
        $favoriteUnit = null
    ) {
        $this->username     = $username;
        $this->email        = $email;
        $this->passwordHash = $passwordHash;
        $this->displayName  = $displayName;
        $this->bio          = $bio;
        $this->avatarUrl    = $avatarUrl;
        $this->favoriteUnit = $favoriteUnit;

        // Defaults (matching schema)
        $this->eloRating        = 1200;
        $this->totalMatches     = 0;
        $this->wins             = 0;
        $this->losses           = 0;
        $this->draws            = 0;
        $this->currentStreak    = 0;
        $this->bestStreak       = 0;
        $this->coins            = 500;
        $this->gems             = 0;
        $this->totalCoinsEarned = 0;
        $this->totalGemsEarned  = 0;
        $this->isActive         = true;
        $this->isBanned         = false;
    }
}
