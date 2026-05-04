CREATE DATABASE IF NOT EXISTS `tradesim_app`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_general_ci;

USE `tradesim_app`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `watchlist`;
DROP TABLE IF EXISTS `trades`;
DROP TABLE IF EXISTS `price_history`;
DROP TABLE IF EXISTS `holdings`;
DROP TABLE IF EXISTS `stocks`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(50) NOT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `stocks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `price` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `holdings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `stock_name` varchar(100) NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `buy_price` decimal(12,2) NOT NULL,
  `instrument_key` varchar(120) DEFAULT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_holding` (`user_id`, `stock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `price_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `stock_name` varchar(100) NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `trades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `instrument_key` varchar(120) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `side` varchar(10) NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `watchlist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `symbol` varchar(40) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_symbol_unique` (`user_id`, `symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `username`, `password`, `balance`) VALUES
  (1, 'agrim', '123', 100000.00);

INSERT INTO `stocks` (`id`, `name`, `price`) VALUES
  (1, 'TCS', 3650.00),
  (2, 'Infosys', 1480.00),
  (3, 'Reliance', 2860.00),
  (4, 'HDFC Bank', 1710.00),
  (5, 'ICICI Bank', 1185.00),
  (6, 'SBI', 845.00),
  (7, 'Wipro', 535.00),
  (8, 'ITC', 438.00),
  (9, 'Bharti Airtel', 1365.00),
  (10, 'Tata Motors', 982.00);

INSERT INTO `price_history` (`stock_name`, `price`) VALUES
  ('TCS', 3650.00),
  ('Infosys', 1480.00),
  ('Reliance', 2860.00),
  ('HDFC Bank', 1710.00),
  ('ICICI Bank', 1185.00),
  ('SBI', 845.00),
  ('Wipro', 535.00),
  ('ITC', 438.00),
  ('Bharti Airtel', 1365.00),
  ('Tata Motors', 982.00);
