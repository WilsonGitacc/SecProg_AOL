#!/bin/bash
set -e

echo "[INIT] Secure LMS Web Container starting..."

# 1. Coordinate MySQL availability with retry loop
echo "[INIT] Waiting for MySQL database (${DB_HOST:-db}:${DB_PORT:-3306}) to accept connections..."
php -r '
  $host = getenv("DB_HOST") ?: "db";
  $port = (int)(getenv("DB_PORT") ?: 3306);
  $user = getenv("DB_MIGRATOR_USER") ?: "lms_migrator";
  $pass = getenv("DB_MIGRATOR_PASSWORD");
  $db   = getenv("DB_NAME") ?: "secure_lms";
  
  $maxAttempts = 30;
  $attempt = 0;
  
  while ($attempt < $maxAttempts) {
      try {
          $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db}", $user, $pass, [
              PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
              PDO::ATTR_TIMEOUT => 2
          ]);
          echo "[INIT] MySQL connection established successfully.\n";
          exit(0);
      } catch (Throwable $e) {
          $attempt++;
          echo "[INIT] Database not yet ready (attempt {$attempt}/{$maxAttempts}: " . $e->getMessage() . "). Retrying in 1s...\n";
          sleep(1);
      }
  }
  fwrite(STDERR, "[ERROR] Database connection timed out after 30 attempts.\n");
  exit(1);
'

# 2. Run schema migrations automatically
if [ -f "/var/www/html/scripts/migrate.php" ]; then
    echo "[INIT] Running database migrations..."
    php /var/www/html/scripts/migrate.php
fi

# 3. Run seeder if seeder script exists (for Milestone 2+)
if [ -f "/var/www/html/scripts/import_lms_data.php" ]; then
    echo "[INIT] Checking seed data status..."
    php /var/www/html/scripts/import_lms_data.php || true
fi

echo "[INIT] Launching Apache HTTP Server..."
exec "$@"
