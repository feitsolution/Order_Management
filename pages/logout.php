<?php
session_start(); // Start the session

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Clear the "Remember Me" cookie if it exists
if (isset($_COOKIE['email'])) {
    setcookie("email", "", time() - 3600, "/");
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: /order_management/dist/pages/login.php");
exit();
?>