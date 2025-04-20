-- Kiểm tra và thêm cột email vào bảng user_tb nếu chưa tồn tại
ALTER TABLE user_tb 
ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL;

-- Cập nhật email cho các người dùng hiện có nếu chưa có
UPDATE user_tb SET email = CONCAT(LOWER(staff_id), '@example.com') WHERE email IS NULL;

-- Kiểm tra và thêm cột email vào bảng handling_department_tb nếu chưa có
ALTER TABLE handling_department_tb 
ADD COLUMN IF NOT EXISTS email VARCHAR(100) NULL AFTER department_name;

-- Cập nhật email cho các phòng ban nếu chưa có
UPDATE handling_department_tb SET email = CONCAT(LOWER(REPLACE(department_name, ' ', '.')), '@example.com') WHERE email IS NULL;
