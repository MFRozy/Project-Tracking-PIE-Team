-- One-time setup of the THROWAWAY test database and its least-privilege user.
-- Run once as a MySQL administrator against a LOCAL server, e.g.:
--   mysql -u root < tests/regression/setup-test-db.sql
-- Touches ONLY `project_tracker_test` and user `pt_test`. Never the real `project_tracker`.
-- The password is a synthetic, local-only value; override via PT_TEST_DB_PASS if you change it.

CREATE DATABASE IF NOT EXISTS project_tracker_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'pt_test'@'127.0.0.1' IDENTIFIED BY 'pt_test_local_only';
CREATE USER IF NOT EXISTS 'pt_test'@'localhost' IDENTIFIED BY 'pt_test_local_only';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON project_tracker_test.* TO 'pt_test'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON project_tracker_test.* TO 'pt_test'@'localhost';
FLUSH PRIVILEGES;

