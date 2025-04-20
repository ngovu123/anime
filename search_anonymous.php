<?php
session_start();
include("../connect.php");
include("functions.php");

// Check if user is logged in
if (!isset($_SESSION["SS_username"])) {
    header("Location: index.php");
    exit();
}

// Thêm vào phần đầu của file search_anonymous.php, sau phần include và kiểm tra đăng nhập
$toast_message = "";
$toast_type = "";

// Xử lý thông báo tìm kiếm ẩn danh
if (isset($_GET['error'])) {
    $toast_message = "Không tìm thấy ý kiến với mã tra cứu này.";
    $toast_type = "error";
}

if (isset($_GET['success'])) {
    $toast_message = "Tìm thấy ý kiến ẩn danh thành công!";
    $toast_type = "success";
}

$error_message = "";
$feedback = null;

// Handle search form submission
if (isset($_POST['search'])) {
    $anonymous_code = sanitizeInput($_POST['anonymous_code']);
    
    if (empty($anonymous_code)) {
        $error_message = "Vui lòng nhập mã tra cứu.";
    } else {
        // Search for feedback with the provided anonymous code
        $sql = "SELECT * FROM feedback_tb WHERE anonymous_code = ? AND is_anonymous = 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("s", $anonymous_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Thêm thông báo thành công khi tìm thấy ý kiến ẩn danh
        if ($result && $result->num_rows > 0) {
            $feedback = $result->fetch_assoc();
            
            // Store the anonymous code in session for future reference
            if (!isset($_SESSION['anonymous_codes'])) {
                $_SESSION['anonymous_codes'] = [];
            }
            
            if (!in_array($anonymous_code, $_SESSION['anonymous_codes'])) {
                $_SESSION['anonymous_codes'][] = $anonymous_code;
            }
            
            // Redirect to view feedback page
            header("Location: view_feedback.php?id=" . $feedback['id'] . "&search_success=1");
            exit();
        } else {
            $error_message = "Không tìm thấy ý kiến với mã tra cứu này.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tra cứu ý kiến ẩn danh - Hệ thống phản hồi ý kiến người lao động</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            padding: 20px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        .search-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        .search-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .search-icon {
            font-size: 3rem;
            color: #007bff;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
<div class="toast-container" id="toastContainer">
    <?php if (!empty($toast_message)): ?>
    <div class="toast <?php echo $toast_type; ?>">
        <div class="toast-icon"><i class="fas fa-<?php echo $toast_type == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i></div>
        <div class="toast-message"><?php echo $toast_message; ?></div>
    </div>
    <?php endif; ?>
</div>
    <div class="container">
        <div class="search-container">
            <div class="search-header">
                <div class="search-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h2>Tra cứu ý kiến ẩn danh</h2>
                <p>Nhập mã tra cứu để xem ý kiến ẩn danh của bạn</p>
            </div>
            
            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo $error_message; ?>
            </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="form-group">
                    <label for="anonymous_code">Mã tra cứu:</label>
                    <input type="text" class="form-control" id="anonymous_code" name="anonymous_code" placeholder="Nhập mã tra cứu" required>
                    <small class="form-text text-muted">Mã tra cứu được cung cấp khi bạn gửi ý kiến ẩn danh.</small>
                </div>
                
                <div class="form-group text-center mt-4">
                    <a href="functionList.php" class="btn btn-secondary mr-2">Quay lại</a>
                    <button type="submit" name="search" class="btn btn-primary">Tra cứu</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script>
// Hiển thị toast khi trang tải xong
$(document).ready(function() {
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
            }, 7000);
        });
    }
});
</script>
</body>
</html>
