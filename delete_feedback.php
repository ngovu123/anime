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
     }
   } else {
     // Nếu không phải ẩn danh, kiểm tra người gửi
     if ($feedback['staff_id'] == $mysql_user) {
       $can_delete = true;
     }
   }
 }
 
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
   header("Location: dashboard.php?error=permission");
   exit();
 }
} else {
 // Feedback not found
 header("Location: dashboard.php");
 exit();
}
?>
