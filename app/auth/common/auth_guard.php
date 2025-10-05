<?php
session_start();
if (!isset($_SESSION['uid'])) {
  header('Location: ' . url('/app/auth/login.php')); // ✅
  exit;
}
