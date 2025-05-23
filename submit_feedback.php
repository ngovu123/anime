<?php
// At the top of the file, add these lines to increase PHP upload limits
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '20M');
ini_set('memory_limit', '128M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);

// Enable error logging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
$user_name = Select_Value_by_Condition("name", "user_tb", "staff_id", $mysql_user);
$success_message = "";
$error_message = "";
$anonymous_code = "";
$inserted_id = 0; // To store the ID of the inserted feedback

// Hàm định dạng nội dung email với font Calibri và sửa lỗi xuống dòng
function formatEmailContent($message) {
    // Fix line breaks in message
    $message = str_replace(
        ["\\r\\n\\r\\n", "\\r\\n", "\r\n\r\n", "\r\n", "\n\n", "\n"],
        ["<br><br>", "<br>", "<br><br>", "<br>", "<br><br>", "<br>"],
        $message
    );
    
    // Use Calibri font and format for better readability
    $html_message = '<div style="font-family: Calibri, \'Segoe UI\', Arial, sans-serif; font-size: 14px; line-height: 1.5;">' . 
                   $message . 
                   '</div>';
    
    // Create plain text version
    $plain_message = strip_tags(str_replace(["<br>", "<br><br>"], ["\n", "\n\n"], $message));
    
    return [
        'html' => $html_message,
        'text' => $plain_message
    ];
}

// Lưu giữ giá trị đã nhập khi form bị lỗi
$form_data = [
    'title' => '',
    'content' => '',
    'handling_department' => '',
    'is_anonymous' => false
];

// Kiểm tra xem đây có phải là trang được tải lại không (POST request với flag 'show_code')
$is_showing_code = isset($_POST['show_code']) && $_POST['show_code'] == 1;
$showing_anonymous_code = isset($_POST['anonymous_code']) ? $_POST['anonymous_code'] : '';

// Handle form submission
if (isset($_POST['submit'])) {
    // Lưu lại giá trị đã nhập
    $form_data = [
        'title' => isset($_POST['title']) ? $_POST['title'] : '',
        'content' => isset($_POST['content']) ? $_POST['content'] : '',
        'handling_department' => isset($_POST['handling_department']) ? $_POST['handling_department'] : '',
        'is_anonymous' => isset($_POST['is_anonymous'])
    ];
    
    // Sanitize inputs
    $title = sanitizeInput($_POST['title']);
    $content = isset($_POST['content']) ? sanitizeInputWithLineBreaks($_POST['content']) : '';
    $handling_department = sanitizeInput($_POST['handling_department']);
    $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;
    
    // Validate inputs
    if (empty($title) || empty($content) || empty($handling_department)) {
        $error_message = "Vui lòng điền đầy đủ thông tin.";
    } else {
        // Handle file upload if exists
        $image_paths = [];
        if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
            $allowed_types = [
                // Hình ảnh
                'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp',
                // Tài liệu
                'application/pdf', 
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // Word
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // Excel
                'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', // PowerPoint
                'text/plain', 'text/csv', 'application/rtf' // Text formats
            ];
            $max_size = 20 * 1024 * 1024; // 20MB
            
            $files = $_FILES['attachments'];
            $total_files = count($files['name']);

            for ($i = 0; $i < $total_files; $i++) {
                if ($files['error'][$i] == 0) {
                    if (!in_array($files['type'][$i], $allowed_types)) {
                        $error_message = "Chỉ chấp nhận các định dạng: JPEG, PNG, GIF, BMP, WebP, PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV, RTF.";
                        break;
                    } elseif ($files['size'][$i] > $max_size) {
                        $error_message = "Kích thước file không được vượt quá 20MB.";
                        break;
                    } else {
                        $upload_dir = "uploads/";
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_name = time() . '_' . basename($files['name'][$i]);
                        $upload_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                            $image_paths[] = $upload_path;
                        } else {
                            $error_message = "Có lỗi xảy ra khi tải file lên.";
                            break;
                        }
                    }
                }
            }
        }
        
        if (empty($error_message)) {
            // Generate feedback ID and anonymous code if needed
            $feedback_id = generateFeedbackID();
            $staff_id = $is_anonymous ? null : $mysql_user;
            $anonymous_code = $is_anonymous ? generateRandomCode() : null;
            
            // Insert feedback into database
            $sql = "INSERT INTO feedback_tb (feedback_id, staff_id, anonymous_code, title, content, handling_department, is_anonymous, status, image_path) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
            $stmt = $db->prepare($sql);

            // Convert image paths array to a string
            $image_path_string = !empty($image_paths) ? implode(',', $image_paths) : null;
            
            $stmt->bind_param("ssssssss", $feedback_id, $staff_id, $anonymous_code, $title, $content, $handling_department, $is_anonymous, $image_path_string);
            
            if ($stmt->execute()) {
                $inserted_id = $db->insert_id;
                
                // Lưu thông tin về các tệp đính kèm
                $saved_attachments = [];
                if (!empty($image_paths)) {
                    foreach ($image_paths as $index => $path) {
                        $file_name = basename($path);
                        $file_size = filesize($path);
                        $file_type = getFileMimeType($path);
                        
                        $saved_attachments[] = [
                            'file_name' => $file_name,
                            'file_path' => $path,
                            'file_type' => $file_type,
                            'file_size' => $file_size
                        ];
                    }
                }
                
                // Gửi thông báo cho bộ phận tiếp nhận với thông tin đính kèm
                $subject = "Ý kiến mới" . ($is_anonymous ? " ẩn danh" : "") . ": " . $title;
                $message = "Có ý kiến mới " . ($is_anonymous ? "<strong>ẩn danh</strong>" : "từ <strong>" . $user_name . " (" . $mysql_user . ")</strong>") . ":\n";
                $message .= "<strong>Tiêu đề:</strong> " . $title . "\n";
                $message .= "<strong>Nội dung:</strong> " . $content . "\n";
                
                // Ghi log trước khi gửi email
                error_log("Chuẩn bị gửi email đến bộ phận xử lý: " . $handling_department);
                
                // Lấy email của người gửi
                $sender_email = Select_Value_by_Condition("email", "user_tb", "staff_id", $mysql_user);
                error_log("Email người gửi: " . ($sender_email ? $sender_email : "Không tìm thấy email"));
                
                // Log giá trị handling_department
                error_log("Handling department from form: " . $handling_department);
                
                // Lấy danh sách email của thành viên trong bộ phận xử lý, loại trừ email của người gửi
                $emails = [];
                $sql_users = "SELECT email FROM user_tb WHERE LOWER(TRIM(department)) = LOWER(TRIM(?)) AND email IS NOT NULL AND email != ''" . ($sender_email ? " AND email != ?" : "");
                $stmt_users = $db->prepare($sql_users);
                if ($stmt_users) {
                    if ($sender_email) {
                        $stmt_users->bind_param("ss", $handling_department, $sender_email);
                    } else {
                        $stmt_users->bind_param("s", $handling_department);
                    }
                    $stmt_users->execute();
                    $result_users = $stmt_users->get_result();
                    error_log("Số email tìm thấy: " . $result_users->num_rows);
                    if ($result_users && $result_users->num_rows > 0) {
                        while ($user = $result_users->fetch_assoc()) {
                            if (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                                $emails[] = $user['email'];
                            }
                        }
                    }
                    $stmt_users->close();
                } else {
                    error_log("Lỗi chuẩn bị truy vấn: " . $db->error);
                }
                
                // Loại bỏ email trống và cắt khoảng trắng
                $emails = array_filter(array_map('trim', $emails));
                
                if (!empty($emails)) {
                    error_log("Danh sách email sẽ nhận thông báo: " . implode(", ", $emails));
                    
                    // Gửi email bằng hàm sendDepartmentEmailListNotificationWithAttachments
                    $sent = sendDepartmentEmailListNotificationWithAttachments($emails, $subject, $message, $saved_attachments);
                    if ($sent) {
                        error_log("Gửi email thành công đến: " . implode(", ", $emails));
                    } else {
                        error_log("Gửi email thất bại, kiểm tra log PHPMailer");
                    }
                } else {
                    error_log("Không tìm thấy email nào để gửi thông báo cho bộ phận: " . $handling_department);
                    // Fallback: Gửi email đến admin
                    $admin_email = 'admin@example.com'; // Thay bằng email admin thực tế
                    $admin_message = $message . "\nLỗi: Không tìm thấy email nào cho bộ phận " . $handling_department;
                    sendDepartmentEmailListNotificationWithAttachments([$admin_email], "Lỗi gửi ý kiến: " . $subject, $admin_message, $saved_attachments);
                    error_log("Đã gửi email cảnh báo đến admin: " . $admin_email);
                }
                
                // Gửi thông báo trạng thái ban đầu (Chờ xử lý)
                sendFeedbackStatusNotification($inserted_id, 1);
                
                // Sửa phần xử lý sau khi gửi ý kiến ẩn danh thành công
                if ($is_anonymous) {
                    // Store anonymous code in session for future reference
                    if (!isset($_SESSION['anonymous_codes'])) {
                        $_SESSION['anonymous_codes'] = [];
                    }
                    
                    if (!in_array($anonymous_code, $_SESSION['anonymous_codes'])) {
                        $_SESSION['anonymous_codes'][] = $anonymous_code;
                    }
                    
                    // Thêm mới: Lưu thông tin về người đã gửi feedback ẩn danh
                    if (!isset($_SESSION['created_anonymous_feedbacks'])) {
                        $_SESSION['created_anonymous_feedbacks'] = [];
                    }
                    
                    $_SESSION['created_anonymous_feedbacks'][$inserted_id] = [
                        'anonymous_code' => $anonymous_code,
                        'user_id' => $mysql_user,
                        'handling_department' => $handling_department
                    ];
                    $success_message = "Gửi ý kiến thành công!";
                    
                    // Đặt flag để hiển thị mã ẩn danh
                    $is_showing_code = true;
                    $showing_anonymous_code = $anonymous_code;
                    
                    // Sử dụng POST để lưu giữ mã ẩn danh khi refresh trang
                    echo '<form id="codeKeeper" method="post" style="display:none;">
                          <input type="hidden" name="show_code" value="1">
                          <input type="hidden" name="anonymous_code" value="' . $anonymous_code . '">
                          </form>';
                    echo '<script>document.getElementById("codeKeeper").submit();</script>';
                    exit();
                } else {
                    // Redirect to dashboard if not anonymous
                    header("Location: dashboard.php?success=1");
                    exit();
                }
                
                // Xóa dữ liệu form khi gửi thành công
                $form_data = [
                    'title' => '',
                    'content' => '',
                    'handling_department' => '',
                    'is_anonymous' => false
                ];
            } else {
                $error_message = "Có lỗi xảy ra: " . $stmt->error;
            }
            
            $stmt->close();
        }
    }
}

// Get all departments
$departments = getAllDepartments();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gửi ý kiến - Hệ thống phản hồi ý kiến</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            font-size: 14px;
            line-height: 1.5;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .card {
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
            margin-bottom: 20px;
            border: none;
        }
        .card-body {
            padding: 20px;
        }
        .form-group label {
            font-weight: 500;
            margin-bottom: 5px;
        }
        .form-control {
            border-radius: 4px;
            border: 1px solid #ced4da;
            padding: 8px 12px;
            font-family: inherit;
            font-size: inherit;
        }
        .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
            border-color: #80bdff;
        }
        .btn-submit {
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
        }
        .btn-submit:hover {
            background-color: #000;
            color: #fff;
        }
        .btn-cancel {
            background-color: transparent;
            border: 1px solid #dee2e6;
            color: #212529;
            border-radius: 4px;
            padding: 8px 12px;
        }
        .anonymous-code-container {
            background-color: #f8f9fa;
            padding: 30px;
            border-radius: 10px;
            margin: 20px auto;
            text-align: center;
            max-width: 500px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .anonymous-code-title {
            color: #212529;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .anonymous-code-box {
            background-color: #fff;
            padding: 15px 20px;
            border-radius: 6px;
            border: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px auto;
            max-width: 300px;
            position: relative;
        }
        .anonymous-code {
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 2rem;
            font-weight: 600;
            letter-spacing: 2px;
            color: #007bff;
            margin: 0;
            padding: 0;
        }
        .copy-btn {
            position: absolute;
            right: -10px;
            top: -10px;
            width: 32px;
            height: 32px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            transition: all 0.2s;
        }
        .copy-btn:hover {
            background-color: #0069d9;
            transform: scale(1.05);
        }
        .copy-btn:active {
            transform: scale(0.95);
        }
        .dashboard-link {
            display: inline-block;
            color: #007bff;
            text-decoration: none;
            margin-top: 10px;
            font-weight: 500;
            font-size: 15px;
        }
        .dashboard-link:hover {
            text-decoration: underline;
            color: #0056b3;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .required-field::after {
            content: " *";
            color: red;
        }
        .file-upload-container {
            margin-bottom: 10px;
        }
        .file-upload-area {
            position: relative;
            border: 2px solid #007bff47;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            background-color: rgba(0, 123, 255, 0.02);
            cursor: pointer;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        .btn-submit, .btn-cancel {
            font-size: 14px;
            padding: 6px 17px;
        }
        .file-upload-icon {
            font-size: 5px;
            color: #007bff;
            margin-bottom: 2px;
            transition: transform 0.3s ease;
        }
        .file-upload-text {
            margin-bottom: 5px;
            font-weight: 500;
            color: #495057;
            font-size: 14px;
        }
        .file-upload-subtext {
            font-size: 12px;
            color: #6c757d;
        }
        .file-upload-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .file-preview-container {
            margin-top: 15px;
        }
        .file-preview-item {
            display: flex;
            align-items: center;
            background-color: #fff;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .file-preview-item:hover {
            border-color: #ced4da;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .file-preview-icon {
            font-size: 20px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .file-preview-details {
            flex-grow: 1;
            min-width: 0;
        }
        .file-preview-name {
            font-weight: 500;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .file-preview-size {
            font-size: 12px;
            color: #6c757d;
        }
        .file-preview-remove {
            margin-left: 10px;
            color: #dc3545;
            background: none;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            cursor: pointer;
            flex-shrink: 0;
        }
        .file-preview-remove:hover {
            background-color: rgba(220, 53, 69, 0.1);
        }
        .file-counter {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #007bff;
            color: white;
            font-size: 12px;
            font-weight: 500;
            height: 20px;
            min-width: 20px;
            padding: 0 6px;
            border-radius: 10px;
            margin-left: 8px;
        }
        .file-type-badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 3px;
            margin-left: 5px;
            text-transform: uppercase;
        }
        .file-type-image {
            background-color: #e3f2fd;
            color: #0d6efd;
        }
        .file-type-document {
            background-color: #e8f5e9;
            color: #198754;
        }
        .file-type-other {
            background-color: #f8f9fa;
            color: #6c757d;
        }
        .file-upload-progress {
            height: 4px;
            width: 100%;
            background-color: #e9ecef;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
            display: none;
        }
        .file-upload-progress-bar {
            height: 100%;
            background-color: #007bff;
            width: 0%;
            transition: width 0.3s ease;
        }
        @media (max-width: 576px) {
            .file-upload-area {
                padding: 15px;
            }
            .file-upload-icon {
                font-size: 28px;
            }
            .file-preview-item {
                padding: 8px 10px;
            }
            .file-preview-icon {
                font-size: 18px;
                margin-right: 8px;
            }
            .file-preview-remove {
                width: 24px;
                height: 24px;
            }
        }
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
            animation: slideInRight 0.3s, fadeOut 0.5s 3.5s forwards;
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
        textarea.form-control {
            min-height: 120px;
            line-height: 1.5;
        }
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 123, 255, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(0, 123, 255, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(0, 123, 255, 0);
            }
        }
        .file-upload-area.dragover {
            animation: pulse 1.5s infinite;
        }
        .file-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(0, 123, 255, 0.3);
            border-radius: 50%;
            border-top-color: #007bff;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        #is_anonymous {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .form-check-label {
            cursor: pointer;
            padding-left: 5px;
        }
        @media (max-width: 576px) {
            #is_anonymous {
                width: 16px;
                height: 16px;
            }
        }
        @media (max-width: 576px) {
            .file-upload-text {
                font-size: 13px;
            }
            .file-upload-subtext {
                font-size: 11px;
            }
        }
        @keyframes flash {
            0% { background-color: #007bff; }
            50% { background-color: #28a745; }
            100% { background-color: #007bff; }
        }
        .copy-success {
            animation: flash 0.5s;
        }
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer">
    <?php if (!empty($error_message)): ?>
    <div class="toast error">
        <div class="toast-icon"><i class="fas fa-exclamation-circle"></i></div>
        <div class="toast-message"><?php echo $error_message; ?></div>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($success_message) && (!$is_showing_code || empty($showing_anonymous_code))): ?>
    <div class="toast success">
        <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
        <div class="toast-message"><?php echo $success_message; ?></div>
    </div>
    <?php endif; ?>
    
    <?php if ($is_showing_code && !empty($showing_anonymous_code)): ?>
    <div class="toast success">
        <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
        <div class="toast-message">Gửi ý kiến ẩn danh thành công!</div>
    </div>
    <?php endif; ?>
</div>
<div class="container">
    <div class="card">
        <div class="card-body">
            <?php if ($is_showing_code && !empty($showing_anonymous_code)): ?>
            <div class="anonymous-code-container">
                <h5 class="anonymous-code-title">Mã tra cứu của bạn</h5>
                <div class="anonymous-code-box">
                    <span class="anonymous-code" id="anonymousCode"><?php echo $showing_anonymous_code; ?></span>
                    <button class="copy-btn" id="copyBtn" onclick="copyAnonymousCode()" title="Sao chép mã">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <p class="text-muted">Vui lòng lưu lại mã này để theo dõi ý kiến của bạn</p>
                <a href="dashboard.php" class="dashboard-link">
                    <i class="fas fa-arrow-left mr-1"></i> Quay về Dashboard
                </a>
            </div>
            <?php else: ?>
            <form method="post" action="" enctype="multipart/form-data">
                <div class="form-group form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="is_anonymous" name="is_anonymous" <?php echo $form_data['is_anonymous'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="is_anonymous"><strong>Gửi ẩn danh</strong> (Thông tin người gửi sẽ bị ẩn).</label>
                </div>
                <div class="form-group">
                    <label for="title" class="required-field"><strong>Tiêu đề</strong></label>
                    <input type="text" class="form-control" id="title" name="title" placeholder="Tóm tắt ý kiến của bạn" required value="<?php echo htmlspecialchars($form_data['title']); ?>">
                </div>
                <div class="form-group">
                    <label for="content" class="required-field"><strong>Nội dung</strong></label>
                    <textarea class="form-control" id="content" name="content" rows="5" placeholder="Mô tả chi tiết ý kiến của bạn" required><?php echo htmlspecialchars($form_data['content']); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="handling_department" class="required-field"><strong>Bộ phận tiếp nhận</strong></label>
                    <select class="form-control" id="handling_department" name="handling_department" required>
                        <option value="">Chọn bộ phận</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo ($form_data['handling_department'] == $dept) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group file-upload-container">
                    <label for="attachments"><strong>Tệp đính kèm (tùy chọn)</strong></label>
                    <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('attachments').click();">
                        <div class="file-upload-text">Nhấn để tải tệp lên</div>
                        <div class="file-upload-subtext">Hỗ trợ: JPG, PNG, PDF, DOC, XLS... (Tối đa 20MB)</div>
                        <input type="file" class="file-upload-input" id="attachments" name="attachments[]" multiple>
                    </div>
                    <div class="file-preview-container" id="filePreviewContainer" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="file-preview-header">
                                <span class="font-weight-medium">Tệp đã chọn</span>
                                <span class="file-counter" id="fileCounter">0</span>
                            </div>
                        </div>
                        <div id="filePreviewList"></div>
                    </div>
                </div>
                <div class="form-group text-right mt-4">
                    <a href="dashboard.php" class="btn btn-cancel mr-2">Hủy</a>
                    <button type="submit" name="submit" class="btn btn-submit">Gửi</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function copyAnonymousCode() {
        const codeElement = document.getElementById('anonymousCode');
        const copyBtn = document.getElementById('copyBtn');
        const textToCopy = codeElement.textContent;
        const textarea = document.createElement('textarea');
        textarea.value = textToCopy;
        textarea.style.position = 'fixed';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            copyBtn.classList.add('copy-success');
            copyBtn.innerHTML = '<i class="fas fa-check"></i>';
            showToast('Đã sao chép mã tra cứu: ' + textToCopy, 'success');
            setTimeout(() => {
                copyBtn.classList.remove('copy-success');
                copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
            }, 1500);
        } catch (err) {
            showToast('Không thể sao chép mã. Vui lòng thử lại.', 'error');
        }
        document.body.removeChild(textarea);
    }
    
    $(document).ready(function() {
        let fileCount = 0;
        $('#fileUploadArea').off('click');
        $('.file-upload-area').on('click', function(e) {
            e.stopPropagation();
            if (!$(this).data('just-selected')) {
                document.getElementById('attachments').click();
            }
            $(this).data('just-selected', false);
        });
        $('#attachments').on('change', function(e) {
            $('.file-upload-area').data('just-selected', true);
            const files = this.files;
            handleFiles(files);
            setTimeout(function() {
                $('.file-upload-area').data('just-selected', false);
            }, 300);
        });
        const dropArea = document.getElementById('fileUploadArea');
        if (dropArea) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });
            function highlight() {
                dropArea.classList.add('dragover');
            }
            function unhighlight() {
                dropArea.classList.remove('dragover');
            }
            dropArea.addEventListener('drop', handleDrop, false);
        }
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
        function handleFiles(files) {
            if (files.length > 0) {
                let totalSize = 0;
                let oversizedFiles = [];
                for (let i = 0; i < files.length; i++) {
                    totalSize += files[i].size;
                    if (files[i].size > 20 * 1024 * 1024) {
                        oversizedFiles.push(files[i].name);
                    }
                }
                if (oversizedFiles.length > 0) {
                    showToast('Các tệp sau vượt quá giới hạn 20MB: ' + oversizedFiles.join(', '), 'error');
                    return;
                }
                if (totalSize > 20 * 1024 * 1024) {
                    showToast('Tổng kích thước tệp vượt quá giới hạn 20MB', 'error');
                    return;
                }
                $('#filePreviewContainer').show();
                fileCount = files.length;
                $('#fileCounter').text(fileCount);
                $('#filePreviewList').empty();
                Array.from(files).forEach((file, index) => {
                    createFilePreview(file, index);
                });
            } else {
                $('#filePreviewContainer').hide();
                fileCount = 0;
            }
        }
        function createFilePreview(file, index) {
            const fileSize = formatFileSize(file.size);
            const fileType = getFileType(file.type);
            const fileIcon = getFileIcon(file.type);
            const fileTypeBadge = getFileTypeBadge(fileType);
            const previewItem = $(`
                <div class="file-preview-item" data-index="${index}">
                    <div class="file-preview-icon ${fileIcon.color}">
                        <i class="${fileIcon.icon}"></i>
                    </div>
                    <div class="file-preview-details">
                        <div class="file-preview-name">${file.name} ${fileTypeBadge}</div>
                        <div class="file-preview-size">${fileSize}</div>
                    </div>
                    <button type="button" class="file-preview-remove" data-index="${index}">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `);
            $('#filePreviewList').append(previewItem);
        }
        $(document).on('click', '.file-preview-remove', function() {
            const index = $(this).data('index');
            removeFile(index);
        });
        function removeFile(index) {
            const input = document.getElementById('attachments');
            const dt = new DataTransfer();
            const files = input.files;
            for (let i = 0; i < files.length; i++) {
                if (i !== index) {
                    dt.items.add(files[i]);
                }
            }
            input.files = dt.files;
            handleFiles(input.files);
        }
        function formatFileSize(bytes) {
            if (bytes < 1024) {
                return bytes + ' B';
            } else if (bytes < 1048576) {
                return (bytes / 1024).toFixed(1) + ' KB';
            } else {
                return (bytes / 1048576).toFixed(1) + ' MB';
            }
        }
        function getFileType(mimeType) {
            if (mimeType.startsWith('image/')) {
                return 'image';
            } else if (
                mimeType === 'application/pdf' || 
                mimeType.includes('word') || 
                mimeType.includes('excel') || 
                mimeType.includes('powerpoint') || 
                mimeType === 'text/plain'
            ) {
                return 'document';
            } else {
                return 'other';
            }
        }
        function getFileIcon(mimeType) {
            if (mimeType.startsWith('image/')) {
                return { icon: 'fas fa-file-image', color: 'text-info' };
            } else if (mimeType === 'application/pdf') {
                return { icon: 'fas fa-file-pdf', color: 'text-danger' };
            } else if (mimeType.includes('word')) {
                return { icon: 'fas fa-file-word', color: 'text-primary' };
            } else if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) {
                return { icon: 'fas fa-file-excel', color: 'text-success' };
            } else if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) {
                return { icon: 'fas fa-file-powerpoint', color: 'text-warning' };
            } else if (mimeType === 'text/plain' || mimeType === 'text/csv') {
                return { icon: 'fas fa-file-alt', color: 'text-secondary' };
            } else {
                return { icon: 'fas fa-file', color: 'text-secondary' };
            }
        }
        function getFileTypeBadge(fileType) {
            if (fileType === 'image') {
                return '<span class="file-type-badge file-type-image">Hình ảnh</span>';
            } else if (fileType === 'document') {
                return '<span class="file-type-badge file-type-document">Tài liệu</span>';
            } else {
                return '<span class="file-type-badge file-type-other">Khác</span>';
            }
        }
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
    });
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
        setTimeout(() => {
            toast.style.opacity = '1';
        }, 10);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                toastContainer.removeChild(toast);
            }, 500);
        }, 4000);
    }
</script>
</body>
</html>
