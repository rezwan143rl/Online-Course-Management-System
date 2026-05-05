<?php
// logout.php - destroys the session and sends back to login

session_start();
session_destroy();
header('Location: index.php');
exit;
?>
