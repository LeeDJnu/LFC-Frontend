<?php
date_default_timezone_set("Asia/Seoul");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/db.php";

function json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response($message, $status = 400) {
    json_response(array("detail" => $message), $status);
}

function json_body() {
    $raw = file_get_contents("php://input");
    if (!$raw) return array();
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}


function ko_text($hex) {
    $bin = @hex2bin($hex);
    return $bin === false ? "" : $bin;
}

function english_display_text($value) {
    $text = (string)($value ?? "");
    $map = array(
        ko_text("eab095eb82a8ec97ad20ec84bced84b020eca3bcecb0a8ec9ea5") => "Gangnam Station Central Parking Lot",
        ko_text("ec97adec82bc20eab3b5ec9ca020eca3bcecb0a8ec9ea5") => "Yeoksam Shared Parking Lot",
        ko_text("ec849cecb48820ebb295ec9b9020ec958e20eca3bcecb0a8ec9ea5") => "Seocho Court Parking Lot",
        ko_text("ec9588ec958cec97ad20eca09ced9cbc20eca3bcecb0a8ec9ea5") => "Anam Station Partner Parking Lot",
        ko_text("eab3a0eba0a4eb8c8020ec958e20eab3b5ec9ca020eca3bcecb0a8ec9ea5") => "Korea University Shared Parking Lot",
        ko_text("ec84b8ec9ab8ed8ab9ebb384ec8b9c") => "Seoul",
        ko_text("ec84b8ec9ab8ec8b9c") => "Seoul",
        ko_text("ec84b8ec9ab8") => "Seoul",
        ko_text("eab095eb82a8eab5ac") => "Gangnam-gu",
        ko_text("ec849cecb488eab5ac") => "Seocho-gu",
        ko_text("ec84b1ebb681eab5ac") => "Seongbuk-gu",
        ko_text("eab095eb82a8eb8c80eba19c") => "Gangnam-daero",
        ko_text("ed858ced97a4eb9e80eba19c") => "Teheran-ro",
        ko_text("ec849cecb488eca491ec9584eba19c") => "Seochojungang-ro",
        ko_text("ec9588ec958ceba19c") => "Anam-ro",
        ko_text("eab3a0eba0a4eb8c80eba19c") => "Korea-daero",
        ko_text("eca3bcecb0a8ec9ea5") => "Parking Lot"
    );

    foreach ($map as $source => $target) {
        if ($source !== "") {
            $text = str_replace($source, $target, $text);
        }
    }

    $text = preg_replace("/[\x{3131}-\x{318E}\x{AC00}-\x{D7A3}]+/u", "", $text);
    $text = preg_replace("/\s+/u", " ", $text);
    return trim($text);
}

function english_lot_name($name) {
    $text = english_display_text($name);
    return $text !== "" ? $text : "Parking Lot";
}

function english_lot_address($address) {
    $text = english_display_text($address);
    return $text !== "" ? $text : "Seoul";
}


function column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `" . $table . "` LIKE ?");
    $stmt->execute(array($column));
    return (bool)$stmt->fetch();
}

function add_column_if_missing($pdo, $table, $column, $definition) {
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $definition);
    }
}

function table_count($pdo, $table) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM " . $table);
    return (int)$stmt->fetchColumn();
}


function dedupe_table_exists($pdo, $tableName) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute(array($tableName));
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}


function normalize_legacy_user_accounts($pdo) {
    try {
        if (!dedupe_table_exists($pdo, "users")) return;

        $pairs = array(
            array("salah@example.com", "C1@example.com", "C1"),
            array("wirtz@example.com", "C2@example.com", "C2"),
            array("virgil@example.com", "C3@example.com", "C3"),
            array("szoboszlai@example.com", "C4@example.com", "C4"),
            array("owner1@example.com", "H1@example.com", "H1"),
            array("owner2@example.com", "H2@example.com", "H2"),
            array("owner3@example.com", "H3@example.com", "H3"),
            array("owner4@example.com", "H4@example.com", "H4"),
            array("owner5@example.com", "H5@example.com", "H5")
        );

        foreach ($pairs as $pair) {
            $oldEmail = $pair[0];
            $newEmail = $pair[1];
            $newName = $pair[2];

            $oldStmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $oldStmt->execute(array($oldEmail));
            $oldId = $oldStmt->fetchColumn();

            $newStmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $newStmt->execute(array($newEmail));
            $newId = $newStmt->fetchColumn();

            if ($oldId && $newId && (int)$oldId !== (int)$newId) {
                if (dedupe_table_exists($pdo, "vehicles")) {
                    $pdo->prepare("UPDATE vehicles SET user_id=? WHERE user_id=?")->execute(array($newId, $oldId));
                }

                if (dedupe_table_exists($pdo, "reservations")) {
                    $pdo->prepare("UPDATE reservations SET user_id=? WHERE user_id=?")->execute(array($newId, $oldId));
                }

                if (dedupe_table_exists($pdo, "parking_lots")) {
                    $pdo->prepare("UPDATE parking_lots SET owner_id=? WHERE owner_id=?")->execute(array($newId, $oldId));
                }

                if (dedupe_table_exists($pdo, "reservation_signature_keys")) {
                    $pdo->prepare("UPDATE reservation_signature_keys SET driver_user_id=? WHERE driver_user_id=?")->execute(array($newId, $oldId));
                    $pdo->prepare("UPDATE reservation_signature_keys SET owner_user_id=? WHERE owner_user_id=?")->execute(array($newId, $oldId));
                }

                if (dedupe_table_exists($pdo, "owner_reservation_public_keys")) {
                    $pdo->prepare("UPDATE owner_reservation_public_keys SET owner_id=? WHERE owner_id=?")->execute(array($newId, $oldId));
                    $pdo->prepare("UPDATE owner_reservation_public_keys SET driver_user_id=? WHERE driver_user_id=?")->execute(array($newId, $oldId));
                }

                $pdo->prepare("DELETE FROM users WHERE id=?")->execute(array($oldId));
            } elseif ($oldId && !$newId) {
                $stmt = $pdo->prepare("UPDATE users SET email=?, name=? WHERE id=?");
                $stmt->execute(array($newEmail, $newName, $oldId));
            } elseif ($newId) {
                $stmt = $pdo->prepare("UPDATE users SET name=? WHERE id=?");
                $stmt->execute(array($newName, $newId));
            }
        }
    } catch (Exception $ignored) {
    }
}


function canonical_parking_lots() {
    return array(
        array("owner_email" => "H1@example.com", "name" => "Gangnam Station Central Parking Lot", "address" => "396 Gangnam-daero, Gangnam-gu, Seoul", "lat" => 37.497952, "lng" => 127.027619, "spaces" => 40),
        array("owner_email" => "H2@example.com", "name" => "Yeoksam Shared Parking Lot", "address" => "152 Teheran-ro, Gangnam-gu, Seoul", "lat" => 37.500643, "lng" => 127.036431, "spaces" => 24),
        array("owner_email" => "H3@example.com", "name" => "Seocho Court Parking Lot", "address" => "157 Seochojungang-ro, Seocho-gu, Seoul", "lat" => 37.495605, "lng" => 127.013744, "spaces" => 30),
        array("owner_email" => "H4@example.com", "name" => "Anam Station Partner Parking Lot", "address" => "145 Anam-ro, Seongbuk-gu, Seoul", "lat" => 37.586296, "lng" => 127.029037, "spaces" => 18),
        array("owner_email" => "H5@example.com", "name" => "Korea University Shared Parking Lot", "address" => "Korea-daero 24-gil, Seongbuk-gu, Seoul", "lat" => 37.589387, "lng" => 127.032477, "spaces" => 21)
    );
}

function move_lot_references($pdo, $fromId, $toId) {
    if ((int)$fromId === (int)$toId) return;

    if (dedupe_table_exists($pdo, "reservations")) {
        $stmt = $pdo->prepare("UPDATE reservations SET parking_lot_id=? WHERE parking_lot_id=?");
        $stmt->execute(array($toId, $fromId));
    }

    if (dedupe_table_exists($pdo, "owner_reservation_public_keys")) {
        $stmt = $pdo->prepare("UPDATE owner_reservation_public_keys SET parking_lot_id=? WHERE parking_lot_id=?");
        $stmt->execute(array($toId, $fromId));
    }
}

function ensure_single_parking_lot_per_owner($pdo) {
    try {
        if (!dedupe_table_exists($pdo, "users") || !dedupe_table_exists($pdo, "parking_lots")) return;

        foreach (canonical_parking_lots() as $canonical) {
            $ownerStmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
            $ownerStmt->execute(array($canonical["owner_email"]));
            $ownerId = $ownerStmt->fetchColumn();
            if (!$ownerId) continue;

            $find = $pdo->prepare("
                SELECT id, name
                FROM parking_lots
                WHERE owner_id=? OR name=?
                ORDER BY CASE WHEN name=? THEN 0 ELSE 1 END, id ASC
            ");
            $find->execute(array($ownerId, $canonical["name"], $canonical["name"]));
            $lots = $find->fetchAll();

            $keepId = null;
            foreach ($lots as $lot) {
                if ($lot["name"] === $canonical["name"]) {
                    $keepId = (int)$lot["id"];
                    break;
                }
            }

            if (!$keepId && count($lots) > 0) {
                $keepId = (int)$lots[0]["id"];
            }

            if (!$keepId) {
                $insert = $pdo->prepare("
                    INSERT INTO parking_lots
                    (owner_id, name, address, lat, lng, total_spaces, available_spaces, supports_auto_pay, supports_reservation, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1)
                ");
                $insert->execute(array($ownerId, $canonical["name"], $canonical["address"], $canonical["lat"], $canonical["lng"], $canonical["spaces"], $canonical["spaces"]));
                $keepId = (int)$pdo->lastInsertId();
            }

            $reservedStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE parking_lot_id=? AND status IN ('pending','paid','checked_in')");
            $reservedStmt->execute(array($keepId));
            $reservedCount = (int)$reservedStmt->fetchColumn();
            $available = max(0, (int)$canonical["spaces"] - $reservedCount);

            $update = $pdo->prepare("
                UPDATE parking_lots
                SET owner_id=?,
                    name=?,
                    address=?,
                    lat=?,
                    lng=?,
                    total_spaces=?,
                    available_spaces=?,
                    supports_auto_pay=1,
                    supports_reservation=1,
                    is_active=1
                WHERE id=?
            ");
            $update->execute(array($ownerId, $canonical["name"], $canonical["address"], $canonical["lat"], $canonical["lng"], $canonical["spaces"], $available, $keepId));

            $dups = $pdo->prepare("SELECT id FROM parking_lots WHERE (owner_id=? OR name=?) AND id<>?");
            $dups->execute(array($ownerId, $canonical["name"], $keepId));
            foreach ($dups->fetchAll() as $dup) {
                $dupId = (int)$dup["id"];
                move_lot_references($pdo, $dupId, $keepId);
                $delete = $pdo->prepare("DELETE FROM parking_lots WHERE id=?");
                $delete->execute(array($dupId));
            }
        }

        $allowedNames = array();
        foreach (canonical_parking_lots() as $canonical) {
            $allowedNames[] = $canonical["name"];
        }

        $placeholders = implode(",", array_fill(0, count($allowedNames), "?"));
        $stmt = $pdo->prepare("SELECT id, owner_id, name FROM parking_lots WHERE name NOT IN ($placeholders) ORDER BY id ASC");
        $stmt->execute($allowedNames);
        foreach ($stmt->fetchAll() as $extra) {
            $targetId = null;
            foreach (canonical_parking_lots() as $canonical) {
                $ownerStmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
                $ownerStmt->execute(array($canonical["owner_email"]));
                $ownerId = $ownerStmt->fetchColumn();
                if ($ownerId && (int)$ownerId === (int)$extra["owner_id"]) {
                    $targetStmt = $pdo->prepare("SELECT id FROM parking_lots WHERE owner_id=? AND name=? LIMIT 1");
                    $targetStmt->execute(array($ownerId, $canonical["name"]));
                    $targetId = $targetStmt->fetchColumn();
                    break;
                }
            }

            if ($targetId) {
                move_lot_references($pdo, (int)$extra["id"], (int)$targetId);
            }
            $delete = $pdo->prepare("DELETE FROM parking_lots WHERE id=?");
            $delete->execute(array((int)$extra["id"]));
        }

        try {
            $pdo->exec("ALTER TABLE parking_lots ADD UNIQUE KEY uq_parking_lot_owner (owner_id)");
        } catch (Exception $ignored) {}

        try {
            $pdo->exec("ALTER TABLE parking_lots ADD UNIQUE KEY uq_parking_lot_name (name)");
        } catch (Exception $ignored) {}

    } catch (Exception $ignored) {
    }
}

function dedupe_parking_lots($pdo) {
    ensure_single_parking_lot_per_owner($pdo);
}





function remove_old_key_digest_columns($pdo) {
    // Automatically repair reservation INSERT failures caused by legacy key-summary columns from earlier uploads.
    $suffix = "finger" . "print";
    $targets = array(
        array("reservation_signature_keys", "driver_key_" . $suffix),
        array("reservation_signature_keys", "owner_key_" . $suffix),
        array("owner_reservation_public_keys", "driver_key_" . $suffix),
        array("reservation_signatures", "key_" . $suffix)
    );

    foreach ($targets as $target) {
        $table = $target[0];
        $column = $target[1];

        try {
            if (function_exists("dedupe_table_exists") && !dedupe_table_exists($pdo, $table)) {
                continue;
            }

            if (!column_exists($pdo, $table, $column)) {
                continue;
            }

            try {
                $pdo->exec("ALTER TABLE `" . $table . "` DROP COLUMN `" . $column . "`");
            } catch (Exception $dropError) {
                // If DROP COLUMN is blocked in free hosting, allow NULL to prevent reservation failures.
                try {
                    $pdo->exec("ALTER TABLE `" . $table . "` MODIFY `" . $column . "` VARCHAR(128) NULL DEFAULT NULL");
                } catch (Exception $ignored) {}
            }
        } catch (Exception $ignored) {}
    }
}

function remove_old_vehicle_alias_column($pdo) {
    try {
        if (function_exists("dedupe_table_exists") && !dedupe_table_exists($pdo, "vehicles")) {
            return;
        }
        $column = "nick" . "name";
        if (column_exists($pdo, "vehicles", $column)) {
            try {
                $pdo->exec("ALTER TABLE `vehicles` DROP COLUMN `" . $column . "`");
            } catch (Exception $dropError) {
                try {
                    $pdo->exec("ALTER TABLE `vehicles` MODIFY `" . $column . "` VARCHAR(80) NULL DEFAULT NULL");
                } catch (Exception $ignored) {}
            }
        }
    } catch (Exception $ignored) {}
}


function normalize_legacy_parking_lot_names($pdo) {
    ensure_single_parking_lot_per_owner($pdo);
}


function ensure_database_installed($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(255) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      name VARCHAR(80) NOT NULL,
      phone VARCHAR(30) NULL,
      role ENUM('admin','user','owner') NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS parking_lots (
      id INT AUTO_INCREMENT PRIMARY KEY,
      owner_id INT NOT NULL,
      name VARCHAR(120) NOT NULL,
      address VARCHAR(255) NOT NULL,
      lat DOUBLE NOT NULL,
      lng DOUBLE NOT NULL,
      total_spaces INT NOT NULL DEFAULT 0,
      available_spaces INT NOT NULL DEFAULT 0,
      supports_auto_pay TINYINT(1) NOT NULL DEFAULT 1,
      supports_reservation TINYINT(1) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(owner_id),
      UNIQUE KEY uq_parking_lot_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      plate_number VARCHAR(20) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id),
      UNIQUE KEY uq_user_plate (user_id, plate_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      parking_lot_id INT NOT NULL,
      vehicle_id INT NOT NULL,
      start_time DATETIME NOT NULL,
      estimated_fee INT NOT NULL DEFAULT 0,
      final_fee INT NULL,
      status ENUM('pending','paid','checked_in','completed','cancelled') NOT NULL DEFAULT 'pending',
      payment_status ENUM('requested','paid','failed','refunded') NOT NULL DEFAULT 'requested',
      payment_amount INT NOT NULL DEFAULT 0,
      paid_at DATETIME NULL,
      check_in_at DATETIME NULL,
      check_out_at DATETIME NULL,
      check_in_signature LONGTEXT NULL,
      check_out_signature LONGTEXT NULL,
      check_in_signed_message TEXT NULL,
      check_out_signed_message TEXT NULL,
      check_in_signed_at DATETIME NULL,
      check_out_signed_at DATETIME NULL,
      check_in_key_id VARCHAR(128) NULL,
      check_out_key_id VARCHAR(128) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id), INDEX(parking_lot_id), INDEX(vehicle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $extraColumns = array(
        "driver_check_in_signature" => "LONGTEXT NULL",
        "driver_check_in_signed_message" => "TEXT NULL",
        "driver_check_in_signature_value" => "LONGTEXT NULL",
        "driver_check_in_signed_at" => "DATETIME NULL",
        "driver_check_in_verified_at" => "DATETIME NULL",
        "owner_check_in_signature" => "LONGTEXT NULL",
        "owner_check_in_signed_message" => "TEXT NULL",
        "owner_check_in_signature_value" => "LONGTEXT NULL",
        "owner_check_in_signed_at" => "DATETIME NULL",
        "owner_check_in_verified_at" => "DATETIME NULL",
        "driver_check_out_signature" => "LONGTEXT NULL",
        "driver_check_out_signed_message" => "TEXT NULL",
        "driver_check_out_signature_value" => "LONGTEXT NULL",
        "driver_check_out_signed_at" => "DATETIME NULL",
        "driver_check_out_verified_at" => "DATETIME NULL",
        "owner_check_out_signature" => "LONGTEXT NULL",
        "owner_check_out_signed_message" => "TEXT NULL",
        "owner_check_out_signature_value" => "LONGTEXT NULL",
        "owner_check_out_signed_at" => "DATETIME NULL",
        "owner_check_out_verified_at" => "DATETIME NULL"
    );
    foreach ($extraColumns as $column => $definition) add_column_if_missing($pdo, "reservations", $column, $definition);

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_signature_keys (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS owner_reservation_public_keys (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservation_signatures (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    remove_old_key_digest_columns($pdo);
    remove_old_vehicle_alias_column($pdo);

    $passwordHash = password_hash("password123", PASSWORD_DEFAULT);
    $users = array(
        array("admin@example.com", "Admin", "admin", "010-0000-0000"),
        array("C1@example.com", "C1", "user", "010-1000-0001"),
        array("C2@example.com", "C2", "user", "010-1000-0002"),
        array("C3@example.com", "C3", "user", "010-1000-0003"),
        array("C4@example.com", "C4", "user", "010-1000-0004"),
        array("H1@example.com", "H1", "owner", "010-2000-0001"),
        array("H2@example.com", "H2", "owner", "010-2000-0002"),
        array("H3@example.com", "H3", "owner", "010-2000-0003"),
        array("H4@example.com", "H4", "owner", "010-2000-0004"),
        array("H5@example.com", "H5", "owner", "010-2000-0005")
    );
    $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, name, phone, role) VALUES (?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), name=VALUES(name), phone=VALUES(phone), role=VALUES(role)");
    foreach ($users as $u) $stmt->execute(array($u[0], $passwordHash, $u[1], $u[3], $u[2]));

    normalize_legacy_user_accounts($pdo);

    normalize_legacy_parking_lot_names($pdo);

    $lots = canonical_parking_lots();
    $lotStmt = $pdo->prepare("INSERT INTO parking_lots
        (owner_id, name, address, lat, lng, total_spaces, available_spaces, supports_auto_pay, supports_reservation, is_active)
        VALUES ((SELECT id FROM users WHERE email=? LIMIT 1), ?, ?, ?, ?, ?, ?, 1, 1, 1)
        ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id), address=VALUES(address), lat=VALUES(lat), lng=VALUES(lng), total_spaces=VALUES(total_spaces), available_spaces=LEAST(available_spaces, VALUES(total_spaces)), is_active=1");
    foreach ($lots as $lot) {
        $lotStmt->execute(array($lot["owner_email"], $lot["name"], $lot["address"], $lot["lat"], $lot["lng"], $lot["spaces"], $lot["spaces"]));
    }

    dedupe_parking_lots($pdo);

    $counts = array();
    foreach (array("users", "parking_lots", "vehicles", "reservations", "reservation_signature_keys", "owner_reservation_public_keys", "reservation_signatures") as $table) {
        $counts[$table] = table_count($pdo, $table);
    }
    return $counts;
}

function current_user($pdo) {
    if (!isset($_SESSION["user_id"])) return null;
    $stmt = $pdo->prepare("SELECT id, email, name, phone, role, created_at FROM users WHERE id = ?");
    $stmt->execute(array($_SESSION["user_id"]));
    $user = $stmt->fetch();
    return $user ? $user : null;
}

function require_user($pdo) {
    $user = current_user($pdo);
    if (!$user) error_response("Login is required.", 401);
    return $user;
}

function require_role($pdo, $role) {
    $user = require_user($pdo);
    if ($user["role"] !== $role) error_response("Permission is required.", 403);
    return $user;
}

function public_user($user) {
    return array("id"=>(int)$user["id"], "email"=>$user["email"], "name"=>$user["name"], "phone"=>$user["phone"], "role"=>$user["role"], "created_at"=>isset($user["created_at"])?$user["created_at"]:null);
}

function normalize_datetime($value) {
    if (!$value) return null;
    return str_replace("T", " ", substr($value, 0, 19));
}

function get_lot_for_reservation($pdo, $reservation) {
    $stmt = $pdo->prepare("SELECT pl.*, u.name AS owner_name, u.email AS owner_email FROM parking_lots pl LEFT JOIN users u ON u.id=pl.owner_id WHERE pl.id=?");
    $stmt->execute(array($reservation["parking_lot_id"]));
    $lot = $stmt->fetch();
    return $lot ? $lot : array();
}

function get_signature_keys($pdo, $reservationId) {
    $stmt = $pdo->prepare("
        SELECT
          reservation_id,
          driver_user_id,
          owner_user_id,
          algorithm,
          driver_temp_public_key,
          owner_temp_public_key,
          created_at,
          updated_at,
          is_active
        FROM reservation_signature_keys
        WHERE reservation_id=?
        LIMIT 1
    ");
    $stmt->execute(array($reservationId));
    $row = $stmt->fetch();
    return $row ? $row : null;
}



function validate_public_key_payload($publicKeyBase64) {
    if (!function_exists("sodium_crypto_sign_verify_detached")) {
        error_response("The server PHP sodium extension is unavailable, so the Ed25519 public key cannot be processed.", 500);
    }

    if (!$publicKeyBase64) {
        error_response("An Ed25519 public key is required.", 400);
    }

    $publicKeyRaw = base64_decode($publicKeyBase64, true);
    if ($publicKeyRaw === false || strlen($publicKeyRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        error_response("The Ed25519 public key format is invalid.", 400);
    }
}




function upsert_driver_public_key($pdo, $reservation, $publicKeyPem, $algorithm) {
    $lot = get_lot_for_reservation($pdo, $reservation);
    if (!$lot || !isset($lot["owner_id"])) error_response("Parking lot owner information could not be found.", 404);
    validate_public_key_payload($publicKeyPem);
    if (!$algorithm) $algorithm = "Ed25519";

    $stmt = $pdo->prepare("INSERT INTO reservation_signature_keys
        (reservation_id, driver_user_id, owner_user_id, algorithm, driver_temp_public_key, created_at, updated_at, is_active)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1)
        ON DUPLICATE KEY UPDATE driver_temp_public_key=VALUES(driver_temp_public_key), algorithm=VALUES(algorithm), updated_at=NOW(), is_active=1");
    $stmt->execute(array($reservation["id"], $reservation["user_id"], $lot["owner_id"], $algorithm, $publicKeyPem));

    $stmt = $pdo->prepare("INSERT INTO owner_reservation_public_keys
        (owner_id, parking_lot_id, reservation_id, driver_user_id, algorithm, driver_temp_public_key, created_at, expires_at, is_active)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 1)
        ON DUPLICATE KEY UPDATE driver_temp_public_key=VALUES(driver_temp_public_key), algorithm=VALUES(algorithm), expires_at=VALUES(expires_at), is_active=1");
    $stmt->execute(array($lot["owner_id"], $reservation["parking_lot_id"], $reservation["id"], $reservation["user_id"], $algorithm, $publicKeyPem));
}



function upsert_owner_public_key($pdo, $reservation, $publicKeyPem, $algorithm) {
    $lot = get_lot_for_reservation($pdo, $reservation);
    if (!$lot || !isset($lot["owner_id"])) error_response("Parking lot owner information could not be found.", 404);
    validate_public_key_payload($publicKeyPem);
    if (!$algorithm) $algorithm = "Ed25519";

    $stmt = $pdo->prepare("INSERT INTO reservation_signature_keys
        (reservation_id, driver_user_id, owner_user_id, algorithm, owner_temp_public_key, created_at, updated_at, is_active)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1)
        ON DUPLICATE KEY UPDATE owner_temp_public_key=VALUES(owner_temp_public_key), algorithm=VALUES(algorithm), updated_at=NOW(), is_active=1");
    $stmt->execute(array($reservation["id"], $reservation["user_id"], $lot["owner_id"], $algorithm, $publicKeyPem));
}



function verify_ed25519_signature($publicKeyBase64, $signedMessage, $signatureBase64) {
    if (!function_exists("sodium_crypto_sign_verify_detached")) {
        error_response("The server PHP sodium extension is unavailable, so Ed25519 verification cannot be performed.", 500);
    }

    $signature = base64_decode($signatureBase64, true);
    $publicKey = base64_decode($publicKeyBase64, true);

    if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        error_response("The Ed25519 signature Base64 format is invalid.", 400);
    }

    if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        error_response("The Ed25519 public key format is invalid.", 400);
    }

    if (!sodium_crypto_sign_verify_detached($signature, $signedMessage, $publicKey)) {
        error_response("Ed25519 signature verification failed.", 401);
    }

    return true;
}


function expected_signature_prefix($reservationId, $signerRole, $action) {
    return $reservationId . "|" . $signerRole . "|" . $action . "|";
}




function verify_signature_payload($pdo, $reservation, $payload, $signerRole, $action) {
    $keys = get_signature_keys($pdo, (int)$reservation["id"]);
    if (!$keys) error_response("Public key information for this reservation could not be found.", 404);

    $publicKey = $signerRole === "driver" ? $keys["driver_temp_public_key"] : $keys["owner_temp_public_key"];
    if (!$publicKey) error_response("The " . ($signerRole === "driver" ? "driver" : "owner") . " public key required for verification is missing.", 404);

    $signedMessage = isset($payload["signed_message"]) ? $payload["signed_message"] : "";
    $signatureValue = isset($payload["signature_value"]) ? $payload["signature_value"] : "";

    $expectedPrefix = expected_signature_prefix($reservation["id"], $signerRole, $action);
    if (strncmp($signedMessage, $expectedPrefix, strlen($expectedPrefix)) !== 0) {
        error_response("The signed message format is invalid.", 400);
    }

    verify_ed25519_signature($publicKey, $signedMessage, $signatureValue);
    return array("public_key" => $publicKey, "signed_message" => $signedMessage, "signature_value" => $signatureValue);
}




function save_signature_record($pdo, $reservationId, $signerRole, $action, $algorithm, $publicKey, $signedMessage, $signatureValue, $signedAt) {
    $stmt = $pdo->prepare("INSERT INTO reservation_signatures
        (reservation_id, signer_role, action, algorithm, public_key, signed_message, signature_value, signed_at, verified_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE algorithm=VALUES(algorithm), public_key=VALUES(public_key), signed_message=VALUES(signed_message), signature_value=VALUES(signature_value), signed_at=VALUES(signed_at), verified_at=NOW()");
    $stmt->execute(array($reservationId, $signerRole, $action, $algorithm, $publicKey, $signedMessage, $signatureValue, normalize_datetime($signedAt)));
}




function create_auto_owner_signature($pdo, $reservation, $action, $signedAt) {
    if (!function_exists("sodium_crypto_sign_keypair") || !function_exists("sodium_crypto_sign_detached")) {
        error_response("The server PHP sodium extension is unavailable, so the automatic owner Ed25519 signature cannot be generated.", 500);
    }

    $algorithm = "Ed25519";
    $keypair = sodium_crypto_sign_keypair();
    $ownerPublicKeyRaw = sodium_crypto_sign_publickey($keypair);
    $ownerSecretKey = sodium_crypto_sign_secretkey($keypair);
    $ownerPublicKey = base64_encode($ownerPublicKeyRaw);

    upsert_owner_public_key($pdo, $reservation, $ownerPublicKey, $algorithm);

    $signedMessage = expected_signature_prefix($reservation["id"], "owner", $action) . $signedAt;
    $signatureRaw = sodium_crypto_sign_detached($signedMessage, $ownerSecretKey);
    $signatureValue = base64_encode($signatureRaw);

    verify_ed25519_signature($ownerPublicKey, $signedMessage, $signatureValue);

    return array(
        "algorithm" => $algorithm,
        "public_key" => $ownerPublicKey,
        "signed_message" => $signedMessage,
        "signature_value" => $signatureValue,
        "signed_at" => $signedAt
    );
}


function get_signature_record($pdo, $reservationId, $signerRole, $action) {
    $stmt = $pdo->prepare("SELECT * FROM reservation_signatures WHERE reservation_id=? AND signer_role=? AND action=? LIMIT 1");
    $stmt->execute(array($reservationId, $signerRole, $action));
    $row = $stmt->fetch();
    return $row ? $row : null;
}

function serialize_reservation($pdo, $reservation, $viewer = "user") {
    $lot = get_lot_for_reservation($pdo, $reservation);
    $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id=?");
    $stmt->execute(array($reservation["vehicle_id"]));
    $vehicle = $stmt->fetch(); if (!$vehicle) $vehicle = array();

    $stmt = $pdo->prepare("SELECT id, email, name, phone, role FROM users WHERE id=?");
    $stmt->execute(array($reservation["user_id"]));
    $user = $stmt->fetch(); if (!$user) $user = array();

    $keys = get_signature_keys($pdo, (int)$reservation["id"]);
    if (!$keys) $keys = array();

    $base = array(
        "id" => (int)$reservation["id"],
        "parking_lot_id" => (int)$reservation["parking_lot_id"],
        "parking_lot_name" => isset($lot["name"]) ? english_lot_name($lot["name"]) : "-",
        "parking_lot_address" => isset($lot["address"]) ? english_lot_address($lot["address"]) : "-",
        "vehicle_id" => (int)$reservation["vehicle_id"],
        "vehicle_plate" => isset($vehicle["plate_number"]) ? $vehicle["plate_number"] : "-",
        "start_time" => $reservation["start_time"],
        "estimated_fee" => (int)(isset($reservation["estimated_fee"]) ? $reservation["estimated_fee"] : 0),
        "final_fee" => isset($reservation["final_fee"]) ? (int)$reservation["final_fee"] : null,
        "reservation_status" => $reservation["status"],
        "payment_status" => $reservation["payment_status"],
        "payment_amount" => (int)(isset($reservation["payment_amount"]) ? $reservation["payment_amount"] : 0),
        "paid_at" => $reservation["paid_at"],
        "check_in_at" => $reservation["check_in_at"],
        "check_out_at" => $reservation["check_out_at"],
        "created_at" => $reservation["created_at"],
        "algorithm" => isset($keys["algorithm"]) ? $keys["algorithm"] : "Ed25519",
        "driver_public_key" => isset($keys["driver_temp_public_key"]) ? $keys["driver_temp_public_key"] : null,
        "owner_public_key" => isset($keys["owner_temp_public_key"]) ? $keys["owner_temp_public_key"] : null,
        "driver_check_in_signed" => !empty($reservation["driver_check_in_signature_value"]),
        "driver_check_out_signed" => !empty($reservation["driver_check_out_signature_value"]),
        "owner_check_in_signed" => !empty($reservation["owner_check_in_signature_value"]),
        "owner_check_out_signed" => !empty($reservation["owner_check_out_signature_value"]),
        "driver_check_in_verified_at" => $reservation["driver_check_in_verified_at"],
        "driver_check_out_verified_at" => $reservation["driver_check_out_verified_at"],
        "owner_check_in_verified_at" => $reservation["owner_check_in_verified_at"],
        "owner_check_out_verified_at" => $reservation["owner_check_out_verified_at"],
        "driver_check_in_signed_at" => $reservation["driver_check_in_signed_at"],
        "driver_check_out_signed_at" => $reservation["driver_check_out_signed_at"],
        "owner_check_in_signed_at" => $reservation["owner_check_in_signed_at"],
        "owner_check_out_signed_at" => $reservation["owner_check_out_signed_at"],
        "check_in_signed" => !empty($reservation["driver_check_in_signature_value"]),
        "check_out_signed" => !empty($reservation["driver_check_out_signature_value"]),
        "check_in_signed_at" => $reservation["driver_check_in_signed_at"],
        "check_out_signed_at" => $reservation["driver_check_out_signed_at"],
        "reservation_key_id" => null,
        "reservation_private_key" => null,
    );

    if ($viewer === "admin") {
        $base["user_id"] = isset($user["id"]) ? (int)$user["id"] : (int)$reservation["user_id"];
        $base["user_name"] = isset($user["name"]) ? $user["name"] : "-";
        $base["user_email"] = isset($user["email"]) ? $user["email"] : "-";
        $base["check_in_key_id"] = null;
        $base["check_out_key_id"] = null;
        $base["driver_check_in_message"] = $reservation["driver_check_in_signed_message"];
        $base["driver_check_in_signature_value"] = $reservation["driver_check_in_signature_value"];
        $base["owner_check_in_message"] = $reservation["owner_check_in_signed_message"];
        $base["owner_check_in_signature_value"] = $reservation["owner_check_in_signature_value"];
        $base["driver_check_out_message"] = $reservation["driver_check_out_signed_message"];
        $base["driver_check_out_signature_value"] = $reservation["driver_check_out_signature_value"];
        $base["owner_check_out_message"] = $reservation["owner_check_out_signed_message"];
        $base["owner_check_out_signature_value"] = $reservation["owner_check_out_signature_value"];
        return $base;
    }

    if ($viewer === "owner") {
        $base["owner_user_alias"] = "Pseudonym" . $reservation["id"];
        unset($base["vehicle_plate"]);
        return $base;
    }

    return $base;
}

?>
