-- Лічильники по днях (BE-011): лише кількості, без персональних даних.
-- day: календарна дата за Europe/Riga, її рахує PHP (RigaClock), а не MySQL.
-- metric: visit | scan | message_lv | message_en | message_ru (білий список — Domain\StatMetrics).
CREATE TABLE IF NOT EXISTS `daily_stats` (
  `day` DATE NOT NULL,
  `metric` VARCHAR(16) NOT NULL,
  `count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
