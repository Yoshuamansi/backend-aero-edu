<?php

require_once __DIR__ . "/../config/database.php";

$sql = "
    SELECT
        id_kategori,
        nama
    FROM kategori
    ORDER BY id_kategori ASC
";

$result = $conn->query($sql);

if (!$result) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mengambil data kategori",
        "data" => []
    ]);

    exit;
}

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = [
        "id_kategori" => (int) $row["id_kategori"],
        "nama"        => $row["nama"]
    ];
}

echo json_encode([
    "success" => true,
    "message" => "Data kategori berhasil diambil",
    "data"    => $data
], JSON_PRETTY_PRINT);