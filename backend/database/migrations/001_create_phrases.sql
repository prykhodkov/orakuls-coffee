-- Таблиця фраз: один запис = одна фраза з трьома перекладами (API.md → Phrase).
-- category: перевіряється кодом (App\Domain\Categories), БД допустимість не гарантує — MySQL 5.7 ігнорує CHECK.
-- created_at/updated_at: без DEFAULT, завжди передаються як UTC_TIMESTAMP().
CREATE TABLE IF NOT EXISTS `phrases` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(16) NOT NULL,
  `text_lv` VARCHAR(500) NOT NULL DEFAULT '',
  `text_en` VARCHAR(500) NOT NULL DEFAULT '',
  `text_ru` VARCHAR(500) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_phrases_category` (`category`),
  KEY `idx_phrases_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
