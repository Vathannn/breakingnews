<?php
function copyFileToFolders($sourceFile, $folderList) {
    // Cek apakah file sumber ada
    if (!file_exists($sourceFile)) {
        echo "Error: File sumber '$sourceFile' tidak ditemukan!\n";
        return false;
    }

    $successCount = 0;
    $errorCount = 0;

    foreach ($folderList as $folder) {
        // Buat path folder html di dalam setiap direktori
        $targetDir = $folder . '/public_html/';
        
        // Cek apakah folder html sudah ada
        if (!is_dir($targetDir)) {
            echo "✗ Folder '$targetDir' tidak ditemukan, dilewati: $folder\n";
            $errorCount++;
            continue;
        }

        // Tentukan path file target
        $targetFile = $targetDir . basename($sourceFile);
        
        // Salin file
        if (copy($sourceFile, $targetFile)) {
            // Tampilkan path absolut file yang berhasil disalin
            $absolutePath = realpath($targetFile);
            echo "✓ Berhasil menyalin file ke: $absolutePath\n";
            $successCount++;
        } else {
            echo "✗ Gagal menyalin file ke: $targetFile\n";
            $errorCount++;
        }
    }

    echo "\n=== HASIL EKSEKUSI ===\n";
    echo "Berhasil: $successCount folder\n";
    echo "Gagal: $errorCount folder\n";
    
    return $errorCount === 0;
}

// Daftar folder
$folders = [
    "academic.telkomuniversity.ac.id",
    "bba.telkomuniversity.ac.id",
    "bbe.telkomuniversity.ac.id",
    "bcomms.telkomuniversity.ac.id",
    "bdpr.telkomuniversity.ac.id",
    "bds.telkomuniversity.ac.id",
    "bid.telkomuniversity.ac.id",
    "bie.telkomuniversity.ac.id",
    "bif.telkomuniversity.ac.id",
    "bit.telkomuniversity.ac.id",
    "ble.telkomuniversity.ac.id",
    "blm.telkomuniversity.ac.id",
    "bpe.telkomuniversity.ac.id",
    "bse.telkomuniversity.ac.id",
    "bte.telkomuniversity.ac.id",
    "dac.telkomuniversity.ac.id",
    "dce.telkomuniversity.ac.id",
    "dho.telkomuniversity.ac.id",
    "dictum.telkomuniversity.ac.id",
    "dif.telkomuniversity.ac.id",
    "dim.telkomuniversity.ac.id",
    "dmm.telkomuniversity.ac.id",
    "docif.telkomuniversity.ac.id",
    "dsm.telkomuniversity.ac.id",
    "emark.telkomuniversity.ac.id",
    "faq.telkomuniversity.ac.id",
    "ieeesb.orgs.telkomuniversity.ac.id",
    "kemahasiswaan-fte.telkomuniversity.ac.id",
    "kep.telkomuniversity.ac.id",
    "legal.telkomuniversity.ac.id",
    "mee.telkomuniversity.ac.id",
    "mif.telkomuniversity.ac.id",
    "mis.telkomuniversity.ac.id",
    "mm.telkomuniversity.ac.id",
    "msf.telkomuniversity.ac.id",
    "quran.telkomuniversity.ac.id",
    "sdmfeb.telkomuniversity.ac.id",
    "see.telkomuniversity.ac.id",
    "senat.telkomuniversity.ac.id",
    "sie.telkomuniversity.ac.id",
    "sif.telkomuniversity.ac.id",
    "simseb.telkomuniversity.ac.id",
    "suv.telkomuniversity.ac.id",
    "syamsululum.telkomuniversity.ac.id"
];

// File yang akan disalin (ganti dengan path file Anda)
$sourceFile = "/home/simseb.telkomuniversity.ac.id/public_html/a.txt"; // Ganti dengan file Anda

// Eksekusi program
echo "Memulai proses penyalinan file...\n";
echo "File sumber: $sourceFile\n";
echo "Jumlah folder target: " . count($folders) . "\n\n";

copyFileToFolders($sourceFile, $folders);
?>
