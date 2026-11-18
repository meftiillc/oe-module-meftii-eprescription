-- OpenEMR MEFTii ePrescription Module Database Setup
-- This script creates module configuration entries in OpenEMR

CREATE TABLE IF NOT EXISTS `oe_mediscript_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `portal_url` VARCHAR(255) NOT NULL DEFAULT 'https://eprescription.meftii.com',
  `client_id` VARCHAR(100) NOT NULL DEFAULT 'mediscript_client',
  `auto_sync_erx` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed default configuration if empty
INSERT INTO `oe_mediscript_config` (`portal_url`, `client_id`, `auto_sync_erx`)
SELECT 'https://eprescription.meftii.com', 'mediscript_client', 1
WHERE NOT EXISTS (SELECT 1 FROM `oe_mediscript_config` WHERE `id` = 1);
