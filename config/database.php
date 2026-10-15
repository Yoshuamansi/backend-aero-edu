<?php

header("Content-Type: application/json; charset=UTF-8");

// Mendukung koneksi ke Railway (online) dan fallback ke localhost (offline)
$host = getenv('MYSQLHOST') ?: "iriguchi.proxy.rlwy.net";
$user = getenv('MYSQLUSER') ?: "root";
$pass = getenv('MYSQLPASSWORD') ?: "PhTWFKtxZEZeOMOzgXemMLqRgPPsUfFA";
$db   = getenv('MYSQLDATABASE') ?: "Aviasi";
$port = getenv('MYSQLPORT') ?: 24285;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Koneksi database gagal: " . $conn->connect_error
    ]);

    exit;
}

$conn->set_charset("utf8mb4");
