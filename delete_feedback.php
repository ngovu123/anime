<?php
session_start();
include("../connect.php");
include("functions.php");
include("email_functions.php"); // Added to provide formatEmailBody
include("email_notification.php");

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
$feedback = null;
$can_delete = false;

// Get feedback details
$sql = "SELECT * FROM feedback_tb WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $feedback_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $feedback = $result->fetch_assoc();
    
    // Kiểm tra quyền xóa feedback
    if ($feedback['status'] == 1) {  // Chỉ cho phép xóa feedback ở trạng thái "Chờ xử lý" (status = 1)
        if ($feedback['is_anonymous'] == 1) {
            // Nếu là feedback ẩn danh, kiểm tra mã ẩn danh trong session
            if (isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) {
                $can_delete = true;
            } elseif (isset($_SESSION['created_anonymous_feedbacks'][$feedback_id]) && 
                    $_SESSION['created_anonymous_feedbacks'][$feedback_id]['user_id'] === $mysql_user) {
                $can_delete = true;
            }
        } else {
            // Nếu là feedback thông thường, kiểm tra người gửi
            if ($feedback['staff_id'] == $mysql_user) {
                $can_delete = true;
            }
        }
    }
} else {
    // Feedback not found
    $_SESSION['error_message'] = "Không tìm thấy ý kiến này.";
    header("Location: dashboard.php");
    exit();
}

if ($can_delete) {
    // Lấy thông tin feedback trước khi xóa để sử dụng trong thông báo
    $feedback_title = $feedback['title'];
    $feedback_content = $feedback['content'];
    $feedback_code = $feedback['feedback_id'];
    $handling_section = $feedback['handling_department'];
    $is_anonymous = $feedback['is_anonymous'];

    // Lấy tên người dùng hiện tại
    $sql = "SELECT name FROM user_tb WHERE staff_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $mysql_user);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_name = $result && $result->num_rows > 0 ? $result->fetch_assoc()['name'] : "Không xác định";

    // Tạo nội dung thông báo
    $subject = "Thông báo xóa ý kiến " ;
    $message = "Ý kiến sau đã bị xóa bởi ";
    
    if ($is_anonymous == 1) {
        $message .= "<strong>người gửi ẩn danh</strong>:<br><br>";
    } else {
        $message .= "<strong>" . htmlspecialchars($user_name) . " (" . $mysql_user . ")</strong>:<br><br>";
    }
    
    $message .= "<strong>Tiêu đề:</strong> " . htmlspecialchars($feedback_title) . "<br>";
    $message .= "<strong>Nội dung:</strong> " . nl2br(htmlspecialchars($feedback_content)) . "<br>";
    $message .= "<strong>Bộ phận xử lý:</strong> " . htmlspecialchars($handling_section) . "<br>";
    $message .= "<strong>Thời gian xóa:</strong> " . date('d/m/Y H:i:s') . "<br>";

    // Định dạng nội dung email
    $message = formatEmailBody($message);

    // Gửi email thông báo đến các thành viên khác trong bộ phận xử lý
    if (!sendDepartmentEmailListNotification($handling_section, $subject, $message, [], $mysql_user)) {
        error_log("Failed to send deletion notification for feedback #$feedback_code");
        $_SESSION['error_message'] = "Xóa ý kiến thành công, nhưng không thể gửi email thông báo.";
    }

    // Delete feedback
    $sql = "DELETE FROM feedback_tb WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $feedback_id);
    
    if ($stmt->execute()) {
        // Log hoạt động xóa feedback
        error_log("Feedback #{$feedback_code} đã bị xóa bởi {$mysql_user}");
        
        // Xóa attachment nếu có
        $sql = "DELETE FROM attachment_tb WHERE reference_id = ? AND reference_type = 'feedback'";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $feedback_id);
        $stmt->execute();
        
        // Xóa responses liên quan
        $sql = "DELETE FROM feedback_response_tb WHERE feedback_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $feedback_id);
        $stmt->execute();
        
        // Xóa rating nếu có
        $sql = "DELETE FROM feedback_rating_tb WHERE feedback_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $feedback_id);
        $stmt->execute();
        
        // Đặt thông báo thành công trong session
        $_SESSION['success_message'] = "Ý kiến đã được xóa thành công!";
    } else {
        $_SESSION['error_message'] = "Có lỗi xảy ra khi xóa ý kiến: " . $db->error;
    }
} else {
    $_SESSION['error_message'] = "Bạn không có quyền xóa ý kiến này hoặc ý kiến đã được xử lý.";
}

// Chuyển hướng về dashboard
header("Location: dashboard.php");
exit();
?>
