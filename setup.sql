CREATE DATABASE IF NOT EXISTS accident_alerts;
USE accident_alerts;

CREATE TABLE IF NOT EXISTS alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lat DECIMAL(9,6),
  lng DECIMAL(9,6),
  hospital VARCHAR(100),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE,
  password_hash VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS hospitals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) UNIQUE,
  lat DECIMAL(9,6),
  lng DECIMAL(9,6),
  chat_id VARCHAR(50),
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  reference VARCHAR(300),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS hospital_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hospital VARCHAR(100),
  chat_id VARCHAR(50),
  type ENUM('approved','rejected'),
  delivered TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
