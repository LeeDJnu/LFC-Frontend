CREATE TABLE IF NOT EXISTS reservation_signature_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL UNIQUE,
  driver_user_id INT NOT NULL,
  owner_user_id INT NOT NULL,
  algorithm VARCHAR(60) NOT NULL DEFAULT 'Ed25519',
  driver_temp_public_key LONGTEXT NULL,
  owner_temp_public_key LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX(driver_user_id), INDEX(owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS owner_reservation_public_keys (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_id INT NOT NULL,
  parking_lot_id INT NOT NULL,
  reservation_id INT NOT NULL UNIQUE,
  driver_user_id INT NOT NULL,
  algorithm VARCHAR(60) NOT NULL DEFAULT 'Ed25519',
  driver_temp_public_key LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  INDEX(owner_id), INDEX(parking_lot_id), INDEX(driver_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reservation_signatures (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  signer_role ENUM('driver','owner') NOT NULL,
  action ENUM('check-in','check-out') NOT NULL,
  algorithm VARCHAR(60) NOT NULL DEFAULT 'Ed25519',
  public_key LONGTEXT NOT NULL,
  signed_message TEXT NOT NULL,
  signature_value LONGTEXT NOT NULL,
  signed_at DATETIME NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_signature_once (reservation_id, signer_role, action),
  INDEX(reservation_id), INDEX(signer_role), INDEX(action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
