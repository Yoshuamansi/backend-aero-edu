<?php

require_once __DIR__ . "/config/database.php";

echo json_encode([
    "success" => true,
    "message" => "API Aero-Edu berhasil terhubung",
    "database" => $db
], JSON_PRETTY_PRINT);