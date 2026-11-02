-- P0-4: Add last_seen_at column to sessions table for idle timeout tracking
ALTER TABLE sessions ADD COLUMN last_seen_at DATETIME NULL AFTER expires_at;
