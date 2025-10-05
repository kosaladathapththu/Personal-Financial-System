<?php
define('SQLITE_PATH', __DIR__ . '/../db/pfms.sqlite');
define('APP_TIMEZONE', 'Asia/Colombo');
date_default_timezone_set(APP_TIMEZONE);

// 👇 add these 2 lines
define('APP_BASE', '/pfms'); // your folder name under htdocs
function url($path){ return APP_BASE . $path; }
