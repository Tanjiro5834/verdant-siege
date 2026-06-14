<?php
require_once __DIR__ . '../utils/Database.php';

class UserRepository{
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function save(User $user) {
        $stmt = $this->db->prepare("
            INSERT INTO users (
                username, email, password_hash,
                display_name, bio, avatar_url, favorite_unit,
                elo_rating, total_matches, wins, losses, draws,
                current_streak, best_streak,
                coins, gems, total_coins_earned, total_gems_earned,
                is_active, is_banned, ban_reason, ban_expires_at,
                last_login_at, last_ip
            ) VALUES (
                :username, :email, :password_hash,
                :display_name, :bio, :avatar_url, :favorite_unit,
                :elo_rating, :total_matches, :wins, :losses, :draws,
                :current_streak, :best_streak,
                :coins, :gems, :total_coins_earned, :total_gems_earned,
                :is_active, :is_banned, :ban_reason, :ban_expires_at,
                :last_login_at, :last_ip
            )
        ");

        $stmt->execute([
            ':username'          => $user->getUsername(),
            ':email'             => $user->getEmail(),
            ':password_hash'     => $user->getPasswordHash(),
            ':display_name'      => $user->getDisplayName(),
            ':bio'               => $user->getBio(),
            ':avatar_url'        => $user->getAvatarUrl(),
            ':favorite_unit'     => $user->getFavoriteUnit(),
            ':elo_rating'        => $user->getEloRating(),
            ':total_matches'     => $user->getTotalMatches(),
            ':wins'              => $user->getWins(),
            ':losses'            => $user->getLosses(),
            ':draws'             => $user->getDraws(),
            ':current_streak'    => $user->getCurrentStreak(),
            ':best_streak'       => $user->getBestStreak(),
            ':coins'             => $user->getCoins(),
            ':gems'              => $user->getGems(),
            ':total_coins_earned'=> $user->getTotalCoinsEarned(),
            ':total_gems_earned' => $user->getTotalGemsEarned(),
            ':is_active'         => $user->getIsActive(),
            ':is_banned'         => $user->getIsBanned(),
            ':ban_reason'        => $user->getBanReason(),
            ':ban_expires_at'    => $user->getBanExpiresAt(),
            ':last_login_at'     => $user->getLastLoginAt(),
            ':last_ip'           => $user->getLastIp(),
        ]);

        return $this->db->lastInsertId();
    }

    public function getById(int $id) : int {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findAll(){
        $stmt = $this->db->prepare("SELECT * FROM users");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByUsername(string $username){
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByEmail(string $email){
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function update(User $user){
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("UPDATE users
            SET
                username            = :username,
                email               = :email,
                password_hash       = :password_hash,
                display_name        = :display_name,
                bio                 = :bio,
                avatar_url          = :avatar_url,
                favorite_unit       = :favorite_unit,
                elo_rating          = :elo_rating,
                total_matches       = :total_matches,
                wins                = :wins,
                losses              = :losses,
                draws               = :draws,
                current_streak      = :current_streak,
                best_streak         = :best_streak,
                coins               = :coins,
                gems                = :gems,
                total_coins_earned  = :total_coins_earned,
                total_gems_earned   = :total_gems_earned,
                is_active           = :is_active,
                is_banned           = :is_banned,
                ban_reason          = :ban_reason,
                ban_expires_at      = :ban_expires_at,
                last_login_at       = :last_login_at,
                last_ip             = :last_ip,
                updated_at          = CURRENT_TIMESTAMP
            WHERE id = :id;
            ");
            
            $stmt->execute([
                ':id'                => $user->getId(),
                ':username'          => $user->getUsername(),
                ':email'             => $user->getEmail(),
                ':password_hash'     => $user->getPasswordHash(),
                ':display_name'      => $user->getDisplayName(),
                ':bio'               => $user->getBio(),
                ':avatar_url'        => $user->getAvatarUrl(),
                ':favorite_unit'     => $user->getFavoriteUnit(),
                ':elo_rating'        => $user->getEloRating(),
                ':total_matches'     => $user->getTotalMatches(),
                ':wins'              => $user->getWins(),
                ':losses'            => $user->getLosses(),
                ':draws'             => $user->getDraws(),
                ':current_streak'    => $user->getCurrentStreak(),
                ':best_streak'       => $user->getBestStreak(),
                ':coins'             => $user->getCoins(),
                ':gems'              => $user->getGems(),
                ':total_coins_earned'=> $user->getTotalCoinsEarned(),
                ':total_gems_earned' => $user->getTotalGemsEarned(),
                ':is_active'         => $user->getIsActive(),
                ':is_banned'         => $user->getIsBanned(),
                ':ban_reason'        => $user->getBanReason(),
                ':ban_expires_at'    => $user->getBanExpiresAt(),
                ':last_login_at'     => $user->getLastLoginAt(),
                ':last_ip'           => $user->getLastIp(),
            ]);

            $this->db->commit(); 
            return true;
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // Delete
    public function deleteById($id){
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function delete(User $user){
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([':id' => $user->getId()]);
    }

    // Extra queries (based on schema)
    public function findActiveUsers(){
        
    }

    public function findBannedUsers(){

    }

    public function findLeaderboard($limit = 10){

    }

    public function findByDisplayName($displayName){

    }

    public function findByFavoriteUnit($favoriteUnit){

    }

    // Stats / Economy helpers
    public function incrementCoins($userId, $amount){
        try{
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if($row){
                $newCoins = $row['coins'] + $amount;

                $updateStmt = $this->db->prepare("UPDATE users SET coins = :coins WHERE id = :id");
                $updateStmt->execute([':coins' => $newCoins, ':id' => $userId]);
            }
            $this->db->commit();
        }catch(Exception $e){
            $this->db->rollBack();
            throw $e;
        }
    }

    public function incrementGems($userId, $amount){
        try{
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if($row){
                $newCoins = $row['coins'] + $amount;

                $updateStmt = $this->db->prepare("UPDATE users SET gems = :coins WHERE id = :id");
                $updateStmt->execute([':coins' => $newCoins, ':id' => $userId]);
            }
            $this->db->commit();
        }catch(Exception $e){
            $this->db->rollBack();
            throw $e;
        }
    }

    // result = win/loss/draw
    public function recordMatchResult($userId, $result){

    } 
}