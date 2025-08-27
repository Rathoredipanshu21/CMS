<?php
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include '../config/db.php';

    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT * FROM admin WHERE username='$username' AND password='$password'";
    $result = $conn->query($sql);

    if ($result->num_rows === 1) {
        $_SESSION['admin'] = $username;
        header("Location: index.php");
    } else {
        $error = "Invalid Username or Password";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            /* background: linear-gradient(135deg, #fceabb, #f8b500); */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-box {
            background: #fff;
            padding: 40px 30px;
            border-radius: 12px;
            box-shadow: 0 0 20px rgba(0,0,0,0.2);
            width: 380px;
            text-align: center;
        }

        .login-box img {
            width: 80px;
            margin-bottom: 15px;
        }

        .login-box h2 {
            margin-bottom: 20px;
            color: #333;
        }

        .input-container {
            position: relative;
            margin-bottom: 20px;
        }

        .input-container input {
            width: 90%;
            padding: 12px 12px 12px 42px;
            border: 1px solid #ccc;
            border-radius: 8px;
            outline: none;
            font-size: 15px;
        }

        .input-container i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
        }

        .input-container .fa-user {
            color: #007bff;
        }

        .input-container .fa-lock {
            color: #dc3545;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #f8b500;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #e6a400;
        }

        .error {
            color: red;
            margin-top: 10px;
        }

        @media (max-width: 450px) {
            .login-box {
                width: 90%;
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>

<div class="login-box">
    <img src="https://cdn-icons-png.flaticon.com/512/5087/5087579.png" alt="Admin Icon">
    <h2>Admin Login</h2>
    <form method="post" action="">
        <div class="input-container">
            <i class="fas fa-user"></i>
            <input type="text" name="username" placeholder="Username" required>
        </div>
        <div class="input-container">
            <i class="fas fa-lock"></i>
            <input type="password" name="password" placeholder="Password" required>
        </div>
        <button type="submit">Login</button>
        <?php if (isset($error)) echo "<div class='error'>$error</div>"; ?>
    </form>
</div>

</body>
</html>
