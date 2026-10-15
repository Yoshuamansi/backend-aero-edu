<?php

require_once __DIR__ . "/../config/database.php";

header("Content-Type: application/json; charset=UTF-8");


/*
|--------------------------------------------------------------------------
| 1. Pastikan method GET
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {

    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Method tidak diperbolehkan. Gunakan GET.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| 2. Validasi id_kategori
|--------------------------------------------------------------------------
*/

if (!isset($_GET["id_kategori"])) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Parameter id_kategori wajib diisi.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


$id_kategori = (int) $_GET["id_kategori"];


if ($id_kategori <= 0) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "id_kategori tidak valid.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| 3. Pastikan kategori tersedia
|--------------------------------------------------------------------------
*/

$sql_kategori = "
    SELECT
        id_kategori,
        nama
    FROM kategori
    WHERE id_kategori = ?
    LIMIT 1
";

$stmt_kategori = $conn->prepare($sql_kategori);


if (!$stmt_kategori) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mempersiapkan query kategori.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


$stmt_kategori->bind_param(
    "i",
    $id_kategori
);


if (!$stmt_kategori->execute()) {

    $stmt_kategori->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mengambil data kategori.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


$result_kategori = $stmt_kategori->get_result();

$kategori = $result_kategori->fetch_assoc();

$stmt_kategori->close();


if (!$kategori) {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "Kategori tidak ditemukan.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| 4. Ambil soal quiz
|--------------------------------------------------------------------------
|
| Sumber pertanyaan:
| soal_quiz
|
*/

$sql_soal = "
    SELECT
        id_soal,
        id_kategori,
        id_materi,
        pertanyaan,
        urutan
    FROM soal_quiz
    WHERE id_kategori = ?
    ORDER BY urutan ASC, id_soal ASC
";

$stmt_soal = $conn->prepare($sql_soal);


if (!$stmt_soal) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mempersiapkan query soal quiz.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


$stmt_soal->bind_param(
    "i",
    $id_kategori
);


if (!$stmt_soal->execute()) {

    $stmt_soal->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mengambil data soal quiz.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


$result_soal = $stmt_soal->get_result();


/*
|--------------------------------------------------------------------------
| 5. Siapkan query pilihan jawaban
|--------------------------------------------------------------------------
|
| PERHATIKAN:
|
| is_benar TIDAK diambil untuk dikirim ke Unity.
|
*/

$sql_pilihan = "
    SELECT
        id_pilihan,
        id_soal,
        teks_jawaban,
        urutan
    FROM pilihan_quiz
    WHERE id_soal = ?
    ORDER BY urutan ASC, id_pilihan ASC
";

$stmt_pilihan = $conn->prepare($sql_pilihan);


if (!$stmt_pilihan) {

    $stmt_soal->close();

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Gagal mempersiapkan query pilihan jawaban.",
        "data" => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| 6. Bentuk response
|--------------------------------------------------------------------------
*/

$data_quiz = [];


while ($soal = $result_soal->fetch_assoc()) {

    $id_soal = (int) $soal["id_soal"];


    /*
    |--------------------------------------------------------------------------
    | Ambil pilihan untuk soal tersebut
    |--------------------------------------------------------------------------
    */

    $stmt_pilihan->bind_param(
        "i",
        $id_soal
    );


    if (!$stmt_pilihan->execute()) {

        $stmt_soal->close();
        $stmt_pilihan->close();

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Gagal mengambil pilihan jawaban.",
            "data" => []
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        exit;
    }


    $result_pilihan = $stmt_pilihan->get_result();


    $pilihan = [];


    while ($row = $result_pilihan->fetch_assoc()) {

        $pilihan[] = [
            "id_pilihan" => (int) $row["id_pilihan"],
            "id_soal" => (int) $row["id_soal"],
            "teks_jawaban" => $row["teks_jawaban"],
            "urutan" => (int) $row["urutan"]
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Masukkan soal + pilihan
    |--------------------------------------------------------------------------
    */

    $data_quiz[] = [
        "id_soal" => $id_soal,
        "id_kategori" => (int) $soal["id_kategori"],
        "id_materi" => $soal["id_materi"] !== null
            ? (int) $soal["id_materi"]
            : null,
        "pertanyaan" => $soal["pertanyaan"],
        "urutan" => (int) $soal["urutan"],
        "pilihan" => $pilihan
    ];
}


/*
|--------------------------------------------------------------------------
| 7. Tutup statement
|--------------------------------------------------------------------------
*/

$stmt_soal->close();
$stmt_pilihan->close();


/*
|--------------------------------------------------------------------------
| 8. Response berhasil
|--------------------------------------------------------------------------
*/

echo json_encode([
    "success" => true,
    "message" => "Data quiz berhasil diambil.",
    "data" => [
        "id_kategori" => (int) $kategori["id_kategori"],
        "nama_kategori" => $kategori["nama"],
        "jumlah_soal" => count($data_quiz),
        "soal" => $data_quiz
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);


/*
|--------------------------------------------------------------------------
| 9. Tutup koneksi
|--------------------------------------------------------------------------
*/

$conn->close();