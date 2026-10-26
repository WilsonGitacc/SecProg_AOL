-- =============================================================================
-- Database Initialization & Dual-User Privilege Configuration
-- =============================================================================

-- Ensure secure_lms database exists with utf8mb4 encoding
CREATE DATABASE IF NOT EXISTS `secure_lms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 1. Migrator User (DDL + DML for migrations and seeders)
-- Note: 'lms_migrator' user authentication is configured via container environment init.sh
GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, SELECT, INSERT, UPDATE, DELETE ON `secure_lms`.* TO 'lms_migrator'@'%';

-- 2. Application User (Runtime web requests: STRICTLY DML ONLY)
-- Note: 'lms_app' is created by the official MySQL image entrypoint via MYSQL_USER/MYSQL_PASSWORD
GRANT SELECT, INSERT, UPDATE, DELETE ON `secure\_lms`.* TO 'lms_app'@'%';

FLUSH PRIVILEGES;
