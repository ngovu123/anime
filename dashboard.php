<?php
session_start();
if (!isset($_SESSION['read_feedbacks'])) {
  $_SESSION['read_feedbacks'] = [];
}
include("../connect.php");
include("functions.php");

// Đảm bảo bảng feedback_viewed_tb tồn tại
ensureFeedbackViewedTableExists();

// Thêm đoạn code này vào phần đầu của file dashboard.php, sau phần include và kiểm tra đăng nhập
// Đánh dấu các feedback đã xem từ session
if (isset($_SESSION['viewed_feedbacks']) && !empty($_SESSION['viewed_feedbacks'])) {
 $viewed_feedbacks = $_SESSION['viewed_feedbacks'];
} else {
 $viewed_feedbacks = [];
}

// Thêm biến này để sử dụng trong vòng lặp hiển thị feedback
$viewed_feedbacks_json = json_encode($viewed_feedbacks);

// Check if user is logged in
if (!isset($_SESSION["SS_username"])) {
 header("Location: login.php");
 exit();
}

$mysql_user = $_SESSION["SS_username"];
$user_info = getUserDepartmentAndSection($mysql_user);
$department = $user_info["department"];
$section = $user_info["section"];

// Get user's name
$user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $mysql_user);

// QUAN TRỌNG: Quản lý session các mã ẩn danh - XÓA để đảm bảo không còn ý kiến ẩn danh nào
// được hiển thị trong dashboard
if (isset($_SESSION['anonymous_codes'])) {
    unset($_SESSION['anonymous_codes']);
}

// Get user's feedback (feedback that the user has submitted) - ONLY NON-ANONYMOUS
$feedbacks = [];
$sql = "SELECT *, 1 as is_owner FROM feedback_tb WHERE staff_id = ? AND is_anonymous = 0 ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param("s", $mysql_user);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $feedbacks[] = $row;
    }
}
$stmt->close();

// Đầu tiên, kiểm tra xem phòng ban của người dùng có phải là handling department không
$is_handling_department = false;
$sql = "SELECT COUNT(*) as count FROM handling_department_tb WHERE department_name = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("s", $department);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    if ($row['count'] > 0) {
        $is_handling_department = true;
    }
}
$stmt->close();

// Sau đó mới lấy feedback được gửi đến phòng ban của người dùng
$department_feedbacks = [];
if ($is_handling_department) {
    // Bộ phận xử lý cần xem được cả ý kiến ẩn danh thuộc phòng ban của họ
    $sql = "SELECT *, 0 as is_owner FROM feedback_tb 
            WHERE handling_department = ? 
            AND (staff_id IS NULL OR staff_id != ?)
            ORDER BY created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $department, $mysql_user);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $department_feedbacks[] = $row;
        }
    }
    $stmt->close();
}

// Initialize all_feedbacks
$all_feedbacks = [];

// If user is not part of handling department, set filter to show only their own feedback
if (!$is_handling_department) {
    // For non-handling departments, we'll only show their own feedback
    // No need for filter tabs, as they can only see their own submissions
    $all_feedbacks = $feedbacks; // Only show their own feedback
} else {
    // For handling departments, merge their own feedback with department feedback
    $all_feedbacks = array_merge($feedbacks, $department_feedbacks);
}

// THÊM: Lọc ý kiến ẩn danh ra khỏi danh sách hiển thị nếu người dùng không phải là phòng ban xử lý
$filtered_feedbacks = [];
foreach ($all_feedbacks as $feedback) {
    // Loại bỏ các ý kiến ẩn danh được tạo bởi người dùng hiện tại
    if ($feedback['is_anonymous'] == 1 && $feedback['is_owner'] == 1) {
        continue; // Bỏ qua ý kiến ẩn danh tạo bởi người dùng
    }
    $filtered_feedbacks[] = $feedback;
}
$all_feedbacks = $filtered_feedbacks;

// Remove duplicates based on feedback ID
$unique_feedbacks = [];
$feedback_ids = [];
foreach ($all_feedbacks as $feedback) {
   if (!in_array($feedback['id'], $feedback_ids)) {
       $feedback_ids[] = $feedback['id'];
       $unique_feedbacks[] = $feedback;
   } else {
       // If duplicate, keep the one marked as owner
       foreach ($unique_feedbacks as $key => $existing) {
           if ($existing['id'] == $feedback['id'] && $feedback['is_owner'] == 1) {
               $unique_feedbacks[$key] = $feedback;
               break;
           }
       }
   }
}
$all_feedbacks = $unique_feedbacks;

// Handle anonymous feedback search
$search_error = "";
if (isset($_POST['search_anonymous'])) {
 $anonymous_code = sanitizeInput($_POST['anonymous_code']);
 
 if (empty($anonymous_code)) {
     $search_error = "Vui lòng nhập mã tra cứu.";
 } else {
     // Search for feedback with the provided anonymous code
     $feedback = getAnonymousFeedbackByCode($anonymous_code);
     
     if ($feedback) {
         // Store the anonymous code in session for future reference
         if (!isset($_SESSION['anonymous_codes'])) {
             $_SESSION['anonymous_codes'] = [];
         }
         
         // Add the anonymous code to the session
         if (!in_array($anonymous_code, $_SESSION['anonymous_codes'])) {
             $_SESSION['anonymous_codes'][] = $anonymous_code;
         }
         
         // Redirect to view feedback page
         header("Location: view_feedback.php?id=" . $feedback['id']);
         exit();
     } else {
         $search_error = "Không tìm thấy ý kiến với mã tra cứu này.";
     }
 }
}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'waiting';

// Sort all feedbacks by created_at
usort($all_feedbacks, function($a, $b) {
 return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Define status map
$status_map = [
  'waiting' => 1,
  'responded' => 2,
  'completed' => 3
];

// Apply status filter only for handling departments
if ($is_handling_department && isset($status_map[$filter])) {
    $status_filter = $status_map[$filter];
    $filtered_feedbacks = [];
    
    foreach ($all_feedbacks as $feedback) {
        if ($feedback['status'] == $status_filter) {
            $filtered_feedbacks[] = $feedback;
        }
    }
    
    $all_feedbacks = $filtered_feedbacks;
}

// Tính tổng số thông báo chưa đọc
$total_unread_messages = countTotalUnreadMessages($mysql_user);

// Lấy thông báo cho từng feedback
$feedback_notifications = [];
foreach ($all_feedbacks as $feedback) {
   $unread_count = countUnreadMessages($feedback['id'], $mysql_user);
   if ($unread_count > 0) {
       $feedback_notifications[$feedback['id']] = $unread_count;
   }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Dashboard - Hệ thống phản hồi ý kiến</title>
 <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
 <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
 <style>
     body {
         background-color: #f8f9fa;
         font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
         font-size: 14px;
         line-height: 1.2;
     }
     .container {
         max-width: 100%;
         padding: 15px;
     }
     @media (min-width: 768px) {
         .container {
             max-width: 960px;
             margin: 0 auto;
             padding: 20px;
         }
     }
     .header {
         display: flex;
         justify-content: space-between;
         align-items: center;
         
         
     }
     .user-info {
         display: flex;
         align-items: center;
     }
     .user-avatar {
         width: 40px;
         height: 40px;
         border-radius: 50%;
         background-color:rgb(30, 93, 156);
         color: white;
         display: flex;
         align-items: center;
         justify-content: center;
         font-weight: bold;
         margin-right: 10px;
     }
     .user-name {
         font-weight: 600;
     }
     .user-department {
         font-size: 0.85rem;
         color: #6c757d;
     }
     .btn-logout {
         position: fixed;
         top: 10px;
         right: 10px;
         background-color: #f8f9fa;
         color: #6c757d;
         border: 1px solid #dee2e6;
         border-radius: 50%;
         width: 40px;
         height: 40px;
         display: flex;
         align-items: center;
         justify-content: center;
         transition: all 0.2s;
         z-index: 1000;
         box-shadow: 0 2px 5px rgba(0,0,0,0.1);
     }
     .btn-logout:hover {
         background-color: #e9ecef;
         color: #dc3545;
     }
     .card {
         border-radius: 12px;
         box-shadow: 0 2px 10px rgba(0,0,0,0.05);
         margin-bottom: 20px;
         border: none;
         overflow: hidden;
     }
    
     @media (min-width: 768px) {
         .card-body {
             padding: 15px;
         }
     }
     .search-box {
         position: relative;
         margin-bottom: 0; /* Changed from 15px */
     }
     .search-box input {
         padding-right: 40px;
         border-radius: 50px;
         border: 1px solid #e9ecef;
         padding: 8px 15px 8px 40px;
         transition: all 0.2s;
         width: 40%; /* Changed from 40% */
         height: 30px;
     }
     .search-box input:focus {
         box-shadow: 0 0 0 0.2rem rgba(0,123,255,.15);
         border-color: #80bdff;
     }
     .search-box i {
         position: absolute;
         left: 15px;
         top: 11px;
         color: #6c757d;
     }
     .search-box button {
         position: absolute;
         right: 5px;
         top: 5px;
         border-radius: 50px;
     }
     .feedback-item {
         border: 1px solid #e9ecef;
         border-radius: 12px;
         padding: 12px;
         margin-bottom: 12px;
         background-color: #fff;
         transition: all 0.2s;
         position: relative;
     }
     .feedback-item:hover {
         transform: translateY(-3px);
         box-shadow: 0 5px 15px rgba(0,0,0,0.1);
     }
     .feedback-item a {	
         display: block;
         text-decoration: none;
         color: white;
     }
     .feedback-title {
         font-weight: 600;
         margin-bottom: 5px;
         font-size: 16px;
         color: #212529;
         position: relative; /* Add for positioning the notification count */
         
     }
     .feedback-meta {
         font-size: 10px;
         color:#a0a3a7;
         margin-bottom: 10px;
     }
     .feedback-content {
         margin-bottom: 10px;
         color: #343a40;
         word-wrap: break-word;
         word-break: break-word;
         overflow-wrap: break-word;
     }
     .badge-waiting {
         background-color: #ffc107;
         color: #212529;
		 font-size: 100%;
     }
     .badge-processing {
         background-color: #17a2b8;
         color: #fff;
     }
     .badge-responded {
         background-color: #28a745;
         color: #fff;
		 font-size: 8px;
     }
     .badge-completed {
         background-color: #6c757d;
         color: #fff;
     }
     .badge-anonymous {
         background-color: #6c757d;
         color: #fff;
     }
     .empty-state {
         text-align: center;
         padding: 50px 0;
     }
     .empty-state i {
         font-size: 3rem;
         color: #8dc1f9;
         margin-bottom: 20px;
     }
     .delete-btn {
         position: absolute;
         bottom: 10px;
         right: 10px;
         background-color: transparent;
         color: #dc3545;
         border: none;
         width: 30px;
         height: 30px;
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         transition: all 0.2s;
         z-index: 10.
     }
     .delete-btn:hover {
         background-color: rgba(220, 53, 69, 0.1);
     }
     .anonymous-search-container {
         display: flex;
         align-items: center;
         background-color: #f1f1f1;
         border-radius: 50px;
     }
     .anonymous-search-container input {
         flex: 1;
         border: none;
         background: transparent;
         padding: 4px 15px;
         outline: none;
     }
     .anonymous-search-container button {
         background-color: #007bff;
         color: white;
         border: none;
         border-radius: 20px;
         padding: 8px 15px;
         margin-left: 5px;
         transition: all 0.2s;
		 font-size: 11px;
     }
     .anonymous-search-container button:hover {
         background-color: #0069d9;
     }
     .filter-container {
         display: flex;
         justify-content: space-between;
         align-items: center;
         margin-bottom: 20px;
         flex-wrap: wrap;
     }
     .filter-dropdown {
         position: relative;
         display: inline-block;
         margin-right: 10px;
         margin-bottom: 10px;
     }
     .filter-button {
         background-color: #fff;
         border: 1px solid #dee2e6;
         color: #212529;
         border-radius: 50px;
         padding: 8px 16px;
         display: flex;
         align-items: center;
         cursor: pointer;
         transition: all 0.2s;
     }
     .filter-button i {
         margin-right: 6px;
     }
     .filter-button:hover {
         background-color: #f8f9fa;
     }
     .filter-menu {
         position: absolute;
         top: 100%;
         left: 0;
         z-index: 1000;
         display: none;
         min-width: 200px;
         padding: 10px 0;
         margin-top: 5px;
         background-color: #fff;
         border-radius: 8px;
         box-shadow: 0 5px 15px rgba(0,0,0,0.1);
     }
     .filter-menu.show {
         display: block;
     }
     .filter-item {
         display: block;
         padding: 8px 15px;
         color: #212529;
         text-decoration: none;
         transition: background-color 0.2s;
     }
     .filter-item:hover {
         background-color: #f8f9fa;
         text-decoration: none;
         color: #212529;
     }
     .filter-item.active {
         background-color: #e9ecef;
         font-weight: 500;
     }
     .btn-submit {
         display: inline-flex;
         align-items: center;
         background-color: #007bff;
         color: white;
         border: none;
         border-radius: 4px;
         padding: 6px 12px;
         font-size: 14px;
         transition: all 0.2s;
         text-decoration: none;
         white-space: nowrap; /* Prevent button text from wrapping */
         height: 30px; /* Match search box height */
     }
     .btn-submit i {
         margin-right: 5px;
         font-size: 12px;
     }
     .btn-submit:hover {
         background-color: #0069d9;
         color: white;
         text-decoration: none;
     }
     
     /* New search-action-container for layout */
     .search-action-container {
         display: flex;
         justify-content: space-between;
         align-items: center;
         margin-bottom: 15px;
         flex-wrap: nowrap;
     }
     
     .search-container {
         flex-grow: 1;
         margin-left: 15px; /* Space between button and search */
     }
     
     @media (max-width: 767px) {
         .filter-container {
             flex-direction: column;
             align-items: flex-start;
         }
         .filter-dropdown {
             width: 100%;
             margin-right: 0;
         }
         .filter-button {
             width: 100%;
             justify-content: space-between;
         }
         .filter-menu {
             width: 100%;
         }
         .search-action-container {
             flex-wrap: nowrap;
         }
         .btn-submit {
             padding: 6px 10px;
             font-size: 13px;
             min-width: 90px;
         }
         .search-container {
             margin-left: 10px;
         }
     }

     /* Notification styles */
     .notification-area {
 position: relative;
 margin-left: 15px;
}

.notification-btn {
 background: none;
 border: none;
 color: #6c757d;
 font-size: 1.2rem;
 position: relative;
 padding: 0;
 width: 40px;
 height: 40px;
 display: flex;
 align-items: center;
 justify-content: center;
 transition: all 0.2s;
}

.notification-btn:hover {
 color: #495057;
}

.notification-badge {
 position: absolute;
 top: 0;
 right: 0;
 background-color: #dc3545;
 color: white;
 border-radius: 50%;
 width: 18px;
 height: 18px;
 font-size: 10px;
 display: flex;
 align-items: center;
 justify-content: center;
 font-weight: bold;
}


@keyframes pulseNotification {
   0% {
       transform: scale(1);
       box-shadow: 0 0 0 0 rgba(255, 59, 48, 0.7);
   }
   
   70% {
       transform: scale(1.1);
       box-shadow: 0 0 0 10px rgba(255, 59, 48, 0);
   }
   
   100% {
       transform: scale(1);
       box-shadow: 0 0 0 0 rgba(255, 59, 48, 0);
   }
}
.notification-dropdown {
 width: 320px;
 padding: 0;
 border-radius: 8px;
 box-shadow: 0 5px 15px rgba(0,0,0,0.1);
 border: none;
 max-height: 400px;
 overflow: hidden;
}
.notification-header {
 display: flex;
 justify-content: space-between;
 align-items: center;
 padding: 10px 15px;
 border-bottom: 1px solid #e9ecef;
}
.notification-list {
 max-height: 350px;
 overflow-y: auto;
}
.notification-item {
 display: flex;
 padding: 12px 15px;
 border-bottom: 1px solid #f1f1f1;
 transition: background-color 0.2s;
 text-decoration: none;
 color: #212529;
}
.notification-item:hover {
 background-color: #f8f9fa;
 text-decoration: none;
 color: #212529;
}
.notification-item.unread {
 background-color: #e8f4ff;
}
.notification-icon {
 width: 36px;
 height: 36px;
 border-radius: 50%;
 background-color: #e9ecef;
 display: flex;
 align-items: center;
 justify-content: center;
 margin-right: 12px;
 color: #6c757d;
}
.notification-content {
 flex-grow: 1;
}
.notification-text {
 font-size: 14px;
 margin-bottom: 3px;
}
.notification-time {
 font-size: 12px;
 color: #6c757d;
}
.no-notifications {
 padding: 30px 15px;
 text-align: center;
 color: #6c757d;
}
.mark-all-read {
 font-size: 12px;
 padding: 0;
}
 </style>
<style>
/* Thay thế CSS cho new-response-indicator bằng notification-count */
.new-response-indicator {
 display: inline-block;
 width: 10px;
 height: 10px;
 background-color: #ff3b30;
 border-radius: 50%;
 margin-left: 5px;
 box-shadow: 0 0 5px rgba(255, 59, 48, 0.5);
 animation: pulse 1.5s infinite;
}

@keyframes pulse {
 0% {
   transform: scale(0.95);
   box-shadow: 0 0 0 0 rgba(255, 59, 48, 0.7);
 }
 
 70% {
   transform: scale(1);
   box-shadow: 0 0 0 5px rgba(255, 59, 48, 0);
 }
 
 100% {
   transform: scale(0.95);
   box-shadow: 0 0 0 0 rgba(255, 59, 48, 0);
 }
}

/* Thêm CSS cho notification-count */
.notification-count {
   display: inline-flex;
   align-items: center;
   justify-content: center;
   min-width: 18px;
   height: 18px;
   background-color: #ff3b30;
   color: white;
   border-radius: 9px;
   font-size: 11px;
   font-weight: bold;
   padding: 0 5px;
   margin-left: 5px;
   box-shadow: 0 1px 3px rgba(0,0,0,0.2);
   animation: fadeInPulse 0.3s ease-in-out;
}

/* Total notification badge */

/* Animation for notification pulse */

/* Animation for notification fadeIn */
@keyframes fadeInPulse {
   0% {
       transform: scale(0.8);
       opacity: 0;
   }
   50% {
       transform: scale(1.1);
   }
   100% {
       transform: scale(1);
       opacity: 1;
   }
}
</style>
<style>
/* Cải thiện hiển thị bộ lọc trên mobile */
@media (max-width: 767px) {
   .filter-menu {
       position: absolute;
       left: 0;
       right: 0;
       width: 100%;
       z-index: 1050;
   }
   
   .filter-item {
       padding: 12px 15px;
       font-size: 16px;
   }
   
   .search-filter-container {
       flex-direction: column;
       align-items: flex-start;
   }
   
   .search-box {
       margin-bottom: 10px;
       width: 100%;
   }
   
   .search-box input {
       width: 60%;
   }
   
   .dropdown {
       align-self: flex-start;
   }
}

/* Đảm bảo menu dropdown hiển thị đúng */
.filter-menu {
   display: none;
   position: absolute;
   background-color: white;
   min-width: 200px;
   box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
   z-index: 1050;
   border-radius: 8px;
   overflow: hidden;
}

.filter-menu.show {
   display: block !important;
}

.filter-item {
   color: black;
   padding: 12px 16px;
   text-decoration: none;
   display: block;
   text-align: left;
}

.filter-item:hover {
   background-color: #f1f1f1;
}

.filter-item.active {
   background-color: #e9ecef;
   font-weight: 500;
}

.search-filter-container {
   display: flex;
   justify-content: space-between;
   align-items: center;
   margin-bottom: 20px;
}
</style>
<style>
/* Toast container */
.toast-container {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 9999;
  max-height: 80vh;
  overflow-y: auto;
  pointer-events: none;
}

.toast-container .toast {
  pointer-events: auto;
}

.toast {
  background-color: rgba(0, 0, 0, 0.8);
  color: white;
  padding: 12px 20px;
  border-radius: 4px;
  margin-bottom: 10px;
  min-width: 250px;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
  display: flex;
  align-items: center;
  animation: slideInRight 0.3s, fadeOut 0.5s 6.5s forwards;
  opacity: 0;
}

.toast.success {
  background-color: rgba(40, 167, 69, 0.9);
}

.toast.error {
  background-color: rgba(220, 53, 69, 0.9);
}

.toast-icon {
  margin-right: 10px;
  font-size: 18px;
}

.toast-message {
  flex: 1;
}

@keyframes slideInRight {
  from {
      transform: translateX(100%);
      opacity: 0;
  }
  to {
      transform: translateX(0);
      opacity: 1;
  }
}

@keyframes fadeOut {
  from {
      opacity: 1;
  }
  to {
      opacity: 0;
      transform: translateY(-20px);
  }
}
</style>
<style>
.status-tabs {
    margin-bottom: 20px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.status-nav {
    display: flex;
    flex-wrap: nowrap;
    width: 100%;
    border-bottom: 1px solid #dee2e6;
}

.status-nav .nav-item {
    white-space: nowrap;
}

.status-nav .nav-link {
    color: #495057;
    border: none;
    padding: 10px 15px;
    font-size: 14px;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    transition: all 0.2s ease;
}

.status-nav .nav-link:hover {
    border-bottom-color: #adb5bd;
}

.status-nav .nav-link.active {
    color: #007bff;
    border-bottom-color: #007bff;
    background-color: transparent;
}

.status-nav .badge {
    margin-left: 5px;
    font-size: 11px;
    font-weight: 500;
    padding: 3px 6px;
}

.badge-warning {
    background-color: #007bff;
    color: #fff;

}

.badge-info {
    background-color: #17a2b8;
    color: #fff;
}

.badge-success {
    background-color: #28a745;
    color: #fff;
}

/* Responsive styles for mobile */
@media (max-width: 767px) {
    .status-nav {
        padding-bottom: 5px;
    }
    
    .status-nav .nav-link {
        padding: 8px 10px;
        font-size: 13px;
    }
    
    .status-nav .badge {
        font-size: 10px;
        padding: 2px 5px;
    }
    
    .search-box {
        margin-bottom: 15px;
    }
    
    .search-box input {
        width: 60%;
    }
}
</style>
<style>
.search-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 15px;
}

.anonymous-search-container {
    display: flex;
    align-items: center;
    background-color: #f5f5f5;
    border-radius: 30px;
    overflow: hidden;
    border: 1px solid #e0e0e0;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
	font-size: 2px;
}

.anonymous-search-container:focus-within {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-color: #007bff;
}

.search-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 10px 15px;
    outline: none;
    font-size: 0.9rem;
}

.search-button {
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 0 30px 30px 0;
    padding: 10px 20px;
    font-weight: 500;
    transition: all 0.2s;
}

.search-button:hover {
    background-color: #0069d9;
}

.search-box {
    position: relative;
    margin-bottom: 15px;
    width: 100%;
}

.search-box i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.search-input-main {
    padding-left: 40px;
    border-radius: 30px;
    border: 1px solid #e0e0e0;
    padding: 10px 15px 10px 40px;
    transition: all 0.3s ease;
    height: 42px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.search-input-main:focus {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-color: #007bff;
}

/* Improve status tabs styling */
.status-nav .nav-link {
    font-size: 0.95rem;
    font-weight: 600;
    padding: 12px 15px;
}

.status-nav .badge {
    font-size: 0.75rem;
    padding: 3px 8px;
}

@media (max-width: 767px) {
    .search-title {
        font-size: 0.95rem;
    }
    
    .search-input {
        font-size: 0.7rem;
        padding: 8px 12px;
    }
    
    .search-button {
        padding: 8px 15px;
        font-size: 0.85rem;
    }
    
    .search-input-main {
        height: 38px;
        font-size: 0.85rem;
    }
    
    .status-nav .nav-link {
        font-size: 0.7rem;
        padding: 8px 8px;
    }
}
</style>
<style>
/* Tìm phần CSS của .delete-btn và thêm CSS mới cho .delete-btn-new */
/* Thêm đoạn CSS này vào phần <style> trong file dashboard.php */
.delete-btn-new {
    position: absolute;
    bottom: 10px;
    right: 10px;
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 3px 8px;
    font-size: 12px;
    display: inline-block;
    transition: all 0.2s;
    text-decoration: none;
    z-index: 10;
}

.delete-btn-new:hover {
    background-color: #007bff;
    color: white;
    text-decoration: none;
}

/* Responsive styles for mobile */
@media (max-width: 767px) {
    .delete-btn-new {
        font-size: 12px;
        padding: 2px 6px;
        bottom: 8px;
        right: 8px;
    }
}
</style>
</head>
<body>
 <!-- Fixed logout button on the right side -->
 <a href="../functionList.php" class="btn-logout" title="Đăng xuất">
     <i class="fas fa-sign-out-alt"></i>
 </a>

<!-- Toast container for notifications -->
<div class="toast-container" id="toastContainer">
    <?php if (!empty($search_error)): ?>
    <div class="toast error">
        <div class="toast-icon"><i class="fas fa-exclamation-circle"></i></div>
        <div class="toast-message"><?php echo $search_error; ?></div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success_message'])): ?>
<div class="toast success">
    <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
    <div class="toast-message"><?php echo $_SESSION['success_message']; ?></div>
</div>
<?php 
// Xóa thông báo sau khi hiển thị
unset($_SESSION['success_message']);
endif; 
?>
</div>

<div class="container">
    <!-- Find the header section in dashboard.php and add this notification bell code -->
<div class="header">
  
</div>
    <?php if (isset($_GET['success']) && $_GET['success'] == 'rating'): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Bạn đã đánh giá phản hồi thành công!
        <button type="button" class="close" data-dismiss="alert">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- Tra cứu ý kiến ẩn danh -->
    
<div class="card">
   <div class="card-body p-2 p-md-3">
       <h6 class="mb-3 search-title">Tra cứu ẩn danh</h6>
       
       <form method="post" action="">
           <div class="anonymous-search-container">
               <input type="text" name="anonymous_code" placeholder="Nhập mã tra cứu" class="search-input" value="<?php echo isset($_POST['anonymous_code']) ? htmlspecialchars($_POST['anonymous_code']) : ''; ?>">
               <button type="submit" name="search_anonymous" class="search-button">Tra cứu</button>
           </div>
       </form>
   </div>
</div>
     
<!-- Search and Action Bar (Combined) -->
<div class="search-action-container">
    <!-- Button moved to left -->
    <a href="submit_feedback.php" class="btn-submit">
        <i class="fas fa-plus"></i> Tạo mới
    </a>
    
    <!-- Search box moved to right -->
    <div class="search-container">
        <div class="search-box mb-0">
            <i class="fas fa-search"></i>
            <input type="text" class="form-control search-input-main" id="searchInput" placeholder="Tìm kiếm ý kiến...">
        </div>
    </div>
</div>

<!-- Tabbed Navigation Filter - Only shown for handling departments -->
<?php if ($is_handling_department): ?>
<div class="status-tabs">
  
<ul class="nav nav-tabs status-nav" id="statusTabs">
   <?php
   // Count items in each status
   $waiting_count = 0;
   $responded_count = 0;
   $completed_count = 0;
   
   // Get all feedbacks before filtering to count properly
   $all_feedbacks_for_count = array_merge($feedbacks, $department_feedbacks);
   $unique_feedbacks_for_count = [];
   $feedback_ids_for_count = [];
   
   foreach ($all_feedbacks_for_count as $fb) {
       if (!in_array($fb['id'], $feedback_ids_for_count)) {
           $feedback_ids_for_count[] = $fb['id'];
           $unique_feedbacks_for_count[] = $fb;
       } else {
           // If duplicate, keep the one marked as owner
           foreach ($unique_feedbacks_for_count as $key => $existing) {
               if ($existing['id'] == $fb['id'] && $fb['is_owner'] == 1) {
                   $unique_feedbacks_for_count[$key] = $fb;
                   break;
               }
           }
       }
   }
   
   foreach ($unique_feedbacks_for_count as $fb) {
    switch ($fb['status']) {
        case 1: $waiting_count++; break;
        case 2: $responded_count++; break;
        case 3: $completed_count++; break;
    }
}
   
   // If no filter is set or filter is 'all', default to 'waiting'
   if ($filter == 'all' || !isset($_GET['filter'])) {
       $filter = 'waiting';
   }
   ?>
   <li class="nav-item">
       <a class="nav-link <?php echo $filter == 'waiting' ? 'active' : ''; ?>" href="?filter=waiting">
           </i> Chờ xử lý <span class="badge badge-pill badge-warning"><?php echo $waiting_count; ?></span>
       </a>
   </li>
   <li class="nav-item">
       <a class="nav-link <?php echo $filter == 'responded' ? 'active' : ''; ?>" href="?filter=responded">
           </i> Đã phản hồi <span class="badge badge-pill badge-success"><?php echo $responded_count; ?></span>
       </a>
   </li>
   <li class="nav-item">
       <a class="nav-link <?php echo $filter == 'completed' ? 'active' : ''; ?>" href="?filter=completed">
           </i> Kết thúc <span class="badge badge-pill badge-secondary"><?php echo $completed_count; ?></span>
       </a>
   </li>
</ul>
</div>
<?php else: ?>
<!-- For non-handling departments, show a simple heading instead of tabs -->

<?php endif; ?>
     
     <?php if (empty($all_feedbacks)): ?>
     <div class="empty-state">
         <i class="fas fa-comment-slash"></i>
         <h6>Không có ý kiến nào</h6>
     </div>
     <?php else: ?>
     <div id="feedbackList">
     <?php foreach ($all_feedbacks as $feedback): ?>
<div class="feedback-item" data-id="<?php echo $feedback['id']; ?>">
   <a href="view_feedback.php?id=<?php echo $feedback['id']; ?>" class="text-decoration-none text-dark">
       <div class="d-flex justify-content-between align-items-start">
           <div class="feedback-title">
               <?php echo htmlspecialchars($feedback['title']); ?>
               <?php 
               // Kiểm tra xem feedback này có thông báo mới không
               if (isset($feedback_notifications[$feedback['id']]) && $feedback_notifications[$feedback['id']] > 0): 
               ?>
               <span class="notification-count"><?php echo $feedback_notifications[$feedback['id']]; ?></span>
               <?php endif; ?>
           </div>
       </div>
       
       <div class="feedback-meta">
    <span>
    <?php 
    if ($feedback['is_anonymous'] == 1) {
        echo "Ẩn danh";
    } else {
        echo htmlspecialchars($feedback['staff_id']) . ' - ' . Select_Value_by_Condition("name", "user_tb", "staff_id", $feedback['staff_id']);
    }
    ?>
    </span>
    <span class="ml-2"><?php echo formatDate($feedback['created_at']); ?></span>
</div>
                 
       <div class="feedback-content">
    <?php 
    // Fix line break issue by properly converting all stored line breaks
    $content = $feedback['content'];

    $content = str_replace(
        ["\\r\\n\\r\\n", "\\r\\n", "\n", '\n'],
        ["\r\n\r\n", "\r\n", "\n", "\n"],
        $content
    );
    
    $content = htmlspecialchars($content);
    
    if (mb_strlen($content, 'UTF-8') > 70) {
        $content = mb_substr($content, 0, 70, 'UTF-8') . '...';
    }
    
    echo '<div style="white-space: pre-wrap; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word;">' . $content . '</div>';
    ?>
</div>
             </a>
             
             <?php 
             // Chỉ hiển thị nút xóa nếu người dùng là người gửi và feedback đang ở trạng thái chờ xử lý
             if ($feedback['is_owner'] == 1 && $feedback['status'] == 1): 
             ?>
             <a href="delete_feedback.php?id=<?php echo $feedback['id']; ?>" class="delete-btn-new" onclick="return confirmDelete(event);">
                 Xóa
             </a>
             <?php endif; ?>
         </div>
         <?php endforeach; ?>
     </div>
     <?php endif; ?>
 </div>

 <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
 <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
 <script>
$(document).ready(function() {
   // Search functionality
   $("#searchInput").on("keyup", function() {
       var value = $(this).val().toLowerCase();
       $("#feedbackList .feedback-item").filter(function() {
           $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
       });
   });
   
   // Kiểm tra xem có feedback nào đã được đọc không
   updateNotificationCounts();
   
   // Kiểm tra thông báo mới khi trang tải xong
   checkForNewNotifications();
   
   // Thiết lập kiểm tra thông báo định kỳ (mỗi 30 giây)
   setInterval(checkForNewNotifications, 30000);
});

// Hàm cập nhật số thông báo
function updateNotificationCounts() {
   // Kiểm tra tất cả các feedback item
   $('.feedback-item').each(function() {
       const feedbackId = $(this).data('id');
       // Nếu feedback đã được đánh dấu là đã đọc trong localStorage
       if (localStorage.getItem('feedback_' + feedbackId + '_read') === 'true') {
           // Xóa chỉ báo thông báo mới
           const notificationEl = $(this).find('.notification-count');
           if (notificationEl.length > 0) {
               notificationEl.fadeOut(300, function() {
                   $(this).remove();
               });
           }
           // Xóa flag đánh dấu đã đọc sau khi đã xử lý
           localStorage.removeItem('feedback_' + feedbackId + '_read');
       }
   });
}

// Hàm kiểm tra thông báo mới
function checkForNewNotifications() {
   // Kiểm tra nếu cần refresh thông báo sau khi xem feedback
   const needRefresh = sessionStorage.getItem('refreshAfterViewFeedback') === 'true';
   
   if (needRefresh) {
       sessionStorage.removeItem('refreshAfterViewFeedback');
   }
   
   // Gọi API để lấy thông báo mới
   $.ajax({
       url: 'check_notifications.php',
       type: 'GET',
       dataType: 'json',
       success: function(response) {
           if (response.success) {
               // Cập nhật số thông báo cho từng feedback
               if (needRefresh) {
                   // Nếu là refresh sau khi xem, xóa tất cả thông báo hiện tại
                   $('.notification-count').remove();
               }
               
               // Thêm thông báo mới
               $.each(response.feedback_notifications, function(feedbackId, count) {
                   const feedbackItem = $('.feedback-item[data-id="' + feedbackId + '"]');
                   if (feedbackItem.length > 0) {
                       const title = feedbackItem.find('.feedback-title');
                       // Chỉ thêm thông báo nếu chưa có và count > 0
                       if (title.length > 0 && count > 0 && title.find('.notification-count').length === 0) {
                           title.append('<span class="notification-count">' + count + '</span>');
                       }
                   }
               });
           }
       }
   });
}

// Hàm xác nhận xóa feedback
function confirmDelete(event) {
   if (!confirm('Bạn có chắc chắn muốn xóa ý kiến này không?')) {
       event.preventDefault();
       return false;
   }
   return true;
}
</script>


<script>
// Hiển thị toast khi trang tải xong
$(document).ready(function() {
  // Hiển thị toast khi trang tải xong
  if ($('.toast').length > 0) {
      $('.toast').each(function() {
          $(this).css('opacity', '1');
          
          // Tự động ẩn sau 7 giây
          setTimeout(() => {
              $(this).css('opacity', '0');
              $(this).css('transform', 'translateY(-20px)');
              
              // Xóa khỏi DOM sau khi animation hoàn tất
              setTimeout(() => {
                  $(this).remove();
              }, 500);
          }, 4000);
      });
  }
});

// Hàm để hiển thị toast thông báo
function showToast(message, type = 'success') {
  const toastContainer = document.getElementById('toastContainer');
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  
  const iconClass = type === 'success' ? 'check-circle' : 'exclamation-circle';
  
  toast.innerHTML = `
    <div class="toast-icon"><i class="fas fa-${iconClass}"></i></div>
    <div class="toast-message">${message}</div>
  `;
  
  toastContainer.appendChild(toast);
  
  // Hiển thị toast
  setTimeout(() => {
    toast.style.opacity = '1';
  }, 10);
  
  // Tự động ẩn sau 4 giây
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-20px)';
    
    // Xóa khỏi DOM sau khi animation hoàn tất
    setTimeout(() => {
      toastContainer.removeChild(toast);
    }, 500);
  }, 4000);
}
</script>

<script>
$(document).ready(function() {
    // Ensure horizontal scrolling works smoothly on mobile for tabs
    const statusTabs = document.querySelector('.status-tabs');
    if (statusTabs) {
        // Check if we're on mobile
        if (window.innerWidth <= 767) {
            // Scroll to active tab
            const activeTab = document.querySelector('.status-nav .active');
            if (activeTab) {
                const tabPosition = activeTab.offsetLeft;
                const tabWidth = activeTab.offsetWidth;
                const containerWidth = statusTabs.offsetWidth;
                
                // Center the active tab
                statusTabs.scrollLeft = tabPosition - (containerWidth / 2) + (tabWidth / 2);
            }
        }
    }
});
</script>
</body>
</html>
