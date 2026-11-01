-- Виконується один раз, при першому створенні volume db_data (механізм образу mysql).
-- Імена зашиті: .sql не читає змінні оточення. Якщо змінюєш DB_USER / DB_NAME в .env — онови тут.
CREATE DATABASE IF NOT EXISTS `orakuls_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `orakuls_test`.* TO 'orakuls'@'%';
FLUSH PRIVILEGES;
