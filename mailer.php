<?php
// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// Set timezone
date_default_timezone_set('Asia/Ho_Chi_Minh');

/**
 * Get mailer configuration from database
 * 
 * @param object $db Database connection
 * @return array|null Mailer configuration or null if not found
 */
function getMailerConfig($db) {
    $sql = "SELECT * FROM mailer_tb WHERE is_active = 1 LIMIT 1";
    $result = $db->query($sql);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Create a new PHPMailer instance with configuration
 * 
 * @param object $db Database connection
 * @return PHPMailer|null PHPMailer instance or null if configuration not found
 */
function createMailer($db) {
    $config = getMailerConfig($db);
    
    if (!$config) {
        error_log("Mailer configuration not found");
        return null;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->CharSet    = 'UTF-8';
        
        // Default sender
        $mail->setFrom($config['from_email'], $config['from_name']);
        
        return $mail;
    } catch (Exception $e) {
        error_log("Mailer initialization error: " . $e->getMessage());
        return null;
    }
}

/**
 * Send email to a specific user
 * 
 * @param object $db Database connection
 * @param string $to_email Recipient email
 * @param string $to_name Recipient name
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param string $plain_body Email body (plain text)
 * @param array $attachments Optional array of attachments
 * @return bool True if email was sent successfully
 */
function sendEmail($db, $to_email, $to_name, $subject, $body, $plain_body = '', $attachments = []) {
    $mail = createMailer($db);
    
    if (!$mail) {
        return false;
    }
    
    try {
        // Recipients
        $mail->addAddress($to_email, $to_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Add plain text alternative if provided
        if (!empty($plain_body)) {
            $mail->AltBody = $plain_body;
        } else {
            $mail->AltBody = strip_tags($body);
        }
        
        // Add attachments if any
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['path']) && file_exists($attachment['path'])) {
                    $mail->addAttachment(
                        $attachment['path'],
                        isset($attachment['name']) ? $attachment['name'] : basename($attachment['path'])
                    );
                }
            }
        }
        
        // Send email
        $mail->send();
        
        // Log success
        error_log("Email sent successfully to: " . $to_email);
        return true;
    } catch (Exception $e) {
        // Log error
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send email to multiple recipients
 * 
 * @param object $db Database connection
 * @param array $recipients Array of recipient arrays with 'email' and 'name' keys
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @param string $plain_body Email body (plain text)
 * @param array $attachments Optional array of attachments
 * @return bool True if email was sent to at least one recipient
 */
function sendBulkEmail($db, $recipients, $subject, $body, $plain_body = '', $attachments = []) {
    if (empty($recipients)) {
        error_log("No recipients provided for bulk email");
        return false;
    }
    
    $mail = createMailer($db);
    
    if (!$mail) {
        return false;
    }
    
    try {
        // Add all recipients
        foreach ($recipients as $recipient) {
            if (isset($recipient['email']) && !empty($recipient['email'])) {
                $mail->addAddress($recipient['email'], isset($recipient['name']) ? $recipient['name'] : '');
            }
        }
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Add plain text alternative if provided
        if (!empty($plain_body)) {
            $mail->AltBody = $plain_body;
        } else {
            $mail->AltBody = strip_tags($body);
        }
        
        // Add attachments if any
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['path']) && file_exists($attachment['path'])) {
                    $mail->addAttachment(
                        $attachment['path'],
                        isset($attachment['name']) ? $attachment['name'] : basename($attachment['path'])
                    );
                }
            }
        }
        
        // Send email
        $mail->send();
        
        // Log success
        error_log("Bulk email sent successfully to " . count($recipients) . " recipients");
        return true;
    } catch (Exception $e) {
        // Log error
        error_log("Bulk email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Get user email by staff ID
 * 
 * @param object $db Database connection
 * @param string $staff_id Staff ID
 * @return string|null User email or null if not found
 */
function getUserEmail($db, $staff_id) {
    $sql = "SELECT email, name FROM user_tb WHERE staff_id = ?";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        error_log("SQL Error in getUserEmail: " . $db->error);
        return null;
    }
    
    $stmt->bind_param("s", $staff_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in getUserEmail: " . $stmt->error);
        return null;
    }
    
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return [
            'email' => $row['email'],
            'name' => $row['name']
        ];
    }
    
    return null;
}

/**
 * Get department emails
 * 
 * @param object $db Database connection
 * @param string $department Department name
 * @return array Array of email addresses and names
 */
function getDepartmentEmails($db, $department) {
    $emails = [];
    
    // Get users in the department
    $sql = "SELECT staff_id, name, email FROM user_tb WHERE department = ? AND email IS NOT NULL AND email != ''";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        error_log("SQL Error in getDepartmentEmails: " . $db->error);
        return $emails;
    }
    
    $stmt->bind_param("s", $department);
    if (!$stmt->execute()) {
        error_log("Execute Error in getDepartmentEmails: " . $stmt->error);
        return $emails;
    }
    
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['email'])) {
                $emails[] = [
                    'email' => $row['email'],
                    'name' => $row['name'],
                    'staff_id' => $row['staff_id']
                ];
            }
        }
    }
    
    return $emails;
}

/**
 * Format email body with standard template
 * 
 * @param string $content Email content
 * @return string Formatted HTML email
 */
function formatEmailBody($content) {
    $html = '
    <!DOCTYPE html>
    <html lang="vi">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Thông báo</title>
        <style>
            body {
                font-family: Arial, Helvetica, sans-serif;
                line-height: 1.6;
                color: #333;
                margin: 0;
                padding: 0;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            .header {
                background-color: #f8f9fa;
                padding: 15px;
                text-align: center;
                border-bottom: 1px solid #ddd;
                margin-bottom: 20px;
            }
            .content {
                padding: 0 15px;
            }
            .footer {
                margin-top: 30px;
                padding: 15px;
                text-align: center;
                font-size: 12px;
                color: #777;
                border-top: 1px solid #ddd;
            }
            a {
                color: #007bff;
                text-decoration: none;
            }
            .btn {
                display: inline-block;
                padding: 10px 20px;
                background-color: #007bff;
                color: #fff;
                border-radius: 5px;
                text-decoration: none;
                margin-top: 15px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Hệ thống phản hồi ý kiến người lao động</h2>
            </div>
            <div class="content">
                ' . $content . '
            </div>
            <div class="footer">
                <p>Đây là email tự động, vui lòng không trả lời email này.</p>
                <p>&copy; ' . date('Y') . ' Hệ thống phản hồi ý kiến người lao động</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    return $html;
}
?>
