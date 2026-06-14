-- =============================================================
-- VERDANT SIEGE — Complete Database Schema
-- Engine: MySQL 8.0+ / MariaDB 10.5+
-- =============================================================

CREATE DATABASE IF NOT EXISTS verdant_siege
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE verdant_siege;

-- =============================================================
-- 1. USERS
-- =============================================================
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    username        VARCHAR(50)  UNIQUE NOT NULL,
    email           VARCHAR(100) UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,

    -- Profile
    display_name    VARCHAR(50),
    bio             TEXT,
    avatar_url      VARCHAR(255),
    favorite_unit   VARCHAR(50),

    -- Ranked stats
    elo_rating      INT UNSIGNED  DEFAULT 1200,
    total_matches   INT UNSIGNED  DEFAULT 0,
    wins            INT UNSIGNED  DEFAULT 0,
    losses          INT UNSIGNED  DEFAULT 0,
    draws           INT UNSIGNED  DEFAULT 0,
    current_streak  SMALLINT      DEFAULT 0,   -- negative = loss streak
    best_streak     SMALLINT      DEFAULT 0,

    -- Economy
    coins           INT UNSIGNED  DEFAULT 500,
    gems            INT UNSIGNED  DEFAULT 0,
    total_coins_earned INT UNSIGNED DEFAULT 0,
    total_gems_earned  INT UNSIGNED DEFAULT 0,

    -- Preferences (moved to separate table for extensibility — see user_settings)

    -- Account status
    is_active       BOOLEAN       DEFAULT TRUE,
    is_banned       BOOLEAN       DEFAULT FALSE,
    ban_reason      TEXT,
    ban_expires_at  DATETIME      NULL,
    last_login_at   DATETIME      NULL,
    last_ip         VARCHAR(45),

    -- Timestamps
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_username  (username),
    INDEX idx_email     (email),
    INDEX idx_elo       (elo_rating DESC),  -- DESC: leaderboard queries go high→low
    INDEX idx_active    (is_active)
) ENGINE=InnoDB;


-- =============================================================
-- 2. USER SETTINGS (extracted from users — avoids wide rows)
--    One row per user, created on registration.
-- =============================================================
CREATE TABLE IF NOT EXISTS user_settings (
    user_id         INT UNSIGNED PRIMARY KEY,
    sound_enabled   BOOLEAN      DEFAULT TRUE,
    music_enabled   BOOLEAN      DEFAULT TRUE,
    effects_enabled BOOLEAN      DEFAULT TRUE,
    language        VARCHAR(10)  DEFAULT 'en',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- =============================================================
-- 3. USER SESSIONS
--    Store a SHA-256 hash of the token for the index.
--    The full token is returned to the client; only the hash
--    lives in the DB (never expose raw token in index scans).
-- =============================================================
CREATE TABLE IF NOT EXISTS user_sessions (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      CHAR(64)     NOT NULL,   -- SHA-256 hex of the raw token
    ip_address      VARCHAR(45),
    user_agent      VARCHAR(512),
    expires_at      DATETIME     NOT NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE  idx_token_hash  (token_hash),
    INDEX   idx_user_id     (user_id),
    INDEX   idx_expires     (expires_at)     -- for cleanup cron
) ENGINE=InnoDB;


-- =============================================================
-- 4. ITEMS CATALOG
--    Source of truth for every purchasable/earnable item.
--    Inventory rows FK to this — renames don't break history.
-- =============================================================
CREATE TABLE IF NOT EXISTS items (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    item_key        VARCHAR(80)  UNIQUE NOT NULL,  -- e.g. 'skin_zombie_classic'
    item_type       ENUM('skin','boost','consumable','cosmetic','battle_pass') NOT NULL,
    name            VARCHAR(100) NOT NULL,
    description     TEXT,
    emoji           VARCHAR(10),
    coin_price      INT UNSIGNED DEFAULT 0,
    gem_price       INT UNSIGNED DEFAULT 0,
    is_available    BOOLEAN      DEFAULT TRUE,
    is_limited      BOOLEAN      DEFAULT FALSE,   -- limited-time items
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_type      (item_type),
    INDEX idx_available (is_available)
) ENGINE=InnoDB;


-- =============================================================
-- 5. USER INVENTORY
--    FKs to both users and items catalog.
-- =============================================================
CREATE TABLE IF NOT EXISTS user_inventory (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    item_id         INT UNSIGNED NOT NULL,   -- FK to items.id
    quantity        INT UNSIGNED DEFAULT 1,
    equipped        BOOLEAN      DEFAULT FALSE,
    acquired_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id)  ON DELETE RESTRICT,
    UNIQUE KEY  uq_user_item (user_id, item_id)
) ENGINE=InnoDB;


-- =============================================================
-- 6. FRIENDS
--    requester sends, addressee accepts/declines.
--    status: pending → accepted | blocked
-- =============================================================
CREATE TABLE IF NOT EXISTS friends (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    requester_id    INT UNSIGNED NOT NULL,
    addressee_id    INT UNSIGNED NOT NULL,
    status          ENUM('pending','accepted','blocked') DEFAULT 'pending',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE,
    -- Prevent duplicate pairs in either direction
    UNIQUE KEY  uq_friendship  (requester_id, addressee_id),
    INDEX       idx_addressee  (addressee_id),
    INDEX       idx_status     (status)
) ENGINE=InnoDB;


-- =============================================================
-- 7. GAMES (canonical match record — the source of truth)
--    One row per game instance. match_history rows FK here.
-- =============================================================
CREATE TABLE IF NOT EXISTS games (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    match_uuid      CHAR(36)     UNIQUE NOT NULL,  -- UUID sent to clients
    mode            ENUM('casual','ranked','ai')   NOT NULL DEFAULT 'casual',
    status          ENUM('waiting','active','completed','abandoned') DEFAULT 'waiting',

    -- Players
    plant_player_id INT UNSIGNED NULL,    -- NULL = AI
    zombie_player_id INT UNSIGNED NULL,   -- NULL = AI
    winner_side     ENUM('plants','zombies','draw') NULL,

    -- Game parameters
    map             VARCHAR(50)  DEFAULT 'sunflower_fields',
    max_waves       TINYINT      DEFAULT 5,
    current_wave    TINYINT      DEFAULT 1,
    current_turn    ENUM('plants','zombies') DEFAULT 'plants',
    turn_number     INT UNSIGNED DEFAULT 1,

    -- Economy snapshot at game start (for replay integrity)
    initial_sun     SMALLINT     DEFAULT 150,
    initial_brain   SMALLINT     DEFAULT 5,

    -- Timing
    started_at      DATETIME     NULL,
    ended_at        DATETIME     NULL,
    duration_secs   INT UNSIGNED NULL,   -- computed on end

    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (plant_player_id)  REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (zombie_player_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_uuid      (match_uuid),
    INDEX idx_status    (status),
    INDEX idx_plant     (plant_player_id),
    INDEX idx_zombie    (zombie_player_id)
) ENGINE=InnoDB;


-- =============================================================
-- 8. GAME STATE (live board — polled or pushed via MQTT/WebSocket)
--    One row per occupied cell per active game.
--    Rows are deleted when a unit dies or game ends.
-- =============================================================
CREATE TABLE IF NOT EXISTS game_units (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    game_id         INT UNSIGNED NOT NULL,
    unit_key        VARCHAR(50)  NOT NULL,   -- e.g. 'peashooter', 'basic_zombie'
    side            ENUM('plants','zombies') NOT NULL,
    row_pos         TINYINT UNSIGNED NOT NULL,  -- 0-4
    col_pos         TINYINT UNSIGNED NOT NULL,  -- 0-8
    hp              SMALLINT     NOT NULL,
    max_hp          SMALLINT     NOT NULL,
    is_alive        BOOLEAN      DEFAULT TRUE,
    placed_at_turn  INT UNSIGNED DEFAULT 1,

    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    -- Only one unit per cell per game
    UNIQUE KEY  uq_cell (game_id, row_pos, col_pos),
    INDEX       idx_game    (game_id),
    INDEX       idx_alive   (game_id, is_alive)
) ENGINE=InnoDB;


-- =============================================================
-- 9. GAME ACTIONS LOG (turn-by-turn event log for replay)
--    Append-only. Never updated.
-- =============================================================
CREATE TABLE IF NOT EXISTS game_actions (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    game_id         INT UNSIGNED NOT NULL,
    turn_number     INT UNSIGNED NOT NULL,
    action_type     ENUM(
                      'place_unit',
                      'remove_unit',
                      'attack',
                      'zombie_move',
                      'wave_start',
                      'base_damage',
                      'end_turn',
                      'surrender'
                    ) NOT NULL,
    actor_side      ENUM('plants','zombies') NOT NULL,
    payload         JSON         NULL,      -- { row, col, unit_key, damage, … }
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    INDEX idx_game_turn (game_id, turn_number)
) ENGINE=InnoDB;


-- =============================================================
-- 10. MATCH HISTORY (per-user summary — replaces your original)
--     Written once per player when a game ends.
-- =============================================================
CREATE TABLE IF NOT EXISTS match_history (
    id              INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    game_id         INT UNSIGNED NOT NULL,      -- FK to games
    user_id         INT UNSIGNED NOT NULL,
    opponent_id     INT UNSIGNED NULL,          -- NULL = AI match
    side            ENUM('plants','zombies')    NOT NULL,
    result          ENUM('win','loss','draw')   NOT NULL,
    elo_change      SMALLINT     NOT NULL,      -- signed: +18 or -12
    elo_after       INT UNSIGNED NOT NULL,
    duration_secs   INT UNSIGNED NULL,
    played_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (game_id)     REFERENCES games(id)        ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)        ON DELETE CASCADE,
    FOREIGN KEY (opponent_id) REFERENCES users(id)        ON DELETE SET NULL,
    INDEX idx_user_history (user_id, played_at DESC),
    INDEX idx_game_id      (game_id)
) ENGINE=InnoDB;


-- =============================================================
-- 11. LEADERBOARD SNAPSHOT
--     Populated by a scheduled job (cron/event) every N minutes.
--     The lobby widget reads from here — no full-table scan on users.
-- =============================================================
CREATE TABLE IF NOT EXISTS leaderboard_snapshot (
    rank_position   INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL,
    elo_rating      INT UNSIGNED NOT NULL,
    wins            INT UNSIGNED NOT NULL,
    losses          INT UNSIGNED NOT NULL,
    win_rate        DECIMAL(5,2) NOT NULL,   -- pre-computed: wins/(wins+losses)*100
    snapshot_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_rank (rank_position)
) ENGINE=InnoDB;


-- =============================================================
-- 12. REFRESH LEADERBOARD (MySQL Event — runs every 5 minutes)
--     Requires event_scheduler=ON in my.cnf
-- =============================================================
DELIMITER $$
CREATE EVENT IF NOT EXISTS evt_refresh_leaderboard
ON SCHEDULE EVERY 5 MINUTE
DO BEGIN
    TRUNCATE TABLE leaderboard_snapshot;
    INSERT INTO leaderboard_snapshot
        (rank_position, user_id, username, elo_rating, wins, losses, win_rate)
    SELECT
        ROW_NUMBER() OVER (ORDER BY elo_rating DESC) AS rank_position,
        id,
        username,
        elo_rating,
        wins,
        losses,
        CASE WHEN (wins + losses) > 0
             THEN ROUND(wins / (wins + losses) * 100, 2)
             ELSE 0
        END AS win_rate
    FROM users
    WHERE is_active = TRUE AND is_banned = FALSE
    LIMIT 100;
END$$
DELIMITER ;


-- =============================================================
-- 13. SEED DATA — Items catalog
-- =============================================================
INSERT INTO items (item_key, item_type, name, emoji, coin_price, gem_price, description) VALUES
  ('boost_xp_2x',       'boost',      'XP Boost 2×',     '🚀', 500,   0,   '1 hour XP multiplier'),
  ('skin_zombie_classic','skin',       'Zombie Skin',      '🧟', 1200,  0,   'Classic zombie avatar frame'),
  ('consumable_sun_500', 'consumable', 'Sun Pack',         '☀️', 300,   0,   '+500 in-game suns at match start'),
  ('battle_pass_s4',     'battle_pass','Battle Pass S4',   '🌿', 2500,  0,   'Season 4 battle pass with 50 tiers'),
  ('skin_plant_gold',    'cosmetic',   'Gold Plant Frame',  '🌻', 0,    150, 'Premium gold border for plant units'),
  ('skin_zombie_cone',   'skin',       'Cone Head Pack',   '🎃', 800,   0,   'Cone zombie skin variants'),
  ('boost_brain_2x',     'boost',      'Brain Boost 2×',   '💀', 500,   0,   '1 hour Brain regen multiplier'),
  ('cosmetic_board_night','cosmetic',  'Night Board Skin', '🌙', 0,    200, 'Dark graveyard board theme');