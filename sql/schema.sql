-- Bitte Präfix n3_essen_ ggf. durch deinen Wert aus config.php ersetzen.

CREATE TABLE IF NOT EXISTS `n3_essen_settings` (
  `setting_key` VARCHAR(64) NOT NULL,
  `setting_value` TEXT NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `menu_url` VARCHAR(255) NOT NULL DEFAULT '',
  `order_method` VARCHAR(1000) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_category_id` (`category_id`),
  CONSTRAINT `fk_n3_essen_suppliers_category` FOREIGN KEY (`category_id`) REFERENCES `n3_essen_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_supplier_weekdays` (
  `supplier_id` INT UNSIGNED NOT NULL,
  `weekday_key` VARCHAR(10) NOT NULL,
  PRIMARY KEY (`supplier_id`, `weekday_key`),
  KEY `idx_weekday_key` (`weekday_key`),
  CONSTRAINT `fk_n3_essen_supplier_weekdays_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `n3_essen_suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_votes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vote_token` VARCHAR(64) NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vote_token_supplier` (`vote_token`, `supplier_id`),
  KEY `idx_vote_token` (`vote_token`),
  KEY `idx_supplier_id` (`supplier_id`),
  CONSTRAINT `fk_n3_essen_votes_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `n3_essen_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_supplier_ratings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vote_token` VARCHAR(64) NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rating_token_supplier` (`vote_token`, `supplier_id`),
  KEY `idx_rating_supplier_id` (`supplier_id`),
  KEY `idx_rating_vote_token` (`vote_token`),
  CONSTRAINT `fk_n3_essen_supplier_ratings_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `n3_essen_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_by_token` VARCHAR(64) NOT NULL DEFAULT '',
  `nickname` VARCHAR(40) NOT NULL,
  `dish_no` VARCHAR(20) NOT NULL DEFAULT '',
  `dish_name` VARCHAR(120) NOT NULL,
  `dish_size` VARCHAR(40) NOT NULL DEFAULT '',
  `price` DECIMAL(8,2) NOT NULL,
  `payment_method` ENUM('bar','paypal') NOT NULL DEFAULT 'bar',
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `note` VARCHAR(200) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_by_token` (`created_by_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(40) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'orga') NOT NULL DEFAULT 'admin',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_admin_user_weekdays` (
  `admin_user_id` INT UNSIGNED NOT NULL,
  `weekday_key` VARCHAR(10) NOT NULL,
  PRIMARY KEY (`admin_user_id`, `weekday_key`),
  KEY `idx_admin_weekday_key` (`weekday_key`),
  CONSTRAINT `fk_n3_essen_admin_user_weekdays_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `n3_essen_admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` INT UNSIGNED NOT NULL,
  `actor_username` VARCHAR(40) NOT NULL,
  `actor_role` ENUM('admin', 'orga') NOT NULL,
  `action_key` VARCHAR(80) NOT NULL,
  `target_type` VARCHAR(40) NOT NULL,
  `target_id` VARCHAR(80) NOT NULL DEFAULT '',
  `details_json` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_rate_limits` (
  `action_key` VARCHAR(160) NOT NULL,
  `window_started_at` DATETIME NOT NULL,
  `request_count` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`action_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `n3_essen_settings` (`setting_key`, `setting_value`) VALUES
('daily_reset_time', '10:30:00'),
('voting_end_time_monday', '16:00'),
('voting_end_time_tuesday', '16:00'),
('voting_end_time_wednesday', '16:00'),
('voting_end_time_thursday', '16:00'),
('voting_end_time_friday', '16:00'),
('voting_end_time_saturday', '16:00'),
('voting_end_time_sunday', '16:00'),
('order_end_time_monday', '18:00'),
('order_end_time_tuesday', '18:00'),
('order_end_time_wednesday', '18:00'),
('order_end_time_thursday', '18:00'),
('order_end_time_friday', '18:00'),
('order_end_time_saturday', '18:00'),
('order_end_time_sunday', '18:00'),
('day_disabled_monday', '0'),
('day_disabled_tuesday', '0'),
('day_disabled_wednesday', '0'),
('day_disabled_thursday', '0'),
('day_disabled_friday', '0'),
('day_disabled_saturday', '0'),
('day_disabled_sunday', '0'),
('paypal_links', '[]'),
('daily_note', ''),
('header_subtitle', ''),
('day_disabled_notice', 'Bestellungen sind heute deaktiviert.'),
('manual_winner_supplier_id', ''),
('reset_daily_note', '1'),
('last_reset_at', '1970-01-01 00:00:00')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO `n3_essen_categories` (`name`) VALUES
('Italienisch'), ('Griechisch'), ('Burger'), ('Döner'), ('Asiatisch')
ON DUPLICATE KEY UPDATE name=VALUES(name);
