<?php
$sqlite = new PDO('sqlite:' . __DIR__ . '/../data/pfms.db');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlite->exec('PRAGMA foreign_keys=ON');
