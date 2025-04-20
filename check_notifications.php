<?php
session_start();
header('Content-Type: application/json');
include("../connect.php");
include("functions.php");

if (!isset($_SESSION["SS_username"])) {
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit();
}

$mysql_user = $_SESSION["SS_username"];

// Get unread notifications count for each feedback
$feedback_notifications = [];

// Get feedbacks owned by the user
$sql = "SELECT id FROM feedback_tb WHERE staff_id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("s", $mysql_user);
$stmt->execute();
$result = $stmt->get_result();

$user_feedbacks = [];
if ($result && $result->num_rows > 0) {
  while ($row = $result->fetch_assoc()) {
      $user_feedbacks[] = $row['id'];
  }
}

// Get feedbacks handled by the user's department
$user_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $mysql_user);
$sql = "SELECT id FROM feedback_tb WHERE handling_department = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("s", $user_department);
$stmt->execute();
$result = $stmt->get_result();

$department_feedbacks = [];
if ($result && $result->num_rows > 0) {
  while ($row = $result->fetch_assoc()) {
      // Only add if not already in user_feedbacks
      if (!in_array($row['id'], $user_feedbacks)) {
          $department_feedbacks[] = $row['id'];
      }
  }
}

// Combine all relevant feedbacks
$all_feedbacks = array_merge($user_feedbacks, $department_feedbacks);

// Get anonymous feedbacks if any
if (isset($_SESSION['anonymous_codes']) && !empty($_SESSION['anonymous_codes'])) {
  $anonymous_codes = $_SESSION['anonymous_codes'];
  $placeholders = str_repeat('?,', count($anonymous_codes) - 1) . '?';
  
  $sql = "SELECT id FROM feedback_tb WHERE anonymous_code IN ($placeholders) AND is_anonymous = 1";
  $stmt = $db->prepare($sql);
  
  $types = str_repeat('s', count($anonymous_codes));
  $stmt->bind_param($types, ...$anonymous_codes);
  $stmt->execute();
  $result = $stmt->get_result();
  
  if ($result && $result->num_rows > 0) {
      while ($row = $result->fetch_assoc()) {
          if (!in_array($row['id'], $all_feedbacks)) {
              $all_feedbacks[] = $row['id'];
          }
      }
  }
}

// Get unread notifications for each feedback
foreach ($all_feedbacks as $feedback_id) {
  $unread_count = countUnreadMessages($feedback_id, $mysql_user);
  if ($unread_count > 0) {
      $feedback_notifications[$feedback_id] = $unread_count;
  }
}

// Return the result
echo json_encode([
  'success' => true,
  'feedback_notifications' => $feedback_notifications,
  'total_unread' => array_sum($feedback_notifications)
]);
?>
