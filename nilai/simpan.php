<?php

require_once __DIR__ . "/../config/database.php";

header("Content-Type: application/json; charset=UTF-8");

/*
|--------------------------------------------------------------------------
| Fungsi response JSON
|--------------------------------------------------------------------------
*/
function response_json($success, $message, $data = [], $status_code = 200)
{
    http_response_code($status_code);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| 1. Pastikan method adalah POST
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    response_json(
        false,
        "Method tidak diperbolehkan. Gunakan POST.",
        [],
        405
    );
}


/*
|--------------------------------------------------------------------------
| 2. Ambil JSON dari request
|--------------------------------------------------------------------------
*/
$input = file_get_contents("php://input");

if ($input === false || trim($input) === "") {
    response_json(
        false,
        "Data JSON tidak ditemukan.",
        [],
        400
    );
}

$data = json_decode($input, true);


/*
|--------------------------------------------------------------------------
| 3. Validasi JSON
|--------------------------------------------------------------------------
*/
if (!is_array($data)) {
    response_json(
        false,
        "Format JSON tidak valid.",
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| 4. Validasi field utama
|--------------------------------------------------------------------------
|
| Unity TIDAK perlu mengirim:
| - jumlah_benar
| - jumlah_salah
| - nilai
|
| Karena semuanya dihitung oleh server.
|
*/
$required_fields = [
    "nama_siswa",
    "no_absen",
    "kelas",
    "id_kategori",
    "detail"
];

foreach ($required_fields as $field) {

    if (!isset($data[$field])) {

        response_json(
            false,
            "Field '$field' wajib diisi.",
            [],
            400
        );
    }
}


/*
|--------------------------------------------------------------------------
| 5. Ambil data utama
|--------------------------------------------------------------------------
*/
$nama_siswa  = trim($data["nama_siswa"]);
$no_absen    = (int) $data["no_absen"];
$kelas       = trim($data["kelas"]);
$id_kategori = (int) $data["id_kategori"];
$detail      = $data["detail"];


/*
|--------------------------------------------------------------------------
| 6. Validasi data utama
|--------------------------------------------------------------------------
*/
if (
    $nama_siswa === "" ||
    $no_absen <= 0 ||
    $kelas === "" ||
    $id_kategori <= 0 ||
    !is_array($detail) ||
    count($detail) === 0
) {

    response_json(
        false,
        "Data quiz tidak valid.",
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| 7. Ambil kategori
|--------------------------------------------------------------------------
|
| Kita memastikan kategori benar-benar ada.
|
*/
$sql_kategori = "
    SELECT id_kategori, nama
    FROM kategori
    WHERE id_kategori = ?
    LIMIT 1
";

$stmt_kategori = $conn->prepare($sql_kategori);

if (!$stmt_kategori) {

    response_json(
        false,
        "Gagal mempersiapkan query kategori.",
        [],
        500
    );
}

$stmt_kategori->bind_param("i", $id_kategori);

if (!$stmt_kategori->execute()) {

    $stmt_kategori->close();

    response_json(
        false,
        "Gagal memeriksa kategori.",
        [],
        500
    );
}

$result_kategori = $stmt_kategori->get_result();
$kategori = $result_kategori->fetch_assoc();

$stmt_kategori->close();


if (!$kategori) {

    response_json(
        false,
        "Kategori quiz tidak ditemukan.",
        [],
        404
    );
}


/*
|--------------------------------------------------------------------------
| 8. Mulai transaction
|--------------------------------------------------------------------------
|
| Semua data:
| - hasil_quiz
| - detail_hasil_quiz
|
| harus berhasil disimpan.
|
| Jika salah satu gagal → semuanya dibatalkan.
|
*/
$conn->begin_transaction();


try {

    /*
    |--------------------------------------------------------------------------
    | 9. Validasi jumlah soal
    |--------------------------------------------------------------------------
    */

    $jumlah_soal = count($detail);

    if ($jumlah_soal <= 0) {
        throw new Exception("Tidak ada soal yang dikirim.");
    }


    /*
    |--------------------------------------------------------------------------
    | 10. Siapkan query untuk mengambil soal + jawaban benar
    |--------------------------------------------------------------------------
    |
    | Server mengambil:
    |
    | soal_quiz
    |       ↓
    | pilihan_quiz
    |
    | berdasarkan id_soal dan id_pilihan yang dikirim siswa.
    |
    */

    $sql_soal = "
        SELECT
            s.id_soal,
            s.id_kategori,
            s.pertanyaan,
            s.urutan,
            p.id_pilihan,
            p.teks_jawaban,
            p.is_benar
        FROM soal_quiz s
        LEFT JOIN pilihan_quiz p
            ON p.id_soal = s.id_soal
           AND p.id_pilihan = ?
        WHERE s.id_soal = ?
          AND s.id_kategori = ?
        LIMIT 1
    ";

    $stmt_soal = $conn->prepare($sql_soal);

    if (!$stmt_soal) {
        throw new Exception(
            "Gagal mempersiapkan query soal."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 11. Siapkan query untuk mengambil jawaban benar
    |--------------------------------------------------------------------------
    |
    | Kita tidak percaya jawaban_benar dari Unity.
    |
    | Server sendiri mencari pilihan yang memiliki:
    |
    | is_benar = 1
    |
    */

    $sql_jawaban_benar = "
        SELECT
            id_pilihan,
            teks_jawaban
        FROM pilihan_quiz
        WHERE id_soal = ?
          AND is_benar = 1
        LIMIT 1
    ";

    $stmt_jawaban_benar = $conn->prepare($sql_jawaban_benar);

    if (!$stmt_jawaban_benar) {
        throw new Exception(
            "Gagal mempersiapkan query jawaban benar."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 12. Siapkan query INSERT detail
    |--------------------------------------------------------------------------
    */

    $sql_detail = "
        INSERT INTO detail_hasil_quiz (
            id_hasil,
            id_soal,
            id_pilihan,
            urutan_soal,
            pertanyaan,
            jawaban_siswa,
            jawaban_benar,
            is_benar
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt_detail = $conn->prepare($sql_detail);

    if (!$stmt_detail) {
        throw new Exception(
            "Gagal mempersiapkan query detail hasil quiz."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 13. Variabel perhitungan
    |--------------------------------------------------------------------------
    */

    $jumlah_benar = 0;
    $jumlah_salah = 0;

    $urutan_default = 1;

    /*
    |--------------------------------------------------------------------------
    | 14. Data detail sementara
    |--------------------------------------------------------------------------
    |
    | Kita simpan dulu hasil pemeriksaan setiap soal.
    |
    | Setelah seluruh soal valid, baru kita INSERT hasil_quiz.
    |
    */

    $detail_hasil = [];


    /*
    |--------------------------------------------------------------------------
    | 15. Periksa setiap jawaban siswa
    |--------------------------------------------------------------------------
    */

    foreach ($detail as $index => $item) {

        /*
        |--------------------------------------------------------------------------
        | Field yang WAJIB dikirim Unity
        |--------------------------------------------------------------------------
        |
        | id_soal
        | id_pilihan
        |
        */

        if (
            !isset($item["id_soal"]) ||
            !isset($item["id_pilihan"])
        ) {

            throw new Exception(
                "Data soal nomor " .
                ($index + 1) .
                " tidak lengkap."
            );
        }


        $id_soal = (int) $item["id_soal"];
        $id_pilihan = (int) $item["id_pilihan"];


        if ($id_soal <= 0) {

            throw new Exception(
                "ID soal pada nomor " .
                ($index + 1) .
                " tidak valid."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Urutan soal
        |--------------------------------------------------------------------------
        |
        | Unity boleh mengirim urutan_soal.
        | Jika tidak dikirim, server menggunakan urutan array.
        |
        */

        $urutan_soal = isset($item["urutan_soal"])
            ? (int) $item["urutan_soal"]
            : $urutan_default;


        /*
        |--------------------------------------------------------------------------
        | 16. Ambil soal dari database
        |--------------------------------------------------------------------------
        */

        $stmt_soal->bind_param(
            "iii",
            $id_pilihan,
            $id_soal,
            $id_kategori
        );

        if (!$stmt_soal->execute()) {

            throw new Exception(
                "Gagal mengambil data soal."
            );
        }

        $result_soal = $stmt_soal->get_result();
        $soal = $result_soal->fetch_assoc();


        /*
        |--------------------------------------------------------------------------
        | Soal tidak ditemukan
        |--------------------------------------------------------------------------
        */

        if (!$soal) {

            throw new Exception(
                "Soal dengan ID $id_soal tidak ditemukan atau bukan bagian dari kategori quiz."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 17. Pastikan pilihan memang milik soal tersebut
        |--------------------------------------------------------------------------
        */

        if (
            $soal["id_pilihan"] === null ||
            (int) $soal["id_pilihan"] !== $id_pilihan
        ) {

            throw new Exception(
                "Pilihan jawaban ID $id_pilihan tidak valid untuk soal ID $id_soal."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 18. Ambil jawaban benar dari DATABASE
        |--------------------------------------------------------------------------
        */

        $stmt_jawaban_benar->bind_param(
            "i",
            $id_soal
        );

        if (!$stmt_jawaban_benar->execute()) {

            throw new Exception(
                "Gagal mengambil jawaban benar."
            );
        }

        $result_benar = $stmt_jawaban_benar->get_result();
        $jawaban_benar = $result_benar->fetch_assoc();


        /*
        |--------------------------------------------------------------------------
        | 19. Pastikan soal memiliki jawaban benar
        |--------------------------------------------------------------------------
        */

        if (!$jawaban_benar) {

            throw new Exception(
                "Soal ID $id_soal belum memiliki jawaban benar."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 20. SERVER menentukan benar atau salah
        |--------------------------------------------------------------------------
        */

        $is_benar = ((int) $soal["is_benar"] === 1);


        if ($is_benar) {

            $jumlah_benar++;

        } else {

            $jumlah_salah++;
        }


        /*
        |--------------------------------------------------------------------------
        | 21. Ambil jawaban siswa
        |--------------------------------------------------------------------------
        */

        $jawaban_siswa = $soal["teks_jawaban"];


        /*
        |--------------------------------------------------------------------------
        | 22. Simpan data detail sementara
        |--------------------------------------------------------------------------
        */

        $detail_hasil[] = [
            "id_soal" => $id_soal,
            "id_pilihan" => $id_pilihan,
            "urutan_soal" => $urutan_soal,
            "pertanyaan" => $soal["pertanyaan"],
            "jawaban_siswa" => $jawaban_siswa,
            "jawaban_benar" => $jawaban_benar["teks_jawaban"],
            "is_benar" => $is_benar ? 1 : 0
        ];


        $urutan_default++;
    }


    /*
    |--------------------------------------------------------------------------
    | 23. Hitung jumlah salah
    |--------------------------------------------------------------------------
    */

    $jumlah_salah = $jumlah_soal - $jumlah_benar;


    /*
    |--------------------------------------------------------------------------
    | 24. Hitung nilai
    |--------------------------------------------------------------------------
    |
    | Nilai = jumlah benar / jumlah soal × 100
    |
    */

    $nilai = ($jumlah_benar / $jumlah_soal) * 100;


    /*
    |--------------------------------------------------------------------------
    | 25. Pembulatan nilai
    |--------------------------------------------------------------------------
    */

    $nilai = round($nilai, 2);


    /*
    |--------------------------------------------------------------------------
    | 26. Simpan hasil utama ke hasil_quiz
    |--------------------------------------------------------------------------
    */

    $sql_hasil = "
        INSERT INTO hasil_quiz (
            id_kategori,
            nama_siswa,
            no_absen,
            kelas,
            jumlah_soal,
            jumlah_benar,
            jumlah_salah,
            nilai,
            tanggal
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmt_hasil = $conn->prepare($sql_hasil);

    if (!$stmt_hasil) {

        throw new Exception(
            "Gagal mempersiapkan query hasil quiz."
        );
    }


    $stmt_hasil->bind_param(
        "isisiidd",
        $id_kategori,
        $nama_siswa,
        $no_absen,
        $kelas,
        $jumlah_soal,
        $jumlah_benar,
        $jumlah_salah,
        $nilai
    );


    if (!$stmt_hasil->execute()) {

        throw new Exception(
            "Gagal menyimpan hasil quiz."
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 27. Ambil ID hasil quiz
    |--------------------------------------------------------------------------
    */

    $id_hasil = $conn->insert_id;

    $stmt_hasil->close();


    /*
    |--------------------------------------------------------------------------
    | 28. Masukkan detail hasil
    |--------------------------------------------------------------------------
    */

    foreach ($detail_hasil as $hasil) {

        $id_soal = $hasil["id_soal"];
        $id_pilihan = $hasil["id_pilihan"];
        $urutan_soal = $hasil["urutan_soal"];
        $pertanyaan = $hasil["pertanyaan"];
        $jawaban_siswa = $hasil["jawaban_siswa"];
        $jawaban_benar = $hasil["jawaban_benar"];
        $is_benar = $hasil["is_benar"];


        $stmt_detail->bind_param(
            "iiiisssi",
            $id_hasil,
            $id_soal,
            $id_pilihan,
            $urutan_soal,
            $pertanyaan,
            $jawaban_siswa,
            $jawaban_benar,
            $is_benar
        );


        if (!$stmt_detail->execute()) {

            throw new Exception(
                "Gagal menyimpan detail hasil quiz."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 29. Tutup statement
    |--------------------------------------------------------------------------
    */

    $stmt_soal->close();
    $stmt_jawaban_benar->close();
    $stmt_detail->close();


    /*
    |--------------------------------------------------------------------------
    | 30. Commit transaction
    |--------------------------------------------------------------------------
    */

    $conn->commit();


    /*
    |--------------------------------------------------------------------------
    | 31. Response berhasil
    |--------------------------------------------------------------------------
    */

    response_json(
        true,
        "Hasil quiz berhasil disimpan.",
        [
            "id_hasil" => (int) $id_hasil,
            "id_kategori" => $id_kategori,
            "nama_siswa" => $nama_siswa,
            "no_absen" => $no_absen,
            "kelas" => $kelas,
            "kategori" => $kategori["nama"],
            "jumlah_soal" => $jumlah_soal,
            "jumlah_benar" => $jumlah_benar,
            "jumlah_salah" => $jumlah_salah,
            "nilai" => $nilai
        ],
        200
    );


} catch (Exception $e) {

    /*
    |--------------------------------------------------------------------------
    | 32. Rollback jika terjadi error
    |--------------------------------------------------------------------------
    */

    $conn->rollback();


    /*
    |--------------------------------------------------------------------------
    | 33. Tutup statement jika masih aktif
    |--------------------------------------------------------------------------
    */

    if (isset($stmt_soal) && $stmt_soal) {
        $stmt_soal->close();
    }

    if (isset($stmt_jawaban_benar) && $stmt_jawaban_benar) {
        $stmt_jawaban_benar->close();
    }

    if (isset($stmt_detail) && $stmt_detail) {
        $stmt_detail->close();
    }


    /*
    |--------------------------------------------------------------------------
    | 34. Response error
    |--------------------------------------------------------------------------
    */

    response_json(
        false,
        $e->getMessage(),
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| 35. Tutup koneksi
|--------------------------------------------------------------------------
*/

$conn->close();