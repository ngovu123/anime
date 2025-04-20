-- Create database
CREATE DATABASE IF NOT EXISTS feedback_system;
USE feedback_system;

-- Create user_tb table (giữ nguyên không thay đổi)
CREATE TABLE IF NOT EXISTS user_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
staff_id VARCHAR(20) NOT NULL UNIQUE,
password VARCHAR(100) NOT NULL,
pdf_pwd VARCHAR(100) NULL,
security_question VARCHAR(255) NULL,
security_answer VARCHAR(100) NULL,
phone_macAddr VARCHAR(50) NULL,
laptop_macAddr VARCHAR(50) NULL,
name VARCHAR(100) NOT NULL,
department VARCHAR(100) NOT NULL,
section VARCHAR(100) NOT NULL,
email VARCHAR(100) NULL, -- Thêm trường email
phone VARCHAR(20) NULL, -- Thêm trường số điện thoại
reset_account TINYINT(1) NOT NULL DEFAULT 0,
activate_phone TINYINT(1) NOT NULL DEFAULT 0,
activate_laptop TINYINT(1) NOT NULL DEFAULT 0,
loginStatus VARCHAR(20) NULL,
loginTime VARCHAR(50) NULL
);

-- Insert sample user (đã có sẵn)
INSERT INTO user_tb (id, staff_id, password, pdf_pwd, security_question, security_answer, phone_macAddr, laptop_macAddr, name, department, section, email, phone, reset_account, activate_phone, activate_laptop, loginStatus, loginTime) VALUES
(3839, 'V73554', 'TVRJek5EVTJOemc1', 'TWpJeU1qSXlNakk9', 'truong mau giao dau tien cua ban ten gi?', 'TVE9PQ==', '', '', 'Ngo Truong Vu', 'IT', 'Phát triển hệ thống', 'ngotruongvu@example.com', '0123456789', '0', '1', '1', 'success', '2025/03/31 15:28:33');

-- Thêm một số người dùng mẫu khác
INSERT INTO user_tb (staff_id, password, name, department, section, email, phone, activate_phone, activate_laptop) VALUES
('V88282', 'TVRJek5EVTJOemc1', 'Nguyễn Văn A', 'Nhân sự', 'Tuyển dụng', 'nguyenvana@example.com', '0987654321', '1', '1'),
('V88283', 'TVRJek5EVTJOemc1', 'Trần Thị B', 'Nhân sự', 'Đào tạo', 'tranthib@example.com', '0912345678', '1', '1'),
('V88284', 'TVRJek5EVTJOemc1', 'Lê Văn C', 'IT', 'Phát triển', 'levanc@example.com', '0909090909', '1', '1'),
('V88285', 'TVRJek5EVTJOemc1', 'Phạm Thị D', 'Pháp lý', 'Hợp đồng', 'phamthid@example.com', '0898989898', '1', '1');

-- Create feedback_tb table
CREATE TABLE IF NOT EXISTS feedback_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
feedback_id VARCHAR(20) NOT NULL UNIQUE,
staff_id VARCHAR(20) NULL,
anonymous_code VARCHAR(20) NULL,
title VARCHAR(255) NOT NULL,
content TEXT NOT NULL,
handling_department VARCHAR(100) NOT NULL,
is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Chờ xử lý, 2: Đã phản hồi, 3: Kết thúc',
image_path VARCHAR(255) NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
INDEX (staff_id),
INDEX (anonymous_code),
INDEX (handling_department),
INDEX (status),
FOREIGN KEY (staff_id) REFERENCES user_tb(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Create feedback_response_tb table with notification field
CREATE TABLE IF NOT EXISTS feedback_response_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
feedback_id INT NOT NULL,
responder_id VARCHAR(20) NOT NULL,
response TEXT NOT NULL,
attachment_path VARCHAR(255) NULL,
notification TEXT NULL,
is_read TINYINT(1) NOT NULL DEFAULT 0,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (feedback_id) REFERENCES feedback_tb(id) ON DELETE CASCADE ON UPDATE CASCADE,
FOREIGN KEY (responder_id) REFERENCES user_tb(staff_id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Create feedback_rating_tb table
CREATE TABLE IF NOT EXISTS feedback_rating_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
feedback_id INT NOT NULL,
rating TINYINT(1) NOT NULL COMMENT '1-5 stars',
comment TEXT NULL,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY (feedback_id) REFERENCES feedback_tb(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Create attachment_tb table (gộp từ feedback_attachment_tb và response_attachment_tb)
CREATE TABLE IF NOT EXISTS attachment_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
reference_id INT NOT NULL COMMENT 'ID của feedback hoặc response',
reference_type ENUM('feedback', 'response') NOT NULL COMMENT 'Loại: feedback hoặc response',
file_name VARCHAR(255) NOT NULL,
file_path VARCHAR(255) NOT NULL,
file_type VARCHAR(100) NOT NULL,
file_size INT NOT NULL,
uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
INDEX (reference_id, reference_type)
);

-- Create handling_department_tb table for email notifications
CREATE TABLE IF NOT EXISTS handling_department_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
department_name VARCHAR(100) NOT NULL UNIQUE,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
INDEX (department_name)
);

-- Insert sample data for handling departments
INSERT INTO handling_department_tb (department_name) VALUES
('IT'),
('Nhân sự'),
('Pháp lý');

-- Create feedback_viewed_tb table to track when users view feedback
CREATE TABLE IF NOT EXISTS feedback_viewed_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
feedback_id INT NOT NULL,
user_id VARCHAR(20) NOT NULL,
viewed_at DATETIME NOT NULL,
INDEX (feedback_id, user_id),
FOREIGN KEY (feedback_id) REFERENCES feedback_tb(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Create mailer_tb table for email configuration
CREATE TABLE IF NOT EXISTS mailer_tb (
id INT AUTO_INCREMENT PRIMARY KEY,
address VARCHAR(50) NOT NULL,
password VARCHAR(30) NOT NULL,
host VARCHAR(30) NOT NULL,
port INT(5) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;

-- Insert default mailer configuration
INSERT INTO mailer_tb (id, address, password, host, port) VALUES
(1, 'automail@s-stec.com', 'u8V%e&4#Rs2Ew', '113.52.35.19', 25);

-- Insert sample data
INSERT INTO feedback_tb (feedback_id, staff_id, title, content, handling_department, status, created_at) VALUES
('FB-12345', 'V88282', 'Cải thiện hiệu quả cuộc họp', 'Tôi tin rằng chúng ta có thể cải thiện hiệu quả cuộc họp bằng cách thực hiện chương trình nghỉ sự nghiêm ngặt và giới hạn thời gian cho từng chủ đề.', 'Nhân sự', 3, '2023-11-15 17:30:00'),
('FB-12346', 'V88284', 'Đề xuất dự án mới', 'Tôi muốn đề xuất một dự án mới về phát triển ứng dụng di động cho nhân viên.', 'IT', 3, '2023-11-16 09:15:00'),
('FB-12347', 'V88285', 'Cải thiện môi trường làm việc', 'Tôi nghĩ chúng ta nên cải thiện không gian làm việc chung để tăng sự tương tác giữa các phòng ban.', 'Nhân sự', 1, '2023-11-17 14:20:00');

INSERT INTO feedback_response_tb (feedback_id, responder_id, response, created_at) VALUES
(1, 'V88283', 'Cảm ơn phản hồi quý giá của bạn. Chúng tôi đồng ý rằng hiệu quả cuộc họp là một lĩnh vực chúng ta có thể cải thiện. Chúng tôi đang lên kế hoạch triển khai định dạng cuộc họp mới vào tháng tới bao gồm các mục chương trình có thời gian và tài liệu trước cuộc họp.', '2023-11-17 09:45:00');

-- Thêm dữ liệu mẫu cho file đính kèm
INSERT INTO attachment_tb (reference_id, reference_type, file_name, file_path, file_type, file_size, uploaded_at) VALUES
(1, 'feedback', 'sample_image.jpg', 'uploads/sample_image.jpg', 'image/jpeg', 102400, '2023-11-15 17:30:00'),
(1, 'response', 'response_document.pdf', 'uploads/responses/response_document.pdf', 'application/pdf', 204800, '2023-11-17 09:45:00');
