USE nursing_exam;

CREATE TABLE IF NOT EXISTS app_records (
  id VARCHAR(80) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  name VARCHAR(160) NOT NULL,
  data LONGTEXT NOT NULL,
  saved_at DATETIME NOT NULL,
  updated_at DATETIME NULL DEFAULT NULL,
  INDEX idx_app_records_user_type (user_id, type),
  CONSTRAINT fk_app_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE ON nursing_exam.* TO 'nursing_exam_user'@'localhost';
FLUSH PRIVILEGES;
