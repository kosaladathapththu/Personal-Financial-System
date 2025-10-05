<?php
session_start();
session_unset();
session_destroy();
header('Location: /pfms/app/auth/login.php'); // 👈 keep /pfms/ prefix to be safe
exit;
