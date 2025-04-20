<?php
session_start();
header('Content-Type: application/json');
include("../connect.php");
include("functions.php");

if (!isset($_SESSION["SS_username"])) {
   echo json_encode(['success' => false, 'message' => 'Unauthorized']);
   exit();
}

if (isset($_POST['feedback_id'])) {
   $feedbackId = intval($_POST['feedback_id']);
   $mysql_user = $_SESSION["SS_username"];
   
   // Đánh dấu tất cả thông báo liên quan đến feedback này là đã đọc
   if (markFeedbackNotificationsAsRead($feedbackId, $mysql_user)) {
       // Lưu feedback_id vào session để biết người dùng đã xem
       if (!isset($_SESSION['viewed_feedbacks'])) {
           $_SESSION['viewed_feedbacks'] = [];
       }
       
       if (!in_array($feedbackId, $_SESSION['viewed_feedbacks'])) {
           $_SESSION['viewed_feedbacks'][] = $feedbackId;
       }
       
       echo json_encode(['success' => true]);
   } else {
       echo json_encode(['success' => false, 'message' => 'Failed to mark notifications as read']);
   }
} else {
   echo json_encode(['success' => false, 'message' => 'Missing feedback ID']);
}
?>
