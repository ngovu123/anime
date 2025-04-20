<?php
include_once("email_functions.php");

/**
* Send notification about new feedback submission
* 
* @param int $feedback_id ID of the feedback
* @param string $staff_id Staff ID of the submitter (null for anonymous)
* @param array $attachments Optional array of attachments
* @return bool True if notification was sent
*/
function sendNewFeedbackNotification($feedback_id, $staff_id = null, $attachments = []) {
  global $db;
  
  // Get feedback details
  $sql = "SELECT * FROM feedback_tb WHERE id = ?";
  $stmt = $db->prepare($sql);
  if (!$stmt) {
      error_log("SQL Error in sendNewFeedbackNotification: " . $db->error);
      return false;
  }
  
  $stmt->bind_param("i", $feedback_id);
  if (!$stmt->execute()) {
      error_log("Execute Error in sendNewFeedbackNotification: " . $stmt->error);
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
      
      // Get submitter name if not anonymous
      $submitter_name = "Ẩn danh";
      $submitter_dept = "";
      if (!$is_anonymous && $staff_id) {
          $user_info = getUserEmail($db, $staff_id);
          if ($user_info) {
              $submitter_name = $user_info['name'];
          }
          $submitter_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $staff_id);
      }
      
      // Create message content
      $message = "Có ý kiến mới " . ($is_anonymous ? "ẩn danh" : "từ " . $submitter_name . " (" . $staff_id. ")") . ":<br><br>";
      $message .= "<strong>Tiêu đề:</strong> " . htmlspecialchars($feedback_title) . "<br>";
      $message .= "<strong>Nội dung:</strong> " . nl2br(htmlspecialchars($feedback_content)) . "<br>";
      if (!$is_anonymous) {
          $message .= "<strong>Bộ phận:</strong> " . htmlspecialchars($submitter_dept) . "<br>";
      }
      $message .= "<strong>Thời gian gửi:</strong> " . date('d/m/Y H:i:s') . "<br>";
      
      // Format email body
      $email_body = formatEmailBody($message);
      
      // Get department emails
      $recipients = getDepartmentEmails($db, $department);
      
      if (empty($recipients)) {
          error_log("No recipients found for department: " . $department);
          return false;
      }
      
      // Send email to department members
      $subject = "Ý kiến mới" . ($is_anonymous ? " ẩn danh" : "") . ": " . $feedback_title;
      return sendBulkEmail($db, $recipients, $subject, $email_body, $attachments);
  }
  
  return false;
}

/**
* Send notification about feedback response
* 
* @param int $feedback_id ID of the feedback
* @param string $responder_id Staff ID of the responder
* @param string $response Response content
* @param array $attachments Optional array of attachments
* @return bool True if notification was sent
*/
function sendResponseNotification($feedback_id, $responder_id, $response, $attachments = []) {
  global $db;
  
  // Get feedback details
  $sql = "SELECT * FROM feedback_tb WHERE id = ?";
  $stmt = $db->prepare($sql);
  if (!$stmt) {
      error_log("SQL Error in sendResponseNotification: " . $db->error);
      return false;
  }
  
  $stmt->bind_param("i", $feedback_id);
  if (!$stmt->execute()) {
      error_log("Execute Error in sendResponseNotification: " . $stmt->error);
      return false;
  }
  
  $result = $stmt->get_result();
  
  if ($result && $result->num_rows > 0) {
      $feedback = $result->fetch_assoc();
      $feedback_code = $feedback['feedback_id'];
      $feedback_title = $feedback['title'];
      $owner_id = $feedback['staff_id'];
      $department = $feedback['handling_department'];
      $is_anonymous = $feedback['is_anonymous'];
      
      // Get responder name
      $responder_name = "Không xác định";
      $responder_info = getUserEmail($db, $responder_id);
      if ($responder_info) {
          $responder_name = $responder_info['name'];
      }
      
      // Create message content
      $message = "Có phản hồi mới cho ý kiến \"" . htmlspecialchars($feedback_title) . "\" từ " . $responder_name . ":<br><br>";
      $message .= "<strong>Nội dung phản hồi:</strong> " . nl2br(htmlspecialchars($response)) . "<br>";
      $message .= "<strong>Thời gian phản hồi:</strong> " . date('d/m/Y H:i:s') . "<br>";
      
      // Format email body
      $email_body = formatEmailBody($message);
      
      // Send to department members if responder is the feedback owner
      if ($responder_id == $owner_id) {
          $recipients = getDepartmentEmails($db, $department);
          
          if (empty($recipients)) {
              error_log("No recipients found for department: " . $department);
              return false;
          }
          
          $subject = "Phản hồi mới cho ý kiến #" . $feedback_code;
          return sendBulkEmail($db, $recipients, $subject, $email_body, $attachments);
      } 
      // Send to feedback owner if responder is from department
      else if (!$is_anonymous && $owner_id) {
          $owner_info = getUserEmail($db, $owner_id);
          
          if (!$owner_info || empty($owner_info['email'])) {
              error_log("Owner email not found for staff ID: " . $owner_id);
              return false;
          }
          
          $subject = "Phản hồi mới cho ý kiến của bạn #" . $feedback_code;
          return sendEmail($db, $owner_info['email'], $owner_info['name'], $subject, $email_body, $attachments);
      }
      // For anonymous feedback, we can't send email (no email address)
      else if ($is_anonymous) {
          // Just log this case
          error_log("Cannot send email for anonymous feedback #" . $feedback_code);
          return true; // Return true as this is an expected case
      }
  }
  
  return false;
}

/**
* Send notification about feedback deletion
* 
* @param int $feedback_id ID of the feedback
* @param string $deleter_id Staff ID of the person who deleted the feedback
* @return bool True if notification was sent
*/
function sendDeletionNotification($feedback_id, $deleter_id) {
  global $db;
  
  // Get feedback details before it's deleted
  $sql = "SELECT * FROM feedback_tb WHERE id = ?";
  $stmt = $db->prepare($sql);
  if (!$stmt) {
      error_log("SQL Error in sendDeletionNotification: " . $db->error);
      return false;
  }
  
  $stmt->bind_param("i", $feedback_id);
  if (!$stmt->execute()) {
      error_log("Execute Error in sendDeletionNotification: " . $stmt->error);
      return false;
  }
  
  $result = $stmt->get_result();
  
  if ($result && $result->num_rows > 0) {
      $feedback = $result->fetch_assoc();
      $feedback_code = $feedback['feedback_id'];
      $feedback_title = $feedback['title'];
      $department = $feedback['handling_department'];
      $is_anonymous = $feedback['is_anonymous'];
      
      // Get deleter name
      $deleter_name = "Không xác định";
      $deleter_info = getUserEmail($db, $deleter_id);
      if ($deleter_info) {
          $deleter_name = $deleter_info['name'];
      }
      
      // Create message content
      $message = "Ý kiến \"" . htmlspecialchars($feedback_title) . "\" đã bị xóa bởi " . 
                ($is_anonymous ? "người gửi ẩn danh" : $deleter_name . " (" . $deleter_id . ")") . ".<br><br>";
      $message .= "<strong>Mã ý kiến:</strong> " . $feedback_code . "<br>";
      $message .= "<strong>Thời gian xóa:</strong> " . date('d/m/Y H:i:s') . "<br>";
      
      // Format email body
      $email_body = formatEmailBody($message);
      
      // Get department emails
      $recipients = getDepartmentEmails($db, $department);
      
      if (empty($recipients)) {
          error_log("No recipients found for department: " . $department);
          return false;
      }
      
      // Send email to department members
      $subject = "Thông báo xóa ý kiến #" . $feedback_code;
      return sendBulkEmail($db, $recipients, $subject, $email_body);
  }
  
  return false;
}

/**
* Send notification about feedback status change
* 
* @param int $feedback_id ID of the feedback
* @param int $new_status New status code
* @return bool True if notification was sent
*/
function sendStatusChangeNotification($feedback_id, $new_status) {
  global $db;
  
  // Get feedback details
  $sql = "SELECT * FROM feedback_tb WHERE id = ?";
  $stmt = $db->prepare($sql);
  if (!$stmt) {
      error_log("SQL Error in sendStatusChangeNotification: " . $db->error);
      return false;
  }
  
  $stmt->bind_param("i", $feedback_id);
  if (!$stmt->execute()) {
      error_log("Execute Error in sendStatusChangeNotification: " . $stmt->error);
      return false;
  }
  
  $result = $stmt->get_result();
  
  if ($result && $result->num_rows > 0) {
      $feedback = $result->fetch_assoc();
      $feedback_code = $feedback['feedback_id'];
      $feedback_title = $feedback['title'];
      $owner_id = $feedback['staff_id'];
      $is_anonymous = $feedback['is_anonymous'];
      
      // Get status text
      $status_text = "";
      switch ($new_status) {
          case 1:
              $status_text = "Chờ xử lý";
              break;
          case 2:
              $status_text = "Đã phản hồi";
              break;
          case 3:
              $status_text = "Kết thúc";
              break;
          default:
              $status_text = "Không xác định";
      }
      
      // Create message content
      $message = "Trạng thái của ý kiến \"" . htmlspecialchars($feedback_title) . "\" đã được thay đổi thành \"" . $status_text . "\".<br><br>";
      $message .= "<strong>Mã ý kiến:</strong> " . $feedback_code . "<br>";
      $message .= "<strong>Thời gian cập nhật:</strong> " . date('d/m/Y H:i:s') . "<br>";
      
      // Format email body
      $email_body = formatEmailBody($message);
      
      // Send to feedback owner if not anonymous
      if (!$is_anonymous && $owner_id) {
          $owner_info = getUserEmail($db, $owner_id);
          
          if ($owner_info && !empty($owner_info['email'])) {
              $subject = "Cập nhật trạng thái ý kiến #" . $feedback_code;
              sendEmail($db, $owner_info['email'], $owner_info['name'], $subject, $email_body);
          }
      }
      
      // Also notify department members
      $recipients = getDepartmentEmails($db, $feedback['handling_department']);
      if (!empty($recipients)) {
          $subject = "Cập nhật trạng thái ý kiến #" . $feedback_code;
          sendBulkEmail($db, $recipients, $subject, $email_body);
      }
      
      return true;
  }
  
  return false;
}
