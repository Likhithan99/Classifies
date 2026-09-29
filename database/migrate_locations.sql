CREATE TABLE IF NOT EXISTS areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    area_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_locations_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE,
    UNIQUE KEY uq_locations_area_name (area_id, name)
) ENGINE=InnoDB;

ALTER TABLE classifieds
    ADD COLUMN location_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD INDEX idx_classifieds_location_created (location_id, created_at);

INSERT IGNORE INTO areas (name) VALUES ('Existing listings');

INSERT INTO locations (area_id, name)
SELECT a.id, COALESCE(NULLIF(LEFT(TRIM(c.location), 120), ''), 'Nearby')
FROM classifieds c
JOIN areas a ON a.name = 'Existing listings'
GROUP BY a.id, COALESCE(NULLIF(LEFT(TRIM(c.location), 120), ''), 'Nearby')
ON DUPLICATE KEY UPDATE name = VALUES(name);

UPDATE classifieds c
JOIN areas a ON a.name = 'Existing listings'
JOIN locations l ON l.area_id = a.id AND l.name = COALESCE(NULLIF(LEFT(TRIM(c.location), 120), ''), 'Nearby')
SET c.location_id = l.id;

ALTER TABLE classifieds
    MODIFY location_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_classifieds_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT;