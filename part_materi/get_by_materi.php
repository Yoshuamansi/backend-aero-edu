<?php

require_once __DIR__ . "/../config/database.php";

/*
|--------------------------------------------------------------------------
| Validasi id_materi
|--------------------------------------------------------------------------
*/

if (!isset($_GET['id_materi']) || !is_numeric($_GET['id_materi'])) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "id_materi wajib diisi dan harus berupa angka",
        "data" => []
    ], JSON_PRETTY_PRINT);

    exit;
}

$id_materi = (int) $_GET['id_materi'];

/*
|--------------------------------------------------------------------------
| Query Part Materi
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id_part,
        id_materi,
        nama_part,
        identifier_unity,
        penjelasan,
        urutan
    FROM part_materi
    WHERE id_materi = ?
    ORDER BY urutan ASC, id_part ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mempersiapkan query",
        "data" => []
    ], JSON_PRETTY_PRINT);

    exit;
}

$stmt->bind_param("i", $id_materi);
$stmt->execute();

$result = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Ambil Data
|--------------------------------------------------------------------------
*/

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = [
        "id_part" => (int) $row["id_part"],
        "id_materi" => (int) $row["id_materi"],
        "nama_part" => $row["nama_part"],
        "identifier_unity" => $row["identifier_unity"],
        "penjelasan" => $row["penjelasan"],
        "urutan" => $row["urutan"] !== null
            ? (int) $row["urutan"]
            : null
    ];
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "message" => count($data) > 0
        ? "Data part materi berhasil diambil"
        : "Belum ada part materi untuk materi ini",
    "data" => $data
], JSON_PRETTY_PRINT);

$stmt->close();
$conn->close();