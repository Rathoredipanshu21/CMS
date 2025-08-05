<?php
session_start();
$error = '';

// Redirect if user is already logged in
if (isset($_SESSION['customer_id'])) {
    header("Location: index.php"); // Create this page for logged-in users
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include '../config/db.php'; // Make sure this path is correct

    $customer_uid = $_POST['customer_uid'] ?? '';
    $mobile_no = $_POST['mobile_no'] ?? '';

    if (empty($customer_uid) || empty($mobile_no)) {
        $error = "Both fields are required.";
    } else {
        // Prepare statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT id, customer_uid, name FROM customers WHERE customer_uid = ? AND mobile_no = ?");
        $stmt->bind_param("ss", $customer_uid, $mobile_no);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 1) {
            $customer = $result->fetch_assoc();
            
            // Regenerate session ID for security
            session_regenerate_id(true);

            // Store customer data in session
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['customer_uid'] = $customer['customer_uid'];
            $_SESSION['customer_name'] = $customer['name'];
            
            // Redirect to a protected customer dashboard page
            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid Unique ID or Mobile Number. Please try again.";
        }
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Login</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <style>
        /* Custom base styles */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f4f8; /* Light blue-gray background */
        }

        /* Custom input field styling */
        .form-input-container {
            position: relative;
            margin-bottom: 1.5rem; /* 24px */
        }
        .form-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem; /* 16px 16px 16px 48px */
            border: 2px solid #e2e8f0;
            border-radius: 0.75rem; /* 12px */
            background-color: #ffffff;
            color: #1a202c;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .form-input:focus {
            outline: none;
            border-color: #4a90e2;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2);
        }
        .form-input-icon {
            position: absolute;
            left: 1rem; /* 16px */
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            font-size: 1.125rem; /* 18px */
            transition: color 0.3s ease;
        }
        .form-input:focus + .form-input-icon {
            color: #4a90e2;
        }

        /* Login button styling */
        .btn-login {
            background: linear-gradient(to right, #4a90e2, #50e3c2);
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 1rem 2rem;
            border-radius: 0.75rem;
            transition: all 0.3s ease;
            box-shadow: 0 10px 20px -5px rgba(74, 144, 226, 0.4);
        }
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 25px -8px rgba(74, 144, 226, 0.5);
        }
        
        /* Custom card styling */
        .login-card {
            background-color: white;
            border-radius: 1.5rem; /* 24px */
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
    </style>
</head>
<body>

    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="grid lg:grid-cols-2 max-w-5xl w-full login-card">
            
            <!-- Left Side: Sticker/Illustration -->
            <div class="hidden lg:flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100" data-aos="fade-right" data-aos-duration="1000">
                <img src="../Assets/login.jpg" alt="Secure Login Sticker" class="w-full h-auto">
            </div>

            <!-- Right Side: Login Form -->
            <div class="p-8 md:p-12" data-aos="fade-left" data-aos-duration="1000">
                <div class="text-center lg:text-left">
                    <h1 class="text-3xl md:text-4xl font-extrabold text-gray-800">Customer Login</h1>
                    <p class="text-gray-500 mt-2">Welcome back! Please enter your credentials.</p>
                </div>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post" class="mt-8">
                    
                    <?php if (!empty($error)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert" data-aos="zoom-in">
                            <p class="font-bold">Login Failed</p>
                            <p><?php echo $error; ?></p>
                        </div>
                    <?php endif; ?>

                    <!-- Unique ID Input -->
                    <div class="form-input-container" data-aos="fade-up" data-aos-delay="100">
                        <label for="customer_uid" class="sr-only">Customer Unique ID</label>
                        <input type="text" id="customer_uid" name="customer_uid" class="form-input" placeholder="Enter your Unique ID (e.g., DBCECMS0001)" required>
                        <i class="fas fa-barcode form-input-icon"></i>
                    </div>

                    <!-- Mobile Number Input -->
                    <div class="form-input-container" data-aos="fade-up" data-aos-delay="200">
                        <label for="mobile_no" class="sr-only">Mobile Number</label>
                        <input type="tel" id="mobile_no" name="mobile_no" class="form-input" placeholder="Enter your Mobile Number" required>
                        <i class="fas fa-mobile-alt form-input-icon"></i>
                    </div>

                    <!-- Submit Button -->
                    <div class="mt-8" data-aos="fade-up" data-aos-delay="300">
                        <button type="submit" class="w-full btn-login">
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Login
                        </button>
                    </div>

                </form>

                <div class="text-center mt-8 text-sm text-gray-400" data-aos="fade-up" data-aos-delay="400">
                    <p>&copy; <?php echo date("Y"); ?> Your Company Name. All Rights Reserved.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- AOS Animation Library Script -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            once: true, // Whether animation should happen only once - while scrolling down
            duration: 800, // values from 0 to 3000, with step 50ms
        });
    </script>

</body>
</html>
