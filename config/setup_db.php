<?php
/**
 * Database Initialization & Seeder
 * PT Kalpindo Kalibrasi - Sistem Informasi Alur Kerja & Penerbitan Sertifikat
 */

declare(strict_types=1);

function initializeDatabase(PDO $pdo): void {
    // 1. Create Scopes Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scopes (
            code VARCHAR(5) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            unit_samples VARCHAR(100),
            color VARCHAR(30) DEFAULT 'sky'
        );
    ");

    // 2. Create Orders Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_number VARCHAR(50) UNIQUE NOT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_address TEXT,
            customer_contact VARCHAR(100),
            order_date DATE NOT NULL,
            service_type VARCHAR(20) NOT NULL, -- 'IN_LAB' atau 'ON_SITE'
            status VARCHAR(30) DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // 3. Create Instruments Table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS instruments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            name VARCHAR(150) NOT NULL,
            scope_code VARCHAR(5) NOT NULL,
            brand VARCHAR(100),
            model_type VARCHAR(100),
            serial_number VARCHAR(100) NOT NULL,
            capacity_range VARCHAR(100),
            resolution VARCHAR(50),
            technician_name VARCHAR(100),
            calibration_date DATE,
            is_kan INTEGER DEFAULT 1, -- 1: KAN, 0: Non-KAN
            status VARCHAR(30) DEFAULT 'ASSIGNED', -- ASSIGNED, IN_PROGRESS, DATA_SUBMITTED, CERTIFIED
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (scope_code) REFERENCES scopes(code)
        );
    ");

    // 4. Create Worksheets Table (Data Mentah Hasil Pengerjaan Teknisi)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS worksheets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instrument_id INTEGER UNIQUE NOT NULL,
            temperature REAL DEFAULT 20.0,
            temperature_uncertainty REAL DEFAULT 1.0,
            humidity REAL DEFAULT 55.0,
            humidity_uncertainty REAL DEFAULT 5.0,
            standard_calibrator VARCHAR(200) NOT NULL,
            standard_cert_no VARCHAR(100),
            standard_valid_until DATE,
            calibration_method VARCHAR(200),
            readings_json TEXT NOT NULL,
            visual_inspection VARCHAR(100) DEFAULT 'Normal & Berfungsi Baik',
            technician_notes TEXT,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (instrument_id) REFERENCES instruments(id) ON DELETE CASCADE
        );
    ");

    // 5. Create Certificates Table (Bagian Sertifikat)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS certificates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instrument_id INTEGER UNIQUE NOT NULL,
            certificate_number VARCHAR(50) UNIQUE NOT NULL, -- Contoh: 2605P0012-00
            scope_code VARCHAR(5) NOT NULL,
            is_kan INTEGER DEFAULT 1, -- 1: KAN, 0: Non-KAN
            year_prefix VARCHAR(2) NOT NULL,
            month_prefix VARCHAR(2) NOT NULL,
            sequence_number INTEGER NOT NULL,
            revision_number VARCHAR(2) DEFAULT '00',
            issue_date DATE NOT NULL,
            valid_until DATE,
            technical_manager VARCHAR(100) DEFAULT 'Ir. Hendra Wijaya, M.T.',
            status VARCHAR(30) DEFAULT 'ISSUED', -- ISSUED, REVISED
            revision_notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (instrument_id) REFERENCES instruments(id) ON DELETE CASCADE,
            FOREIGN KEY (scope_code) REFERENCES scopes(code)
        );
    ");

    // 6. Create Users Table (Multi-Role Authentication & Access Control)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            role VARCHAR(30) NOT NULL, -- 'SUPER_ADMIN', 'SALES', 'TECHNICIAN', 'CERT_ADMIN'
            department VARCHAR(100) NOT NULL,
            avatar_initials VARCHAR(5) DEFAULT 'KP',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Seed Scopes: P (Pressure), M (Massa), T (Suhu), D (Dimensi), E (Electric)
    $scopesData = [
        ['P', 'Pressure (Tekanan)', 'Kalibrasi pressure gauge, differential manometer, transmitter tekanan', 'bar, psi, kPa, MPa', 'emerald'],
        ['M', 'Massa (Mass)', 'Timbangan analitik presisi, timbangan elektronik, anak timbangan kelas E2/F1/M1', 'g, kg, mg', 'indigo'],
        ['T', 'Suhu (Temperature)', 'Thermometer digital, thermocouple, thermohygrometer, oven, bath', '°C, %RH, K', 'amber'],
        ['D', 'Dimensi (Dimension)', 'Vernier caliper, digital micrometer, dial gauge, height gauge', 'mm, inch, µm', 'blue'],
        ['E', 'Kelistrikan (Electric)', 'Digital multimeter, clamp meter, voltage calibrator, insulation tester', 'V, A, Ohm, Hz', 'violet']
    ];

    $stmtScope = $pdo->prepare("INSERT OR IGNORE INTO scopes (code, name, description, unit_samples, color) VALUES (?, ?, ?, ?, ?)");
    foreach ($scopesData as $scope) {
        $stmtScope->execute($scope);
    }

    // Seed Dummy Orders
    $ordersData = [
        [
            'ORD-2605-001',
            'PT Astra Otoparts Tbk (Divisi Produksi)',
            'Kawasan Industri KIIC Lot C-4, Karawang, Jawa Barat',
            'Bpk. Bambang Supeno (0812-3456-7890)',
            '2026-05-10',
            'ON_SITE',
            'COMPLETED'
        ],
        [
            'ORD-2605-002',
            'PT Indofood CBP Sukses Makmur Tbk',
            'Jl. Raya Cibitung KM. 49, Bekasi, Jawa Barat',
            'Ibu Rina Astuti (0813-9876-5432)',
            '2026-05-12',
            'IN_LAB',
            'COMPLETED'
        ],
        [
            'ORD-2605-003',
            'PT Kalbe Farma Tbk (Laboratorium QC)',
            'Kawasan Industri Delta Silicon II, Cikarang, Bekasi',
            'Bpk. Dr. Haryanto (0811-2233-4455)',
            '2026-05-14',
            'IN_LAB',
            'WORKSHEET_DONE' // Siap dibuatkan sertifikat!
        ],
        [
            'ORD-2605-004',
            'PT Unilever Indonesia Tbk',
            'Jl. Jababeka Raya Blok O, Cikarang, Bekasi',
            'Ibu Maya Pertiwi (0815-1122-3344)',
            '2026-05-15',
            'ON_SITE',
            'IN_PROGRESS' // Sedang dikerjakan teknisi
        ]
    ];

    $stmtOrder = $pdo->prepare("INSERT OR IGNORE INTO orders (order_number, customer_name, customer_address, customer_contact, order_date, service_type, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($ordersData as $o) {
        $stmtOrder->execute($o);
    }

    // Seed Dummy Instruments
    // 1. Order 1 - Pressure Gauge (Selesai bersertifikat 2605P0012-00)
    $pdo->exec("
        INSERT OR IGNORE INTO instruments (id, order_id, name, scope_code, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status)
        VALUES (
            1, 1, 'Digital Pressure Gauge', 'P', 'Fluke Calibration', '700G07', 'SN-2605891',
            '0 - 10 bar', '0.001 bar', 'Ahmad Farhan, A.Md.', '2026-05-11', 'CERTIFIED'
        );
    ");

    // Worksheet for Instrument 1
    $readingsInst1 = json_encode([
        ['point' => '0.00 bar', 'standard' => 0.000, 'run1' => 0.000, 'run2' => 0.000, 'run3' => 0.000, 'mean' => 0.000, 'correction' => 0.000, 'uncertainty' => 0.004],
        ['point' => '2.50 bar', 'standard' => 2.500, 'run1' => 2.502, 'run2' => 2.501, 'run3' => 2.502, 'mean' => 2.502, 'correction' => -0.002, 'uncertainty' => 0.005],
        ['point' => '5.00 bar', 'standard' => 5.000, 'run1' => 5.003, 'run2' => 5.002, 'run3' => 5.003, 'mean' => 5.003, 'correction' => -0.003, 'uncertainty' => 0.006],
        ['point' => '7.50 bar', 'standard' => 7.500, 'run1' => 7.504, 'run2' => 7.503, 'run3' => 7.504, 'mean' => 7.504, 'correction' => -0.004, 'uncertainty' => 0.007],
        ['point' => '10.00 bar', 'standard' => 10.000, 'run1' => 10.005, 'run2' => 10.004, 'run3' => 10.005, 'mean' => 10.005, 'correction' => -0.005, 'uncertainty' => 0.008]
    ], JSON_PRETTY_PRINT);

    $pdo->exec("
        INSERT OR IGNORE INTO worksheets (id, instrument_id, temperature, temperature_uncertainty, humidity, humidity_uncertainty, standard_calibrator, standard_cert_no, standard_valid_until, calibration_method, readings_json, visual_inspection, technician_notes)
        VALUES (
            1, 1, 20.2, 0.8, 54.0, 4.0,
            'Dead Weight Tester Fluke P3000 Series SN: DWT-4412',
            'CERT-KAN-P-2025-042', '2027-04-15',
            'Metode Kalibrasi Tekanan MK-KAL-04 (Mengacu EURAMET cg-17)',
            " . $pdo->quote($readingsInst1) . ",
            'Fisik bersih, display jernih, konektor NPT 1/4 inch baik',
            'Pengambilan data onsite lancar, tidak ada kebocoran sambungan manifold.'
        );
    ");

    // Certificate for Instrument 1 (Sesuai contoh user: 2605P0012-00)
    $pdo->exec("
        INSERT OR IGNORE INTO certificates (id, instrument_id, certificate_number, scope_code, year_prefix, month_prefix, sequence_number, revision_number, issue_date, valid_until, technical_manager, status)
        VALUES (
            1, 1, '2605P0012-00', 'P', '26', '05', 12, '00',
            '2026-05-12', '2027-05-12', 'Ir. Hendra Wijaya, M.T.', 'ISSUED'
        );
    ");

    // 2. Order 2 - Timbangan Analitik (Massa -> Selesai bersertifikat 2605M0005-00)
    $pdo->exec("
        INSERT OR IGNORE INTO instruments (id, order_id, name, scope_code, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status)
        VALUES (
            2, 2, 'Analytical Balance 4 Desimal', 'M', 'Mettler Toledo', 'ME204T', 'MT-994102',
            '0 - 220 g', '0.0001 g', 'Budi Santoso, S.T.', '2026-05-13', 'CERTIFIED'
        );
    ");

    $readingsInst2 = json_encode([
        ['point' => '1.0000 g', 'standard' => 1.00002, 'run1' => 1.0000, 'run2' => 1.0000, 'run3' => 1.0001, 'mean' => 1.0000, 'correction' => 0.00002, 'uncertainty' => 0.00005],
        ['point' => '10.0000 g', 'standard' => 10.00005, 'run1' => 10.0001, 'run2' => 10.0000, 'run3' => 10.0001, 'mean' => 10.0001, 'correction' => -0.00005, 'uncertainty' => 0.00007],
        ['point' => '50.0000 g', 'standard' => 50.00012, 'run1' => 50.0002, 'run2' => 50.0001, 'run3' => 50.0002, 'mean' => 50.0002, 'correction' => -0.00008, 'uncertainty' => 0.00011],
        ['point' => '100.0000 g', 'standard' => 100.00020, 'run1' => 100.0003, 'run2' => 100.0002, 'run3' => 100.0003, 'mean' => 100.0003, 'correction' => -0.00010, 'uncertainty' => 0.00015],
        ['point' => '200.0000 g', 'standard' => 200.00045, 'run1' => 200.0005, 'run2' => 200.0005, 'run3' => 200.0004, 'mean' => 200.0005, 'correction' => -0.00005, 'uncertainty' => 0.00022]
    ], JSON_PRETTY_PRINT);

    $pdo->exec("
        INSERT OR IGNORE INTO worksheets (id, instrument_id, temperature, temperature_uncertainty, humidity, humidity_uncertainty, standard_calibrator, standard_cert_no, standard_valid_until, calibration_method, readings_json, visual_inspection, technician_notes)
        VALUES (
            2, 2, 20.0, 0.5, 52.0, 3.0,
            'Anak Timbangan Standar Kelas E2 (OIML R111) SN: E2-SET-102',
            'CERT-SNSU-M-2025-019', '2027-02-28',
            'Metode Kalibrasi Timbangan Non-Otomatis MK-KAL-01 (EURAMET cg-18)',
            " . $pdo->quote($readingsInst2) . ",
            'Kondisi bersih, meja anti-getar stabil, kaca draft shield utuh',
            'Hasil pengujian histeresis dan ketertelusuran daya ulang sangat baik.'
        );
    ");

    $pdo->exec("
        INSERT OR IGNORE INTO certificates (id, instrument_id, certificate_number, scope_code, year_prefix, month_prefix, sequence_number, revision_number, issue_date, valid_until, technical_manager, status)
        VALUES (
            2, 2, '2605M0005-00', 'M', '26', '05', 5, '00',
            '2026-05-13', '2027-05-13', 'Ir. Hendra Wijaya, M.T.', 'ISSUED'
        );
    ");

    // 3. Order 3 - Digital Thermometer (Suhu) -> Lembar kerja sudah diisi teknisi, MENUNGGU BAGIAN SERTIFIKAT!
    $pdo->exec("
        INSERT OR IGNORE INTO instruments (id, order_id, name, scope_code, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status)
        VALUES (
            3, 3, 'Digital Thermometer & Probe PT100', 'S', 'Chino Corp', 'DP-500B', 'CN-718290',
            '-20 °C s/d 150 °C', '0.01 °C', 'Dedi Kurniawan, A.Md.', '2026-05-14', 'DATA_SUBMITTED'
        );
    ");

    $readingsInst3 = json_encode([
        ['point' => '0.00 °C', 'standard' => 0.01, 'run1' => 0.03, 'run2' => 0.02, 'run3' => 0.03, 'mean' => 0.03, 'correction' => -0.02, 'uncertainty' => 0.03],
        ['point' => '50.00 °C', 'standard' => 50.00, 'run1' => 50.04, 'run2' => 50.03, 'run3' => 50.04, 'mean' => 50.04, 'correction' => -0.04, 'uncertainty' => 0.04],
        ['point' => '100.00 °C', 'standard' => 99.98, 'run1' => 100.05, 'run2' => 100.04, 'run3' => 100.05, 'mean' => 100.05, 'correction' => -0.07, 'uncertainty' => 0.05]
    ], JSON_PRETTY_PRINT);

    $pdo->exec("
        INSERT OR IGNORE INTO worksheets (id, instrument_id, temperature, temperature_uncertainty, humidity, humidity_uncertainty, standard_calibrator, standard_cert_no, standard_valid_until, calibration_method, readings_json, visual_inspection, technician_notes)
        VALUES (
            3, 3, 21.0, 1.0, 58.0, 5.0,
            'Standard Platinum Resistance Thermometer (SPRT) Fluke 5628 SN: 8812',
            'CERT-KAN-S-2025-110', '2026-11-20',
            'Metode Kalibrasi Termometer Digital MK-KAL-02 (Komparasi Direct Bath)',
            " . $pdo->quote($readingsInst3) . ",
            'Probe utuh, kabel tidak ada robekan, battery level prima',
            'Data mentah dari lembar kertas sudah selesai direkap, mohon diterbitkan nomor sertifikat ruang lingkup Suhu.'
        );
    ");

    // 4. Order 4 - Digital Micrometer (Dimensi) -> Sedang dikerjakan teknisi di lapangan (On-Site)
    $pdo->exec("
        INSERT OR IGNORE INTO instruments (id, order_id, name, scope_code, brand, model_type, serial_number, capacity_range, resolution, technician_name, calibration_date, status)
        VALUES (
            4, 4, 'Digital Outside Micrometer', 'D', 'Mitutoyo', '293-240-30', 'MTY-552918',
            '0 - 25 mm', '0.001 mm', 'Ahmad Farhan, A.Md.', '2026-05-15', 'IN_PROGRESS'
        );
    ");

    // 5. Seed Users for Multi-Role RBAC (Personal Employee Accounts + Master Admin)
    $usersData = [
        [
            'admin',
            password_hash('admin', PASSWORD_DEFAULT),
            'Administrator Utama',
            'admin@kalpindo.co.id',
            'SUPER_ADMIN',
            'Manajemen Sistem & Operasional',
            'AD'
        ],
        [
            'radit',
            password_hash('password123', PASSWORD_DEFAULT),
            'Raditya Pratama',
            'raditya@kalpindo.co.id',
            'CERT_ADMIN',
            'Administrasi Sertifikasi & Mutu',
            'RP'
        ],
        [
            'siti',
            password_hash('password123', PASSWORD_DEFAULT),
            'Siti Rahmawati, S.E.',
            'siti@kalpindo.co.id',
            'SALES',
            'Sales & Layanan Pelanggan',
            'SR'
        ],
        [
            'fauzi',
            password_hash('password123', PASSWORD_DEFAULT),
            'Ahmad Fauzi, A.Md.',
            'fauzi@kalpindo.co.id',
            'TECHNICIAN',
            'Laboratorium Kalibrasi Teknis',
            'AF'
        ],
        // Legacy role aliases for backward compatibility
        [
            'sales',
            password_hash('password123', PASSWORD_DEFAULT),
            'Siti Rahmawati, S.E.',
            'sales@kalpindo.co.id',
            'SALES',
            'Sales & Layanan Pelanggan',
            'SR'
        ],
        [
            'teknisi',
            password_hash('password123', PASSWORD_DEFAULT),
            'Ahmad Fauzi, A.Md.',
            'teknisi@kalpindo.co.id',
            'TECHNICIAN',
            'Laboratorium Kalibrasi Teknis',
            'AF'
        ],
        [
            'sertifikat',
            password_hash('password123', PASSWORD_DEFAULT),
            'Raditya Pratama',
            'sertifikat@kalpindo.co.id',
            'CERT_ADMIN',
            'Administrasi Sertifikasi & Mutu',
            'RP'
        ]
    ];

    $stmtUser = $pdo->prepare("
        INSERT OR IGNORE INTO users (username, password_hash, full_name, email, role, department, avatar_initials)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($usersData as $u) {
        $stmtUser->execute($u);
    }
}
