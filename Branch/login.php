<?php
// Start the session at the very top of the script
session_start();
include '../config/db.php';

$error_message = '';

// Process login form when submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password']; // The plaintext password from the form

    // Use a prepared statement to prevent SQL injection. This is very important.
    // We now include the password in the WHERE clause to find a match.
    // NOTE: The table name is 'branch' as per your SQL query.
    $stmt = $conn->prepare("SELECT id, branch_name FROM branch WHERE username = ? AND password = ?");
    $stmt->bind_param("ss", $username, $password); // "ss" for two strings
    $stmt->execute();
    $result = $stmt->get_result();

    // Check if exactly one user was found with that username/password combination
    if ($result->num_rows === 1) {
        $branch = $result->fetch_assoc();

        // --- SUCCESS! ---
        // The username and password matched. Now we set the session variables.
        // This is the critical step to make index.php work correctly.
        $_SESSION['branch_id'] = $branch['id'];
        $_SESSION['branch_user'] = $branch['branch_name'];

        // Redirect to the dashboard
        header("Location: index.php");
        exit;
    } else {
        // If no rows were found, the credentials are wrong.
        $error_message = 'Invalid username or password.';
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Branch Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }
    </style>
</head>
<body class="bg-slate-100 flex items-center justify-center h-screen">
    <div class="w-full max-w-sm bg-white p-8 rounded-2xl shadow-xl" data-aos="flip-left" data-aos-duration="800">
        
        <div class="text-center mb-6">
            <i class="fas fa-cogs text-5xl text-blue-500"></i>
            <h1 class="text-3xl font-bold text-gray-800 mt-4">Branch Portal</h1>
            <p class="text-gray-500">Please sign in to continue</p>
        </div>

        <form method="POST">
            <div class="mb-4">
                <label for="username" class="sr-only">Username</label>
                <input type="text" id="username" name="username" placeholder="Username" required class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
            </div>
            <div class="mb-6">
                <label for="password" class="sr-only">Password</label>
                <input type="password" id="password" name="password" placeholder="Password" required class="w-full p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                </div>
            <?php endif; ?>

            <button type="submit" name="login" class="w-full bg-blue-600 text-white font-bold p-3 rounded-lg hover:bg-blue-700 transition transform hover:scale-105 focus:outline-none focus:ring-4 focus:ring-blue-300">
                <i class="fas fa-sign-in-alt mr-2"></i> Login
            </button>
        </form>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>AOS.init();</script>
</body>
</html>