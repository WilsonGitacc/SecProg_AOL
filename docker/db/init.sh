#!/bin/bash
set -e

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE:-secure_lms}\` 
        CHARACTER SET utf8mb4 
        COLLATE utf8mb4_unicode_ci;

    -- 1. Provision lms_migrator (DDL + DML)
    CREATE USER IF NOT EXISTS '${DB_MIGRATOR_USER:-lms_migrator}'@'%' 
        IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
    ALTER USER '${DB_MIGRATOR_USER:-lms_migrator}'@'%' 
        IDENTIFIED BY '${DB_MIGRATOR_PASSWORD}';
    GRANT CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER, SELECT, INSERT, UPDATE, DELETE 
        ON \`${MYSQL_DATABASE:-secure_lms}\`.* 
        TO '${DB_MIGRATOR_USER:-lms_migrator}'@'%';

    -- 2. Provision lms_app (Least Privilege: DML ONLY)
    ESCAPED_DB=$(echo "${MYSQL_DATABASE:-secure_lms}" | sed 's/_/\\_/g')
    REVOKE ALL PRIVILEGES ON \`${ESCAPED_DB}\`.* FROM '${MYSQL_USER:-lms_app}'@'%' 2>/dev/null || true
    GRANT SELECT, INSERT, UPDATE, DELETE 
        ON \`${ESCAPED_DB}\`.* 
        TO '${MYSQL_USER:-lms_app}'@'%';

    FLUSH PRIVILEGES;
EOSQL
