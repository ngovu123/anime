<?php
session_start();
include("../connect.php");
include("functions.php");
include("email_notification.php");

// Check if user is logged in
if (!isset($_SESSION["SS_username"])) {
    header("Location: login.php");
    exit();
}


$mysql_user = $_SESSION["SS_username"];
$user_info = getUserDepartmentAndSection($mysql_user);
$department = $user_info["department"];

// Check if feedback ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

$feedback_id = intval($_GET['id']);
$feedback = null;
$responses = [];
$rating = null;
$is_handler = false;
$can_delete = false;
$is_owner = false;
$success_message = "";
$error_message = "";

// Ensure uploads directory exists
$upload_dir = "uploads/responses/";
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
// Get feedback details
$sql = "SELECT f.*, u.department as sender_department FROM feedback_tb f 
    LEFT JOIN user_tb u ON f.staff_id = u.staff_id 
    WHERE f.id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $feedback_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $feedback = $result->fetch_assoc();

    // Check if user is authorized to view this feedback
    if ($feedback['staff_id'] == $mysql_user) {
        // User is the feedback submitter (non-anonymous)
        $is_owner = true;
        $is_handler = false;
   } elseif ($feedback['is_anonymous'] == 1 && 
          ((isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) || 
           (isset($_SESSION['temp_anonymous_view']) && in_array($feedback['anonymous_code'], $_SESSION['temp_anonymous_view'])))) {
    // User is the anonymous feedback submitter (has anonymous code in session)
    $is_owner = true;
    $is_handler = false;
    } elseif ($department == $feedback['handling_department']) {
        // User belongs to the handling department
        $is_handler = true;
        $is_owner = false;
    } else {
        // User is not authorized to view this feedback
        header("Location: dashboard.php");
        exit();
    }

    // Check if feedback can be deleted (chỉ khi ở trạng thái chờ xử lý)
    if ($is_owner && $feedback['status'] == 1) {
        $can_delete = true;
    }

    // Get responses with attachments
    $sql = "SELECT r.*, u.name FROM feedback_response_tb r 
        LEFT JOIN user_tb u ON r.responder_id = u.staff_id 
        WHERE r.feedback_id = ? AND r.response != '' 
        ORDER BY r.created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $feedback_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Get attachments for this response
            $response_id = $row['id'];
            $row['attachments'] = getResponseAttachments($response_id);
            
            // Debug log
            error_log("Response ID: " . $response_id . " has " . count($row['attachments']) . " attachments");
            
            $responses[] = $row;
        }
    }

    // Get rating
    $sql = "SELECT * FROM feedback_rating_tb WHERE feedback_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $feedback_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $rating = $result->fetch_assoc();
    }

    // Update last viewed time when a user views the feedback
    updateLastViewed($feedback_id, $mysql_user);

    // Kiểm tra nếu người dùng đang xem feedback ẩn danh
    $viewing_anonymous = $feedback['is_anonymous'] == 1 && isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes']);

    // Đánh dấu đã đọc trong session
    if (!isset($_SESSION['read_feedbacks'])) {
        $_SESSION['read_feedbacks'] = [];
    }
    if (!in_array($feedback_id, $_SESSION['read_feedbacks'])) {
        $_SESSION['read_feedbacks'][] = $feedback_id;
    }

    // Đánh dấu tất cả thông báo liên quan đến feedback này là đã đọc
    markFeedbackNotificationsAsRead($feedback_id, $mysql_user);

    // Thêm đoạn code này để đảm bảo thông báo được cập nhật khi quay lại dashboard
    echo "<script>
        // Đánh dấu feedback này đã được đọc trong localStorage
        localStorage.setItem('feedback_" . $feedback_id . "_read', 'true');
        // Đặt flag để dashboard biết dashboard cần refresh thông báo
        sessionStorage.setItem('refreshAfterViewFeedback', 'true');
    </script>";

} else {
    // Feedback not found
    header("Location: dashboard.php");
    exit();
}

// Handle response submission
if (isset($_POST['submit_response'])) {
    $response_text = sanitizeInputWithLineBreaks($_POST['response']);

    if (empty($response_text)) {
        $error_message = "Vui lòng nhập nội dung phản hồi.";
    } else {
        // Process file uploads
        $attachment_paths = [];
        
        if (!empty($_FILES['attachments']['name'][0])) {
            $file_count = count($_FILES['attachments']['name']);
            
            for ($i = 0; $i < $file_count; $i++) {
                if ($_FILES['attachments']['error'][$i] == 0) {
                    $file = [
                        'name' => $_FILES['attachments']['name'][$i],
                        'type' => $_FILES['attachments']['type'][$i],
                        'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                        'error' => $_FILES['attachments']['error'][$i],
                        'size' => $_FILES['attachments']['size'][$i]
                    ];
                    
                    $allowed_types = [
                        'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp',
                        'application/pdf', 
                        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'text/plain', 'text/csv', 'application/rtf'
                    ];
                    $max_size = 20 * 1024 * 1024; // 20MB
                    
                    if (!in_array($file['type'], $allowed_types)) {
                        $error_message = "Chỉ chấp nhận các định dạng: JPEG, PNG, GIF, BMP, WebP, PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV, RTF.";
                        break;
                    } elseif ($file['size'] > $max_size) {
                        $error_message = "Kích thước file không được vượt quá 20MB.";
                        break;
                    } else {
                        $upload_result = handleFileUpload($file, "uploads/responses/");
                        if ($upload_result['success']) {
                            $attachment_paths[] = [
                                'path' => $upload_result['file_path'],
                                'name' => $upload_result['file_name'],
                                'type' => $upload_result['file_type'],
                                'size' => $upload_result['file_size']
                            ];
                        } else {
                            $error_message = "Có lỗi xảy ra khi tải file lên.";
                            break;
                        }
                    }
                }
            }
        }
        
       if (empty($error_message)) {
            // Use the new function to save response and send notifications
            if (saveAndNotifyNewResponse($feedback_id, $mysql_user, $response_text, $attachment_paths)) {
                $success_message = "Gửi phản hồi thành công!";
                
                // Cập nhật trạng thái thành "Đã phản hồi" (status = 2) nếu người gửi phản hồi là handler và trạng thái hiện tại là "Chờ xử lý" (status = 1)
                if ($is_handler && $feedback['status'] == 1) {
                    $update_sql = "UPDATE feedback_tb SET status = 2 WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->bind_param("i", $feedback_id);
                    $update_stmt->execute();
                    
                    // Gửi thông báo về việc thay đổi trạng thái
                    sendFeedbackStatusNotification($feedback_id, 2);
                }
                
                // IMPORTANT: Do NOT change status to 3 (kết thúc) here when a user replies
                // Leave status as 2 (đã phản hồi) and keep chat open
                
                // Refresh page to show new response
                header("Location: view_feedback.php?id=$feedback_id&success=1");
                exit();
            } else {
                $error_message = "Có lỗi xảy ra khi lưu phản hồi.";
            }
        }
    }
}

// Handle rating submission
if (isset($_POST['submit_rating']) && $is_owner && $feedback['status'] == 2) {
    $rating_value = intval($_POST['rating']);
    $rating_comment = sanitizeInput($_POST['rating_comment']);

    if ($rating_value < 1 || $rating_value > 5) {
        $error_message = "Vui lòng chọn mức độ đánh giá hợp lệ.";
    } else {
        $sql = "INSERT INTO feedback_rating_tb (feedback_id, rating, comment) VALUES (?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("iis", $feedback_id, $rating_value, $rating_comment);
        
        if ($stmt->execute()) {
            // Update feedback status to "Kết thúc" ONLY after rating is submitted
            $sql = "UPDATE feedback_tb SET status = 3 WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $feedback_id);
            if ($stmt->execute()) {
                // Gửi thông báo trạng thái "Kết thúc"
                sendFeedbackStatusNotification($feedback_id, 3);
            }
            
            // Create notification for handlers
            $sql = "SELECT DISTINCT u.staff_id FROM user_tb u 
                    WHERE u.department = ? AND u.staff_id != ?";
            $stmt = $db->prepare($sql);
            
            // Fix: Create variables for the parameters
            $dept = $feedback['handling_department'];
            // Use empty string as default if staff_id is null
            $staff = $feedback['staff_id'] ? $feedback['staff_id'] : '';
            
            $stmt->bind_param("ss", $dept, $staff);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $message = "Ý kiến \"" . $feedback['title'] . "\" đã được đánh giá và kết thúc";
                    createNotification($row['staff_id'], $feedback_id, $message);
                }
            }
            
            $success_message = "Gửi đánh giá thành công!";
            
            // Chuyển hướng về dashboard.php sau khi đánh giá
            header("Location: dashboard.php?success=rating");
            exit();
        } else {
            $error_message = "Có lỗi xảy ra: " . $stmt->error;
        }
    }
}

// Handle status change - IMPORTANT: Only allow changing between status 1 and 2
if (isset($_POST['change_status']) && $is_handler) {
    $new_status = intval($_POST['status']);

    // Chỉ cho phép thay đổi trạng thái từ "Chờ xử lý" sang "Đã phản hồi" hoặc ngược lại
    // KHÔNG cho phép thay đổi trạng thái sang "Kết thúc" (status = 3)
    if (($new_status == 2 && $feedback['status'] == 1) || ($new_status == 1 && $feedback['status'] == 2)) {
        $sql = "UPDATE feedback_tb SET status = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $new_status, $feedback_id);
        
        if ($stmt->execute()) {
            $feedback['status'] = $new_status;
            
            // Gửi thông báo trạng thái mới
            sendFeedbackStatusNotification($feedback_id, $new_status);
            
            $success_message = "Cập nhật trạng thái thành công!";
            
            // Refresh page
            header("Location: view_feedback.php?id=$feedback_id&success=3");
            exit();
        }
        else {
            $error_message = "Có lỗi xảy ra: " . $stmt->error;
        }
    } else if ($new_status == 3) {
        $error_message = "Không thể thay đổi trạng thái sang Kết thúc. Trạng thái Kết thúc chỉ được thiết lập khi người gửi ý kiến đánh giá phản hồi.";
    } else {
        $error_message = "Không thể thay đổi trạng thái này.";
    }
}

// Get success message from URL
if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) {
        $success_message = "Gửi phản hồi thành công!";
    } elseif ($_GET['success'] == 2) {
        $success_message = "Gửi đánh giá thành công!";
    } elseif ($_GET['success'] == 3) {
        $success_message = "Cập nhật trạng thái thành công!";
    }
}

// Get sender name
$sender_name = $feedback['is_anonymous'] ? 'Ẩn danh' : Select_Value_by_Condition("name", "user_tb", "staff_id", $feedback['staff_id']);

// Determine if user can reply
$can_reply = false;

// Handler can reply when status is "waiting" (status = 1) or "responded" (status = 2)
if ($is_handler && ($feedback['status'] == 1 || $feedback['status'] == 2)) {
    $can_reply = true;
}

// Owner can reply only when status is "responded" (status = 2)
if ($is_owner && $feedback['status'] == 2) {
    $can_reply = true;
}

// Determine if we should show the chat container
// Show chat container if there are responses OR if status is "responded" or "completed"
$show_chat = !empty($responses) || $feedback['status'] >= 2;

// Thêm biến để kiểm soát hiển thị phần nhập chat
$show_chat_input = $can_reply;


$show_processing_message = $is_owner && $feedback['status'] == 1;

// Lấy thông tin về file đính kèm của feedback
$feedback_attachments = [];
if (!empty($feedback['image_path'])) {
    // Check if it's a comma-separated list of paths
    $paths = explode(',', $feedback['image_path']);
    
    foreach ($paths as $path) {
        if (!empty(trim($path))) {
            $file_ext = pathinfo($path, PATHINFO_EXTENSION);
            $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif']);
            $is_pdf = strtolower($file_ext) === 'pdf';
            
            $feedback_attachments[] = [
                'path' => trim($path),
                'name' => basename(trim($path)),
                'type' => $is_image ? 'image' : ($is_pdf ? 'pdf' : 'file'),
                'ext' => $file_ext
            ];
        }
    }
}

// Helper function for time elapsed string
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'năm',
        'm' => 'tháng',
        'w' => 'tuần',
        'd' => 'ngày',
        'h' => 'giờ',
        'i' => 'phút',
        's' => 'giây',
    );

    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? '' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' trước' : 'vừa xong';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Chi tiết ý kiến - Hệ thống phản hồi ý kiến</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
body {
    background-color: #f8f9fa;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
    margin: 0;
    padding: 0;
    min-height: 100vh;
    font-size: 14px;
    line-height: 1.5;
}
.container {
    max-width: 100%;
    padding: 10px;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 20px);
}
@media (min-width: 768px) {
    .container {
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
        height: calc(100vh - 40px);
    }
}
.feedback-card {
    background-color: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
    position: relative;
}
.feedback-header {
    padding: 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background-color: #fff;
    position: sticky;
    top: 0;
    z-index: 10;
}
.main-content {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow-y: auto; 
    -webkit-overflow-scrolling: touch;
    padding-bottom: 0; /* Remove any bottom padding */
}
/* Create a single scrollable container that wraps all three elements */

.feedback-info {
    padding: 15px;
    background-color: #fff;
    border-bottom: 1px solid #eee;
    max-height: none; /* Bỏ giới hạn chiều cao */
    overflow-y: visible; /* Loại bỏ thanh trượt riêng */
}

/* Ensure chat container doesn't have its own scrollbar */
.chat-container {
    flex: 1;
    padding: 15px 15px 0 15px; /* Reduce bottom padding */
    background-color: #f5f7f9;
    overflow-y: visible;
    display: flex;
    flex-direction: column;
}
.chat-input-container {
    padding: 10px;
    background-color: #fff;
    border-top: 1px solid #eee;
    position: sticky;
    bottom: 0;
    z-index: 5;
    width: 100%;
    margin-top: auto; /* Push it to the bottom */
}
.feedback-title {
    font-weight: 600;
    font-size: 1.1rem;
    margin: 0;
    flex: 1;
    word-wrap: break-word;
    padding-right: 10px;
}
.feedback-status {
    margin-left: 10px;
    padding: 5px 8px;
    font-size: 12px;
    font-weight: 500;
    border-radius: 4px;
    white-space: nowrap;
}
.status-waiting {
    background-color: #007bff;
    color: #fff;
}
.status-processing {
    background-color: #17a2b8;
    color: #fff;
}
.status-responded {
    background-color: #28a745;
    color: #fff;
}
.status-completed {
    background-color: #6c757d;
    color: #fff;
}
.close-btn {
    background: none;
    border: none;
    font-size: 1.1rem;
    line-height: 1;
    color: #6c757d;
    cursor: pointer;
    padding: 0;
    margin-left: 10px;
    position: absolute;
    top: 15px;
    right: 15px;
}
.feedback-meta {
    display: flex;
    flex-direction: column;
    margin-bottom: 15px;
    font-size: 12px;
}
.meta-item {
    
}
.meta-label {
    
    margin-right: 5px;
}
.feedback-content {
    margin-top: 15px;
    line-height: 1.5;
}
.chat-form {
    display: flex;
    flex-direction: column;
}
.chat-input-wrapper {
    display: flex;
    background-color: #f1f3f5;
    border-radius: 24px;
    padding: 8px 15px;
    margin-bottom: 10px;
}
.chat-input {
    flex: 1;
    border: none;
    background: transparent;
    padding: 8px 0;
    outline: none;
    resize: none;
    max-height: 100px;
    min-height: 24px;
}
.chat-actions {
    display: flex;
    align-items: center;
}
.attachment-btn {
    background: none;
    border: none;
    color: #6c757d;
    cursor: pointer;
    padding: 8px;
    margin-right: 5px;
    border-radius: 50%;
}
.attachment-btn:hover {
    background-color: rgba(0,0,0,0.05);
}
.send-btn {
    background-color: #007bff;
    color: white;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: 8px;
}
.send-btn:hover {
    background-color: #0069d9;
}
.send-btn:disabled {
    background-color: #b0d4ff;
    cursor: not-allowed;
}
.message {
    margin-bottom: 20px;
}
.message-user {
    text-align: right;
}
.message-other {
    text-align: left;
}
.message-header {
    margin-bottom: 5px;
    font-size: 11px;
    color: #6c757d;
}
.message-bubble {
    display: inline-block;
    max-width: 85%;
    padding: 12px 16px;
    border-radius: 18px;
    position: relative;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    text-align: left;
}
.message-user .message-bubble {
    background-color: #007bff;
    color: white;
    border-bottom-right-radius: 4px;
}
.message-other .message-bubble {
    background-color: white;
    color: #212529;
    border-bottom-left-radius: 4px;
}
.message-text {
    word-wrap: break-word;
    white-space: pre-line;
    word-break: break-word;
    overflow-wrap: break-word;
}
.system-message {
    text-align: center;
    margin: 15px 0;
    font-size: 13px;
    color: #6c757d;
}
.system-message span {
    background-color: rgba(0,0,0,0.05);
    padding: 5px 10px;
    border-radius: 12px;
    display: inline-block;
}
.attachments-container {
    margin-top: 10px;
}
.attachment-preview {
    margin-top: 8px;
    background-color: rgba(0,0,0,0.05);
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    margin-bottom: 5px;
}
.attachment-preview i {
    margin-right: 8px;
    font-size: 18px;
}
.attachment-preview a {
    color: inherit;
    text-decoration: none;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.message-user .attachment-preview a {
    color: white;
}
.attachment-image {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    margin-top: 8px;
    cursor: pointer;
    margin-bottom: 5px;
}
.file-upload-container {
    margin-top: 10px;
}
.file-upload-label {
    display: block;
    background-color: #f8f9fa;
    border: 1px dashed #ced4da;
    border-radius: 4px;
    padding: 10px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
}
.file-upload-label:hover {
    background-color: #e9ecef;
}
.file-upload-input {
    display: none;
}
.file-preview-list {
    margin-top: 10px;
}
.file-preview-item {
    display: flex;
    align-items: center;
    background-color: #f8f9fa;
    border-radius: 4px;
    padding: 8px;
    margin-bottom: 5px;
}
.file-preview-item i {
    margin-right: 8px;
}
.file-preview-name {
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.file-preview-remove {
    color: #dc3545;
    cursor: pointer;
    padding: 5px;
}
.rating-container {
    text-align: center;
    margin: 20px 0;
}
.chat-container {
    display: flex;
    flex-direction: column;
    min-height: 200px; /* Ensure minimum height */
}

.chat-messages {
    flex: 1;
}

.rating-btn {
    background-color: #007bff;
    color: white;
    border: none;
    border-radius: 20px;
    padding: 8px 16px;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
}
.rating-btn i {
    margin-right: 5px;
    font-size: 18px;
}
.rating-stars {
    font-size: 24px;
    color: #ffc107;
    margin-bottom: 10px;
}
.rating-comment {
    font-style: italic;
}
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}
.modal-content {
    background-color: #fff;
    margin: 10% auto;
    padding: 0;
    width: 90%;
    max-width: 500px;
    border-radius: 8px;
    overflow: hidden;
    animation: modalFadeIn 0.3s;
}
.modal-header {
    padding: 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-title {
    margin: 0;
    font-weight: 600;
}
.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    line-height: 1;
    color: #6c757d;
    cursor: pointer;
}
.modal-body {
    padding: 15px;
}
.modal-footer {
    padding: 15px;
    border-top: 1px solid #eee;
    text-align: right;
}
.rating-form .stars {
    text-align: center;
    margin-bottom: 15px;
}
.rating-form .star-label {
    font-size: 30px;
    cursor: pointer;
    color: #e4e4e4;
    padding: 0 5px;
}
.rating-form input[type="radio"] {
    display: none;
}
.image-modal {
    display: none;
    position: fixed;
    z-index: 1050;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.9);
    opacity: 0;
    transition: opacity 0.3s;
}
.image-modal-content {
    margin: auto;
    display: block;
    max-width: 90%;
    max-height: 90%;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
}
.image-modal-close {
    position: absolute;
    top: 15px;
    right: 25px;
}
@keyframes modalFadeIn {
    from {opacity: 0; transform: translateY(-20px);}
    to {opacity: 1; transform: translateY(0);}
}

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

.feedback-date {
    font-size: 0.75rem;
    margin-top: 2px;
}

.response-label {
    padding: 10px 15px 0 15px;
    background-color: #f5f79;
    border-bottom: 1px solid #eee;
    overflow: visible; /* Đảm bảo không có thanh trượt riêng */
}

.response-label h6 {
    margin-bottom: 10px;
    font-weight: 600;
    color: #495057;
    font-size: 14px; /* Đã điều chỉnh kích thước giống với "Nội dung" */
}

/* Đảm bảo star rating hiển thị đúng */
.star-container {
    font-size: 30px;
}
.star-item {
    cursor: pointer;
    padding: 0 5px;
}
.star-item.fas {
    color: #ffc107;
}

/* Điều chỉnh cho thiết bị di động */
@media (max-width: 767px) {
    .container {
        padding: 0;
        height: 100vh;
        overflow: hidden; /* Prevent scrolling on the main container */
    }
    
    .feedback-card {
        border-radius: 0;
        height: 100vh;
        display: flex;
        flex-direction: column;
    }
    
    .combined-scroll-container {
        height: calc(100vh - 56px - 56px); /* Adjust based on header and input heights */
    }
     .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
	
    .chat-container {
        padding: 10px 10px 0 10px; /* Reduce bottom padding */
    }
    
    .chat-input-container {
        padding: 8px;
    }
    .feedback-info {
        padding-bottom: 5px;
    }
    
    /* Ensure no extra space at bottom */
    .rating-container {
        margin-bottom: 10px;
    }
    
    .message-bubble {
        max-width: 60%;
    }
    
    .modal-content {
        width: 95%;
        margin: 5% auto;
    }
}

@media (min-width: 768px) {
    .combined-scroll-container {
        height: calc(100vh - 80px - 65px); /* Adjust based on header and input heights for desktop */
    }
}


.attachments-container {
    margin-top: 10px;
}
.attachment-preview {
    margin-top: 8px;
    background-color: rgba(0,0,0,0.05);
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    margin-bottom: 5px;
}
.attachment-preview i {
    margin-right: 8px;
    font-size: 18px;
}
.attachment-preview a {
    color: inherit;
    text-decoration: none;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.message-user .attachment-preview a {
    color: white;
}
.attachment-image {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    margin-top: 8px;
    cursor: pointer;
    margin-bottom: 5px;
}

/* Add this CSS rule to ensure proper padding on mobile */
@media (max-width: 767px) {
    .feedback-header {
        padding: 12px;
        position: relative;
    }
    
    .close-btn {
        top: 12px;
        right: 12px;
    }
    
    .feedback-title {
        padding-right: 20px;
        font-size: 1rem;
    }
}

/* Replace it with this */
@media (max-width: 767px) {
    .feedback-header {
        padding: 10px;
        position: relative;
    }
    
    .close-btn {
        top: 10px;
        right: 10px;
    }
    
    .feedback-title {
        padding-right: 20px;
        font-size: 0.95rem;
    }
    
    .feedback-info {
        padding: 10px;
    }
    
    .chat-container {
        padding: 10px;
    }
    
    .chat-input-container {
        padding: 10px;
    }
}

@media (max-width: 767px) {
    .status-nav .nav-link i {
        font-size: 12px;
    }
    
    .feedback-status i {
        font-size: 12px;
    }
    
    .badge i {
        font-size: 10px;
    }
}

.meta-item {
    margin-bottom: 5px; /* Giảm từ 8px xuống 5px */
    display: flex;
    align-items: baseline;
}

.meta-label {
    min-width: 90px; /* Giảm từ 100px xuống 90px */
    font-weight: 500;
    color: #495057;
    font-size: 12px; /* Giảm kích thước chữ */
}

.feedback-meta {
    margin-bottom: 15px; /* Giảm từ 20px xuống 15px */
}

.feedback-status {
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
}

.feedback-content {
    margin-top: 15px;
}

.feedback-content strong {
    display: inline-block;
    margin-bottom: 8px;
    color: #495057;
}

.content-text {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

@media (max-width: 767px) {
    .meta-label {
        min-width: 60px;
        font-size: 0.7rem; /* Giảm kích thước chữ trên mobile */
    }
    
    .meta-item {
        margin-bottom: 4px; /* Giảm khoảng cách trên mobile */
    }
    
    .feedback-status {
        font-size: 0.6rem;
        padding: 2px 4px;
    }
}

.attachments-container {
    margin-top: 10px;
    width: 100%;
}
.attachment-preview {
    margin-top: 8px;
    background-color: rgba(0,0,0,0.05);
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    margin-bottom: 5px;
    width: 100%;
}
.attachment-preview i {
    margin-right: 8px;
    font-size: 18px;
}
.attachment-preview a {
    color: inherit;
    text-decoration: none;
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.message-user .attachment-preview {
    background-color: rgba(255,255,255,0.2);
}
.message-user .attachment-preview a {
    color: white;
}
.attachment-image {
    max-width: 200px;
    max-height: 200px;
    border-radius: 8px;
    margin-top: 8px;
    cursor: pointer;
    margin-bottom: 5px;
}

/* Find the CSS for meta-item and meta-label and update them to reduce spacing */
.meta-item {
   margin-bottom: 3px; /* Reduce from 5px to 3px */
   display: flex;
   align-items: baseline;
}

.meta-label {
   min-width: 80px; /* Reduce from 90px to 80px */
   font-weight: 500;
   color: #495057;
   font-size: 12px;
}

.feedback-meta {
   margin-bottom: 10px; /* Reduce from 15px to 10px */
}

/* Find the feedback-content CSS and update it */
.feedback-content {
   margin-top: 10px; /* Reduce from 15px to 10px */
}
</style>
</head>
<body>
<div class="container">
    <div class="toast-container" id="toastContainer">
        <?php if (!empty($success_message)): ?>
        <div class="toast success">
            <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
            <div class="toast-message"><?php echo $success_message; ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
        <div class="toast error">
            <div class="toast-icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="toast-message"><?php echo $error_message; ?></div>
        <?php endif; ?>
    </div>
    
    
<div class="feedback-card">
    <div class="feedback-header">
        <div class="d-flex flex-column">
            <h5 class="feedback-title"><?php echo htmlspecialchars($feedback['title']); ?></h5>
        </div>
        <button type="button" class="close-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <div class="main-content">
        <div class="combined-scroll-container">
            <div class="feedback-info">
<div class="feedback-meta">
<div class="meta-item">
   <span class="meta-label">Ngày gửi:</span>
   <strong><?php echo formatDate($feedback['created_at'], true); ?></strong>
</div>
   <div class="meta-item">
       <span class="meta-label">Người gửi:</span>
       <strong><?php echo htmlspecialchars($feedback['staff_id']); ?> - <?php echo htmlspecialchars($sender_name); ?></strong>
   </div>
   <div class="meta-item">
       <?php
       // Lấy bộ phận của người gửi
       $sender_department = "";
       if (!$feedback['is_anonymous'] && !empty($feedback['staff_id'])) {
           $sender_department = Select_Value_by_Condition("department", "user_tb", "staff_id", $feedback['staff_id']);
       }
       ?>
       <span class="meta-label">Bộ phận:</span>
       <strong><?php echo !empty($sender_department) ? htmlspecialchars($sender_department) : 'Không xác định'; ?></strong>
   </div>
   <div class="meta-item">
      <span class="meta-label">Trạng thái:</span>
      <span class="feedback-status status-<?php 
    echo $feedback['status'] == 1 ? 'waiting' : 
        ($feedback['status'] == 2 ? 'responded' : 'completed'); 
?>">
         <?php if ($feedback['status'] == 1): ?>
    <i class="far fa-clock mr-1"></i>
<?php elseif ($feedback['status'] == 2): ?>
    <i class="fas fa-reply mr-1"></i>
<?php elseif ($feedback['status'] == 3): ?>
    <i class="fas fa-check-circle mr-1"></i>
<?php else: ?>
    <i class="fas fa-check-circle mr-1"></i>
<?php endif; ?>
          <?php echo getStatusText($feedback['status']); ?>
      </span>
  </div>
</div>

<div class="feedback-content">
   <div><strong>Nội dung:</strong></div>
   
       <?php 
       // Fix line break issue by properly converting stored line breaks
       $content = $feedback['content'];

       // Nếu nội dung có các chuỗi "\\r\n" (escaped line break), chuyển về dạng thật
       $content = str_replace("\\r\\n\\r\\n", "\r\n\r\n", $content);
       $content = str_replace("\\r\\n", "\r\n", $content);
       
       // Hiển thị nội dung, giữ dòng gốc, bảo mật HTML
       echo '<div style="white-space: pre-wrap; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; font-size: 15px;">' . htmlspecialchars($content) . '</div>';
       ?>
</div>
                
                <?php if (!empty($feedback_attachments)): ?>
                <div class="attachments-container">
                  <div class="row">
                  <?php foreach ($feedback_attachments as $attachment): ?>
                      <?php if ($attachment['type'] === 'image'): ?>
                      <div class="col-md-4 col-6 mb-2">
                          <img src="<?php echo htmlspecialchars($attachment['path']); ?>" alt="Hình ảnh đính kèm" 
                              class="img-fluid rounded" style="max-height: 150px; cursor: pointer;" 
                              onclick="openImageModal('<?php echo htmlspecialchars($attachment['path']); ?>')">
                      </div>
                      <?php elseif ($attachment['type'] === 'pdf'): ?>
                      <div class="col-12 mb-2">
                          <div class="attachment-preview">
                              <i class="fas fa-file-pdf text-danger"></i>
                              <a href="<?php echo htmlspecialchars($attachment['path']); ?>" target="_blank">
                                  <?php echo htmlspecialchars($attachment['name']); ?>
                              </a>
                              <a href="<?php echo htmlspecialchars($attachment['path']); ?>" download class="ml-auto">
                                  <i class="fas fa-download"></i>
                              </a>
                          </div>
                      </div>
                      <?php else: ?>
                      <div class="col-12 mb-2">
                          <div class="attachment-preview">
                              <i class="fas fa-file-<?php echo getFileIconClass($attachment['ext']); ?>"></i>
                              <a href="<?php echo htmlspecialchars($attachment['path']); ?>" target="_blank" download>
                          <?php echo htmlspecialchars($attachment['name']); ?>
                      </a>
                  </div>
              </div>
              <?php endif; ?>
          <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
    </div>
<?php if ($feedback['status'] > 1): ?>
<div class="response-label">
    <h6>Phản hồi:</h6>
</div>
<?php endif; ?>
   
<?php
// Tìm đoạn code hiển thị chat container (khoảng dòng 500-600)
// Thay thế TOÀN BỘ đoạn code hiển thị chat container bằng đoạn này:
?>
<div class="chat-container" id="chatContainer" style="<?php echo $show_chat ? '' : 'display: none;'; ?>">
    <?php if ($is_owner && $feedback['status'] == 1): ?>
    <div class="system-message">
        <span>Ý kiến của bạn đang chờ xử lý. Bạn sẽ nhận được phản hồi sớm.</span>
    </div>
    <?php elseif (empty($responses)): ?>
    <div class="system-message">
        <span>Chưa có phản hồi nào.</span>
    </div>
    <?php else: ?>
        <?php foreach ($responses as $response): ?>
            <div class="message <?php echo $response['responder_id'] == $mysql_user ? 'message-user' : 'message-other'; ?>">
                <div class="message-header">
                    <?php 
                    // Kiểm tra xem tin nhắn có phải của người đang xem không
                    if ($response['responder_id'] == $mysql_user) {
                        echo 'Bạn';
                    } else {
                        // Lấy bộ phận của người gửi tin nhắn
                        $responder_dept = Select_Value_by_Condition("department", "user_tb", "staff_id", $response['responder_id']);
                        
                        // Lấy tên của người gửi tin nhắn
                        $responder_name = isset($response['name']) ? $response['name'] : '';
                        
                        // Kiểm tra xem người gửi tin nhắn có thuộc bộ phận xử lý không
                        if ($responder_dept == $feedback['handling_department']) {
                            // Nếu thuộc bộ phận xử lý, hiển thị tên bộ phận
                            echo htmlspecialchars($responder_dept);
                        } else if ($feedback['is_anonymous'] == 1) {
                            // Nếu feedback là ẩn danh
                            $anonymous_codes = isset($_SESSION['anonymous_codes']) ? $_SESSION['anonymous_codes'] : [];
                            
                            // Nếu người gửi tin nhắn là người tạo feedback ẩn danh
                            if (in_array($feedback['anonymous_code'], $anonymous_codes)) {
                                echo 'Ẩn danh';
                            } else {
                                // Hiển thị tên người gửi
                                echo htmlspecialchars($responder_name);
                            }
                        } else {
                            // Nếu không thuộc bộ phận xử lý và không phải ẩn danh, hiển thị tên
                            echo htmlspecialchars($responder_name);
                        }
                    } 
                    ?> • <?php echo formatDate($response['created_at'], true); ?>
                </div>
                
                <div class="message-bubble">
                    <div class="message-text">
                        <?php 
                        // Fix line break issue by properly converting stored line breaks
                        $response_text = $response['response'];

                        // Chuyển các ký tự xuống dòng escaped về dạng thật
                        $response_text = str_replace("\\r\\n\\r\\n", "\r\n\r\n", $response_text);
                        $response_text = str_replace("\\r\\n", "\r\n", $response_text);

                        // Hiển thị nội dung giữ nguyên dòng, không cần <br>
                        echo '<div style="white-space: pre-wrap; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; font-size: 14px;">' . htmlspecialchars($response_text) . '</div>';
                        ?>
                    </div>
                    
                    <?php if (!empty($response['attachments'])): ?>
                    <div class="attachments-container">
                        <?php foreach ($response['attachments'] as $attachment): ?>
                            <?php 
                            // Log attachment information for debugging
                            error_log("Displaying attachment: " . $attachment['file_name'] . ", path: " . $attachment['file_path']);
                            
                            $file_ext = pathinfo($attachment['file_path'], PATHINFO_EXTENSION);
                            $is_image = in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']);
                            $is_pdf = strtolower($file_ext) === 'pdf';
                            
                            if ($is_image): 
                            ?>
                            <img src="<?php echo htmlspecialchars($attachment['file_path']); ?>" alt="Hình ảnh đính kèm" 
                                class="attachment-image" onclick="openImageModal('<?php echo htmlspecialchars($attachment['file_path']); ?>')">
                            <?php elseif ($is_pdf): ?>
                            <div class="attachment-preview">
                                <i class="fas fa-file-pdf text-danger"></i>
                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                                </a>
                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" download class="ml-auto">
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="attachment-preview">
                                <i class="fas fa-file-<?php echo getFileIconClass($file_ext); ?>"></i>
                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" target="_blank" download>
                                    <?php echo htmlspecialchars($attachment['file_name']); ?>
                                </a>
                                <a href="<?php echo htmlspecialchars($attachment['file_path']); ?>" download class="ml-auto">
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if ($rating): ?>
        <div class="message message-user">
            <div class="message-header">
                <?php echo $feedback['is_anonymous'] ? 'Ẩn danh' : $sender_name; ?> • <?php echo formatDate($rating['created_at']); ?>
            </div>
            <div class="message-bubble">
                <div class="rating-stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="<?php echo $i <= $rating['rating'] ? 'fas' : 'far'; ?> fa-star"></i>
                    <?php endfor; ?>
                </div>
                <?php if (!empty($rating['comment'])): ?>
                    <div class="rating-comment">
                    <?php 
                    $comment_text = $rating['comment'];

                    // Chuyển các chuỗi xuống dòng dạng text sang ký tự thực
                    $comment_text = str_replace("\\r\\n\\r\\n", "\r\n\r\n", $comment_text);
                    $comment_text = str_replace("\\r\\n", "\r\n", $comment_text);
                    
                    // Hiển thị nội dung an toàn và giữ nguyên xuống dòng
                    echo '<div style="white-space: pre-wrap;">' . htmlspecialchars($comment_text) . '</div>';
                    ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
       
        <?php if ($is_owner && $feedback['status'] == 2 && !$rating): ?>
        <div class="rating-container d-block">
          <button type="button" class="rating-btn" id="showRatingBtn">
              <i class="fas fa-star"></i> Đánh giá phản hồi
          </button>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($can_reply): ?>
<div class="chat-input-container">
 <form method="post" action="" enctype="multipart/form-data" class="chat-form">
     <div class="chat-input-wrapper">
         <textarea class="chat-input" name="response" placeholder="Nhập phản hồi của bạn..." required></textarea>
         <div class="chat-actions">
             <label for="attachments" class="attachment-btn">
                 <i class="fas fa-paperclip"></i>
             </label>
             <button type="submit" name="submit_response" class="send-btn">
                 <i class="fas fa-paper-plane"></i>
             </button>
         </div>
     </div>
     
     <input type="file" id="attachments" name="attachments[]" multiple class="d-none">
     
     <div id="filePreview" class="file-preview-list" style="display: none;"></div>
 </form>
</div>
<?php endif; ?>
</div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="image-modal">
<span class="image-modal-close" onclick="closeImageModal()">&times;</span>
<img class="image-modal-content" id="modalImage">
</div>

<!-- Rating Modal -->
<div id="ratingModal" class="modal">
<div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title">Đánh giá phản hồi</h5>
        <button type="button" class="modal-close" id="closeRatingModal">&times;</button>
    </div>
    <div class="modal-body">
        <form method="post" action="" class="rating-form">
            <div class="stars">
                <div class="star-rating">
                    <input type="hidden" name="rating" id="selected-rating" value="0">
                    <div class="star-container" style="text-align: center; margin-bottom: 20px;">
                        <i class="far fa-star star-item" data-rating="1"></i>
                        <i class="far fa-star star-item" data-rating="2"></i>
                        <i class="far fa-star star-item" data-rating="3"></i>
                        <i class="far fa-star star-item" data-rating="4"></i>
                        <i class="far fa-star star-item" data-rating="5"></i>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <textarea class="form-control" name="rating_comment" placeholder="Nhận xét của bạn (không bắt buộc)" rows="3"></textarea>
            </div>
            <div class="text-right">
                <button type="submit" name="submit_rating" class="btn btn-primary" id="submit-rating-btn" disabled>Gửi đánh giá</button>
            </div>
        </form>
    </div>
</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
   // Scroll to bottom of chat container
   scrollToBottom();
   
   // Handle rating modal
   $("#showRatingBtn, #showRatingBtnMobile").click(function() {
       $("#ratingModal").fadeIn();
   });
   
   $("#closeRatingModal").click(function() {
       $("#ratingModal").fadeOut();
   });
   
   // Close modal when clicking outside
   $(window).click(function(event) {
       if ($(event.target).is("#ratingModal")) {
           $("#ratingModal").fadeOut();
       }
       if ($(event.target).is("#imageModal")) {
           closeImageModal();
       }
   });
   
   // New star rating system
   $('.star-item').on('click', function() {
       const rating = $(this).data('rating');
       $('#selected-rating').val(rating);
       
       // Update stars display
       $('.star-item').each(function() {
           const starValue = $(this).data('rating');
           if (starValue <= rating) {
               $(this).removeClass('far').addClass('fas');
           } else {
               $(this).removeClass('fas').addClass('far');
           }
       });
       
       // Enable submit button
       $('#submit-rating-btn').prop('disabled', false);
   });
   
   // Hover effect for stars
   $('.star-item').hover(
       function() {
           const hoverRating = $(this).data('rating');
           
           $('.star-item').each(function() {
               const starValue = $(this).data('rating');
               if (starValue <= hoverRating) {
                   $(this).removeClass('far').addClass('fas');
               }
           });
       },
       function() {
           const selectedRating = $('#selected-rating').val();
           
           $('.star-item').each(function() {
               const starValue = $(this).data('rating');
               if (starValue <= selectedRating) {
                   $(this).removeClass('far').addClass('fas');
               } else {
                   $(this).removeClass('fas').addClass('far');
               }
           });
       }
   );
   
   // File upload handling
   $('#attachments').change(function() {
       const files = this.files;
       if (files.length > 0) {
           const filePreview = $('#filePreview');
           filePreview.html('');
           filePreview.show();
           
           // Create preview for each file
           for (let i = 0; i < files.length; i++) {
               const file = files[i];
               const fileItem = $('<div class="file-preview-item"></div>');
               const icon = getFileIcon(file.name);
               
               fileItem.append(`<i class="${icon}"></i>`);
               fileItem.append(`<span class="file-preview-name">${file.name}</span>`);
               fileItem.append(`<span class="text-muted ml-2">(${formatFileSize(file.size)})</span>`);
               fileItem.append(`<span class="file-preview-remove" data-index="${i}"><i class="fas fa-times"></i></span>`);
               
               filePreview.append(fileItem);
           }
       } else {
           $('#filePreview').hide();
       }
   });
   
   // Remove file from selection
   $(document).on('click', '.file-preview-remove', function() {
       const index = $(this).data('index');
       const input = document.getElementById('attachments');
       
       // Create a new FileList without the removed file
       const dt = new DataTransfer();
       const files = input.files;
       
       for (let i = 0; i < files.length; i++) {
           if (i !== index) {
               dt.items.add(files[i]);
           }
       }
       
       input.files = dt.files;
       
       // Trigger change event to update preview
       $(input).trigger('change');
   });
   
   // Auto-resize textarea
   $('.chat-input').on('input', function() {
       this.style.height = 'auto';
       this.style.height = (this.scrollHeight) + 'px';
   });
   
   // Hiển thị toast khi trang tải xong
   if ($('.toast').length > 0) {
       $('.toast').each(function() {
           $(this).css('opacity', '1');
           
           // Tự động ẩn sau 4 giây
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
   
   // Cải thiện trải nghiệm cuộn
   setupScrolling();
   
   // Thêm sự kiện resize
   window.addEventListener('resize', function() {
       setupScrolling();
   });
   
   // Quan trọng: Đánh dấu feedback đã đọc và lưu vào localStorage
   const feedbackId = <?php echo $feedback_id; ?>;
   
   // Lưu vào localStorage để dashboard biết feedback này đã được đọc
   localStorage.setItem('feedback_' + feedbackId + '_read', 'true');
});


// Function to scroll to bottom of chat container
function scrollToBottom() {
   const chatContainer = document.getElementById('chatContainer');
   if (chatContainer) {
       chatContainer.scrollTop = chatContainer.scrollHeight;
   }
}

// Function to open image modal
function openImageModal(imageSrc) {
   const modal = document.getElementById('imageModal');
   const modalImg = document.getElementById('modalImage');
   
   modal.style.display = 'block';
   modalImg.src = imageSrc;
   
   // Add animation
   setTimeout(() => {
       modal.style.opacity = '1';
   }, 10);
}

// Function to close image modal
function closeImageModal() {
   const modal = document.getElementById('imageModal');
   modal.style.opacity = '0';
   
   // Wait for animation to complete
   setTimeout(() => {
       modal.style.display = 'none';
   }, 300);
}

// Function to setup proper scrolling
function setupScrolling() {
    // Đảm bảo cuộn đến cuối khi có tin nhắn mới
    scrollToBottom();
    
    // Xử lý vấn đề với viewport height
    const vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
    
    // Điều chỉnh chiều cao container trên mobile
    if (window.innerWidth <= 767) {
        document.querySelector('.container').style.height = `${window.innerHeight}px`;
        document.querySelector('.feedback-card').style.height = `${window.innerHeight}px`;
    }
}

// Thêm sự kiện resize để cập nhật kích thước khi xoay màn hình
window.addEventListener('resize', function() {
    setupScrolling();
});

// Gọi setupScrolling khi trang tải xong
$(document).ready(function() {
    setupScrolling();
    // Các code khác giữ nguyên
});

// Helper function to get file icon based on extension
function getFileIcon(fileName) {
   const ext = fileName.split('.').pop().toLowerCase();
   
   switch(ext) {
       case 'pdf':
           return 'fas fa-file-pdf text-danger';
       case 'doc':
       case 'docx':
           return 'fas fa-file-word text-primary';
       case 'xls':
       case 'xlsx':
           return 'fas fa-file-excel text-success';
       case 'ppt':
       case 'pptx':
           return 'fas fa-file-powerpoint text-warning';
       case 'txt':
       case 'rtf':
           return 'fas fa-file-alt text-secondary';
       case 'csv':
           return 'fas fa-file-csv text-success';
       case 'jpg':
       case 'jpeg':
       case 'png':
       case 'gif':
       case 'bmp':
       case 'webp':
           return 'fas fa-file-image text-info';
       default:
           return 'fas fa-file text-secondary';
   }
}

// Helper function to format file size
function formatFileSize(bytes) {
   if (bytes < 1024) {
       return bytes + ' B';
   } else if (bytes < 1024 * 1024) {
       return (bytes / 1024).toFixed(1) + ' KB';
   } else {
       return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
   }
}

// Set a flag in sessionStorage to indicate we're leaving the view_feedback page
// This will be checked when we return to the dashboard to refresh notifications
$(window).on('beforeunload', function() {
    sessionStorage.setItem('refreshAfterViewFeedback', 'true');
});

// Clear existing notifications for this feedback
$(document).ready(function() {
   const feedbackId = <?php echo $feedback['id']; ?>;
   
   // Cập nhật thời gian đọc cuối cùng khi người dùng xem feedback
   $.ajax({
       url: 'mark_messages_read.php',
       type: 'POST',
       data: { feedback_id: feedbackId },
       dataType: 'json',
       success: function(response) {
           if (response.success) {
               console.log('Đã đánh dấu tin nhắn là đã đọc');
               
               // Đặt flag để dashboard biết cần tải lại khi quay lại
               sessionStorage.setItem('refreshAfterViewFeedback', 'true');
               
               // Lưu danh sách các feedback đã xem
               let viewedFeedbacks = JSON.parse(sessionStorage.getItem('viewedFeedbacks') || '[]');
               if (!viewedFeedbacks.includes(feedbackId)) {
                   viewedFeedbacks.push(feedbackId);
                   sessionStorage.setItem('viewedFeedbacks', JSON.stringify(viewedFeedbacks));
               }
           }
       }
   });
   
   // Cập nhật localStorage để đánh dấu feedback là đã xem
   const viewedFeedbacks = JSON.parse(localStorage.getItem('viewedFeedbacks') || '{}');
   viewedFeedbacks[feedbackId] = new Date().toISOString();
   localStorage.setItem('viewedFeedbacks', JSON.stringify(viewedFeedbacks));
});

// Set a flag in sessionStorage to indicate we're leaving the view_feedback page
// This will be checked when we return to the dashboard to refresh notifications
$(window).on('beforeunload', function() {
   const feedbackId = <?php echo $feedback['id']; ?>;
   sessionStorage.setItem('refreshAfterViewFeedback', 'true');
   
   // Lưu danh sách các feedback đã xem
   let viewedFeedbacks = JSON.parse(sessionStorage.getItem('viewedFeedbacks') || '[]');
   if (!viewedFeedbacks.includes(feedbackId)) {
       viewedFeedbacks.push(feedbackId);
       sessionStorage.setItem('viewedFeedbacks', JSON.stringify(viewedFeedbacks));
   }
});

$(document).ready(function() {
    // Mark messages as read when viewing a feedback
    const feedbackId = <?php echo $feedback_id; ?>;
    
    $.ajax({
        url: 'mark_messages_read.php',
        type: 'POST',
        data: {
            feedback_id: feedbackId
        },
        dataType: 'json',
        success: function(response) {
            console.log('Marked messages as read for feedback:', feedbackId);
        }
    });
    
    // Set a flag to refresh notifications when returning to dashboard
    sessionStorage.setItem('refreshAfterViewFeedback', 'true');
});

// Set a flag in sessionStorage when leaving the page
$(window).on('beforeunload', function() {
    sessionStorage.setItem('refreshAfterViewFeedback', 'true');
});
</script>

<script>
// Đảm bảo chiều cao viewport được tính đúng trên thiết bị di động
function setMobileHeight() {
    // Đặt biến CSS --vh để sử dụng trong CSS
    let vh = window.innerHeight * 0.01;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
    
    // Điều chỉnh chiều cao container trên mobile
    if (window.innerWidth <= 767) {
        document.querySelector('.container').style.height = `${window.innerHeight}px`;
        document.querySelector('.feedback-card').style.height = `${window.innerHeight}px`;
    }
}

// Gọi hàm khi trang tải và khi thay đổi kích thước
window.addEventListener('load', setMobileHeight);
window.addEventListener('resize', setMobileHeight);
window.addEventListener('orientationchange', setMobileHeight);

// Đảm bảo thanh cuộn hoạt động đúng
document.addEventListener('DOMContentLoaded', function() {
    // Đảm bảo chat container có thể cuộn
    const chatContainer = document.getElementById('chatContainer');
    if (chatContainer) {
        chatContainer.style.overflowY = 'auto';
        chatContainer.style.webkitOverflowScrolling = 'touch';
    }
    
    // Đảm bảo feedback info có thể cuộn
    const feedbackInfo = document.querySelector('.feedback-info');
    if (feedbackInfo) {
        feedbackInfo.style.overflowY = 'auto';
        feedbackInfo.style.webkitOverflowScrolling = 'touch';
    }
    
    // Cuộn xuống cuối chat container
    scrollToBottom();
});
</script>

<style>
/* Add this CSS rule to ensure proper padding on mobile */
@media (max-width: 767px) {
    .feedback-header {
        padding: 20px;
        position: relative;
    }
    
    .close-btn {
        top: 10px;
        right: 10px;
    }
    
    .feedback-title {
        padding-right: 20px;
        font-size: 0.95rem;
    }
    
    .feedback-info {
        padding: 10px;
    }
    
    .chat-container {
        padding: 10px;
    }
    
    .chat-input-container {
        padding: 10px;
    }
}
</style>

<style>
/* Ensure consistent padding and alignment between header and content */
@media (max-width: 767px) {
    .feedback-header {
        padding: 15px;
        position: relative;
    }
    
    .close-btn {
        top: 15px;
        right: 15px;
    }
    
    .feedback-title {
        padding-right: 25px;
        font-size: 1.1rem;
    }
    
    .feedback-info {
        padding: 15px;
    }
    
    .chat-container {
        padding: 15px;
    }
    
    .chat-input-container {
        padding: 15px;
    }
}
</style>
<script>
function fixMobileLayout() {
    // Use actual viewport height instead of vh units which can be inaccurate on mobile
    const viewportHeight = window.innerHeight;
    
    if (window.innerWidth <= 767) {
        // Set container height
        document.querySelector('.container').style.height = `${viewportHeight}px`;
        document.querySelector('.feedback-card').style.height = `${viewportHeight}px`;
        
        // Calculate available space for main content
        const headerHeight = document.querySelector('.feedback-header').offsetHeight;
        const formHeight = document.querySelector('.chat-form')?.offsetHeight || 0;
        
        // Set main content height
        const mainContent = document.querySelector('.main-content');
        mainContent.style.height = `${viewportHeight - headerHeight - formHeight}px`;
        
        // Make sure chat container fills available space
        const feedbackInfoHeight = document.querySelector('.feedback-info').offsetHeight;
        const responseLabel = document.querySelector('.response-label')?.offsetHeight || 0;
        
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer) {
            chatContainer.style.minHeight = `${mainContent.offsetHeight - feedbackInfoHeight - responseLabel}px`;
        }
    } else {
        // Reset styles on desktop
        document.querySelector('.container').style.height = '';
        document.querySelector('.feedback-card').style.height = '';
        document.querySelector('.main-content').style.height = '';
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer) {
            chatContainer.style.minHeight = '';
        }
    }
    
    // Scroll to bottom after layout fixes
    scrollToBottom();
}
</script>

<script>
// Asegurar que los adjuntos se muestren correctamente
$(document).ready(function() {
    // Verificar si hay adjuntos en cada mensaje
    $('.message').each(function() {
        const attachmentsContainer = $(this).find('.attachments-container');
        if (attachmentsContainer.length > 0 && attachmentsContainer.children().length === 0) {
            attachmentsContainer.hide();
        } else if (attachmentsContainer.length > 0) {
            attachmentsContainer.show();
        }
    });
    
    // Manejar errores de carga de imágenes
    $('.attachment-image').on('error', function() {
        console.error('Error al cargar la imagen:', $(this).attr('src'));
        // Reemplazar con un icono de error
        const errorDiv = $('<div class="attachment-preview"></div>');
        errorDiv.html('<i class="fas fa-exclamation-triangle text-warning"></i> <span>Error al cargar la imagen</span>');
        $(this).replaceWith(errorDiv);
    });
    
    // Verificar enlaces de adjuntos
    $('.attachment-preview a').each(function() {
        const href = $(this).attr('href');
        if (!href || href === '#' || href === 'undefined') {
            console.error('Enlace de adjunto inválido');
            $(this).addClass('text-danger').attr('title', 'Enlace inválido');
            $(this).on('click', function(e) {
                e.preventDefault();
                alert('No se puede acceder a este archivo');
            });
        }
    });
});
</script>


<script>
// Asegurar que los adjuntos se muestren correctamente
$(document).ready(function() {
    console.log("Verificando adjuntos en mensajes...");
    
    // Verificar si hay adjuntos en cada mensaje
    $('.message').each(function() {
        const attachmentsContainer = $(this).find('.attachments-container');
        console.log("Contenedor de adjuntos encontrado:", attachmentsContainer.length);
        
        if (attachmentsContainer.length > 0) {
            const attachmentItems = attachmentsContainer.children();
            console.log("Elementos de adjuntos:", attachmentItems.length);
            
            if (attachmentItems.length === 0) {
                console.log("No hay elementos de adjuntos, ocultando contenedor");
                attachmentsContainer.hide();
            } else {
                console.log("Mostrando contenedor de adjuntos");
                attachmentsContainer.show();
            }
        }
    });
    
    // Manejar errores de carga de imágenes
    $('.attachment-image').on('error', function() {
        console.error('Error al cargar la imagen:', $(this).attr('src'));
        // Reemplazar con un icono de error
        const errorDiv = $('<div class="attachment-preview"></div>');
        errorDiv.html('<i class="fas fa-exclamation-triangle text-warning"></i> <span>Error al cargar la imagen</span>');
        $(this).replaceWith(errorDiv);
    });
    
    // Verificar enlaces de adjuntos
    $('.attachment-preview a').each(function() {
        const href = $(this).attr('href');
        console.log("Verificando enlace de adjunto:", href);
        
        if (!href || href === '#' || href === 'undefined') {
            console.error('Enlace de adjunto inválido');
            $(this).addClass('text-danger').attr('title', 'Enlace inválido');
            $(this).on('click', function(e) {
                e.preventDefault();
                alert('No se puede acceder a este archivo');
            });
        }
    });
});
</script>
