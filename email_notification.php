<?php
// Thêm cấu hình timezone ở đầu file
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Include database connection
include_once("../connect.php");
include_once("functions.php");

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Sử dụng trực tiếp các file PHPMailer
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';

/**
 * Hàm lấy cấu hình email từ bảng mailer_tb
 * 
 * @return array|null Mảng chứa thông tin cấu hình email hoặc null nếu không tìm thấy
 */
function getMailerConfig() {
    global $db;
    
    $sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
    $result = $db->query($sql);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Function to send email notification to a staff member
 * 
 * @param string $staff_id Staff ID of the recipient
 * @param string $name Name of the recipient
 * @param string $subject Subject of the notification
 * @param string $message Content of the notification
 * @return bool True if email was sent successfully
 */
function sendEmailNotification($staff_id, $name, $subject, $message) {
    global $db;
    
    // Get user's email
    $sql = "SELECT email FROM user_tb WHERE staff_id = ?";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        error_log("SQL Error in sendEmailNotification: " . $db->error);
        return false;
    }
    
    $stmt->bind_param("s", $staff_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $email = $user['email'];
        
        if (!empty($email)) {
            // Send email using PHPMailer
            return sendPHPMailer($email, $subject, $message);
        }
    }
    
    // Fallback to text file if no email available
    $dir = "email_notifications";
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $sanitized_name = sanitizeFilename($name);
    $filename = $dir . "/" . $staff_id . "-" . $sanitized_name . ".txt";
    
    $content = "Date: " . date("Y-m-d H:i:s") . "\n";
    $content .= "Subject: " . $subject . "\n";
    $content .= "To: " . $name . " (" . $staff_id . ")\n";
    $content .= "-----------------------------------\n\n";
    $content .= $message . "\n\n";
    $content .= "-----------------------------------\n";
    
    $result = file_put_contents($filename, $content, FILE_APPEND);
    
    return ($result !== false);
}

/**
 * Function to send email notifications to all members of a department
 * 
 * @param string $department Department name
 * @param string $subject Subject of the notification
 * @param string $message Content of the notification
 * @return bool True if at least one notification was sent
 */
function sendDepartmentEmailNotifications($department, $subject, $message) {
    global $db;
    
    $sql = "SELECT staff_id, name, email FROM user_tb WHERE department = ?";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $department);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sent = false;
    
    if ($result && $result->num_rows > 0) {
        while ($user = $result->fetch_assoc()) {
            if (!empty($user['email'])) {
                $sent = sendPHPMailer($user['email'], $subject, $message) || $sent;
            } else {
                $sent = sendEmailNotification($user['staff_id'], $user['name'], $subject, $message) || $sent;
            }
        }
    }
    
    return $sent;
}

/**
 * Function to send email notifications based on department email list
 * 
 * @param string $department Department name
 * @param string $subject Subject of the notification
 * @param string $message Content of the notification
 * @param array $attachments Optional array of attachments
 * @param string|null $exclude_staff_id Staff ID to exclude from recipients
 * @return bool True if notification was sent successfully
 */
function sendDepartmentEmailListNotification($department, $subject, $message, $attachments = [], $exclude_staff_id = null) {
    global $db;
    
    // Lấy email từ bảng user_tb, loại trừ exclude_staff_id
    $sql = "SELECT staff_id, email FROM user_tb WHERE department = ? AND email IS NOT NULL AND email != ''";
    if ($exclude_staff_id) {
        $sql .= " AND staff_id != ?";
    }
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in sendDepartmentEmailListNotification: " . $db->error);
        return false;
    }
    
    if ($exclude_staff_id) {
        $stmt->bind_param("ss", $department, $exclude_staff_id);
    } else {
        $stmt->bind_param("s", $department);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $emails = [];
    if ($result && $result->num_rows > 0) {
        while ($user = $result->fetch_assoc()) {
            $email = trim($user['email']);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$user['staff_id']] = $email;
            }
        }
    }
    
    error_log("Emails found for department $department (excluding $exclude_staff_id): " . implode(", ", $emails));
    
    if (!empty($emails)) {
        // Gửi một email duy nhất với nhiều người nhận
        $config = getMailerConfig();
        if (!$config) {
            error_log("Mailer configuration not found");
            return false;
        }
        
        $mail = new PHPMailer(true);
        try {
            // Bật chế độ gỡ lỗi
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer: $str");
            };
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['address'];
            $mail->Password = $config['password'];
            $mail->Port = $config['port'];
            $mail->CharSet = 'UTF-8';
            
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            // Kiểm tra kết nối SMTP
            if (!$mail->smtpConnect()) {
                throw new Exception('Không thể kết nối đến máy chủ SMTP');
            }
            
            // Set sender
            $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
            
            // Add recipients
            foreach ($emails as $staff_id => $email) {
                $mail->addAddress($email);
                error_log("Added recipient: $email (staff_id: $staff_id)");
            }
            
            // Set email content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message; // Đã được định dạng HTML
            $mail->AltBody = strip_tags($message);
            
            // Add attachments if any
            foreach ($attachments as $attachment) {
                if (isset($attachment['file_path']) && file_exists($attachment['file_path'])) {
                    $mail->addAttachment(
                        $attachment['file_path'],
                        isset($attachment['file_name']) ? $attachment['file_name'] : basename($attachment['file_path'])
                    );
                    error_log("Added attachment: " . $attachment['file_name']);
                }
            }
            
            // Send email
            $mail->send();
            error_log("Email notification sent successfully to: " . implode(", ", $emails));
            return true;
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage());
        }
    }
    
    // Fallback to text file if no emails or email sending fails
    $dir = "email_notifications";
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $sanitized_dept = sanitizeFilename($department);
    $filename = $dir . "/" . $sanitized_dept . "_Notification_" . date("Ymd_His") . ".txt";
    
    $content = "Date: " . date("Y-m-d H:i:s") . "\n";
    $content .= "Subject: " . $subject . "\n";
    $content .= "To: Department " . $department . "\n";
    $content .= "Excluded: " . ($exclude_staff_id ? $exclude_staff_id : "None") . "\n";
    $content .= "-----------------------------------\n\n";
    $content .= $message . "\n\n";
    $content .= "-----------------------------------\n";
    
    $result = file_put_contents($filename, $content);
    if ($result !== false) {
        error_log("Notification saved to file: $filename");
    } else {
        error_log("Failed to save notification to file: $filename");
    }
    
    return ($result !== false);
}

/**
 * Function to send email using PHPMailer
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message Email message
 * @param array $attachments Optional array of attachments
 * @return bool True if email was sent successfully
 */
function sendPHPMailer($to, $subject, $message, $attachments = []) {
    global $db;
    
    $config = getMailerConfig();
    if (!$config) {
        error_log("Mailer configuration not found");
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) {
            error_log("PHPMailer: $str");
        };
        
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['address'];
        $mail->Password = $config['password'];
        $mail->Port = $config['port'];
        $mail->CharSet = 'UTF-8';
        
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        if (!$mail->smtpConnect()) {
            throw new Exception('Không thể kết nối đến máy chủ SMTP');
        }
        
        $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
        $mail->addAddress($to);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);
        
        foreach ($attachments as $attachment) {
            if (isset($attachment['file_path']) && file_exists($attachment['file_path'])) {
                $mail->addAttachment(
                    $attachment['file_path'],
                    isset($attachment['file_name']) ? $attachment['file_name'] : basename($attachment['file_path'])
                );
            }
        }
        
        $mail->send();
        error_log("Email sent successfully to: $to");
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed to $to: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Function to send feedback notification to department
 * 
 * @param int $feedback_id ID of the feedback
 * @param string $department Department name
 * @param string $subject Email subject
 * @param string $message Email message
 * @param array $attachments Optional array of attachments
 * @param string|null $exclude_staff_id Staff ID to exclude from recipients
 * @return bool True if notification was sent
 */
function sendFeedbackNotificationToDepartment($feedback_id, $department, $subject, $message, $attachments = [], $exclude_staff_id = null) {
    global $db;
    
    $sql = "SELECT staff_id, email FROM user_tb WHERE department = ? AND email IS NOT NULL AND email != ''";
    if ($exclude_staff_id) {
        $sql .= " AND staff_id != ?";
    }
    
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in sendFeedbackNotificationToDepartment: " . $db->error);
        return false;
    }
    
    if ($exclude_staff_id) {
        $stmt->bind_param("ss", $department, $exclude_staff_id);
    } else {
        $stmt->bind_param("s", $department);
    }
    
    if (!$stmt->execute()) {
        error_log("Execute Error in sendFeedbackNotificationToDepartment: " . $stmt->error);
        return false;
    }
    
    $result = $stmt->get_result();
    
    $emails = [];
    if ($result && $result->num_rows > 0) {
        while ($user = $result->fetch_assoc()) {
            if (!empty($user['email'])) {
                $emails[$user['staff_id']] = $user['email'];
            }
        }
    }
    
    error_log("Emails found for department $department (excluding $exclude_staff_id): " . implode(", ", $emails));
    
    if (!empty($emails)) {
        $config = getMailerConfig();
        if (!$config) {
            error_log("Mailer configuration not found");
            return false;
        }
        
        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer: $str");
            };
            
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['address'];
            $mail->Password = $config['password'];
            $mail->Port = $config['port'];
            $mail->CharSet = 'UTF-8';
            
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            if (!$mail->smtpConnect()) {
                throw new Exception('Không thể kết nối đến máy chủ SMTP');
            }
            
            $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
            
            foreach ($emails as $staff_id => $email) {
                $mail->addAddress($email);
                error_log("Added recipient: $email (staff_id: $staff_id)");
            }
            
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = strip_tags($message);
            
            foreach ($attachments as $attachment) {
                if (isset($attachment['file_path']) && file_exists($attachment['file_path'])) {
                    $mail->addAttachment(
                        $attachment['file_path'],
                        isset($attachment['file_name']) ? $attachment['file_name'] : basename($attachment['file_path'])
                    );
                    error_log("Added attachment: " . $attachment['file_name']);
                }
            }
            
            $mail->send();
            error_log("Email notification sent successfully to: " . implode(", ", $emails));
            return true;
        } catch (Exception $e) {
            error_log("Email sending failed: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage());
        }
    }
    
    // Fallback to text file
    $dir = "email_notifications";
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $sanitized_dept = sanitizeFilename($department);
    $filename = $dir . "/" . $sanitized_dept . "_Notification_" . date("Ymd_His") . ".txt";
    
    $content = "Date: " . date("Y-m-d H:i:s") . "\n";
    $content .= "Subject: " . $subject . "\n";
    $content .= "To: Department " . $department . "\n";
    $content .= "Excluded: " . ($exclude_staff_id ? $exclude_staff_id : "None") . "\n";
    $content .= "-----------------------------------\n\n";
    $content .= $message . "\n\n";
    $content .= "-----------------------------------\n";
    
    $result = file_put_contents($filename, $content);
    if ($result !== false) {
        error_log("Notification saved to file: $filename");
    }
    
    return ($result !== false);
}
?>
