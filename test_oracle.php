<?php
   require 'config/env.php';
   require 'db/oracle.php';
   
   $status = oracle_get_status();
   echo "<pre>";
   print_r($status);
   echo "</pre>";