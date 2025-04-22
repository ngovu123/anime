<?php
// Thêm cấu hình timezone ở đầu file
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include database connection
include_once("../connect.php");

// === Database Utility Functions ===

/**
 * Check if a value exists in a table based on a condition
 * @param string $column The column to check
 * @param string $table The table name
 * @param string $condition The condition for the query
 * @return bool True if value exists, false otherwise
 */
function Check_Value_by_Condition($column, $table, $condition) {
    global $db;
    $sql = "SELECT $column FROM $table WHERE $condition";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        return true;
    }
    return false;
}

/**
 * Select a value from a table based on a condition
 * @param string $column The column to select
 * @param string $table The table name
 * @param string $condition_column The column for the condition
 * @param string $condition_value The value for the condition
 * @return string The selected value or empty string if not found
 */
function Select_Value_by_Condition($column, $table, $condition_column, $condition_value) {
    global $db;
    $sql = "SELECT $column FROM $table WHERE $condition_column = '$condition_value'";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row[$column];
    }
    return "";
}

/**
 * Update login status for a user
 * @param string $status The login status
 * @param string $mysql_user The user ID
 */
function Update_LoginStatus($status, $mysql_user) {
    global $db;
    $current_time = date("Y/m/d H:i:s");
    $sql = "UPDATE user_tb SET loginStatus = '$status', loginTime = '$current_time' WHERE staff_id = '$mysql_user'";
    $db->query($sql);
}

/**
 * Update a value in a table based on a condition
 * @param string $table The table name
 * @param string $column The column to update
 * @param string $value The new value
 * @param string $condition_column The column for the condition
 * @param string $condition_value The value for the condition
 * @return bool True if update successful, false otherwise
 */
function Update_Value_by_Condition($table, $column, $value, $condition_column, $condition_value) {
    global $db;
    $sql = "UPDATE $table SET $column = '$value' WHERE $condition_column = '$condition_value'";
    return $db->query($sql);
}

/**
 * Check if a signature exists for a user
 * @param string $mysql_user The user ID
 * @return bool Always true (placeholder)
 */
function Check_Signature_Exist($mysql_user) {
    return true;
}

/**
 * Generate a random code for anonymous feedback
 * @param int $length The length of the code
 * @return string The generated code
 */
function generateRandomCode($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

/**
 * Generate a unique feedback ID
 * @return string The generated feedback ID
 */
function generateFeedbackID() {
    global $db;
    $prefix = "FB-";
    $sql = "SELECT MAX(CAST(SUBSTRING(feedback_id, 4) AS UNSIGNED)) as max_id FROM feedback_tb";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $next_id = $row['max_id'] + 1;
    } else {
        $next_id = 10001;
    }
    return $prefix . $next_id;
}

/**
 * Check if a user can handle feedback
 * @param string $staff_id The staff ID
 * @param string $handling_department The department handling the feedback
 * @param string|null $feedback_staff_id The staff ID of the feedback creator
 * @return bool True if user can handle feedback, false otherwise
 */
function canHandleFeedback($staff_id, $handling_department, $feedback_staff_id = null) {
    global $db;
    $sql = "SELECT department FROM user_tb WHERE staff_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in canHandleFeedback: " . $db->error);
        return false;
    }
    $stmt->bind_param("s", $staff_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in canHandleFeedback: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return ($row['department'] == $handling_department && $staff_id != $feedback_staff_id);
    }
    return false;
}

/**
 * Get user department and section
 * @param string $staff_id The staff ID
 * @return array Department and section
 */
function getUserDepartmentAndSection($staff_id) {
    global $db;
    $sql = "SELECT department, section FROM user_tb WHERE staff_id = '$staff_id'";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return ["department" => "", "section" => ""];
}

/**
 * Get all departments
 * @return array List of department names
 */
function getAllDepartments() {
    global $db;
    $departments = [];
    $sql = "SELECT department_name FROM handling_department_tb";
    $result = $db->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row['department_name'];
        }
    }
    return $departments;
}

/**
 * Get feedback status text
 * @param int $status_code The status code
 * @return string The status text
 */
function getStatusText($status_code) {
    switch ($status_code) {
        case 1:
            return "Chờ xử lý";
        case 2:
            return "Đã phản hồi";
        case 3:
            return "Kết thúc";
        default:
            return "Không xác định";
    }
}

/**
 * Sanitize input to prevent injection
 * @param string $input The input to sanitize
 * @return string Sanitized input
 */
function sanitizeInput($input) {
    global $db;
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES);
    $input = $db->real_escape_string($input);
    return $input;
}

/**
 * Sanitize input while preserving line breaks
 * @param string $input The input to sanitize
 * @return string Sanitized input
 */
function sanitizeInputWithLineBreaks($input) {
    global $db;
    $input = trim($input);
    $input = $db->real_escape_string($input);
    return $input;
}

/**
 * Format date
 * @param string $date The date to format
 * @param bool $includeTime Whether to include time
 * @return string Formatted date
 */
function formatDate($date, $includeTime = false) {
    if ($includeTime) {
        return date('d/m/Y H:i', strtotime($date));
    } else {
        return date('d/m/Y', strtotime($date));
    }
}

/**
 * Sanitize filename to avoid encoding issues
 * @param string $filename The filename to sanitize
 * @return string Sanitized filename
 */
function sanitizeFilename($filename) {
    $filename = preg_replace('/[àáạảãâầấậẩẫăằắặẳẵ]/u', 'a', $filename);
    $filename = preg_replace('/[èéẹẻẽêềếệểễ]/u', 'e', $filename);
    $filename = preg_replace('/[ìíịỉĩ]/u', 'i', $filename);
    $filename = preg_replace('/[òóọỏõôồốộổỗơờớợởỡ]/u', 'o', $filename);
    $filename = preg_replace('/[ùúụủũưừứựửữ]/u', 'u', $filename);
    $filename = preg_replace('/[ỳýỵỷỹ]/u', 'y', $filename);
    $filename = preg_replace('/[đ]/u', 'd', $filename);
    $filename = preg_replace('/[ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ]/u', 'A', $filename);
    $filename = preg_replace('/[ÈÉẸẺẼÊỀẾỆỂỄ]/u', 'E', $filename);
    $filename = preg_replace('/[ÌÍỊỈĨ]/u', 'I', $filename);
    $filename = preg_replace('/[ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ]/u', 'O', $filename);
    $filename = preg_replace('/[ÙÚỤỦŨƯỪỨỰỬỮ]/u', 'U', $filename);
    $filename = preg_replace('/[ỲÝỴỶỸ]/u', 'Y', $filename);
    $filename = preg_replace('/[Đ]/u', 'D', $filename);
    $filename = str_replace(' ', '', $filename);
    $filename = preg_replace('/[^a-zA-Z0-9]/', '', $filename);
    return $filename;
}

/**
 * Format email content with Calibri font and proper line breaks
 * @param string $message The message to format
 * @return array HTML and plain text versions
 */
if (!function_exists('formatEmailContent')) {
    function formatEmailContent($message) {
        // Fix line breaks in message
        $message = str_replace(
            ["\\r\\n\\r\\n", "\\r\\n", "\r\n\r\n", "\r\n", "\n\n", "\n"],
            ["<br><br>", "<br>", "<br><br>", "<br>", "<br><br>", "<br>"],
            $message
        );
        
        // Use Calibri font and format for better readability
        $html_message = '<div style="font-family: Calibri, \'Segoe UI\', Arial, sans-serif; font-size: 14px; line-height: 1.5;">' . 
                       $message . 
                       '</div>';
        
        // Create plain text version
        $plain_message = strip_tags(str_replace(["<br>", "<br><br>"], ["\n", "\n\n"], $message));
        
        return [
            'html' => $html_message,
            'text' => $plain_message
        ];
    }
}

// === Notification Functions ===

/**
 * Create a notification for a user
 * @param string $user_id The user ID
 * @param int $feedback_id The feedback ID
 * @param string $message The notification message
 * @param bool $has_attachment Whether the notification has attachments
 * @return bool True if successful, false otherwise
 */
function createNotification($user_id, $feedback_id, $message, $has_attachment = false) {
    global $db;
    $sql = "SELECT feedback_id, title, content, handling_department, created_at FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in createNotification (prepare): " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in createNotification: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        $feedback_code = $feedback['feedback_id'];
        $feedback_title = $feedback['title'];
        $feedback_content = $feedback['content'];
        $department = $feedback['handling_department'];
        $created_at = formatDate($feedback['created_at']);
        $user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $user_id);
        $user_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $user_id);
        $sql = "INSERT INTO feedback_response_tb (feedback_id, responder_id, response, notification, is_read) 
                VALUES (?, 'system', '', ?, 0)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in createNotification (insert): " . $db->error);
            return false;
        }
        $stmt->bind_param("is", $feedback_id, $message);
        if (!$stmt->execute()) {
            error_log("Execute Error in createNotification (insert): " . $stmt->error);
            return false;
        }
        if (!empty($user_email)) {
            $sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
            $result = $db->query($sql);
            if (!$result || $result->num_rows == 0) {
                error_log("Mailer configuration not found");
                return false;
            }
            $config = $result->fetch_assoc();
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $config['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['address'];
                $mail->Password = $config['password'];
                $mail->Port = $config['port'];
                $mail->CharSet = 'UTF-8';
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );
                $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
                $mail->addAddress($user_email, $user_name);
                $mail->isHTML(true);
                $mail->Subject = "Thông báo từ hệ thống phản hồi ý kiến";
                $formatted = formatEmailContent($message);
                $mail->Body = $formatted['html'];
                $mail->AltBody = $formatted['text'];
                $mail->send();
                error_log("Email notification sent to user: " . $user_id);
                return true;
            } catch (Exception $e) {
                error_log("Email sending failed: " . $mail->ErrorInfo);
                $notifications_dir = "email_notifications";
                if (!file_exists($notifications_dir)) {
                    mkdir($notifications_dir, 0755, true);
                }
                $notification_file = "{$notifications_dir}/{$user_id}.txt";
                file_put_contents($notification_file, $message . "\n\n---\n\n", FILE_APPEND);
                error_log("Email notification saved to file: " . $notification_file);
            }
        }
        return true;
    }
    return false;
}

/**
 * Create a notification for anonymous feedback
 * @param int $feedback_id The feedback ID
 * @param string $message The notification message
 * @param bool $has_attachment Whether the notification has attachments
 * @return bool True if successful, false otherwise
 */
function createAnonymousNotification($feedback_id, $message, $has_attachment = false) {
    global $db;
    $sql = "SELECT handling_department, feedback_id, title, content, anonymous_code FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in createAnonymousNotification (first prepare): " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in createAnonymousNotification: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        $sql = "INSERT INTO feedback_response_tb (feedback_id, responder_id, response, notification, is_read) 
                VALUES (?, 'system', '', ?, 0)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in createAnonymousNotification (insert prepare): " . $db->error);
            return false;
        }
        $stmt->bind_param("is", $feedback_id, $message);
        if (!$stmt->execute()) {
            error_log("Execute Error in createAnonymousNotification (insert): " . $stmt->error);
            return false;
        }
        return true;
    }
    return false;
}

/**
 * Notify department about feedback
 * @param string $department The department name
 * @param int $feedback_id The feedback ID
 * @param string $message The notification message
 * @param bool $has_attachment Whether the notification has attachments
 * @return bool True if successful, false otherwise
 */
function notifyDepartmentAboutFeedback($department, $feedback_id, $message, $has_attachment = false) {
    global $db;
    $sql = "SELECT feedback_id, title FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in notifyDepartmentAboutFeedback (first prepare): " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in notifyDepartmentAboutFeedback: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        $feedback_code = $feedback['feedback_id'];
        $feedback_title = $feedback['title'];
        $sql = "INSERT INTO feedback_response_tb (feedback_id, responder_id, response, notification, is_read) 
                VALUES (?, 'system', '', ?, 0)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in notifyDepartmentAboutFeedback (insert prepare): " . $db->error);
            return false;
        }
        $stmt->bind_param("is", $feedback_id, $message);
        if (!$stmt->execute()) {
            error_log("Execute Error in notifyDepartmentAboutFeedback (insert): " . $stmt->error);
            return false;
        }
        $subject = "Thông báo về ý kiến #{$feedback_code}: {$feedback_title}";
        $emails = getDepartmentEmails($department);
        if (empty($emails)) {
            error_log("No email addresses found for department: $department");
            return false;
        }
        return sendDepartmentEmailListNotificationWithAttachments($emails, $subject, $message);
    }
    return false;
}

/**
 * Send email notification to department email list with optional attachments
 * @param array $emails Array of email addresses
 * @param string $subject The email subject
 * @param string $message The email message
 * @param array $attachments Array of attachments (optional)
 * @return bool True if successful, false otherwise
 */
function sendDepartmentEmailListNotificationWithAttachments($emails, $subject, $message, $attachments = []) {
    global $db;
    if (empty($emails)) {
        error_log("No email addresses provided");
        return false;
    }

    // Retrieve mailer configuration
    $sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
    $result = $db->query($sql);
    if (!$result || $result->num_rows == 0) {
        error_log("Mailer configuration not found");
        return false;
    }
    $config = $result->fetch_assoc();

    $mail = new PHPMailer(true);
    try {
        // Configure PHPMailer
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

        // Set sender
        $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');

        // Add recipients
        foreach ($emails as $email) {
            $email = trim($email);
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($email);
                error_log("Added recipient: " . $email);
            }
        }

        // If no valid recipients, return false
        if (empty($mail->getToAddresses())) {
            error_log("No valid email addresses to send");
            return false;
        }

        // Set email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $formatted = formatEmailContent($message);
        $mail->Body = $formatted['html'];
        $mail->AltBody = $formatted['text'];

        // Add attachments if provided
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
        error_log("Email notification sent to: " . implode(", ", $emails));
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        // Save to file as fallback
        $notifications_dir = "email_notifications";
        if (!file_exists($notifications_dir)) {
            mkdir($notifications_dir, 0755, true);
        }
        $notification_file = "$notifications_dir/email_" . time() . ".txt";
        file_put_contents($notification_file, "Subject: $subject\nTo: " . implode(", ", $emails) . "\nMessage: $message\nError: " . $mail->ErrorInfo . "\n\n---\n\n", FILE_APPEND);
        error_log("Email notification saved to file: $notification_file");
        return false;
    }
}

/**
 * Send feedback status notification
 * @param int $feedback_id The feedback ID
 * @param int $new_status The new status code
 * @return bool True if successful, false otherwise
 */
if (!function_exists('sendFeedbackStatusNotification')) {
    function sendFeedbackStatusNotification($feedback_id, $new_status) {
        global $db;
        $sql = "SELECT f.*, fr.response, fr.created_at as response_time, fr.id as response_id 
                FROM feedback_tb f 
                LEFT JOIN feedback_response_tb fr ON f.id = fr.feedback_id 
                WHERE f.id = ? 
                ORDER BY fr.created_at DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in sendFeedbackStatusNotification: " . $db->error);
            return false;
        }
        $stmt->bind_param("i", $feedback_id);
        if (!$stmt->execute()) {
            error_log("Execute Error in sendFeedbackStatusNotification: " . $stmt->error);
            return false;
        }
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $feedback = $result->fetch_assoc();
            $feedback_code = $feedback['feedback_id'];
            $feedback_title = $feedback['title'];
            $feedback_content = $feedback['content'];
            $owner_id = $feedback['staff_id'];
            $department = $feedback['handling_department'];
            $is_anonymous = $feedback['is_anonymous'];
            $anonymous_code = $feedback['anonymous_code'];
            $created_at = formatDate($feedback['created_at']);
            $response_text = $feedback['response'];
            $response_time = isset($feedback['response_time']) ? formatDate($feedback['response_time']) : '';
            $response_id = $feedback['response_id'];
            $sender_name = "";
            $sender_dept = "";
            if (!$is_anonymous && $owner_id) {
                $sender_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $owner_id);
                $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $owner_id);
            }
            $status_text = getStatusText($new_status);
            $rating_info = "";
            if ($new_status == 3) {
                $sql = "SELECT rating, comment FROM feedback_rating_tb WHERE feedback_id = ?";
                $stmt = $db->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $feedback_id);
                    $stmt->execute();
                    $rating_result = $stmt->get_result();
                    if ($rating_result && $rating_result->num_rows > 0) {
                        $rating = $rating_result->fetch_assoc();
                        $rating_info = "\nKết quả đánh giá phản hồi: " . $rating['rating'] . " sao";
                        if (!empty($rating['comment'])) {
                            $rating_info .= "\nNhận xét: " . $rating['comment'];
                        }
                    }
                }
            }
            $response_attachments = [];
            if ($response_id) {
                $response_attachments = getResponseAttachments($response_id);
            }
            if ($new_status == 1) {
                $message = "Thông báo: Ý kiến của bạn hiện đang ở trạng thái Chờ xử lý. Chúng tôi đã ghi nhận và sẽ xem xét trong thời gian sớm nhất. Cảm ơn bạn đã đóng góp!";
                $message .= "\n[" . date('d/m/Y H:i') . "]";
                $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
                $message .= "\nTiêu đề: {$feedback_title}";
                $message .= "\nNội dung: {$feedback_content}";
                $message .= "\nBộ phận xử lý: {$department}";
                if (!empty($feedback['image_path'])) {
                    $message .= "\nĐính kèm: Có file đính kèm";
                }
            } else if ($new_status == 3) {
                $message = "Thông báo: Phản hồi đã được hoàn tất. Cảm ơn bạn đã theo dõi và xử lý phản hồi một cách kịp thời.";
                $message .= "\nTiêu đề: {$feedback_title}";
                $message .= "\nNgày gửi: {$created_at}";
                $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
                $message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
                $message .= "\nBộ phận xử lý: {$department}";
                $message .= "\nNội dung: {$feedback_content}";
                if (!empty($feedback['image_path'])) {
                    $message .= "\nĐính kèm: Có file đính kèm";
                }
                if (!empty($response_text)) {
                    $message .= "\nPhản hồi:";
                    $message .= "\n+ Thời gian: {$response_time}";
                    $message .= "\n+ Nội dung: {$response_text}";
                    if (!empty($response_attachments)) {
                        $message .= "\nĐính kèm: ";
                        foreach ($response_attachments as $index => $attachment) {
                            if ($index > 0) $message .= ", ";
                            $message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
                        }
                    }
                }
                $message .= $rating_info;
            } else {
                $message = "Trạng thái của ý kiến \"{$feedback_title}\" đã được thay đổi thành \"{$status_text}\"";
            }
            if ($is_anonymous) {
                createAnonymousNotification($feedback_id, $message);
            } else if ($owner_id) {
                createNotification($owner_id, $feedback_id, $message);
            }
            $sql = "SELECT DISTINCT u.staff_id FROM user_tb u WHERE u.department = ? AND u.staff_id != ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ss", $department, $owner_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    createNotification($row['staff_id'], $feedback_id, $message);
                }
            }
            return true;
        }
        return false;
    }
}

/**
 * Mark a notification as read
 * @param int $notification_id The notification ID
 * @return bool True if successful, false otherwise
 */
function markNotificationAsRead($notification_id) {
    global $db;
    $sql = "UPDATE feedback_response_tb SET is_read = 1 WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in markNotificationAsRead: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $notification_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in markNotificationAsRead: " . $stmt->error);
        return false;
    }
    return true;
}

/**
 * Mark all notifications as read for a user
 * @param string $user_id The user ID
 * @return bool True if successful, false otherwise
 */
function markAllNotificationsAsRead($user_id) {
    global $db;
    $sql = "SELECT id FROM feedback_tb WHERE staff_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in markAllNotificationsAsRead (first prepare): " . $db->error);
        return false;
    }
    $stmt->bind_param("s", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in markAllNotificationsAsRead: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    $feedback_ids = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $feedback_ids[] = $row['id'];
        }
    }
    $user_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $user_id);
    $sql = "SELECT id FROM feedback_tb WHERE handling_department = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in markAllNotificationsAsRead (second prepare): " . $db->error);
        return false;
    }
    $stmt->bind_param("s", $user_department);
    if (!$stmt->execute()) {
        error_log("Execute Error in markAllNotificationsAsRead (second execute): " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $feedback_ids[] = $row['id'];
        }
    }
    if (!empty($feedback_ids)) {
        $feedback_ids_str = implode(',', $feedback_ids);
        $sql = "UPDATE feedback_response_tb SET is_read = 1 WHERE feedback_id IN ({$feedback_ids_str})";
        $db->query($sql);
        return true;
    }
    return false;
}

/**
 * Mark all notifications related to a specific feedback as read
 * @param int $feedback_id The feedback ID
 * @param string $user_id The user ID
 * @return bool True if successful, false otherwise
 */
function markFeedbackNotificationsAsRead($feedback_id, $user_id) {
    global $db;
    ensureFeedbackViewedTableExists();
    $sql = "UPDATE feedback_response_tb SET is_read = 1 WHERE feedback_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in markFeedbackNotificationsAsRead: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in markFeedbackNotificationsAsRead: " . $stmt->error);
        return false;
    }
    $current_time = date('Y-m-d H:i:s');
    $sql = "SELECT * FROM feedback_viewed_tb WHERE feedback_id = ? AND user_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in markFeedbackNotificationsAsRead (select): " . $db->error);
        return false;
    }
    $stmt->bind_param("is", $feedback_id, $user_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in markFeedbackNotificationsAsRead (select): " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $sql = "UPDATE feedback_viewed_tb SET viewed_at = ? WHERE feedback_id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in markFeedbackNotificationsAsRead (update): " . $db->error);
            return false;
        }
        $stmt->bind_param("sis", $current_time, $feedback_id, $user_id);
    } else {
        $sql = "INSERT INTO feedback_viewed_tb (feedback_id, user_id, viewed_at) VALUES (?, ?, ?)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in markFeedbackNotificationsAsRead (insert): " . $db->error);
            return false;
        }
        $stmt->bind_param("iss", $feedback_id, $user_id, $current_time);
    }
    if (!$stmt->execute()) {
        error_log("Execute Error in markFeedbackNotificationsAsRead (final): " . $stmt->error);
        return false;
    }
    return true;
}

/**
 * Ensure feedback_viewed_tb table exists
 */
function ensureFeedbackViewedTableExists() {
    global $db;
    $check_table = $db->query("SHOW TABLES LIKE 'feedback_viewed_tb'");
    if ($check_table && $check_table->num_rows == 0) {
        $create_table = "CREATE TABLE IF NOT EXISTS feedback_viewed_tb (
            id INT AUTO_INCREMENT PRIMARY KEY,
            feedback_id INT NOT NULL,
            user_id VARCHAR(20) NOT NULL,
            viewed_at DATETIME NOT NULL,
            INDEX (feedback_id, user_id),
            FOREIGN KEY (feedback_id) REFERENCES feedback_tb(id) ON DELETE CASCADE ON UPDATE CASCADE
        )";
        $db->query($create_table);
    }
}

/**
 * Send email notification about feedback deletion
 * @param int $feedback_id The feedback ID
 * @param string $mysql_user The user ID
 * @return bool True if successful, false otherwise
 */
function sendFeedbackDeletionNotification($feedback_id, $mysql_user) {
    global $db;
    $sql = "SELECT feedback_id, title, content, handling_department, is_anonymous FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in sendFeedbackDeletionNotification: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in sendFeedbackDeletionNotification: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        $feedback_code = $feedback['feedback_id'];
        $feedback_title = $feedback['title'];
        $feedback_content = $feedback['content'];
        $department = $feedback['handling_department'];
        $is_anonymous = $feedback['is_anonymous'];
        $user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $mysql_user);
        $user_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $mysql_user);
        $current_time = date('d/m/Y H:i');
        $message = "[{$current_time}] Thông báo ý kiến: Ý Kiến này đã bị xóa bởi ";
        $message .= $is_anonymous ? "người gửi ẩn danh" : "{$mysql_user} - {$user_name}";
        $message .= "\nTiêu đề: {$feedback_title}";
        $message .= "\nNội dung: {$feedback_content}";
        $message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $user_department);
        $message .= "\nBộ phận xử lý: {$department}";
        notifyDepartmentAboutFeedback($department, $feedback_id, $message);
        return true;
    }
    return false;
}

/**
 * Send new response notification
 * @param int $feedback_id The feedback ID
 * @param string $responder_id The responder ID
 * @param bool $has_attachment Whether the response has attachments
 * @param array $attachments Array of attachments
 * @return bool True if successful, false otherwise
 */
function sendNewResponseNotification($feedback_id, $responder_id, $has_attachment = false, $attachments = []) {
    global $db;
    $sql = "SELECT f.*, fr.response, fr.created_at as response_time 
            FROM feedback_tb f 
            LEFT JOIN feedback_response_tb fr ON f.id = fr.feedback_id 
            WHERE f.id = ? 
            ORDER BY fr.created_at DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in sendNewResponseNotification: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in sendNewResponseNotification: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        $feedback_code = $feedback['feedback_id'];
        $feedback_title = $feedback['title'];
        $feedback_content = $feedback['content'];
        $owner_id = $feedback['staff_id'];
        $department = $feedback['handling_department'];
        $is_anonymous = $feedback['is_anonymous'];
        $anonymous_code = $feedback['anonymous_code'];
        $created_at = formatDate($feedback['created_at']);
        $response_text = $feedback['response'];
        $response_time = isset($feedback['response_time']) ? formatDate($feedback['response_time']) : date('d/m/Y H:i');
        $responder_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $responder_id);
        $responder_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $responder_id);
        $sender_name = "";
        $sender_dept = "";
        if (!$is_anonymous && $owner_id) {
            $sender_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $owner_id);
            $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $owner_id);
        }
        $dept_message = "Thông báo: Bạn đã nhận được một phản hồi mới từ {$responder_name}. Vui lòng kiểm tra để cập nhật thông tin kịp thời.";
        $dept_message .= "\nTiêu đề: {$feedback_title}";
        $dept_message .= "\nNgày gửi: {$created_at}";
        $dept_message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
        $dept_message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
        $dept_message .= "\nBộ phận xử lý: {$department}";
        $dept_message .= "\nNội dung: {$feedback_content}";
        if (!empty($feedback['image_path'])) {
            $dept_message .= "\nĐính kèm: Có file đính kèm";
        }
        $dept_message .= "\nPhản hồi:";
        $dept_message .= "\n+ Thời gian: {$response_time}";
        $dept_message .= "\n+ Nội dung: {$response_text}";
        if (!empty($attachments)) {
            $dept_message .= "\nĐính kèm: ";
            foreach ($attachments as $index => $attachment) {
                if ($index > 0) $dept_message .= ", ";
                $dept_message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
            }
        }
        $user_message = "Thông báo: Bạn đã nhận được một phản hồi mới từ {$responder_name}. Vui lòng kiểm tra để cập nhật thông tin kịp thời.";
        $user_message .= "\nTiêu đề: {$feedback_title}";
        $user_message .= "\nNgày gửi: {$created_at}";
        $user_message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
        $user_message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
        $user_message .= "\nBộ phận xử lý: {$department}";
        $user_message .= "\nNội dung: {$feedback_content}";
        if (!empty($feedback['image_path'])) {
            $user_message .= "\nĐính kèm: Có file đính kèm";
        }
        $user_message .= "\nPhản hồi:";
        $user_message .= "\n+ Thời gian: {$response_time}";
        $user_message .= "\n+ Nội dung: {$response_text}";
        if (!empty($attachments)) {
            $user_message .= "\nĐính kèm: ";
            foreach ($attachments as $index => $attachment) {
                if ($index > 0) $user_message .= ", ";
                $user_message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
            }
        }
        if ($responder_id == $owner_id) {
            sendDepartmentEmailListNotificationWithAttachments($emails, "Phản hồi mới cho ý kiến #{$feedback_code}", $dept_message, $attachments);
        } else {
            if ($is_anonymous) {
                createAnonymousNotification($feedback_id, $user_message, $has_attachment);
            } else if ($owner_id) {
                createNotification($owner_id, $feedback_id, $user_message, $has_attachment);
            }
        }
        return true;
    }
    return false;
}

// === File Handling Functions ===

/**
 * Handle single file upload
 * @param array $file The uploaded file
 * @param string $upload_dir The upload directory
 * @return array Upload result
 */
function handleFileUpload($file, $upload_dir = "Uploads/") {
    if (!isset($file) || !is_array($file) || empty($file['name']) || $file['error'] != 0 || $file['size'] <= 0) {
        return ['success' => false, 'message' => 'Invalid file upload'];
    }
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $file_name = time() . '_' . basename($file['name']);
    $upload_path = $upload_dir . $file_name;
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return [
            'success' => true,
            'file_path' => $upload_path,
            'file_name' => $file_name,
            'file_type' => $file['type'],
            'file_size' => $file['size']
        ];
    }
    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

/**
 * Handle multiple file uploads
 * @param array $files The uploaded files
 * @param string $upload_dir The upload directory
 * @return array Array of upload results
 */
function handleMultipleFileUploads($files, $upload_dir = "Uploads/") {
    $uploaded_files = [];
    if (!isset($files) || !is_array($files) || !isset($files['name']) || empty($files['name'][0])) {
        return $uploaded_files;
    }
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    foreach ($files['name'] as $key => $name) {
        if ($files['error'][$key] !== 0 || $files['size'][$key] <= 0) {
            continue;
        }
        $file = [
            'name' => $files['name'][$key],
            'type' => $files['type'][$key],
            'tmp_name' => $files['tmp_name'][$key],
            'error' => $files['error'][$key],
            'size' => $files['size'][$key]
        ];
        $file_name = time() . '_' . $key . '_' . basename($file['name']);
        $upload_path = $upload_dir . $file_name;
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            $uploaded_files[] = [
                'success' => true,
                'file_path' => $upload_path,
                'file_name' => $file_name,
                'original_name' => $file['name'],
                'file_type' => $file['type'],
                'file_size' => $file['size']
            ];
        }
    }
    return $uploaded_files;
}

/**
 * Get response attachments
 * @param int $response_id The response ID
 * @return array Array of attachments
 */
function getResponseAttachments($response_id) {
    global $db;
    $attachments = [];
    error_log("Looking for attachments for response ID: " . $response_id);
    $sql = "SELECT * FROM attachment_tb WHERE reference_id = ? AND reference_type = 'response'";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in getResponseAttachments: " . $db->error);
        return [];
    }
    $stmt->bind_param("i", $response_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in getResponseAttachments: " . $stmt->error);
        return [];
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            error_log("Found attachment: " . print_r($row, true));
            $attachments[] = [
                'file_path' => $row['file_path'],
                'file_name' => $row['file_name'],
                'file_type' => $row['file_type'],
                'file_size' => $row['file_size']
            ];
        }
    }
    error_log("Found " . count($attachments) . " attachments for response ID: " . $response_id);
    return $attachments;
}

/**
 * Get feedback attachments
 * @param int $feedback_id The feedback ID
 * @return array Array of attachments
 */
function getFeedbackAttachments($feedback_id) {
    global $db;
    $attachments = [];
    $sql = "SELECT * FROM attachment_tb WHERE reference_id = ? AND reference_type = 'feedback'";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in getFeedbackAttachments: " . $db->error);
        return [];
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in getFeedbackAttachments: " . $stmt->error);
        return [];
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $attachments[] = $row;
        }
    }
    return $attachments;
}

/**
 * Check if file exists and is readable
 * @param string $file_path The file path
 * @return bool True if accessible, false otherwise
 */
function isFileAccessible($file_path) {
    return file_exists($file_path) && is_readable($file_path);
}

/**
 * Get absolute URL for a file
 * @param string $file_path The file path
 * @return string The absolute URL
 */
function getFileUrl($file_path) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $base_url = $protocol . $host;
    if (strpos($file_path, '/') === 0) {
        return $base_url . $file_path;
    } else {
        $current_dir = dirname($_SERVER['PHP_SELF']);
        return $base_url . $current_dir . '/' . $file_path;
    }
}

/**
 * Get anonymous feedback by code
 * @param string $code The anonymous code
 * @return array|null Feedback data or null if not found
 */
function getAnonymousFeedbackByCode($code) {
    global $db;
    $sql = "SELECT * FROM feedback_tb WHERE anonymous_code = ? AND is_anonymous = 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in getAnonymousFeedbackByCode: " . $db->error);
        return null;
    }
    $stmt->bind_param("s", $code);
    if (!$stmt->execute()) {
        error_log("Execute Error in getAnonymousFeedbackByCode: " . $stmt->error);
        return null;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

/**
 * Get department emails
 * @param string $department The department name
 * @return array Array of email addresses
 */
function getDepartmentEmails($department) {
    global $db;
    $sql = "SELECT email_list FROM handling_department_tb WHERE department_name = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in getDepartmentEmails: " . $db->error);
        return [];
    }
    $stmt->bind_param("s", $department);
    if (!$stmt->execute()) {
        error_log("Execute Error in getDepartmentEmails: " . $stmt->error);
        return [];
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return explode(',', $row['email_list']);
    }
    return [];
}

/**
 * Update last viewed timestamp for a feedback
 * @param int $feedback_id The feedback ID
 * @param string $user_id The user ID
 * @return bool True if successful, false otherwise
 */
function updateLastViewed($feedback_id, $user_id) {
    global $db;
    $current_time = date('Y-m-d H:i:s');
    ensureFeedbackViewedTableExists();
    $sql = "SELECT id FROM feedback_viewed_tb WHERE feedback_id = ? AND user_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in updateLastViewed (select): " . $db->error);
        return false;
    }
    $stmt->bind_param("is", $feedback_id, $user_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in updateLastViewed (select): " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $sql = "UPDATE feedback_viewed_tb SET viewed_at = ? WHERE feedback_id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in updateLastViewed (update): " . $db->error);
            return false;
        }
        $stmt->bind_param("sis", $current_time, $feedback_id, $user_id);
    } else {
        $sql = "INSERT INTO feedback_viewed_tb (feedback_id, user_id, viewed_at) VALUES (?, ?, ?)";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in updateLastViewed (insert): " . $db->error);
            return false;
        }
        $stmt->bind_param("iss", $feedback_id, $user_id, $current_time);
    }
    if (!$stmt->execute()) {
        error_log("Execute Error in updateLastViewed (execute): " . $stmt->error);
        return false;
    }
    markFeedbackNotificationsAsRead($feedback_id, $user_id);
    return true;
}

/**
 * Process and save a new response to feedback
 * @param int $feedback_id The feedback ID
 * @param string $responder_id The responder ID
 * @param string $response_text The response text
 * @param array $attachments Array of attachments
 * @return bool True if successful, false otherwise
 */
function saveAndNotifyNewResponse($feedback_id, $responder_id, $response_text, $attachments = []) {
    global $db;
    $sql = "INSERT INTO feedback_response_tb (feedback_id, responder_id, response) VALUES (?, ?, ?)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in saveAndNotifyNewResponse: " . $db->error);
        return false;
    }
    $stmt->bind_param("iss", $feedback_id, $responder_id, $response_text);
    if (!$stmt->execute()) {
        error_log("Execute Error in saveAndNotifyNewResponse: " . $stmt->error);
        return false;
    }
    $response_id = $db->insert_id;
    if (!empty($attachments)) {
        error_log("Processing " . count($attachments) . " attachments");
        foreach ($attachments as $attachment) {
            error_log("Processing attachment: " . print_r($attachment, true));
            if (!isset($attachment['name']) || !isset($attachment['path']) || 
                !isset($attachment['type']) || !isset($attachment['size'])) {
                error_log("Attachment missing required fields");
                continue;
            }
            $file_name = $attachment['name'];
            $file_path = $attachment['path'];
            $file_type = $attachment['type'];
            $file_size = $attachment['size'];
            $sql = "INSERT INTO attachment_tb (reference_id, reference_type, file_name, file_path, file_type, file_size) 
                    VALUES (?, 'response', ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                error_log("SQL Error in saveAndNotifyNewResponse (attachment): " . $db->error);
                continue;
            }
            $stmt->bind_param("issis", $response_id, $file_name, $file_path, $file_type, $file_size);
            if (!$stmt->execute()) {
                error_log("Execute Error in saveAndNotifyNewResponse (attachment): " . $stmt->error);
                error_log("SQL Error: " . $stmt->error);
            } else {
                error_log("Successfully inserted attachment with ID: " . $db->insert_id);
            }
        }
    }
    $sql = "SELECT * FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $feedback_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        if ($feedback['status'] == 1) {
            $sql = "UPDATE feedback_tb SET status = 2 WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $feedback_id);
            $stmt->execute();
            sendFeedbackStatusNotification($feedback_id, 2);
        }
        $has_attachments = !empty($attachments);
        sendNewResponseNotification($feedback_id, $responder_id, $has_attachments, $attachments);
        return true;
    }
    return false;
}

// === File Utility Functions ===

/**
 * Get file icon class based on extension
 * @param string $extension The file extension
 * @return string The icon class
 */
function getFileIconClass($extension) {
    $extension = strtolower($extension);
    switch ($extension) {
        case 'pdf':
            return 'pdf';
        case 'doc':
        case 'docx':
            return 'word';
        case 'xls':
        case 'xlsx':
            return 'excel';
        case 'ppt':
        case 'pptx':
            return 'powerpoint';
        case 'txt':
        case 'rtf':
            return 'alt';
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
            return 'image';
        default:
            return 'file';
    }
}

/**
 * Format file size
 * @param int $bytes The file size in bytes
 * @return string Formatted file size
 */
function formatFileSize($bytes) {
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } else {
        return round($bytes / 1048576, 2) . ' MB';
    }
}

/**
 * Get file MIME type
 * @param string $file_path The file path
 * @return string The MIME type
 */
function getFileMimeType($file_path) {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);
        return $mime_type;
    }
    if (function_exists('mime_content_type')) {
        return mime_content_type($file_path);
    }
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'rtf' => 'application/rtf'
    ];
    return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
}

// === Message Counting Functions ===

/**
 * Count unread messages for a feedback
 * @param int $feedback_id The feedback ID
 * @param string $user_id The user ID
 * @return int Number of unread messages
 */
function countUnreadMessages($feedback_id, $user_id) {
    global $db;
    ensureFeedbackViewedTableExists();
    $sql = "SELECT viewed_at FROM feedback_viewed_tb WHERE feedback_id = ? AND user_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in countUnreadMessages (select): " . $db->error);
        return 0;
    }
    $stmt->bind_param("is", $feedback_id, $user_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in countUnreadMessages (select): " . $stmt->error);
        return 0;
    }
    $result = $stmt->get_result();
    $last_viewed = null;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_viewed = $row['viewed_at'];
    }
    if ($last_viewed === null) {
        $sql = "SELECT COUNT(*) as count FROM feedback_response_tb WHERE feedback_id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in countUnreadMessages (count all): " . $db->error);
            return 0;
        }
        $stmt->bind_param("i", $feedback_id);
    } else {
        $sql = "SELECT COUNT(*) as count FROM feedback_response_tb WHERE feedback_id = ? AND created_at > ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in countUnreadMessages (count new): " . $db->error);
            return 0;
        }
        $stmt->bind_param("is", $feedback_id, $last_viewed);
    }
    if (!$stmt->execute()) {
        error_log("Execute Error in countUnreadMessages (count): " . $stmt->error);
        return 0;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['count'];
    }
    return 0;
}

/**
 * Count total unread messages for a user
 * @param string $user_id The user ID
 * @return int Total number of unread messages
 */
function countTotalUnreadMessages($user_id) {
    global $db;
    $sql = "SELECT id FROM feedback_tb WHERE staff_id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in countTotalUnreadMessages (first prepare): " . $db->error);
        return 0;
    }
    $stmt->bind_param("s", $user_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in countTotalUnreadMessages: " . $db->error);
        return 0;
    }
    $result = $stmt->get_result();
    $feedback_ids = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $feedback_ids[] = $row['id'];
        }
    }
    $user_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $user_id);
    $sql = "SELECT id FROM feedback_tb WHERE handling_department = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in countTotalUnreadMessages (second prepare): " . $db->error);
        return 0;
    }
    $stmt->bind_param("s", $user_department);
    if (!$stmt->execute()) {
        error_log("Execute Error in countTotalUnreadMessages (second execute): " . $stmt->error);
        return 0;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if (!in_array($row['id'], $feedback_ids)) {
                $feedback_ids[] = $row['id'];
            }
        }
    }
    $total_unread = 0;
    foreach ($feedback_ids as $feedback_id) {
        $total_unread += countUnreadMessages($feedback_id, $user_id);
    }
    return $total_unread;
}

// === Feedback Permission Functions ===

/**
 * Check if user can delete feedback
 * @param array $feedback The feedback data
 * @param string $user_id The user ID
 * @return bool True if user can delete, false otherwise
 */
function canUserDeleteFeedback($feedback, $user_id) {
    if ($feedback['status'] != 1) {
        return false;
    }
    if ($feedback['is_anonymous'] == 0) {
        return $feedback['staff_id'] == $user_id;
    }
    if (isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) {
        return true;
    }
    return false;
}

/**
 * Save anonymous feedback user information
 * @param int $feedback_id The feedback ID
 * @param string $anonymous_code The anonymous code
 * @param string $user_id The user ID
 * @return bool True if successful, false otherwise
 */
function saveAnonymousFeedbackUser($feedback_id, $anonymous_code, $user_id) {
    if (!isset($_SESSION['created_anonymous_feedbacks'])) {
        $_SESSION['created_anonymous_feedbacks'] = [];
    }
    $_SESSION['created_anonymous_feedbacks'][$feedback_id] = [
        'anonymous_code' => $anonymous_code,
        'user_id' => $user_id
    ];
    return true;
}

/**
 * Check if user is the creator of anonymous feedback
 * @param int $feedback_id The feedback ID
 * @param string $user_id The user ID
 * @return bool True if user is creator, false otherwise
 */
function isAnonymousFeedbackCreator($feedback_id, $user_id) {
    if (!isset($_SESSION['created_anonymous_feedbacks']) || 
        !isset($_SESSION['created_anonymous_feedbacks'][$feedback_id])) {
        return false;
    }
    return $_SESSION['created_anonymous_feedbacks'][$feedback_id]['user_id'] === $user_id;
}

/**
 * Check if user is in handling department of their own anonymous feedback
 * @param int $feedback_id The feedback ID
 * @param string $user_id The user ID
 * @param string $department The department name
 * @return bool True if user is in handling department, false otherwise
 */
function isAnonymousFeedbackCreatorInHandlingDepartment($feedback_id, $user_id, $department) {
    global $db;
    if (!isAnonymousFeedbackCreator($feedback_id, $user_id)) {
        return false;
    }
    $sql = "SELECT handling_department FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in isAnonymousFeedbackCreatorInHandlingDepartment: " . $db->error);
        return false;
    }
    $stmt->bind_param("i", $feedback_id);
    if (!$stmt->execute()) {
        error_log("Execute Error in isAnonymousFeedbackCreatorInHandlingDepartment: " . $stmt->error);
        return false;
    }
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['handling_department'] === $department;
    }
    return false;
}
?>
