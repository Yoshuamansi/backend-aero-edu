<?php

require_once __DIR__ . "/../config/database.php";

/*
|--------------------------------------------------------------------------
| Validasi id_kategori
|--------------------------------------------------------------------------
*/

if (!isset($_GET['id_kategori']) || !is_numeric($_GET['id_kategori'])) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "id_kategori wajib diisi dan harus berupa angka",
        "data" => []
    ], JSON_PRETTY_PRINT);

    exit;
}

$id_kategori = (int) $_GET['id_kategori'];

/*
|--------------------------------------------------------------------------
| Query materi
|--------------------------------------------------------------------------
*/



$sql = "
    SELECT
        id_materi,
        id_kategori,
        judul,
        deskripsi,
        gambar,
        urutan
    FROM materi
    WHERE id_kategori = ?
      AND (
          id_kategori <> (
              SELECT id_kategori
              FROM kategori
              WHERE LOWER(TRIM(nama)) = 'dasar dunia aviasi'
              LIMIT 1
          )
          OR status_tampil = 1
      )
    ORDER BY urutan ASC, id_materi ASC
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

$stmt->bind_param("i", $id_kategori);
$stmt->execute();

$result = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Ambil data
|--------------------------------------------------------------------------
*/

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = [
        "id_materi" => (int) $row["id_materi"],
        "id_kategori" => (int) $row["id_kategori"],
        "judul" => $row["judul"],
        "deskripsi" => $row["deskripsi"],
        "gambar" => $row["gambar"],
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
        ? "Data materi berhasil diambil"
        : "Belum ada materi untuk kategori ini",
    "data" => $data
], JSON_PRETTY_PRINT);

$stmt->close();
$conn->close();