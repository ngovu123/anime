<?php
session_start();
include("../connect.php");
include("functions.php");
include("email_notification.php");
include("email_functions.php");
// Check if user is logged in
if (!isset($_SESSION["SS_username"])) {
    header("Location: login.php");
    exit();
}

$mysql_user = $_SESSION["SS_username"];
$user_info = getUserDepartmentAndSection($mysql_user);
$section = $user_info["section"]; // User's section from user_tb

// Check if feedback ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    error_log("Redirecting to dashboard: No ID provided");
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
$sql = "SELECT f.*, u.section as sender_section 
        FROM feedback_tb f 
        LEFT JOIN user_tb u ON f.staff_id = u.staff_id 
        WHERE f.id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param("i", $feedback_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $feedback = $result->fetch_assoc();

    // Check if user is the owner
    if ($feedback['staff_id'] == $mysql_user) {
        $is_owner = true;
        $is_handler = false;
        error_log("User {$mysql_user} is owner of feedback ID {$feedback_id}");
    } elseif ($feedback['is_anonymous'] == 1 && 
             ((isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) || 
              (isset($_SESSION['temp_anonymous_view']) && in_array($feedback['anonymous_code'], $_SESSION['temp_anonymous_view'])))) {
        $is_owner = true;
        $is_handler = false;
        error_log("User {$mysql_user} is anonymous owner of feedback ID {$feedback_id}");
    } else {
        // Check if user is a handler by matching their section with handling_staff's section in handling_department_tb
        $sql_handler = "SELECT 1 
                        FROM handling_department_tb hdt 
                        JOIN user_tb u ON FIND_IN_SET(u.staff_id, hdt.handling_staff)
                        WHERE hdt.department_name = ? AND u.section = ?";
        error_log("SQL: $sql_handler, handling_department: {$feedback['handling_department']}, section: $section");
        
        if (empty($feedback['handling_department']) || empty($section)) {
            error_log("Invalid parameters: handling_department={$feedback['handling_department']}, section=$section");
            header("Location: dashboard.php");
            exit();
        }
        
        $stmt_handler = $db->prepare($sql_handler);
        if ($stmt_handler === false) {
            error_log("Prepare failed: " . $db->error);
            header("Location: dashboard.php");
            exit();
        }
        
        $stmt_handler->bind_param("ss", $feedback['handling_department'], $section);
        $stmt_handler->execute();
        $handler_result = $stmt_handler->get_result();
        if ($handler_result->num_rows > 0) {
            $is_handler = true;
            $is_owner = false;
            error_log("User {$mysql_user} (section: {$section}) is handler for feedback ID {$feedback_id} (handling_department: {$feedback['handling_department']})");
        } else {
            error_log("Redirecting to dashboard: User {$mysql_user} (section: {$section}) not authorized for feedback ID {$feedback_id} (handling_department: {$feedback['handling_department']})");
            header("Location: dashboard.php");
            exit();
        }
        $stmt_handler->close();
    }

    $is_own_anonymous_to_own_section = false;
    if ($feedback['is_anonymous'] == 1 && $is_handler) {
        if (isset($_SESSION['created_anonymous_feedbacks'][$feedback_id]) && 
            $_SESSION['created_anonymous_feedbacks'][$feedback_id]['user_id'] === $mysql_user) {
            $is_own_anonymous_to_own_section = true;
        }
    }

    if ($is_own_anonymous_to_own_section) {
        $is_handler = false;
    }

    $is_own_anonymous = false;
    if ($feedback['is_anonymous'] == 1) {
        if (isset($_SESSION['created_anonymous_feedbacks'][$feedback_id]) && 
            $_SESSION['created_anonymous_feedbacks'][$feedback_id]['user_id'] === $mysql_user) {
            $is_own_anonymous = true;
        } else if (isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) {
            $is_own_anonymous = true;
        }
    }

    $can_delete = false;
    if ($feedback['status'] == 1) {
        if ($feedback['is_anonymous'] == 1) {
            if ($is_own_anonymous) {
                $can_delete = true;
            }
        } else if ($is_owner) {
            $can_delete = true;
        }
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
            $response_id = $row['id'];
            $row['attachments'] = getResponseAttachments($response_id);
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

    updateLastViewed($feedback_id, $mysql_user);

    $viewing_anonymous = $feedback['is_anonymous'] == 1 && isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes']);

    if (!isset($_SESSION['read_feedbacks'])) {
        $_SESSION['read_feedbacks'] = [];
    }
    if (!in_array($feedback_id, $_SESSION['read_feedbacks'])) {
        $_SESSION['read_feedbacks'][] = $feedback_id;
    }

    markFeedbackNotificationsAsRead($feedback_id, $mysql_user);

    echo "<script>
        localStorage.setItem('feedback_" . $feedback_id . "_read', 'true');
        sessionStorage.setItem('refreshAfterViewFeedback', 'true');
    </script>";

} else {
    error_log("Redirecting to dashboard: Feedback ID {$feedback_id} not found");
    header("Location: dashboard.php");
    exit();
}

function generateProfessionalEmail($subject, $greeting, $body_content, $feedback_id, $feedback_title, $attachment_paths = []) {
    $body_content_with_attachment = $body_content;
    if (!empty($attachment_paths)) {
        $body_content_with_attachment .= '<br><br>Nội dung phản hồi có đính kèm tệp';
    }

    $content = '
        <p style="margin: 0 0 12px; color: #333333;">
            <span style="font-weight: bold; text-decoration: underline;">Kính gửi: </span> ' . htmlspecialchars($greeting) . ',
        </p>
        <div style="margin: 0 0 15px; line-height: 1.6; color: #333333;">' . $body_content_with_attachment . '</div>
        <hr style="border: 0; border-top: 1px solid #e0e0e0; margin: 15px 0;">
        <p style="margin: 0; font-size: 12px; color: #666666;">Cảm ơn quý vị đã sử dụng Hệ thống phản hồi ý kiến.</p>
        <p style="margin: 5px 0 0; font-size: 12px; color: #666666;">Trân trọng,<br>Hệ thống phản hồi ý kiến</p>
    ';

    return formatEmailBody($content);
}

// Handle response submission
if (isset($_POST['submit_response'])) {
    $response_text = sanitizeInputWithLineBreaks($_POST['response']);

    if (empty($response_text)) {
        $error_message = "Vui lòng nhập nội dung phản hồi.";
    } else {
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
                    $max_size = 20 * 1024 * 1024;
                    
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
            if (saveAndNotifyNewResponse($feedback_id, $mysql_user, $response_text, $attachment_paths)) {
                $success_message = "Gửi phản hồi thành công!";
                
                if ($is_handler && $feedback['status'] == 1) {
                    $update_sql = "UPDATE feedback_tb SET status = 2 WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->bind_param("i", $feedback_id);
                    $update_stmt->execute();
                    sendFeedbackStatusNotification($feedback_id, 2);
                }

                // Send professional email notification
                $handling_department = $feedback['handling_department'];
                $subject = "Phản hồi mới: {$feedback['title']}";
                
                $clean_response_text = str_replace("\r\n\r\n", "\n\n", $response_text);
                $clean_response_text = str_replace("\r\n", "\n", $clean_response_text);
                
                $body_content = "<strong>Nội dung phản hồi:</strong><br>" . nl2br(htmlspecialchars($clean_response_text));
                
                $recipient_emails = [];
                if ($is_handler) {
                    $user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $mysql_user);
                    $body_content = "Phản hồi từ <strong>{$user_name} ({$mysql_user})</strong>:<br><br>";
                    $body_content .= "<strong>Nội dung phản hồi:</strong><br>" . nl2br(htmlspecialchars($clean_response_text));
                    
                    if ($feedback['is_anonymous'] == 1) {
                        if (isset($_SESSION['created_anonymous_feedbacks'][$feedback_id])) {
                            $owner_id = $_SESSION['created_anonymous_feedbacks'][$feedback_id]['user_id'];
                            $owner_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $owner_id);
                            if ($owner_email) {
                                $recipient_emails[] = $owner_email;
                            }
                        }
                    } else {
                        $owner_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $feedback['staff_id']);
                        if ($owner_email) {
                            $recipient_emails[] = $owner_email;
                        }
                    }
                    $greeting = $feedback['is_anonymous'] ? 'Người gửi ý kiến' : Select_Value_by_Condition("name", "user_tb", "staff_id", $feedback['staff_id']);
                } else {
                    if ($feedback['is_anonymous'] == 1) {
                        $body_content = "Bạn đã nhận được phản hồi từ <strong>Ẩn danh</strong>:<br><br>";
                    } else {
                        $user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $mysql_user);
                        $body_content = " Bạn đã nhận được phản hồi từ <strong>{$user_name} ({$mysql_user})</strong>:<br><br>";
                    }
                    $body_content .= "<strong>Nội dung phản hồi:</strong><br>" . nl2br(htmlspecialchars($clean_response_text));
                    $recipient_emails = getDepartmentEmails($handling_department);
                    $greeting = "Đội ngũ {$handling_department}";
                }
                
                // Remove the current user's email from recipients
                $current_user_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $mysql_user);
                if ($current_user_email) {
                    $recipient_emails = array_filter($recipient_emails, function($email) use ($current_user_email) {
                        return trim($email) !== trim($current_user_email);
                    });
                }
                
                $recipient_emails = array_filter(array_map('trim', $recipient_emails));
                
                if (!empty($recipient_emails)) {
                    $email_html = generateProfessionalEmail(
                        $subject,
                        $greeting,
                        $body_content,
                        $feedback['feedback_id'],
                        htmlspecialchars($feedback['title']),
                        $attachment_paths
                    );
                    
                    $sent = sendDepartmentEmailListNotificationWithAttachments($recipient_emails, $subject, $email_html, $attachment_paths, true);
                    if ($sent) {
                        error_log("Professional email sent successfully to: " . implode(", ", $recipient_emails));
                    } else {
                        error_log("Failed to send professional email, check PHPMailer logs");
                    }
                } else {
                    error_log("No recipient emails found for notification");
                    $admin_email = 'admin@example.com';
                    $admin_subject = "Lỗi gửi thông báo phản hồi: $subject";
                    $admin_body = $body_content . "<br><br>Lỗi: Không tìm thấy email người nhận.";
                    $admin_html = generateProfessionalEmail(
                        $admin_subject,
                        'Quản trị viên',
                        $admin_body,
                        $feedback['feedback_id'],
                        htmlspecialchars($feedback['title']),
                        $attachment_paths
                    );
                    sendDepartmentEmailListNotificationWithAttachments([$admin_email], $admin_subject, $admin_html, $attachment_paths, true);
                    error_log("Sent warning email to admin: $admin_email");
                }
                
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
            $sql = "UPDATE feedback_tb SET status = 3 WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $feedback_id);
            if ($stmt->execute()) {
                sendFeedbackStatusNotification($feedback_id, 3);
            }
            
            // Notify handling department about rating
            $recipient_emails = getDepartmentEmails($feedback['handling_department']);
            if (!empty($recipient_emails)) {
                $subject = "Đánh giá ý kiến: {$feedback['title']}";
                $body_content = "Ý kiến đã được đánh giá:<br><br>";
                $body_content .= "<strong>Điểm đánh giá:</strong> {$rating_value}/5<br>";
                if (!empty($rating_comment)) {
                    $body_content .= "<strong>Nhận xét:</strong><br>" . nl2br(htmlspecialchars($rating_comment));
                }
                
                $email_html = generateProfessionalEmail(
                    $subject,
                    "Đội ngũ {$feedback['handling_department']}",
                    $body_content,
                    $feedback['feedback_id'],
                    htmlspecialchars($feedback['title'])
                );
                
                $sent = sendDepartmentEmailListNotificationWithAttachments($recipient_emails, $subject, $email_html, [], true);
                if ($sent) {
                    error_log("Rating notification email sent successfully to: " . implode(", ", $recipient_emails));
                } else {
                    error_log("Failed to send rating notification email");
                }
            }
            
            $success_message = "Gửi đánh giá thành công!";
            header("Location: dashboard.php?success=rating");
            exit();
        } else {
            $error_message = "Có lỗi xảy ra: " . $stmt->error;
        }
    }
}

// Handle status change
if (isset($_POST['change_status']) && $is_handler) {
    $new_status = intval($_POST['status']);
    if (($new_status == 2 && $feedback['status'] == 1) || ($new_status == 1 && $feedback['status'] == 2)) {
        $sql = "UPDATE feedback_tb SET status = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ii", $new_status, $feedback_id);
        
        if ($stmt->execute()) {
            $feedback['status'] = $new_status;
            sendFeedbackStatusNotification($feedback_id, $new_status);
            
            // Send status change notification
            $recipient_emails = [];
            if ($feedback['is_anonymous'] == 1) {
                if (isset($_SESSION['created_anonymous_feedbacks'][$feedback_id])) {
                    $owner_id = $_SESSION['created_anonymous_feedbacks'][$feedback_id]['user_id'];
                    $owner_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $owner_id);
                    if ($owner_email) {
                        $recipient_emails[] = $owner_email;
                    }
                }
            } else {
                $owner_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $feedback['staff_id']);
                if ($owner_email) {
                    $recipient_emails[] = $owner_email;
                }
            }
            
            if (!empty($recipient_emails)) {
                $subject = "Cập nhật trạng thái: {$feedback['title']}";
                $status_text = getStatusText($new_status);
                $body_content = "Trạng thái ý kiến đã được cập nhật thành <strong>$status_text</strong>.";
                
                $email_html = generateProfessionalEmail(
                    $subject,
                    $feedback['is_anonymous'] ? 'Người gửi ý kiến' : Select_Value_by_Condition("name", "user_tb", "staff_id", $feedback['staff_id']),
                    $body_content,
                    $feedback['feedback_id'],
                    htmlspecialchars($feedback['title'])
                );
                
                $sent = sendDepartmentEmailListNotificationWithAttachments($recipient_emails, $subject, $email_html, [], true);
                if ($sent) {
                    error_log("Status change notification email sent successfully to: " . implode(", ", $recipient_emails));
                } else {
                    error_log("Failed to send status change notification email");
                }
            }
            
            $success_message = "Cập nhật trạng thái thành công!";
            header("Location: view_feedback.php?id=$feedback_id&success=3");
            exit();
        } else {
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

$sender_name = $feedback['is_anonymous'] ? 'Ẩn danh' : Select_Value_by_Condition("name", "user_tb", "staff_id", $feedback['staff_id']);

$can_reply = false;
$is_own_anonymous_to_section = ($feedback['is_anonymous'] == 1 && 
                              $is_handler && 
                              ((isset($_SESSION['anonymous_codes']) && in_array($feedback['anonymous_code'], $_SESSION['anonymous_codes'])) || 
                               (isset($_SESSION['temp_anonymous_view']) && in_array($feedback['anonymous_code'], $_SESSION['temp_anonymous_view']))));

if ($is_handler && !$is_own_anonymous_to_own_section && ($feedback['status'] == 1 || $feedback['status'] == 2)) {
    $can_reply = true;
}

if ($is_owner && $feedback['status'] == 2 && !$is_own_anonymous_to_own_section) {
    $can_reply = true;
}

$show_chat = !empty($responses) || $feedback['status'] >= 2;
$show_chat_input = $can_reply;
$show_processing_message = $is_owner && $feedback['status'] == 1;

$feedback_attachments = [];
if (!empty($feedback['image_path'])) {
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
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
    padding-bottom: 0;
}
.feedback-info {
    padding: 15px;
    background-color: #fff;
    border-bottom: 1px solid #eee;
    max-height: none;
    overflow-y: visible;
}
.chat-container {
    flex: 1;
    padding: 15px 15px 0 15px;
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
    margin-top: auto;
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
    margin-bottom: 10px;
    font-size: 12px;
}
.meta-item {
    margin-bottom: 3px;
    display: flex;
    align-items: baseline;
}
.meta-label {
    min-width: 80px;
    font-weight: 500;
    color: #495057;
    font-size: 12px;
}
.feedback-content {
    margin-top: 10px;
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
    margin-bottom: 0;
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
    display: none;
}
.file-preview-list.show {
    display: block;
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
    min-height: 200px;
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
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
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
    background-color: #f5f7f9;
    border-bottom: 1px solid #eee;
}
.response-label h6 {
    margin-bottom: 10px;
    font-weight: 600;
    color: #495057;
    font-size: 14px;
}
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
@media (max-width: 767px) {
    .container {
        padding: 0;
        height: 100vh;
        overflow: hidden;
        margin: 0;
    }
    .feedback-card {
        border-radius: 0;
        height: 100vh;
        display: flex;
        flex-direction: column;
        margin: 0;
    }
    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding-bottom: 0;
        overflow: hidden;
    }
    .combined-scroll-container {
        flex: 1;
        overflow-y: auto;
        margin-bottom: 0;
    }
    .chat-container {
        padding: 10px 10px 0 10px;
        margin-bottom: 0;
        flex: 1;
    }
    .chat-input-container {
        padding: 6px;
        margin: 0;
        position: sticky;
        bottom: 0;
        width: 100%;
        box-sizing: border-box;
        background-color: #fff;
        border-top: 1px solid #eee;
    }
    .chat-input-wrapper {
        margin-bottom: 0 !important;
        padding: 6px 12px;
        position: relative;
        border-radius: 20px;
    }
    .feedback-info {
        padding-bottom: 5px;
    }
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
    .file-preview-list {
        margin: 0;
        padding: 0;
    }
    .file-preview-list.show {
        margin-top: 5px;
    }
}
@media (min-width: 768px) {
    .combined-scroll-container {
        height: calc(100vh - 80px - 65px);
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
    margin-bottom: 3px;
    display: flex;
    align-items: baseline;
}
.meta-label {
    min-width: 80px;
    font-weight: 500;
    color: #495057;
    font-size: 12px;
}
.feedback-meta {
    margin-bottom: 10px;
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
    margin-top: 10px;
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
        font-size: 0.7rem;
    }
    .meta-item {
        margin-bottom: 4px;
    }
    .feedback-status {
        font-size: 0.6rem;
        padding: 2px 4px;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="toast-container" id="toastContainer">
        <?php if (!empty($success_message)): ?>
        <div class="toast success">
            <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
            <div class="toast-message"><?php echo htmlspecialchars($success_message); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
        <div class="toast error">
            <div class="toast-icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="toast-message"><?php echo htmlspecialchars($error_message); ?></div>
        </div>
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
                            <strong><?php echo $feedback['is_anonymous'] ? 'Ẩn danh' : htmlspecialchars($feedback['staff_id'] . ' - ' . $sender_name); ?></strong>
                        </div>
                        <div class="meta-item">
                            <?php
                            $sender_section = "";
                            if (!$feedback['is_anonymous'] && !empty($feedback['staff_id'])) {
                                $sender_section = Select_Value_by_Condition("section", "user_tb", "staff_id", $feedback['staff_id']);
                            }
                            ?>
                            <span class="meta-label">Bộ phận:</span>
                            <strong><?php echo !empty($sender_section) ? htmlspecialchars($sender_section) : 'Không xác định'; ?></strong>
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
                                <?php endif; ?>
                                <?php echo getStatusText($feedback['status']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="feedback-content">
                        <div><strong>Nội dung:</strong></div>
                        <?php 
                        $content = $feedback['content'];
                        $content = str_replace("\\r\\n\\r\\n", "\r\n\r\n", $content);
                        $content = str_replace("\\r\\n", "\r\n", $content);
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
                
                <div class="chat-container" id="chatContainer" style="<?php echo $show_chat ? '' : 'display: none;'; ?>">
                    <?php if ($is_own_anonymous_to_section && $feedback['status'] == 1): ?>
                    <div class="system-message">
                        <span>Ý kiến ẩn danh của bạn đang chờ xử lý. Bạn không thể tự chat với chính mình, vui lòng chờ người khác trong bộ phận xử lý phản hồi.</span>
                    </div>
                    <?php elseif ($is_owner && $feedback['status'] == 1): ?>
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
                                    if ($response['responder_id'] == $mysql_user) {
                                        echo 'Bạn';
                                    } else {
                                        $responder_section = Select_Value_by_Condition("section", "user_tb", "staff_id", $response['responder_id']);
                                        $responder_name = isset($response['name']) ? $response['name'] : '';
                                        if ($responder_section) {
                                            echo htmlspecialchars($responder_section);
                                        } else if ($feedback['is_anonymous'] == 1) {
                                            $anonymous_codes = isset($_SESSION['anonymous_codes']) ? $_SESSION['anonymous_codes'] : [];
                                            if (in_array($feedback['anonymous_code'], $anonymous_codes)) {
                                                echo 'Ẩn danh';
                                            } else {
                                                echo htmlspecialchars($responder_name);
                                            }
                                        } else {
                                            echo htmlspecialchars($responder_name);
                                        }
                                    } 
                                    ?> • <?php echo formatDate($response['created_at'], true); ?>
                                </div>
                                
                                <div class="message-bubble">
                                    <div class="message-text">
                                        <?php 
                                        $response_text = $response['response'];
                                        $response_text = str_replace("\\r\\n\\r\\n", "\r\n\r\n", $response_text);
                                        $response_text = str_replace("\\r\\n", "\r\n", $response_text);
                                        echo '<div style="white-space: pre-wrap; word-wrap: break-word; word-break: break-word; overflow-wrap: break-word; font-size: 14px;">' . htmlspecialchars($response_text) . '</div>';
                                        ?>
                                    </div>
                                    
                                    <?php if (!empty($response['attachments'])): ?>
                                    <div class="attachments-container">
                                        <?php foreach ($response['attachments'] as $attachment): ?>
                                            <?php 
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
                                    $comment_text = str_replace("\\r\\n\\r\\n", "\r\n\r\n", $comment_text);
                                    $comment_text = str_replace("\\r\\n", "\r\n", $comment_text);
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
                        
                        <div id="filePreview" class="file-preview-list"></div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <span class="image-modal-close" onclick="closeImageModal()">×</span>
        <img class="image-modal-content" id="modalImage">
    </div>

    <!-- Rating Modal -->
    <div id="ratingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Đánh giá phản hồi</h5>
                <button type="button" class="modal-close" id="closeRatingModal">×</button>
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
    function formatFileSize(bytes) {
        if (bytes < 1024) {
            return bytes + ' B';
        } else if (bytes < 1048576) {
            return (bytes / 1024).toFixed(2) + ' KB';
        } else {
            return (bytes / 1048576).toFixed(2) + ' MB';
        }
    }

    $(document).ready(function() {
        scrollToBottom();
        
        $("#showRatingBtn, #showRatingBtnMobile").click(function() {
            $("#ratingModal").fadeIn();
        });
        
        $("#closeRatingModal").click(function() {
            $("#ratingModal").fadeOut();
        });
        
        $(window).click(function(event) {
            if ($(event.target).is("#ratingModal")) {
                $("#ratingModal").fadeOut();
            }
            if ($(event.target).is("#imageModal")) {
                closeImageModal();
            }
        });
        
        $('.star-item').on('click', function() {
            const rating = $(this).data('rating');
            $('#selected-rating').val(rating);
            
            $('.star-item').each(function() {
                const starValue = $(this).data('rating');
                if (starValue <= rating) {
                    $(this).removeClass('far').addClass('fas');
                } else {
                    $(this).removeClass('fas').addClass('far');
                }
            });
            
            $('#submit-rating-btn').prop('disabled', false);
        });
        
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
        
        $('#attachments').change(function() {
            const files = this.files;
            const filePreview = $('#filePreview');
            if (files.length > 0) {
                filePreview.html('');
                filePreview.addClass('show');
                
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
                filePreview.removeClass('show');
                filePreview.html('');
            }
            setMobileHeight();
        });
        
        $(document).on('click', '.file-preview-remove', function() {
            const index = $(this).data('index');
            const input = document.getElementById('attachments');
            
            const dt = new DataTransfer();
            const files = input.files;
            
            for (let i = 0; i < files.length; i++) {
                if (i !== index) {
                    dt.items.add(files[i]);
                }
            }
            
            input.files = dt.files;
            $(input).trigger('change');
        });
        
        $('form.chat-form').on('submit', function() {
            setTimeout(function() {
                $('#attachments').val('');
                const filePreview = $('#filePreview');
                filePreview.html('');
                filePreview.removeClass('show');
                $('.chat-input-wrapper').css('margin-bottom', '0');
                setMobileHeight();
            }, 100);
        });
        
        $('.chat-input').on('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            setMobileHeight();
        });
        
        if ($('.toast').length > 0) {
            $('.toast').each(function() {
                $(this).css('opacity', '1');
                setTimeout(() => {
                    $(this).css('opacity', '0');
                    $(this).css('transform', 'translateY(-20px)');
                    setTimeout(() => {
                        $(this).remove();
                    }, 500);
                }, 4000);
            });
        }
        
        setMobileHeight();
        window.addEventListener('resize', function() {
            setMobileHeight();
        });
        
        const feedbackId = <?php echo $feedback_id; ?>;
        localStorage.setItem('feedback_' + feedbackId + '_read', 'true');
    });

    function scrollToBottom() {
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    }

    function openImageModal(imageSrc) {
        const modal = document.getElementById('imageModal');
        const modalImg = document.getElementById('modalImage');
        
        modal.style.display = 'block';
        modalImg.src = imageSrc;
        
        setTimeout(() => {
            modal.style.opacity = '1';
        }, 10);
    }

    function closeImageModal() {
        const modal = document.getElementById('imageModal');
        modal.style.opacity = '0';
        setTimeout(() => {
            modal.style.display = 'none';
        }, 300);
    }

    function setMobileHeight() {
        let vh = window.innerHeight * 0.01;
        document.documentElement.style.setProperty('--vh', `${vh}px`);
        if (window.innerWidth <= 767) {
            const container = document.querySelector('.container');
            const feedbackCard = document.querySelector('.feedback-card');
            container.style.height = `${window.innerHeight}px`;
            feedbackCard.style.height = `${window.innerHeight}px`;

            const headerHeight = document.querySelector('.feedback-header').offsetHeight;
            const inputContainerHeight = document.querySelector('.chat-input-container')?.offsetHeight || 0;
            const combinedScrollContainer = document.querySelector('.combined-scroll-container');
            if (combinedScrollContainer) {
                combinedScrollContainer.style.height = `calc(100vh - ${headerHeight}px - ${inputContainerHeight}px)`;
            }

            const chatInputWrapper = document.querySelector('.chat-input-wrapper');
            if (chatInputWrapper) {
                chatInputWrapper.style.marginBottom = '0px';
            }
        }
    }

    window.addEventListener('load', setMobileHeight);
    window.addEventListener('resize', setMobileHeight);
    window.addEventListener('orientationchange', setMobileHeight);
    window.addEventListener('resize', () => {
        setTimeout(setMobileHeight, 100);
    });

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


    $(window).on('beforeunload', function() {
        sessionStorage.setItem('refreshAfterViewFeedback', 'true');
    });

    $(document).ready(function() {
        const feedbackId = <?php echo $feedback['id']; ?>;
        $.ajax({
            url: 'mark_messages_read.php',
            type: 'POST',
            data: { feedback_id: feedbackId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    console.log('Đã đánh dấu tin nhắn là đã đọc');
                    sessionStorage.setItem('refreshAfterViewFeedback', 'true');
                    let viewedFeedbacks = JSON.parse(sessionStorage.getItem('viewedFeedbacks') || '[]');
                    if (!viewedFeedbacks.includes(feedbackId)) {
                        viewedFeedbacks.push(feedbackId);
                        sessionStorage.setItem('viewedFeedbacks', JSON.stringify(viewedFeedbacks));
                    }
                }
            }
        });
        
        const viewedFeedbacks = JSON.parse(localStorage.getItem('viewedFeedbacks') || '{}');
        viewedFeedbacks[feedbackId] = new Date().toISOString();
        localStorage.setItem('viewedFeedbacks', JSON.stringify(viewedFeedbacks));
    });

    $(window).on('beforeunload', function() {
        const feedbackId = <?php echo $feedback['id']; ?>;
        sessionStorage.setItem('refreshAfterViewFeedback', 'true');
        let viewedFeedbacks = JSON.parse(sessionStorage.getItem('viewedFeedbacks') || '[]');
        if (!viewedFeedbacks.includes(feedbackId)) {
            viewedFeedbacks.push(feedbackId);
            sessionStorage.setItem('viewedFeedbacks', JSON.stringify(viewedFeedbacks));
        }
    });

    $(document).ready(function() {
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
        sessionStorage.setItem('refreshAfterViewFeedback', 'true');
    });

    $(window).on('beforeunload', function() {
        sessionStorage.setItem('refreshAfterViewFeedback', 'true');
    });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const chatContainer = document.getElementById('chatContainer');
        if (chatContainer) {
            chatContainer.style.overflowY = 'auto';
            chatContainer.style.webkitOverflowScrolling = 'touch';
        }
        
        const feedbackInfo = document.querySelector('.feedback-info');
        if (feedbackInfo) {
            feedbackInfo.style.overflowY = 'auto';
            feedbackInfo.style.webkitOverflowScrolling = 'touch';
        }
        
        scrollToBottom();
    });
    </script>

    <script>
    $(document).ready(function() {
        console.log("Verifying attachments in messages...");
        
        $('.message').each(function() {
            const attachmentsContainer = $(this).find('.attachments-container');
            console.log("Attachment container found:", attachmentsContainer.length);
            
            if (attachmentsContainer.length > 0) {
                const attachmentItems = attachmentsContainer.children();
                console.log("Attachment items:", attachmentItems.length);
                
                if (attachmentItems.length === 0) {
                    console.log("No attachment items, hiding container");
                    attachmentsContainer.hide();
                } else {
                    console.log("Showing attachment container");
                    attachmentsContainer.show();
                }
            }
        });
        
        $('.attachment-image').on('error', function() {
            console.error('Error loading image:', $(this).attr('src'));
            const errorDiv = $('<div class="attachment-preview"></div>');
            errorDiv.html('<i class="fas fa-exclamation-triangle text-warning"></i> <span>Error loading image</span>');
            $(this).replaceWith(errorDiv);
        });
        
        $('.attachment-preview a').each(function() {
            const href = $(this).attr('href');
            console.log("Verifying attachment link:", href);
            
            if (!href || href === '#' || href === 'undefined') {
                console.error('Invalid attachment link');
                $(this).addClass('text-danger').attr('title', 'Invalid link');
                $(this).on('click', function(e) {
                    e.preventDefault();
                    alert('Cannot access this file');
                });
            }
        });
    });
    </script>
</body>
</html>
