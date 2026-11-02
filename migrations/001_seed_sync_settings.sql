-- P0-2: Pre-populate settings rows for concurrency lock avoidance
INSERT INTO settings (key_name, value_json) VALUES
('tracker', '{"appName":"PI Project Management","currency":"IDR","settingsAt":0}'),
('tracker_state', '{"rev":0,"deleted":[],"improvements":[]}')
ON DUPLICATE KEY UPDATE key_name = key_name;
