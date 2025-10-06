<?php
session_start();

if (isset($_SESSION['uid'])) {
    header('Location: /pfms/public/dashboard.php');
    exit;
}

header('Location: /pfms/app/auth/login.php');
exit;
