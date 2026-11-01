-- Orākuls Coffee · повна схема БД (MySQL 5.7)
-- ЗГЕНЕРОВАНО командою `php bin/build-schema.php` з database/migrations/*.sql. Вручну не редагувати.
-- Імпорт: phpMyAdmin → обрати порожню базу → вкладка «Імпорт» → цей файл.
-- Даних тут немає: сід на прод не потрапляє, фрази завантажує адмін через імпорт.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `migrations` (
  `name` VARCHAR(100) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 001_create_phrases.sql ----
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

INSERT IGNORE INTO `migrations` (`name`, `applied_at`) VALUES
  ('001_create_phrases.sql', UTC_TIMESTAMP());
