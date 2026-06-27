-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 04, 2026 at 04:20 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `phan-mem-rap-may`
--

-- --------------------------------------------------------

--
-- Table structure for table `chitiet_donhang`
--

CREATE TABLE `chitiet_donhang` (
  `id_ct` int(11) NOT NULL,
  `id_donhang` int(11) DEFAULT NULL,
  `ten_donhang` varchar(255) DEFAULT NULL,
  `ten_cauhinh` varchar(255) DEFAULT NULL,
  `ten_linhkien` varchar(255) DEFAULT NULL,
  `loai_linhkien` varchar(100) DEFAULT NULL,
  `linhkien_chon` varchar(255) DEFAULT NULL,
  `so_serial` varchar(255) DEFAULT NULL,
  `so_may` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_id_save` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chitiet_donhang`
--

INSERT INTO `chitiet_donhang` (`id_ct`, `id_donhang`, `ten_donhang`, `ten_cauhinh`, `ten_linhkien`, `loai_linhkien`, `linhkien_chon`, `so_serial`, `so_may`, `user_id`, `user_id_save`) VALUES
(599, 77, '123', 'Cấu hình 1', '12100', 'CPU', 'cấu hình 1', '1', 1, NULL, 3),
(600, 77, '123', 'Cấu hình 2', '3500', 'CPU', 'cấu hình 2', '3', 1, NULL, 3),
(601, 77, '123', 'Cấu hình 1', '12100', 'CPU', 'cấu hình 1', '2', 2, 3, 3),
(602, 77, '123', 'Cấu hình 1, Cấu hình 2', 'H610', 'MAIN', 'cấu hình 1', '1', 2, 3, 3),
(603, 77, '123', 'Cấu hình 1, Cấu hình 2 ', 'H610', 'MAIN', 'cấu hình 1', '2', 1, NULL, 3),
(604, 77, '123', 'Cấu hình 1, Cấu hình 2', 'H610', 'MAIN', 'cấu hình 2', '3', 1, NULL, 3),
(605, 77, '123', 'Cấu hình 1, Cấu hình 2', '8G', 'RAM', 'cấu hình 1', '1', 2, 3, 3),
(606, 77, '123', 'Cấu hình 1, Cấu hình 2', '8G', 'RAM', 'cấu hình 1', '2', 1, NULL, 3),
(607, 77, '123', 'Cấu hình 1, Cấu hình 2 ', '8G', 'RAM', 'cấu hình 1', '3', 1, NULL, 3),
(608, 77, '123', 'Cấu hình 1, Cấu hình 2', '8G', 'RAM', 'cấu hình 1', '4', 2, 3, 3),
(609, 77, '123', 'Cấu hình 1, Cấu hình 2', '8G', 'RAM', 'cấu hình 2', '5', 1, NULL, 3),
(610, 77, '123', 'Cấu hình 1', '256', 'SSD', 'cấu hình 1', '1', 1, NULL, 3),
(611, 77, '123', 'Cấu hình 2', '512', 'SSD', 'cấu hình 2', '1', 1, NULL, 3),
(612, 77, '123', 'Cấu hình 1', '256', 'SSD', 'cấu hình 1', '2', 2, 3, 3),
(613, 77, '123', 'Cấu hình 1', '550W', 'PSU', 'cấu hình 1', '1', 1, NULL, 3),
(614, 77, '123', 'Cấu hình 2', '660W', 'PSU', 'cấu hình 2', '1', 1, NULL, 3),
(615, 77, '123', 'Cấu hình 1', '550W', 'PSU', 'cấu hình 1', '2', 2, 3, 3),
(616, 77, '123', 'Cấu hình 1', 'WIN 11 HOME', 'WIN', 'cấu hình 1', '1', 1, NULL, 3),
(617, 77, '123', 'Cấu hình 2', 'WIN 11 PRO', 'WIN', 'cấu hình 2', '3', 1, NULL, 3),
(618, 77, '123', 'Cấu hình 1', 'WIN 11 HOME', 'WIN', 'cấu hình 1', '2', 2, 3, 3);

--
-- Triggers `chitiet_donhang`
--
DELIMITER $$
CREATE TRIGGER `delete_lock_after_detail_delete` AFTER DELETE ON `chitiet_donhang` FOR EACH ROW BEGIN
    DELETE FROM trang_thai_lap_may
    WHERE id_donhang = OLD.id_donhang
      AND so_may = OLD.so_may
      AND user_id = OLD.user_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `donhang`
--

CREATE TABLE `donhang` (
  `id_donhang` int(11) NOT NULL,
  `ma_don_hang` varchar(50) NOT NULL,
  `ten_khach_hang` varchar(255) DEFAULT NULL,
  `ghi_chu` text DEFAULT NULL,
  `so_luong_may` int(11) DEFAULT 1,
  `user_id` int(11) DEFAULT NULL,
  `ngay_tao` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `donhang`
--

INSERT INTO `donhang` (`id_donhang`, `ma_don_hang`, `ten_khach_hang`, `so_luong_may`, `user_id`, `ngay_tao`) VALUES
(77, 'RS-1777859786', '123', 3, 3, '2026-05-04 01:56:26');

-- --------------------------------------------------------

--
-- Table structure for table `trang_thai_lap_may`
--

CREATE TABLE `trang_thai_lap_may` (
  `id` int(11) NOT NULL,
  `id_donhang` int(11) NOT NULL,
  `so_may` int(11) NOT NULL,
  `config_name` varchar(255) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `last_active` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(100) DEFAULT NULL,
  `role` enum('ketoan','kythuat','admin') DEFAULT 'kythuat',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `fullname`, `role`, `created_at`) VALUES
(1, 'ketoan', '$2y$10$XfRz1ZhUU7TgqiMRXz/hCeMLLP4zq48Te3SlWZChYsZIsUkjJU2Im', 'Kế Toán', 'ketoan', '2026-04-08 02:57:43'),
(2, 'kythuat', '$2y$10$U.jhARKGBLv5wI03RtamPugP3q/AThtPEZAG36jFF7bvyGzCpBA0C', 'Kỹ Thuật', 'kythuat', '2026-04-08 02:57:43'),
(3, 'admin', '$2y$10$KxUKEteoPv5UWm2/rDr5bOVLXJ8C0yekhjFBCS5w8FuEbxkCq43iq', 'Quản Trị Viên', 'admin', '2026-04-08 02:57:43'),
(4, 'kythuat01', '$2y$10$U.jhARKGBLv5wI03RtamPugP3q/AThtPEZAG36jFF7bvyGzCpBA0C', 'kythuat01', 'kythuat', '2026-04-23 04:27:25');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `chitiet_donhang`
--
ALTER TABLE `chitiet_donhang`
  ADD PRIMARY KEY (`id_ct`);

--
-- Indexes for table `donhang`
--
ALTER TABLE `donhang`
  ADD PRIMARY KEY (`id_donhang`);

--
-- Indexes for table `trang_thai_lap_may`
--
ALTER TABLE `trang_thai_lap_may`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_machine` (`id_donhang`,`so_may`,`config_name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `chitiet_donhang`
--
ALTER TABLE `chitiet_donhang`
  MODIFY `id_ct` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=619;

--
-- AUTO_INCREMENT for table `donhang`
--
ALTER TABLE `donhang`
  MODIFY `id_donhang` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `trang_thai_lap_may`
--
ALTER TABLE `trang_thai_lap_may`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1562;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
