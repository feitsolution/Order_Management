<?php
session_start(); // Start the session

// Include the database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Initialize variables
$error_message = "";
$success_message = "";

// ✅ Fetch branding logo
$logo_url = "../assets/images/placeholder.png"; // fallback
$sql_branding = "SELECT logo_url FROM branding WHERE active = 1 LIMIT 1";
$result_branding = $conn->query($sql_branding);
if ($result_branding && $result_branding->num_rows > 0) {
    $branding = $result_branding->fetch_assoc();
    if (!empty($branding['logo_url'])) {
        $logo_url = $branding['logo_url'];
    }
}

// Handle form submit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $first_name = trim($_POST['first_name']);
    $last_name  = trim($_POST['last_name']);
    $name       = $first_name . ' ' . $last_name;
    $email      = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password   = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $terms      = isset($_POST['terms']);

    // Validation
    if (!$terms) {
        $error_message = "You must agree to the Terms & Conditions.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error_message = "Email already registered.";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert user as inactive
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, status, role_id, created_at, updated_at) VALUES (?, ?, ?, 'inactive', 2, NOW(), NOW())");
            $stmt->bind_param("sss", $name, $email, $hashed_password);

            if ($stmt->execute()) {
                $success_message = "Registration successful! Your account is inactive. Please contact admin to activate.";
            } else {
                $error_message = "Error: " . $stmt->error;
            }
        }
        $stmt->close();
    }
}

$conn->close();
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Register | Order Management Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300;400;500;600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <style>
        /* Password toggle styles */
        .password-container {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 10px; /* Right side */
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
        }
        .password-toggle:hover {
            color: #555;
        }
        .form-control {
            width: 100%;
            padding-right: 40px; /* Space for the eye icon */
        }
    </style>
</head>
<body>
<div class="auth-main relative">
    <div class="auth-wrapper v1 flex items-center w-full h-full min-h-screen">
        <div class="auth-form flex items-center justify-center grow flex-col min-h-screen relative p-6 ">
            <div class="w-full max-w-[350px] relative">
                <div class="card sm:my-12 w-full shadow-none">
                    <div class="card-body !p-10">
                        <div class="text-center mb-8">
                            <a href="#"><img src="<?php echo $logo_url; ?>" alt="Company Logo" class="mx-auto auth-logo"/></a>
                        </div>

                        <h4 class="text-center font-medium mb-4">Sign Up</h4>

                        <?php if (!empty($error_message)): ?>
                            <div class="error-message text-red-600 text-center mb-3"><?php echo $error_message; ?></div>
                        <?php elseif (!empty($success_message)): ?>
                            <div class="success-message text-green-600 text-center mb-3"><?php echo $success_message; ?></div>
                        <?php endif; ?>

                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                            <div class="grid grid-cols-12 gap-3 mb-3">
                                <div class="col-span-12 sm:col-span-6">
                                    <input type="text" class="form-control" name="first_name" placeholder="First Name" required>
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <input type="text" class="form-control" name="last_name" placeholder="Last Name" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <input type="email" class="form-control" name="email" placeholder="Email Address" required>
                            </div>

                            <div class="mb-3">
                                <div class="password-container">
                                    <input type="password" class="form-control" name="password" id="password" placeholder="Password" required />
                                    <span class="password-toggle" onclick="togglePassword('password', this)">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="password-container">
                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required />
                                    <span class="password-toggle" onclick="togglePassword('confirm_password', this)">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>

                                <div class="flex mt-1 justify-between items-center flex-wrap">
                                    <div class="form-check">
                                        <input class="form-check-input input-primary" type="checkbox" name="terms" id="customCheckc1" required />
                                        <label class="form-check-label text-muted" for="customCheckc1">I agree to all the Terms &amp; Conditions</label>
                                    </div>
                                </div>

                                <div class="mt-4 text-center">
                                    <button type="submit" class="btn btn-primary mx-auto shadow-2xl">Sign Up</button>
                                </div>

                                <div class="flex justify-between items-end flex-wrap mt-4">
                                    <h6 class="font-medium mb-0">Already have an Account?</h6>
                                    <a href="login.php" class="text-primary-500">Login</a>
                                </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(fieldId, iconElement) {
    const input = document.getElementById(fieldId);
    input.type = input.type === 'password' ? 'text' : 'password';

    const icon = iconElement.querySelector('i');
    if (icon) {
       icon.classList.toggle('fa-eye-slash');
       icon.classList.toggle('fa-eye');
    }
}
</script>
</body>
</html>
