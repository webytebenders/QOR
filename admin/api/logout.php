<?php
require_once '../includes/auth.php';
startSecureSession();
logout();
redirect('../');
