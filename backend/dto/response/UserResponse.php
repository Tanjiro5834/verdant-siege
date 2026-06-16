<?php

class UserResponse {
    public int $id;
    public string $username;
    public string $email;
    public ?string $displayName;
    public ?string $bio;
    public ?string $avatarUrl;
    public ?string $favoriteUnit;

    // Ranked stats
    public int $eloRating;
    public int $totalMatches;
    public int $wins;
    public int $losses;
    public int $draws;
    public int $currentStreak;
    public int $bestStreak;

    // Economy
    public int $coins;
    public int $gems;
    public int $totalCoinsEarned;
    public int $totalGemsEarned;

    // Account status
    public bool $isActive;
    public bool $isBanned;
    public ?string $banReason;
    public ?string $banExpiresAt;
    public ?string $lastLoginAt;
    public ?string $lastIp;

    // Timestamps
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    public function __construct(array $data) {
        foreach ($data as $key => $value) {
            $camel = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $camel)) {
                $this->$camel = $value;
            }
        }
    }

    public function toArray(): array {
        return [
            'id'             => $this->id,
            'username'       => $this->username,
            'email'          => $this->email,
            'display_name'   => $this->displayName,
            'bio'            => $this->bio,
            'avatar_url'     => $this->avatarUrl,
            'favorite_unit'  => $this->favoriteUnit,
            'elo_rating'     => $this->eloRating,
            'total_matches'  => $this->totalMatches,
            'wins'           => $this->wins,
            'losses'         => $this->losses,
            'draws'          => $this->draws,
            'current_streak' => $this->currentStreak,
            'best_streak'    => $this->bestStreak,
            'coins'          => $this->coins,
            'gems'           => $this->gems,
            'created_at'     => $this->createdAt,
            'last_login_at'  => $this->lastLoginAt,
        ];
    }
}
