-- Create mailer_tb table for email server configuration
CREATE TABLE IF NOT EXISTS mailer_tb (
    id INT AUTO_INCREMENT PRIMARY KEY,
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL,
    smtp_username VARCHAR(255) NOT NULL,
    smtp_password VARCHAR(255) NOT NULL,
    smtp_secure ENUM('tls', 'ssl') NOT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert sample data
INSERT INTO mailer_tb (smtp_host, smtp_port, smtp_username, smtp_password, smtp_secure, from_email, from_name, is_active) VALUES
('smtp.example.com', 587, 'notifications@example.com', 'password123', 'tls', 'feedback@example.com', 'Hệ thống phản hồi ý kiến', 1);
