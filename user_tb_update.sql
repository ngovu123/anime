-- Add email and phone columns to user_tb
ALTER TABLE user_tb 
ADD COLUMN email VARCHAR(255) NULL AFTER section,
ADD COLUMN phone VARCHAR(20) NULL AFTER email;

-- Update sample users with email addresses
UPDATE user_tb SET email = 'v73554@example.com', phone = '0901234567' WHERE staff_id = 'V73554';
UPDATE user_tb SET email = 'v88282@example.com', phone = '0901234568' WHERE staff_id = 'V88282';
UPDATE user_tb SET email = 'v88283@example.com', phone = '0901234569' WHERE staff_id = 'V88283';
UPDATE user_tb SET email = 'v88284@example.com', phone = '0901234570' WHERE staff_id = 'V88284';
UPDATE user_tb SET email = 'v88285@example.com', phone = '0901234571' WHERE staff_id = 'V88285';
