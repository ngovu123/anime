<?php
// Include database connection
include("../connect.php");

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Sử dụng trực tiếp các file PHPMailer
require_once 'PHPMailer/src/PHPMailer.php';
require_once 'PHPMailer/src/SMTP.php';
require_once 'PHPMailer/src/Exception.php';


// Get mailer configuration
$sql = "SELECT * FROM mailer_tb WHERE id = 1 LIMIT 1";
$result = $db->query($sql);

if (!$result || $result->num_rows == 0) {
    die("Không tìm thấy cấu hình email trong cơ sở dữ liệu.");
}

$config = $result->fetch_assoc();

// Display current configuration
echo "<h2>Cấu hình email hiện tại</h2>";
echo "<p>Host: " . $config['host'] . "</p>";
echo "<p>Port: " . $config['port'] . "</p>";
echo "<p>Address: " . $config['address'] . "</p>";
echo "<p>Password: " . str_repeat('*', strlen($config['password'])) . "</p>";

// Test email sending
if (isset($_POST['test_email'])) {
    $to_email = $_POST['to_email'];
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['address']; // Sử dụng trường address làm username
        $mail->Password   = $config['password'];
        $mail->Port       = $config['port'];
        $mail->CharSet    = 'UTF-8';
        
        // Thêm cấu hình SMTPOptions để vô hiệu hóa xác thực chứng chỉ SSL
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom($config['address'], 'Hệ thống phản hồi ý kiến');
        $mail->addAddress($to_email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Kiểm tra kết nối email';
        $mail->Body    = 'Đây là email kiểm tra từ hệ thống phản hồi ý kiến.';
        $mail->AltBody = 'Đây là email kiểm tra từ hệ thống phản hồi ý kiến.';
        
        // Send email
        $mail->send();
        echo "<p style='color: green;'>Email đã được gửi thành công đến " . $to_email . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>Không thể gửi email: " . $mail->ErrorInfo . "</p>";
    }
}

// Display test form
echo "<h2>Kiểm tra gửi email</h2>";
echo "<form method='post'>";
echo "<p>Nhập địa chỉ email để kiểm tra: <input type='email' name='to_email' required></p>";
echo "<p><input type='submit' name='test_email' value='Gửi email kiểm tra'></p>";
echo "</form>";
?>
