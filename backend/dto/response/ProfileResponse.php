<?php

class ProfileResponse {
    // Identity
    public int     $id;
    public string  $username;
    public string  $email;
    public ?string $displayName;
    public ?string $bio;
    public ?string $avatarUrl;
    public ?string $favoriteUnit;

    // Ranked stats
    public int   $eloRating;
    public int   $rankPosition;
    public int   $totalMatches;
    public int   $wins;
    public int   $losses;
    public int   $draws;
    public float $winRate;
    public int   $currentStreak;
    public int   $bestStreak;

    // Economy
    public int $coins;
    public int $gems;

    // Timestamps
    public ?string $createdAt;
    public ?string $lastLoginAt;

    // Match history rows from MatchRepository
    public array $matchHistory;

    public function __construct(array $data, array $matchHistory = []) {
        $this->id            = (int) $data['id'];
        $this->username      = $data['username'];
        $this->email         = $data['email'];
        $this->displayName   = $data['display_name']   ?? null;
        $this->bio           = $data['bio']             ?? null;
        $this->avatarUrl     = $data['avatar_url']      ?? null;
        $this->favoriteUnit  = $data['favorite_unit']   ?? null;
        $this->eloRating     = (int)   ($data['elo_rating']     ?? 1200);
        $this->rankPosition  = (int)   ($data['rank_position']  ?? 0);
        $this->totalMatches  = (int)   ($data['total_matches']  ?? 0);
        $this->wins          = (int)   ($data['wins']            ?? 0);
        $this->losses        = (int)   ($data['losses']          ?? 0);
        $this->draws         = (int)   ($data['draws']           ?? 0);
        $this->winRate       = (float) ($data['win_rate']        ?? 0.0);
        $this->currentStreak = (int)   ($data['current_streak']  ?? 0);
        $this->bestStreak    = (int)   ($data['best_streak']     ?? 0);
        $this->coins         = (int)   ($data['coins']           ?? 500);
        $this->gems          = (int)   ($data['gems']            ?? 0);
        $this->createdAt     = $data['created_at']    ?? null;
        $this->lastLoginAt   = $data['last_login_at'] ?? null;
        $this->matchHistory  = $matchHistory;
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
            'rank_position'  => $this->rankPosition,
            'total_matches'  => $this->totalMatches,
            'wins'           => $this->wins,
            'losses'         => $this->losses,
            'draws'          => $this->draws,
            'win_rate'       => $this->winRate,
            'current_streak' => $this->currentStreak,
            'best_streak'    => $this->bestStreak,
            'coins'          => $this->coins,
            'gems'           => $this->gems,
            'created_at'     => $this->createdAt,
            'last_login_at'  => $this->lastLoginAt,
        ];
    }
}