<?php
session_start();
include("connect.php");
include("functions.php");

// Check if user is logged in
if (isset($_SESSION["SS_username"])) {
  header("Location: dashboard.php");
  exit();
}

// Get MAC address (placeholder - implement according to your actual method)
$macaddr = "00:00:00:00:00:00"; // Replace with actual MAC address detection
$DeviceType = "laptop"; // Replace with actual device type detection

// Handle login form submission
if (isset($_POST["login"])) {
  // Prevent injection            
  $username = addslashes($_POST['username']);
  $username = strip_tags($username);
  $username = strtoupper($username);
  $username = trim($username);
  $password = addslashes($_POST['password']);
  $password = strip_tags($password);
  $password = trim($password);
  $password = base64_encode(base64_encode($password));
  
  if ($DeviceType == "phone") {
      $mac_columns = "phone_macAddr";
      $ActivateDevice_col = "activate_phone";
  } else {
      $mac_columns = "laptop_macAddr";
      $ActivateDevice_col = "activate_laptop";
  }
  
  /*CHECK USERNAME EXIST IN DATABASE*/
  if (Check_Value_by_Condition("staff_id", "user_tb", "staff_id = '" . $username . "'") == false) {
      echo "<script>alert('Tài khoản không tồn tại.')</script>";
  } else {
      //Check correct userID with CurrentDevice
      $userID_of_CurrentDevice = Select_Value_by_Condition("staff_id", "user_tb", $mac_columns, $macaddr);
      if ($userID_of_CurrentDevice != "" && $userID_of_CurrentDevice != $username) {
          echo "<script>alert('Tài khoản không được đăng ký cho thiết bị này.');</script>";
      } else {
          //Check password not blank
          if (Select_Value_by_Condition('password', "user_tb", "staff_id", $username) == "") {
              echo "<script>alert('Vui lòng đăng ký mật khẩu.')</script>";
          } else {
              //Check userID and password correct
              if (Check_Value_by_Condition("*", "user_tb", "staff_id = '" . $username . "' AND password = '" . $password . "'") == false) {
                  echo "<script>alert('Tên tài khoản hoặc mật khẩu không chính xác.')</script>";
                  Update_LoginStatus('failed', $username);
              } else {
                  //Check registered security question
                  if (Check_Value_by_Condition("security_answer", "user_tb", "staff_id = '" . $username . "' AND security_answer = ''") == true) {
                      $_SESSION["SS_username"] = $username;
                      echo "<script>
                                  location.assign('register_SecurityQuestion.php');                                    
                              </script>";
                  } elseif (!Check_Signature_Exist($username)) {
                      // Check registered signature
                      $_SESSION["SS_username"] = $username;
                      echo "<script>
                              alert('Để triển khai HỢP ĐỒNG ĐIỆN TỬ\
vui lòng tạo CHỮ KÝ CÁ NHÂN.')
                              window.location.href = 'E_Contracts/signatureRegister.php';
                          </script>";
                  } else {
                      //Check MAC address correct
                      if (Select_Value_by_Condition($mac_columns, "user_tb", "staff_id", $username) <> $macaddr) {
                          echo "<script>alert('Tài khoản không được đăng ký cho thiết bị này.')</script>";
                          Update_LoginStatus('failed', $username);
                      } else {
                          if (Select_Value_by_Condition($ActivateDevice_col, "user_tb", "staff_id", $username) != 1) {
                              Update_Value_by_Condition("user_tb", $ActivateDevice_col, "1", "staff_id", $username);
                          } else {
                              Update_LoginStatus('success', $username);
                              $_SESSION["SS_username"] = $username;
                              header("Location: dashboard.php");
                          }
                      }
                  }
              }
          }
      }
  }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Đăng nhập - Hệ thống phản hồi ý kiến người lao động</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
      body {
          background-color: #f8f9fa;
      }
      .login-container {
          max-width: 400px;
          margin: 100px auto;
          padding: 20px;
          background-color: #fff;
          border-radius: 5px;
          box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
      }
      .login-logo {
          text-align: center;
          margin-bottom: 20px;
      }
      .login-logo img {
          max-width: 150px;
      }
      .login-title {
          text-align: center;
          margin-bottom: 20px;
          color: #333;
      }
  </style>
</head>
<body>
  <div class="container">
      <div class="login-container">
          <div class="login-logo">
              <img src="/placeholder.svg?height=150&width=150" alt="Logo">
          </div>
          <h3 class="login-title">Hệ thống phản hồi ý kiến người lao động</h3>
          <form method="post" action="">
              <div class="form-group">
                  <label for="username">Mã nhân viên:</label>
                  <input type="text" class="form-control" id="username" name="username" required>
              </div>
              <div class="form-group">
                  <label for="password">Mật khẩu:</label>
                  <input type="password" class="form-control" id="password" name="password" required>
              </div>
              <button type="submit" name="login" class="btn btn-primary btn-block">Đăng nhập</button>
          </form>
      </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
