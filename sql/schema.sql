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

CREATE TABLE IF NOT EXISTS `n3_essen_votes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vote_token` VARCHAR(64) NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vote_token` (`vote_token`),
  KEY `idx_supplier_id` (`supplier_id`),
  CONSTRAINT `fk_n3_essen_votes_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `n3_essen_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` VARCHAR(12) NOT NULL,
  `edit_token` VARCHAR(64) NOT NULL,
  `nickname` VARCHAR(40) NOT NULL,
  `dish_no` VARCHAR(20) NOT NULL DEFAULT '',
  `dish_name` VARCHAR(120) NOT NULL,
  `dish_size` VARCHAR(40) NOT NULL DEFAULT '',
  `price` DECIMAL(8,2) NOT NULL,
  `payment_method` ENUM('bar','paypal') NOT NULL DEFAULT 'bar',
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `note` VARCHAR(200) NOT NULL DEFAULT '',
  `confirmed` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_public_id` (`public_id`),
  UNIQUE KEY `uniq_edit_token` (`edit_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(40) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `n3_essen_rate_limits` (
  `action_key` VARCHAR(160) NOT NULL,
  `window_started_at` DATETIME NOT NULL,
  `request_count` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`action_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `n3_essen_settings` (`setting_key`, `setting_value`) VALUES
('voting_end_time', '16:00:00'),
('order_end_time', '18:00:00'),
('daily_reset_time', '10:30:00'),
('paypal_link', ''),
('paypal_links', '[]'),
('paypal_link_active_id', ''),
('daily_note', ''),
('order_closed', '0'),
('manual_winner_supplier_id', ''),
('reset_daily_note', '1'),
('last_reset_at', '1970-01-01 00:00:00')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);

INSERT INTO `n3_essen_categories` (`name`) VALUES
('Italienisch'), ('Griechisch'), ('Burger'), ('Döner'), ('Asiatisch')
ON DUPLICATE KEY UPDATE name=VALUES(name);

INSERT INTO `n3_essen_admin_users` (`username`, `password_hash`, `created_at`) VALUES
('admin', '$2y$12$GNqw/UBiF19Pd1o5Z2Toke.OTW7T.Pn0veykfJLqDpGcp7a0G.NcG', NOW())
ON DUPLICATE KEY UPDATE username=VALUES(username);
-- Standardpasswort: bitte sofort ändern. Passwort für Hash ist: admin1234
