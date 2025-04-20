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
* Function to send email notification by saving to text file
* 
* @param string $staff_id Staff ID of the recipient
* @param string $name Name of the recipient
* @param string $subject Subject of the notification
* @param string $message Content of the notification
* @return bool True if notification was saved successfully
*/
function sendEmailNotification($staff_id, $name, $subject, $message) {
   // Create email_notifications directory if it doesn't exist
   $dir = "email_notifications";
   if (!file_exists($dir)) {
       mkdir($dir, 0755, true);
   }
   
   // Format the filename: V7344-Ngotruongvu.txt
   // Xử lý tên file để tránh lỗi encoding
   $sanitized_name = sanitizeFilename($name);
   $filename = $dir . "/" . $staff_id . "-" . $sanitized_name . ".txt";
   
   // Format the email content with UTF-8 encoding
   $content = "Date: " . date("Y-m-d H:i:s") . "\n";
   $content .= "Subject: " . $subject . "\n";
   $content .= "To: " . $name . " (" . $staff_id . ")\n";
   $content .= "-----------------------------------\n\n";
   $content .= $message . "\n\n";
   $content .= "-----------------------------------\n";
   
   // Append to the file (create if doesn't exist) with UTF-8 encoding
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
   
   // Get all users in the department
   $sql = "SELECT staff_id, name FROM user_tb WHERE department = ?";
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
           $sent = sendEmailNotification($user['staff_id'], $user['name'], $subject, $message) || $sent;
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
* @return bool True if notification was saved successfully
*/
function sendDepartmentEmailListNotification($department, $subject, $message) {
   global $db;
   
   // Get email list for the department
   $sql = "SELECT email_list FROM handling_department_tb WHERE department_name = ?";
   $stmt = $db->prepare($sql);
   
   if (!$stmt) {
       return false;
   }
   
   $stmt->bind_param("s", $department);
   $stmt->execute();
   $result = $stmt->get_result();
   
   if ($result && $result->num_rows > 0) {
       $row = $result->fetch_assoc();
       $email_list = $row['email_list'];
       
       // Create email_notifications directory if it doesn't exist
       $dir = "email_notifications";
       if (!file_exists($dir)) {
           mkdir($dir, 0755, true);
       }
       
       // Format the filename: Department_Notification_YYYYMMDD_HHMMSS.txt
       $sanitized_dept = sanitizeFilename($department);
       $filename = $dir . "/" . $sanitized_dept . "_Notification_" . date("Ymd_His") . ".txt";
       
       // Format the email content with UTF-8 encoding
       $content = "Date: " . date("Y-m-d H:i:s") . "\n";
       $content .= "Subject: " . $subject . "\n";
       $content .= "To: " . $email_list . "\n";
       $content .= "Department: " . $department . "\n";
       $content .= "-----------------------------------\n\n";
       $content .= $message . "\n\n";
       $content .= "-----------------------------------\n";
       
       // Write to the file with UTF-8 encoding
       $result = file_put_contents($filename, $content);
       
       return ($result !== false);
   }
   
   return false;
}

/**
* Function to notify about feedback deletion
* 
* @param int $feedback_id ID of the feedback
* @param string $feedback_code Feedback code (e.g., FB-12345)
* @param string $title Feedback title
* @param string $department Handling department
* @param bool $is_anonymous Whether the feedback was anonymous
* @return bool True if notification was sent
*/
function notifyFeedbackDeletion($feedback_id, $feedback_code, $title, $content, $department, $is_anonymous, $mysql_user = null, $user_name = null) {
  $current_time = date('d/m/Y H:i');
  $subject = "Thông báo xóa ý kiến";
  
  $message = "[{$current_time}] Thông báo ý kiến: Ý Kiến này đã bị xóa bởi ";
  $message .= $is_anonymous ? "người gửi ẩn danh" : "{$mysql_user} - {$user_name}";
  $message .= "\nTiêu đề: {$title}";
  $message .= "\nNội dung: {$content}";
  
  // Lấy bộ phận của người gửi nếu không ẩn danh
  $sender_dept = "";
  if (!$is_anonymous && $mysql_user) {
      $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $mysql_user);
      $message .= "\nBộ phận: {$sender_dept}";
  } else {
      $message .= "\nBộ phận: Ẩn danh";
  }
  
  $message .= "\nBộ phận xử lý: {$department}";
  
  // Send to department email list
  return sendDepartmentEmailListNotification($department, $subject, $message);
}

/**
* Function to notify about new feedback response
* 
* @param int $feedback_id ID of the feedback
* @param string $feedback_code Feedback code (e.g., FB-12345)
* @param string $title Feedback title
* @param string $responder_id Staff ID of the responder
* @param string $responder_name Name of the responder
* @param string $response Response content
* @param array $attachments Array of attachment information
* @param string $recipient_id Staff ID of the recipient (if not anonymous)
* @param string $recipient_name Name of the recipient (if not anonymous)
* @param bool $is_anonymous Whether the feedback was anonymous
* @return bool True if notification was sent
*/
function notifyNewResponse($feedback_id, $feedback_code, $title, $responder_id, $responder_name, $response, $attachments = [], $recipient_id = null, $recipient_name = null, $is_anonymous = false, $created_at = null, $content = null, $department = null) {
  $subject = "Thông báo phản hồi mới cho ý kiến {$feedback_code}";
  
  // Lấy thông tin người phản hồi
  $responder_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $responder_id);
  
  // Lấy thông tin người gửi nếu không ẩn danh
  $sender_dept = "";
  if (!$is_anonymous && $recipient_id) {
      $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $recipient_id);
  }
  
  // Định dạng thời gian
  $response_time = date("d/m/Y H:i");
  if (!$created_at) {
      $created_at = date("d/m/Y H:i");
  } else if (is_string($created_at)) {
      $created_at = formatDate($created_at);
  }
  
  $message = "Thông báo: Bạn đã nhận được một phản hồi mới từ {$responder_name}. Vui lòng kiểm tra để cập nhật thông tin kịp thời.";
  $message .= "\nTiêu đề: {$title}";
  $message .= "\nNgày gửi: {$created_at}";
  $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$recipient_id} - {$recipient_name}");
  $message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
  $message .= "\nBộ phận xử lý: {$department}";
  
  if ($content) {
      $message .= "\nNội dung: {$content}";
  }
  
  // Thêm thông tin đính kèm nếu có
  if (!empty($attachments)) {
      $message .= "\nĐính kèm: ";
      foreach ($attachments as $index => $attachment) {
          if ($index > 0) $message .= ", ";
          $message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
      }
  }
  
  $message .= "\nPhản hồi:";
  $message .= "\n+ Thời gian: {$response_time}";
  $message .= "\n+ Nội dung: {$response}";
  
  // Thêm thông tin đính kèm phản hồi nếu có
  if (!empty($attachments)) {
      $message .= "\nĐính kèm: ";
      foreach ($attachments as $index => $attachment) {
          if ($index > 0) $message .= ", ";
          $message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
      }
  }
  
  if ($is_anonymous) {
      // For anonymous feedback, send to department
      return sendDepartmentEmailListNotification($department, $subject, $message);
  } else if ($recipient_id && $recipient_name) {
      // For regular feedback, send to the specific recipient
      return sendEmailNotification($recipient_id, $recipient_name, $subject, $message);
  }
  
  return false;
}

/**
* Function to notify about feedback status change
* 
* @param int $feedback_id ID of the feedback
* @param string $feedback_code Feedback code (e.g., FB-12345)
* @param string $title Feedback title
* @param int $new_status New status code
* @param string $recipient_id Staff ID of the recipient (if not anonymous)
* @param string $recipient_name Name of the recipient (if not anonymous)
* @param bool $is_anonymous Whether the feedback was anonymous
* @return bool True if notification was sent
*/
function notifyStatusChange($feedback_id, $feedback_code, $title, $new_status, $recipient_id = null, $recipient_name = null, $is_anonymous = false, $content = null, $department = null, $created_at = null) {
  $status_text = getStatusText($new_status);
  
  // Lấy thông tin người gửi nếu không ẩn danh
  $sender_dept = "";
  if (!$is_anonymous && $recipient_id) {
      $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $recipient_id);
  }
  
  // Định dạng thời gian
  if (!$created_at) {
      $created_at = date("d/m/Y H:i");
  } else if (is_string($created_at)) {
      $created_at = formatDate($created_at);
  }
  
  // Tạo nội dung thông báo dựa trên trạng thái
  if ($new_status == 1) { // Chờ xử lý
      $subject = "Thông báo ý kiến mới #{$feedback_code}";
      $message = "Thông báo: Ý kiến của bạn hiện đang ở trạng thái Chờ xử lý. Chúng tôi đã ghi nhận và sẽ xem xét trong thời gian sớm nhất. Cảm ơn bạn đã đóng góp!";
      $message .= "\n[" . date('d/m/Y H:i') . "]";
      $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$recipient_id} - {$recipient_name}");
      $message .= "\nTiêu đề: {$title}";
      $message .= "\nNội dung: {$content}";
      $message .= "\nBộ phận xử lý: {$department}";
  } else if ($new_status == 4) { // Kết thúc
      $subject = "Thông báo kết thúc ý kiến #{$feedback_code}";
      $message = "Thông báo: Phản hồi đã được hoàn tất. Cảm ơn bạn đã theo dõi và xử lý phản hồi một cách kịp thời.";
      $message .= "\nTiêu đề: {$title}";
      $message .= "\nNgày gửi: {$created_at}";
      $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$recipient_id} - {$recipient_name}");
      $message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
      $message .= "\nBộ phận xử lý: {$department}";
      $message .= "\nNội dung: {$content}";
      
      // Lấy thông tin đánh giá nếu có
      $rating_info = "";
      $sql = "SELECT rating, comment FROM feedback_rating_tb WHERE feedback_id = ?";
      $stmt = $GLOBALS['db']->prepare($sql);
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
      
      $message .= $rating_info;
  } else {
      $subject = "Thông báo thay đổi trạng thái ý kiến #{$feedback_code}";
      $message = "Trạng thái của ý kiến \"{$title}\" đã được thay đổi thành \"{$status_text}\".";
  }
  
  if ($is_anonymous) {
      // Tạo file thông báo cho feedback ẩn danh
      $notifications_dir = "email_notifications";
      if (!file_exists($notifications_dir)) {
          mkdir($notifications_dir, 0755, true);
      }
      
      $notification_file = "{$notifications_dir}/anonymous_{$feedback_id}.txt";
      file_put_contents($notification_file, $message . "\n\n---\n\n", FILE_APPEND);
      return true;
  } else if ($recipient_id && $recipient_name) {
      // Gửi email cho người nhận cụ thể
      return sendEmailNotification($recipient_id, $recipient_name, $subject, $message);
  }
  
  return false;
}

/**
* Function to notify about feedback status change
* 
* @param int $feedback_id ID of the feedback
* @param string $feedback_code Feedback code (e.g., FB-12345)
* @param string $title Feedback title
* @param int $new_status New status code
* @param string $department Handling department
* @param string $recipient_id Staff ID of the recipient (if not anonymous)
* @param string $recipient_name Name of the recipient (if not anonymous)
* @param bool $is_anonymous Whether the feedback was anonymous
* @param string $anonymous_code Anonymous code (if is_anonymous is true)
* @return bool True if notification was sent
*/
function sendStatusChangeEmail($feedback_id, $feedback_code, $title, $new_status, $department, $recipient_id = null, $recipient_name = null, $is_anonymous = false, $anonymous_code = null, $content = null, $created_at = null) {
  return notifyStatusChange($feedback_id, $feedback_code, $title, $new_status, $recipient_id, $recipient_name, $is_anonymous, $content, $department, $created_at);
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
    
    // Get mailer configuration
    $sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
    $result = $db->query($sql);
    
    if (!$result || $result->num_rows == 0) {
        error_log("Mailer configuration not found");
        return false;
    }
    
    $config = $result->fetch_assoc();
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['address']; // Sử dụng trường address làm username
        $mail->Password   = $config['password'];
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';
        
        // Recipients
        $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);
        
        // Add attachments if any
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['file_path']) && file_exists($attachment['file_path'])) {
                    $mail->addAttachment(
                        $attachment['file_path'],
                        isset($attachment['file_name']) ? $attachment['file_name'] : basename($attachment['file_path'])
                    );
                }
            }
        }
        
        // Send email
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
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
* @return bool True if notification was sent
*/
function sendFeedbackNotificationToDepartment($feedback_id, $department, $subject, $message, $attachments = []) {
    global $db;
    
    // Get department email
    $sql = "SELECT email FROM handling_department_tb WHERE department_name = ?";
    $stmt = $db->prepare($sql);
    
    if (!$stmt) {
        error_log("SQL Error in sendFeedbackNotificationToDepartment: " . $db->error);
        return false;
    }
    
    $stmt->bind_param("s", $department);
    if (!$stmt->execute()) {
        error_log("Execute Error in sendFeedbackNotificationToDepartment: " . $stmt->error);
        return false;
    }
    
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $department_email = $row['email'];
        
        if (!empty($department_email)) {
            // Send email using PHPMailer
            return sendPHPMailer($department_email, $subject, $message, $attachments);
        }
    }
    
    // Fallback to text file notification
    return sendDepartmentEmailListNotificationWithAttachments($department, $subject, $message, $attachments);
}
?>
