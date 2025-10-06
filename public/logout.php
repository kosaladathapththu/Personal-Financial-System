<?php
session_start();

// Check if user clicked "Yes" to logout
if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
    session_unset();
    session_destroy();
    header('Location: /pfms/app/auth/login.php'); // redirect to login
    exit;
}

// If user clicked "No", redirect back to dashboard
if (isset($_POST['confirm']) && $_POST['confirm'] === 'no') {
    header('Location: /pfms/public/dashboard.php');
    exit;
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Logout Confirmation</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f4f8;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .logout-box {
            background: #ffffff;
            padding: 25px 35px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            text-align: center;
        }
        .logout-box h2 {
            margin-bottom: 15px;
            color: #e74c3c;
        }
        .logout-box p {
            margin-bottom: 25px;
            font-size: 16px;
        }
        .logout-box button {
            padding: 10px 20px;
            margin: 0 10px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }
        .yes-btn {
            background-color: #e74c3c;
            color: #fff;
        }
        .yes-btn:hover {
            background-color: #c0392b;
        }
        .no-btn {
            background-color: #3498db;
            color: #fff;
        }
        .no-btn:hover {
            background-color: #2980b9;
        }
    </style>
</head>
<body>
    <div class="logout-box">
        <h2>Confirm Logout ❗</h2>
        <p>Are you sure you want to log out?</p>
        <form method="post">
            <button type="submit" name="confirm" value="yes" class="yes-btn">Yes, Logout</button>
            <button type="submit" name="confirm" value="no" class="no-btn">No, Go Back</button>
        </form>
    </div>
</body>
</html>
