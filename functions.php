<?php
// Thêm cấu hình timezone ở đầu file
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Include PHPMailer classes - MOVE THESE TO THE TOP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Include database connection
include_once("../connect.php");

// Function to check if a value exists in a table based on a condition
function Check_Value_by_Condition($column, $table, $condition) {
  global $db;
  $sql = "SELECT $column FROM $table WHERE $condition";
  $result = $db->query($sql);
  if ($result && $result->num_rows > 0) {
      return true;
  }
  return false;
}

// Function to select a value from a table based on a condition
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

// Function to update login status
function Update_LoginStatus($status, $mysql_user) {
  global $db;
  $current_time = date("Y/m/d H:i:s");
  $sql = "UPDATE user_tb SET loginStatus = '$status', loginTime = '$current_time' WHERE staff_id = '$mysql_user'";
  $db->query($sql);
}

// Function to update a value in a table based on a condition
function Update_Value_by_Condition($table, $column, $value, $condition_column, $condition_value) {
  global $db;
  $sql = "UPDATE $table SET $column = '$value' WHERE $condition_column = '$condition_value'";
  return $db->query($sql);
}

// Function to check if signature exists
function Check_Signature_Exist($mysql_user) {
  // This is a placeholder - implement according to your actual signature checking logic
  return true;
}

// Function to generate a random code for anonymous feedback
function generateRandomCode($length = 8) {
  $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $code = '';
  for ($i = 0; $i < $length; $i++) {
      $code .= $characters[rand(0, strlen($characters) - 1)];
  }
  return $code;
}

// Function to generate a feedback ID
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

// Function to check if user can handle feedback (same department but not the author)
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
      // Người dùng phải thuộc cùng bộ phận và không phải là người tạo feedback
      return ($row['department'] == $handling_department && $staff_id != $feedback_staff_id);
  }
  return false;
}

// Function to get user department and section
function getUserDepartmentAndSection($staff_id) {
  global $db;
  $sql = "SELECT department, section FROM user_tb WHERE staff_id = '$staff_id'";
  $result = $db->query($sql);
  if ($result && $result->num_rows > 0) {
      return $result->fetch_assoc();
  }
  return ["department" => "", "section" => ""];
}

// Function to get all departments
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

// Function to get feedback status text
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

// Function to escape and sanitize input
function sanitizeInput($input) {
  global $db;
  $input = trim($input);
  $input = stripslashes($input);
  $input = htmlspecialchars($input, ENT_QUOTES);
  $input = $db->real_escape_string($input);
  return $input;
}

// Function to sanitize input while preserving line breaks
function sanitizeInputWithLineBreaks($input) {
  global $db;
  // Chỉ trim đầu và cuối, không trim các khoảng trắng giữa các dòng
  $input = trim($input);
  // Không sử dụng stripslashes để giữ nguyên các ký tự đặc biệt
  // Không sử dụng htmlspecialchars ở đây để tránh chuyển đổi xuống dòng
  // Chỉ escape các ký tự đặc biệt SQL để tránh SQL injection
  $input = $db->real_escape_string($input);
  return $input;
}

// Function to format date
function formatDate($date, $includeTime = false) {
    if ($includeTime) {
        return date('d/m/Y H:i', strtotime($date));
    } else {
        return date('d/m/Y', strtotime($date));
    }
}

// Thêm hàm sanitizeFilename vào functions.php để có thể sử dụng ở nhiều nơi
/**
* Function to sanitize filename to avoid encoding issues
* 
* @param string $filename The filename to sanitize
* @return string Sanitized filename
*/
function sanitizeFilename($filename) {
  // Remove accents and special characters
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
  
  // Remove spaces
  $filename = str_replace(' ', '', $filename);
  
  // Remove any remaining non-alphanumeric characters
  $filename = preg_replace('/[^a-zA-Z0-9]/', '', $filename);
  
  return $filename;
}

// Sửa lại hàm createNotification để sử dụng sanitizeFilename
function createNotification($user_id, $feedback_id, $message, $has_attachment = false) {
    global $db;
        
    // Lấy thông tin feedback
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
        
        // Lấy thông tin người dùng
        $user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $user_id);
        $user_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $user_id);
        
        // Thêm thông báo vào bảng feedback_response_tb
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
        
        // Send email if user has an email address
        if (!empty($user_email)) {
            // Get mailer configuration
            $sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
            $result = $db->query($sql);
            
            if (!$result || $result->num_rows == 0) {
                error_log("Mailer configuration not found");
                return false;
            }
            
            $config = $result->fetch_assoc();
            
            // Create a new PHPMailer instance
            $mail = new PHPMailer(true);
            
            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host       = $config['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $config['address'];
                $mail->Password   = $config['password'];
                $mail->Port       = $config['port'];
                $mail->CharSet    = 'UTF-8';
                
                // Add SMTPOptions to disable SSL certificate verification if needed
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );
                
                // Set sender
                $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
                
                // Add recipient
                $mail->addAddress($user_email, $user_name);
                
                // Set email subject and body
                $mail->isHTML(true);
                $mail->Subject = "Thông báo từ hệ thống phản hồi ý kiến";
                
                // Convert plain text message to HTML
                $html_message = nl2br(htmlspecialchars($message));
                $mail->Body    = $html_message;
                $mail->AltBody = $message;
                
                // Send the email
                $mail->send();
                error_log("Email notification sent to user: " . $user_id);
                return true;
            } catch (Exception $e) {
                error_log("Email sending failed: " . $mail->ErrorInfo);
                
                // Create a backup text file notification if email sending fails
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

// Sửa lại hàm createAnonymousNotification để sử dụng sanitizeFilename
function createAnonymousNotification($feedback_id, $message, $has_attachment = false) {
 global $db;
 
 // Lấy thông tin về feedback
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
     
     // Thêm thông báo vào bảng feedback_response_tb
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

// Sửa lại hàm notifyDepartmentAboutFeedback để sử dụng sanitizeFilename
function notifyDepartmentAboutFeedback($department, $feedback_id, $message, $has_attachment = false) {
    global $db;
    
    // Lấy thông tin feedback
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
        
        // Thêm thông báo vào bảng feedback_response_tb
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
        
        // Send email notification to department
        $subject = "Thông báo về ý kiến #{$feedback_code}: {$feedback_title}";
        return sendDepartmentEmailListNotificationWithAttachments($department, $subject, $message);
    }
    
    return false;
}

// Function to mark notification as read
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

// Function to mark all notifications as read
function markAllNotificationsAsRead($user_id) {
  global $db;
  
  // Lấy danh sách feedback của người dùng
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
  
  // Lấy danh sách feedback thuộc bộ phận xử lý của người dùng
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
  
  // Đánh dấu tất cả thông báo là đã đọc
  if (!empty($feedback_ids)) {
      $feedback_ids_str = implode(',', $feedback_ids);
      $sql = "UPDATE feedback_response_tb SET is_read = 1 WHERE feedback_id IN ({$feedback_ids_str})";
      $db->query($sql);
      return true;
  }
  
  return false;
}

// Function to mark all notifications related to a specific feedback as read
function markFeedbackNotificationsAsRead($feedback_id, $user_id) {
  global $db;
  
  // Đảm bảo bảng feedback_viewed_tb tồn tại
  ensureFeedbackViewedTableExists();
  
  // Cập nhật trạng thái đã đc cho tất cả thông báo của feedback này
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
  
  // Lưu thời gian đọc cuối cùng
  $current_time = date('Y-m-d H:i:s');
  
  // Kiểm tra xem đã có bản ghi chưa
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
      // Cập nhật bản ghi hiện có
      $sql = "UPDATE feedback_viewed_tb SET viewed_at = ? WHERE feedback_id = ? AND user_id = ?";
      $stmt = $db->prepare($sql);
      if (!$stmt) {
          error_log("SQL Error in markFeedbackNotificationsAsRead (update): " . $db->error);
          return false;
      }
      $stmt->bind_param("sis", $current_time, $feedback_id, $user_id);
  } else {
      // Tạo bản ghi mới
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

// Đảm bảo bảng feedback_viewed_tb tồn tại
function ensureFeedbackViewedTableExists() {
  global $db;
  
  // Kiểm tra xem bảng feedback_viewed_tb đã tồn tại chưa
  $check_table = $db->query("SHOW TABLES LIKE 'feedback_viewed_tb'");
  if ($check_table && $check_table->num_rows == 0) {
      // Tạo bảng nếu chưa tồn tại
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

// Function to handle file upload
function handleFileUpload($file, $upload_dir = "uploads/") {
  // First check if this is a valid file upload
  if (!isset($file) || !is_array($file) || empty($file['name']) || $file['error'] != 0 || $file['size'] <= 0) {
      return ['success' => false, 'message' => 'Invalid file upload'];
  }

  // Check if directory exists, if not create it
  if (!file_exists($upload_dir)) {
      mkdir($upload_dir, 0777, true);
  }

  // Generate unique filename
  $file_name = time() . '_' . basename($file['name']);
  $upload_path = $upload_dir . $file_name;

  // Move uploaded file
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

// Function to handle multiple file uploads
function handleMultipleFileUploads($files, $upload_dir = "uploads/") {
  $uploaded_files = [];

  // Validate the files array
  if (!isset($files) || !is_array($files) || !isset($files['name']) || empty($files['name'][0])) {
      return $uploaded_files; // Return empty array if no valid files
  }

  // Check if directory exists, if not create it
  if (!file_exists($upload_dir)) {
      mkdir($upload_dir, 0777, true);
  }

  // Process each file
  foreach ($files['name'] as $key => $name) {
      // Skip invalid files
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
      
      // Generate unique filename
      $file_name = time() . '_' . $key . '_' . basename($file['name']);
      $upload_path = $upload_dir . $file_name;
      
      // Move uploaded file
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

// Replace the getResponseAttachments function with this improved version
function getResponseAttachments($response_id) {
    global $db;
    $attachments = [];
    
    // Debug log
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
            // Debug log
            error_log("Found attachment: " . print_r($row, true));
            
            $attachments[] = [
                'file_path' => $row['file_path'],
                'file_name' => $row['file_name'],
                'file_type' => $row['file_type'],
                'file_size' => $row['file_size']
            ];
        }
    }
    
    // Log for debugging
    error_log("Found " . count($attachments) . " attachments for response ID: " . $response_id);
    
    return $attachments;
}

// Function to get feedback attachments from attachment_tb
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

// Function to check if file exists and is readable
function isFileAccessible($file_path) {
  return file_exists($file_path) && is_readable($file_path);
}

// Function to get absolute URL for a file
function getFileUrl($file_path) {
  $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
  $host = $_SERVER['HTTP_HOST'];
  $base_url = $protocol . $host;

  // Convert relative path to absolute URL
  if (strpos($file_path, '/') === 0) {
      return $base_url . $file_path;
  } else {
      $current_dir = dirname($_SERVER['PHP_SELF']);
      return $base_url . $current_dir . '/' . $file_path;
  }
}

// Function to get anonymous feedback by code
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

// Function to get department emails
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

// Function to send email notification about feedback deletion
function sendFeedbackDeletionNotification($feedback_id, $mysql_user) {
 global $db;
 
 // Lấy thông tin feedback
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
     
     // Lấy tên người dùng và bộ phận
     $user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $mysql_user);
     $user_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $mysql_user);
     
     // Tạo nội dung thông báo theo định dạng mới
     $current_time = date('d/m/Y H:i');
     $message = "[{$current_time}] Thông báo ý kiến: Ý Kiến này đã bị xóa bởi ";
     $message .= $is_anonymous ? "người gửi ẩn danh" : "{$mysql_user} - {$user_name}";
     $message .= "\nTiêu đề: {$feedback_title}";
     $message .= "\nNội dung: {$feedback_content}";
     $message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $user_department);
     $message .= "\nBộ phận xử lý: {$department}";
     
     // Gửi thông báo cho bộ phận xử lý
     notifyDepartmentAboutFeedback($department, $feedback_id, $message);
     
     return true;
 }
 
 return false;
}

// Update the sendNewResponseNotification function to match the specified format
function sendNewResponseNotification($feedback_id, $responder_id, $has_attachment = false, $attachments = []) {
 global $db;
 
 // Lấy thông tin feedback
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
     
     // Lấy thông tin người phản hồi
     $responder_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $responder_id);
     $responder_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $responder_id);
     
     // Lấy thông tin người gửi (nếu không ẩn danh)
     $sender_name = "";
     $sender_dept = "";
     if (!$is_anonymous && $owner_id) {
         $sender_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $owner_id);
         $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $owner_id);
     }
     
     // Tạo nội dung thông báo cho bộ phận xử lý
     $dept_message = "Thông báo: Bạn đã nhận được một phản hồi mới từ {$responder_name}. Vui lòng kiểm tra để cập nhật thông tin kịp thời.";
     $dept_message .= "\nTiêu đề: {$feedback_title}";
     $dept_message .= "\nNgày gửi: {$created_at}";
     $dept_message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
     $dept_message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
     $dept_message .= "\nBộ phận xử lý: {$department}";
     $dept_message .= "\nNội dung: {$feedback_content}";
     
     // Thêm thông tin đính kèm nếu có
     if (!empty($feedback['image_path'])) {
         $dept_message .= "\nĐính kèm: Có file đính kèm";
     }
     
     $dept_message .= "\nPhản hồi:";
     $dept_message .= "\n+ Thời gian: {$response_time}";
     $dept_message .= "\n+ Nội dung: {$response_text}";
     
     // Thêm thông tin đính kèm phản hồi nếu có
     if (!empty($attachments)) {
         $dept_message .= "\nĐính kèm: ";
         foreach ($attachments as $index => $attachment) {
             if ($index > 0) $dept_message .= ", ";
             $dept_message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
         }
     }
     
     // Tạo nội dung thông báo cho người gửi
     $user_message = "Thông báo: Bạn đã nhận được một phản hồi mới từ {$responder_name}. Vui lòng kiểm tra để cập nhật thông tin kịp thời.";
     $user_message .= "\nTiêu đề: {$feedback_title}";
     $user_message .= "\nNgày gửi: {$created_at}";
     $user_message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
     $user_message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
     $user_message .= "\nBộ phận xử lý: {$department}";
     $user_message .= "\nNội dung: {$feedback_content}";
     
     // Thêm thông tin đính kèm nếu có
     if (!empty($feedback['image_path'])) {
         $user_message .= "\nĐính kèm: Có file đính kèm";
     }
     
     $user_message .= "\nPhản hồi:";
     $user_message .= "\n+ Thời gian: {$response_time}";
     $user_message .= "\n+ Nội dung: {$response_text}";
     
     // Thêm thông tin đính kèm phản hồi nếu có
     if (!empty($attachments)) {
         $user_message .= "\nĐính kèm: ";
         foreach ($attachments as $index => $attachment) {
             if ($index > 0) $user_message .= ", ";
             $user_message .= $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")";
         }
     }
     
     // Nếu người phản hồi là người gửi feedback
     if ($responder_id == $owner_id) {
         // Thông báo cho bộ phận xử lý với thông tin đính kèm
         sendDepartmentEmailListNotificationWithAttachments($department, "Phản hồi mới cho ý kiến #{$feedback_code}", $dept_message, $attachments);
     } else {
         // Nếu là feedback ẩn danh
         if ($is_anonymous) {
             // Tạo thông báo cho người dùng ẩn danh
             createAnonymousNotification($feedback_id, $user_message, $has_attachment);
         } else if ($owner_id) {
             // Thông báo cho người gửi feedback
             createNotification($owner_id, $feedback_id, $user_message, $has_attachment);
         }
     }
     
     return true;
 }
 
 return false;
}

// Update the sendFeedbackStatusNotification function to match the specified format
function sendFeedbackStatusNotification($feedback_id, $new_status) {
 global $db;
 
 // Lấy thông tin feedback
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
     
     // Lấy thông tin người gửi (nếu không ẩn danh)
     $sender_name = "";
     $sender_dept = "";
     if (!$is_anonymous && $owner_id) {
         $sender_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $owner_id);
         $sender_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $owner_id);
     }
     
     // Lấy tên trạng thái
     $status_text = getStatusText($new_status);
     
     // Lấy thông tin đánh giá nếu trạng thái là "Kết thúc"
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
     
     // Lấy thông tin đính kèm của phản hồi
     $response_attachments = [];
     if ($response_id) {
         $response_attachments = getResponseAttachments($response_id);
     }
     
     // Tạo nội dung thông báo dựa trên trạng thái
     if ($new_status == 1) { // Chờ xử lý
         $message = "Thông báo: Ý kiến của bạn hiện đang ở trạng thái Chờ xử lý. Chúng tôi đã ghi nhận và sẽ xem xét trong thời gian sớm nhất. Cảm ơn bạn đã đóng góp!";
         $message .= "\n[" . date('d/m/Y H:i') . "]";
         $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
         $message .= "\nTiêu đề: {$feedback_title}";
         $message .= "\nNội dung: {$feedback_content}";
         $message .= "\nBộ phận xử lý: {$department}";
         
         // Thêm thông tin đính kèm nếu có
         if (!empty($feedback['image_path'])) {
             $message .= "\nĐính kèm: Có file đính kèm";
         }
     } else if ($new_status == 3) { // Kết thúc
         $message = "Thông báo: Phản hồi đã được hoàn tất. Cảm ơn bạn đã theo dõi và xử lý phản hồi một cách kịp thời.";
         $message .= "\nTiêu đề: {$feedback_title}";
         $message .= "\nNgày gửi: {$created_at}";
         $message .= "\nNgười gửi: " . ($is_anonymous ? "Ẩn danh" : "{$owner_id} - {$sender_name}");
         $message .= "\nBộ phận: " . ($is_anonymous ? "Ẩn danh" : $sender_dept);
         $message .= "\nBộ phận xử lý: {$department}";
         $message .= "\nNội dung: {$feedback_content}";
         
         // Thêm thông tin đính kèm nếu có
         if (!empty($feedback['image_path'])) {
             $message .= "\nĐính kèm: Có file đính kèm";
         }
         
         if (!empty($response_text)) {
             $message .= "\nPhản hồi:";
             $message .= "\n+ Thời gian: {$response_time}";
             $message .= "\n+ Nội dung: {$response_text}";
             
             // Thêm thông tin đính kèm phản hồi nếu có
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
         // Trạng thái khác
         $message = "Trạng thái của ý kiến \"{$feedback_title}\" đã được thay đổi thành \"{$status_text}\"";
     }
     
     // Nếu là feedback ẩn danh
     if ($is_anonymous) {
         // Tạo thông báo cho người dùng ẩn danh
         createAnonymousNotification($feedback_id, $message);
     } else if ($owner_id) {
         // Thông báo cho người gửi feedback
         createNotification($owner_id, $feedback_id, $message);
     }
     
     // Thông báo cho bộ phận xử lý
     notifyDepartmentAboutFeedback($department, $feedback_id, $message);
     
     return true;
 }
 
 return false;
}

// Function to get file icon class based on extension
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

// Function to format file size
function formatFileSize($bytes) {
  if ($bytes < 1024) {
      return $bytes . ' B';
  } elseif ($bytes < 1048576) {
      return round($bytes / 1024, 2) . ' KB';
  } else {
      return round($bytes / 1048576, 2) . ' MB';
  }
}

// Function to get file MIME type
function getFileMimeType($file_path) {
  // First try to use finfo
  if (function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $mime_type = finfo_file($finfo, $file_path);
      finfo_close($finfo);
      return $mime_type;
  }
  
  // If finfo is not available, try to use mime_content_type
  if (function_exists('mime_content_type')) {
      return mime_content_type($file_path);
  }
  
  // If all else fails, guess based on extension
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

// Function to count unread messages for a feedback
function countUnreadMessages($feedback_id, $user_id) {
 global $db;
 
 // Đảm bảo bảng feedback_viewed_tb tồn tại
 ensureFeedbackViewedTableExists();
 
 // Lấy thời gian xem cuối cùng của người dùng
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
 
 // Nếu chưa từng xem, đếm tất cả tin nhắn
 if ($last_viewed === null) {
     $sql = "SELECT COUNT(*) as count FROM feedback_response_tb WHERE feedback_id = ?";
     $stmt = $db->prepare($sql);
     if (!$stmt) {
         error_log("SQL Error in countUnreadMessages (count all): " . $db->error);
         return 0;
     }
     
     $stmt->bind_param("i", $feedback_id);
 } else {
     // Nếu đã xem, chỉ đếm tin nhắn mới
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

// Function to count total unread messages for a user
function countTotalUnreadMessages($user_id) {
 global $db;
 
 // Lấy danh sách feedback của người dùng
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
 
 // Lấy danh sách feedback thuộc bộ phận xử lý của người dùng
 $user_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $user_id);
 $sql = "SELECT id FROM feedback_tb WHERE handling_department = ?";
 $stmt = $db->prepare($sql);
 if (!$stmt) {
     error_log("SQL Error in countTotalUnreadMessages (second prepare): " . $db->error);
     return 0;
 }
 
 $stmt->bind_param("s", $user_department);
 if (!$stmt->execute()) {
     error_log("Execute Error in countTotalUnreadMessages (second execute): " . $db->error);
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
 
 // Đếm tổng số tin nhắn chưa đọc
 $total_unread = 0;
 foreach ($feedback_ids as $feedback_id) {
     $total_unread += countUnreadMessages($feedback_id, $user_id);
 }
 
 return $total_unread;
}
/**
 * Sends an email notification with attachments to all users in a specific department
 * 
 * @param string $department The department name
 * @param string $subject The email subject
 * @param string $message The email message
 * @param array $attachments Array of attachments with file details
 * @return bool True if emails were sent successfully, false otherwise
 */
function sendDepartmentEmailListNotificationWithAttachments($department, $subject, $message, $attachments = []) {
    global $db;
    
 
    
    // Get department email addresses from handling_department_tb
    $sql = "SELECT email_list FROM handling_department_tb WHERE department_name = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        error_log("SQL Error in sendDepartmentEmailListNotificationWithAttachments: " . $db->error);
        return false;
    }
    
    $stmt->bind_param("s", $department);
    if (!$stmt->execute()) {
        error_log("Execute Error in sendDepartmentEmailListNotificationWithAttachments: " . $stmt->error);
        return false;
    }
    
    $result = $stmt->get_result();
    
    $recipients = [];
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        // Split email list by comma
        if (!empty($row['email_list'])) {
            $recipients = explode(',', $row['email_list']);
            // Trim whitespace from emails
            $recipients = array_map('trim', $recipients);
            // Filter out empty emails
            $recipients = array_filter($recipients);
        }
    }
    
    // If no recipients found in handling_department_tb, try to get emails from user_tb
    if (empty($recipients)) {
        $sql = "SELECT email FROM user_tb WHERE department = ? AND email IS NOT NULL AND email != ''";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in sendDepartmentEmailListNotificationWithAttachments (user_tb): " . $db->error);
            return false;
        }
        
        $stmt->bind_param("s", $department);
        if (!$stmt->execute()) {
            error_log("Execute Error in sendDepartmentEmailListNotificationWithAttachments (user_tb): " . $stmt->error);
            return false;
        }
        
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            while ($user = $result->fetch_assoc()) {
                if (!empty($user['email'])) {
                    $recipients[] = $user['email'];
                }
            }
        }
    }
    
    // If still no recipients, log error and return
    if (empty($recipients)) {
        error_log("No recipients found for department: " . $department);
        return false;
    }
    
    // Get mailer configuration
    $sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
    $result = $db->query($sql);
    
    if (!$result || $result->num_rows == 0) {
        error_log("Mailer configuration not found");
        return false;
    }
    
    $config = $result->fetch_assoc();
    
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['address'];
        $mail->Password   = $config['password'];
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';
        
        // Add SMTPOptions to disable SSL certificate verification if needed
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Set sender
        $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
        
        // Add all recipients
        foreach ($recipients as $recipient) {
            $mail->addAddress($recipient);
        }
        
        // Set email subject and body
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Convert plain text message to HTML
        $html_message = nl2br(htmlspecialchars($message));
        $mail->Body    = $html_message;
        $mail->AltBody = $message;
        
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
        
        // Send the email
        $mail->send();
        error_log("Email sent successfully to department: " . $department);
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        
        // Create a backup text file notification if email sending fails
        $notifications_dir = "email_notifications";
        if (!file_exists($notifications_dir)) {
            mkdir($notifications_dir, 0755, true);
        }
        
        $sanitized_dept = sanitizeFilename($department);
        $notification_file = "{$notifications_dir}/{$sanitized_dept}_Email_" . date('Ymd_His') . ".txt";
        
        $file_content = "To: " . implode(", ", $recipients) . "\n";
        $file_content .= "Subject: {$subject}\n";
        $file_content .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $file_content .= "Message:\n{$message}\n";
        
        if (!empty($attachments)) {
            $file_content .= "\nAttachments:\n";
            foreach ($attachments as $attachment) {
                $file_content .= "- " . $attachment['file_name'] . " (" . formatFileSize($attachment['file_size']) . ")\n";
            }
        }
        
        file_put_contents($notification_file, $file_content);
        error_log("Email notification saved to file: " . $notification_file);
        
        return false;
    }
}
/**
 * Updates the last viewed timestamp for a feedback by a specific user
 * 
 * @param int $feedback_id The ID of the feedback
 * @param string $user_id The ID of the user
 * @return bool True if the update was successful, false otherwise
 */
function updateLastViewed($feedback_id, $user_id) {
    global $db;
    
    // Get current date and time
    $current_time = date('Y-m-d H:i:s');
    
    // Ensure the feedback_viewed_tb table exists
    ensureFeedbackViewedTableExists();
    
    // Check if a record already exists for this feedback and user
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
        // Update existing record
        $sql = "UPDATE feedback_viewed_tb SET viewed_at = ? WHERE feedback_id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error in updateLastViewed (update): " . $db->error);
            return false;
        }
        
        $stmt->bind_param("sis", $current_time, $feedback_id, $user_id);
    } else {
        // Insert new record
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
    
    // Also mark all notifications for this feedback as read
    markFeedbackNotificationsAsRead($feedback_id, $user_id);
    
    return true;
}

// Add this improved function to handle response submission and notifications
// Add this at the end of the file

/**
 * Process and save a new response to feedback
 * 
 * @param int $feedback_id The feedback ID
 * @param string $responder_id The staff ID of the responder
 * @param string $response_text The response text
 * @param array $attachments Array of attachments
 * @return bool True if successful, false otherwise
 */
function saveAndNotifyNewResponse($feedback_id, $responder_id, $response_text, $attachments = []) {
    global $db;
    
    // Insert response into database
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
    
    // Save attachments if any
    if (!empty($attachments)) {
        error_log("Processing " . count($attachments) . " attachments");
        
        foreach ($attachments as $attachment) {
            error_log("Processing attachment: " . print_r($attachment, true));
            
            // Make sure we have all required fields
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
    
    // Get feedback details
    $sql = "SELECT * FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $feedback_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $feedback = $result->fetch_assoc();
        
        // IMPORTANT FIX: Only update feedback status to "Đã phản hồi" (2) if it's in "Chờ xử lý" (1)
        // NEVER update to status 3 (Kết thúc) automatically - this should only happen after rating
        if ($feedback['status'] == 1) {
            $sql = "UPDATE feedback_tb SET status = 2 WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $feedback_id);
            $stmt->execute();
            
            // Send status notification
            sendFeedbackStatusNotification($feedback_id, 2);
        }
        
        // Send response notification
        $has_attachments = !empty($attachments);
        sendNewResponseNotification($feedback_id, $responder_id, $has_attachments, $attachments);
        
        return true;
    }
    
    return false;
}
