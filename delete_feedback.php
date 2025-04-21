<?php
session_start();
// Thêm debug để xác định vấn đề
error_reporting(E_ALL);
ini_set('display_errors', 1);
include("../connect.php");
include("functions.php");

// Check if user is logged in
if (!isset($_SESSION["SS_username"])) {
 header("Location: login.php");
 exit();
}

$mysql_user = $_SESSION["SS_username"];

// Check if feedback ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
 header("Location: dashboard.php");
 exit();
}

$feedback_id = intval($_GET['id']);

// Debug: Log session data for troubleshooting
error_log("User: $mysql_user, Feedback ID: $feedback_id");
error_log("Anonymous Codes in Session: " . (isset($_SESSION['anonymous_codes']) ? implode(',', $_SESSION['anonymous_codes']) : 'none'));
error_log("Created Anonymous Feedbacks: " . (isset($_SESSION['created_anonymous_feedbacks']) ? print_r(array_keys($_SESSION['created_anonymous_feedbacks']), true) : 'none'));

// Sửa phần kiểm tra quyền xóa feedback - cho phép xóa cả feedback ẩn danh
$sql = "SELECT * FROM feedback_tb WHERE id = ?";
$stmt = $db->prepare($sql);

if (!$stmt) {
 // Log lỗi và chuyển hướng
 error_log("SQL Error in delete_feedback.php: " . $db->error);
 header("Location: dashboard.php?error=db");
 exit();
}

$stmt->bind_param("i", $feedback_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
 $feedback = $result->fetch_assoc();
 
 // Kiểm tra quyền xóa: người dùng phải là người gửi hoặc có mã ẩn danh trong session
 // Và feedback phải ở trạng thái "chờ xử lý" (status = 1)
 $can_delete = false;
 
 if ($feedback['status'] == 1) {
   if ($feedback['is_anonymous'] == 1) {
     // Nếu là feedback ẩn danh, kiểm tra mã ẩn danh trong session
     if (isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) {
       $can_delete = true;
       error_log("Can delete by anonymous_codes");
     }
     
     // THÊM: Kiểm tra từ session created_anonymous_feedbacks
     if (isset($_SESSION['created_anonymous_feedbacks'])) {
       foreach ($_SESSION['created_anonymous_feedbacks'] as $id => $data) {
         if ($data['anonymous_code'] === $feedback['anonymous_code'] && $data['user_id'] === $mysql_user) {
           $can_delete = true;
           error_log("Can delete by created_anonymous_feedbacks");
           break;
         }
       }
     }
     
     // THÊM: Kiểm tra trực tiếp từ mã ẩn danh của feedback
     if ($feedback_id == $feedback_id) {
       error_log("Feedback anonymous_code: " . $feedback['anonymous_code']);
       error_log("Current user: $mysql_user");
     }
   } else {
     // Nếu không phải ẩn danh, kiểm tra người gửi
     if ($feedback['staff_id'] == $mysql_user) {
       $can_delete = true;
       error_log("Can delete by staff_id");
     }
   }
 }
 
 error_log("Can delete: " . ($can_delete ? 'true' : 'false'));
 
 if ($can_delete) {
   // Gửi thông báo xóa feedback
   sendFeedbackDeletionNotification($feedback_id, $mysql_user);
   
   // Delete feedback
   $sql = "DELETE FROM feedback_tb WHERE id = ?";
   $stmt = $db->prepare($sql);
   
   if (!$stmt) {
       // Log lỗi và chuyển hướng
       error_log("SQL Error in delete_feedback.php (delete query): " . $db->error);
       header("Location: view_feedback.php?id=$feedback_id&error=1");
       exit();
   }
   
   $stmt->bind_param("i", $feedback_id);
   
   if ($stmt->execute()) {
    // Sử dụng session để lưu thông báo thành công
    $_SESSION['success_message'] = "Ý kiến đã được xóa thành công!";
    header("Location: dashboard.php");
    exit();
   } else {
    header("Location: view_feedback.php?id=$feedback_id&error=1");
    exit();
   }
 } else {
   // Không có quyền xóa hoặc feedback không ở trạng thái "chờ xử lý"
   $_SESSION['error_message'] = "Bạn không có quyền xóa ý kiến này hoặc ý kiến không ở trạng thái chờ xử lý.";
   header("Location: dashboard.php?error=permission");
   exit();
 }
} else {
 // Feedback not found
 $_SESSION['error_message'] = "Không tìm thấy ý kiến.";
 header("Location: dashboard.php");
 exit();
}
?>
