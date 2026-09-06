-- ==========================================================
-- PT KALPINDO KALIBRASI INDONESIA
-- Basis Data Sistem Informasi Alur Kerja Kalibrasi & Penerbitan Sertifikat (ISO/IEC 17025)
-- ==========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. Tabel Ruang Lingkup (Scopes)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `scopes`;
CREATE TABLE `scopes` (
  `code` varchar(5) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `unit_samples` varchar(100) DEFAULT NULL,
  `color` varchar(30) DEFAULT 'sky',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `scopes` (`code`, `name`, `description`, `unit_samples`, `color`) VALUES
('P', 'Pressure (Tekanan)', 'Kalibrasi pressure gauge, differential manometer, transmitter tekanan', 'bar, psi, kPa, MPa', 'emerald'),
('S', 'Suhu & Kelembaban (Temperature)', 'Thermometer digital, thermocouple, thermohygrometer, oven, bath', '°C, %RH, K', 'amber'),
('M', 'Massa (Mass)', 'Timbangan analitik presisi, timbangan elektronik, anak timbangan kelas E2/F1/M1', 'g, kg, mg', 'indigo'),
('D', 'Dimensi (Dimension)', 'Vernier caliper, digital micrometer, dial gauge, height gauge', 'mm, inch, µm', 'blue'),
('E', 'Kelistrikan (Electrical)', 'Digital multimeter, clamp meter, voltage calibrator, insulation tester', 'V, A, Ohm, Hz', 'violet'),
('V', 'Volumetrik (Volume)', 'Labu ukur, micropipette, buret, piknometer', 'ml, L, µl', 'cyan'),
('F', 'Gaya & Torsi (Force & Torque)', 'Torque wrench, digital push-pull gauge, load cell', 'Nm, N, kgf', 'rose');

-- ----------------------------------------------------------
-- 2. Tabel Surat Perintah Kerja / Order (Orders)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL UNIQUE,
  `customer_name` varchar(150) NOT NULL,
  `customer_address` text DEFAULT NULL,
  `customer_contact` varchar(100) DEFAULT NULL,
  `order_date` date NOT NULL,
  `service_type` enum('IN_LAB','ON_SITE') NOT NULL DEFAULT 'IN_LAB',
  `status` varchar(30) DEFAULT 'PENDING',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `orders` (`id`, `order_number`, `customer_name`, `customer_address`, `customer_contact`, `order_date`, `service_type`, `status`) VALUES
(1, 'ORD-2605-001', 'PT Astra Otoparts Tbk (Divisi Produksi)', 'Kawasan Industri KIIC Lot C-4, Karawang, Jawa Barat', 'Bpk. Bambang Supeno (0812-3456-7890)', '2026-05-10', 'ON_SITE', 'COMPLETED'),
(2, 'ORD-2605-002', 'PT Indofood CBP Sukses Makmur Tbk', 'Jl. Raya Cibitung KM. 49, Bekasi, Jawa Barat', 'Ibu Rina Astuti (0813-9876-5432)', '2026-05-12', 'IN_LAB', 'COMPLETED'),
(3, 'ORD-2605-003', 'PT Kalbe Farma Tbk (Laboratorium QC)', 'Kawasan Industri Delta Silicon II, Cikarang, Bekasi', 'Bpk. Dr. Haryanto (0811-2233-4455)', '2026-05-14', 'IN_LAB', 'WORKSHEET_DONE'),
(4, 'ORD-2605-004', 'PT Unilever Indonesia Tbk', 'Jl. Jababeka Raya Blok O, Cikarang, Bekasi', 'Ibu Maya Pertiwi (0815-1122-3344)', '2026-05-15', 'ON_SITE', 'IN_PROGRESS');

-- ----------------------------------------------------------
-- 3. Tabel Alat Kalibrasi (Instruments)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `instruments`;
CREATE TABLE `instruments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `scope_code` varchar(5) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model_type` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) NOT NULL,
  `capacity_range` varchar(100) DEFAULT NULL,
  `resolution` varchar(50) DEFAULT NULL,
  `technician_name` varchar(100) DEFAULT NULL,
  `calibration_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT 'ASSIGNED',
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `scope_code` (`scope_code`),
  CONSTRAINT `fk_inst_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inst_scope` FOREIGN KEY (`scope_code`) REFERENCES `scopes` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `instruments` (`id`, `order_id`, `name`, `scope_code`, `brand`, `model_type`, `serial_number`, `capacity_range`, `resolution`, `technician_name`, `calibration_date`, `status`) VALUES
(1, 1, 'Digital Pressure Gauge', 'P', 'Fluke Calibration', '700G07', 'SN-2605891', '0 - 10 bar', '0.001 bar', 'Ahmad Farhan, A.Md.', '2026-05-11', 'CERTIFIED'),
(2, 2, 'Analytical Balance 4 Desimal', 'M', 'Mettler Toledo', 'ME204T', 'MT-994102', '0 - 220 g', '0.0001 g', 'Budi Santoso, S.T.', '2026-05-13', 'CERTIFIED'),
(3, 3, 'Digital Thermometer & Probe PT100', 'S', 'Chino Corp', 'DP-500B', 'CN-718290', '-20 °C s/d 150 °C', '0.01 °C', 'Dedi Kurniawan, A.Md.', '2026-05-14', 'DATA_SUBMITTED'),
(4, 4, 'Digital Outside Micrometer', 'D', 'Mitutoyo', '293-240-30', 'MTY-552918', '0 - 25 mm', '0.001 mm', 'Ahmad Farhan, A.Md.', '2026-05-15', 'IN_PROGRESS');

-- ----------------------------------------------------------
-- 4. Tabel Lembar Kerja Fisik (Worksheets)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `worksheets`;
CREATE TABLE `worksheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instrument_id` int(11) NOT NULL UNIQUE,
  `temperature` decimal(5,2) DEFAULT 20.00,
  `temperature_uncertainty` decimal(4,2) DEFAULT 1.00,
  `humidity` decimal(5,2) DEFAULT 55.00,
  `humidity_uncertainty` decimal(4,2) DEFAULT 5.00,
  `standard_calibrator` varchar(200) NOT NULL,
  `standard_cert_no` varchar(100) DEFAULT NULL,
  `standard_valid_until` date DEFAULT NULL,
  `calibration_method` varchar(200) DEFAULT NULL,
  `readings_json` longtext NOT NULL,
  `visual_inspection` varchar(100) DEFAULT 'Normal & Berfungsi Baik',
  `technician_notes` text DEFAULT NULL,
  `submitted_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_ws_inst` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `worksheets` (`id`, `instrument_id`, `temperature`, `temperature_uncertainty`, `humidity`, `humidity_uncertainty`, `standard_calibrator`, `standard_cert_no`, `standard_valid_until`, `calibration_method`, `readings_json`, `visual_inspection`, `technician_notes`) VALUES
(1, 1, 20.20, 0.80, 54.00, 4.00, 'Dead Weight Tester Fluke P3000 Series SN: DWT-4412', 'CERT-KAN-P-2025-042', '2027-04-15', 'Metode Kalibrasi Tekanan MK-KAL-04 (Mengacu EURAMET cg-17)', '[{"point":"0.00 bar","standard":0,"run1":0,"run2":0,"run3":0,"mean":0,"correction":0,"uncertainty":0.004},{"point":"2.50 bar","standard":2.5,"run1":2.502,"run2":2.501,"run3":2.502,"mean":2.502,"correction":-0.002,"uncertainty":0.005},{"point":"5.00 bar","standard":5,"run1":5.003,"run2":5.002,"run3":5.003,"mean":5.003,"correction":-0.003,"uncertainty":0.006},{"point":"7.50 bar","standard":7.5,"run1":7.504,"run2":7.503,"run3":7.504,"mean":7.504,"correction":-0.004,"uncertainty":0.007},{"point":"10.00 bar","standard":10,"run1":10.005,"run2":10.004,"run3":10.005,"mean":10.005,"correction":-0.005,"uncertainty":0.008}]', 'Fisik bersih, display jernih, konektor NPT 1/4 inch baik', 'Pengambilan data onsite lancar, tidak ada kebocoran sambungan manifold.'),
(2, 2, 20.00, 0.50, 52.00, 3.00, 'Anak Timbangan Standar Kelas E2 (OIML R111) SN: E2-SET-102', 'CERT-SNSU-M-2025-019', '2027-02-28', 'Metode Kalibrasi Timbangan Non-Otomatis MK-KAL-01 (EURAMET cg-18)', '[{"point":"1.0000 g","standard":1.00002,"run1":1,"run2":1,"run3":1.0001,"mean":1,"correction":0.00002,"uncertainty":0.00005},{"point":"10.0000 g","standard":10.00005,"run1":10.0001,"run2":10,"run3":10.0001,"mean":10.0001,"correction":-0.00005,"uncertainty":0.00007},{"point":"50.0000 g","standard":50.00012,"run1":50.0002,"run2":50.0001,"run3":50.0002,"mean":50.0002,"correction":-0.00008,"uncertainty":0.00011},{"point":"100.0000 g","standard":100.0002,"run1":100.0003,"run2":100.0002,"run3":100.0003,"mean":100.0003,"correction":-0.0001,"uncertainty":0.00015},{"point":"200.0000 g","standard":200.00045,"run1":200.0005,"run2":200.0005,"run3":200.0004,"mean":200.0005,"correction":-0.00005,"uncertainty":0.00022}]', 'Kondisi bersih, meja anti-getar stabil, kaca draft shield utuh', 'Hasil pengujian histeresis dan ketertelusuran daya ulang sangat baik.'),
(3, 3, 21.00, 1.00, 58.00, 5.00, 'Standard Platinum Resistance Thermometer (SPRT) Fluke 5628 SN: 8812', 'CERT-KAN-S-2025-110', '2026-11-20', 'Metode Kalibrasi Termometer Digital MK-KAL-02 (Komparasi Direct Bath)', '[{"point":"0.00 °C","standard":0.01,"run1":0.03,"run2":0.02,"run3":0.03,"mean":0.03,"correction":-0.02,"uncertainty":0.03},{"point":"50.00 °C","standard":50,"run1":50.04,"run2":50.03,"run3":50.04,"mean":50.04,"correction":-0.04,"uncertainty":0.04},{"point":"100.00 °C","standard":99.98,"run1":100.05,"run2":100.04,"run3":100.05,"mean":100.05,"correction":-0.07,"uncertainty":0.05}]', 'Probe utuh, kabel tidak ada robekan, battery level prima', 'Data mentah dari lembar kertas sudah selesai direkap, mohon diterbitkan nomor sertifikat ruang lingkup Suhu.');

-- ----------------------------------------------------------
-- 5. Tabel Sertifikat Kalibrasi (Certificates)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `certificates`;
CREATE TABLE `certificates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `instrument_id` int(11) NOT NULL UNIQUE,
  `certificate_number` varchar(50) NOT NULL UNIQUE,
  `scope_code` varchar(5) NOT NULL,
  `year_prefix` varchar(2) NOT NULL,
  `month_prefix` varchar(2) NOT NULL,
  `sequence_number` int(11) NOT NULL,
  `revision_number` varchar(2) DEFAULT '00',
  `issue_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `technical_manager` varchar(100) DEFAULT 'Ir. Hendra Wijaya, M.T.',
  `status` varchar(30) DEFAULT 'ISSUED',
  `revision_notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `scope_code` (`scope_code`),
  CONSTRAINT `fk_cert_inst` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cert_scope` FOREIGN KEY (`scope_code`) REFERENCES `scopes` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `certificates` (`id`, `instrument_id`, `certificate_number`, `scope_code`, `year_prefix`, `month_prefix`, `sequence_number`, `revision_number`, `issue_date`, `valid_until`, `technical_manager`, `status`, `revision_notes`) VALUES
(1, 1, '2605P0012-00', 'P', '26', '05', 12, '00', '2026-05-12', '2027-05-12', 'Ir. Hendra Wijaya, M.T.', 'ISSUED', NULL),
(2, 2, '2605M0005-00', 'M', '26', '05', 5, '00', '2026-05-13', '2027-05-13', 'Ir. Hendra Wijaya, M.T.', 'ISSUED', NULL);

-- ----------------------------------------------------------
-- 6. Tabel Pengguna Multi-Role (Users & RBAC)
-- ----------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL UNIQUE,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` varchar(30) NOT NULL, -- 'SUPER_ADMIN', 'SALES', 'TECHNICIAN', 'CERT_ADMIN'
  `department` varchar(100) NOT NULL,
  `avatar_initials` varchar(5) DEFAULT 'KP',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `email`, `role`, `department`, `avatar_initials`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ir. Hendra Wijaya, M.T.', 'hendra.wijaya@kalpindo.co.id', 'SUPER_ADMIN', 'Manajemen & Mutu ISO 17025', 'HW'),
(2, 'sales', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Siti Rahmawati, S.E.', 'siti.sales@kalpindo.co.id', 'SALES', 'Sales & Layanan Pelanggan', 'SR'),
(3, 'teknisi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ahmad Fauzi, A.Md.', 'ahmad.fauzi@kalpindo.co.id', 'TECHNICIAN', 'Laboratorium Kalibrasi Teknis', 'AF'),
(4, 'sertifikat', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Raditya Pratama', 'raditya.cert@kalpindo.co.id', 'CERT_ADMIN', 'Administrasi Penerbitan Sertifikat', 'RP');

SET FOREIGN_KEY_CHECKS = 1;
