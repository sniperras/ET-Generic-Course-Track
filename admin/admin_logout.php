<?php
require_once '../include/auth.php';
logoutAdmin();
header("Location: admin_login.php");
exit();
?>