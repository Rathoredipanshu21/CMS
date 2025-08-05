<?php
include 'config/db.php'; // Ensure you have your database connection here

// Fetch current commission percentage
$sql = "SELECT * FROM settings WHERE setting_key = 'commission_percentage' LIMIT 1";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
$current_percentage = $row ? $row['setting_value'] : 0;

// Handle form submit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_percentage = floatval($_POST['commission']);
    $update_sql = "UPDATE settings SET setting_value='$new_percentage', updated_at=NOW() WHERE setting_key='commission_percentage'";
    if ($conn->query($update_sql) === TRUE) {
        $success = "Commission updated successfully!";
        $current_percentage = $new_percentage; // Update the local variable
    } else {
        $error = "Error updating commission: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin - Manage Commission</title>
    <style>
        body {
            font-family: "Segoe UI", sans-serif;
            background: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .admin-container {
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
            text-align: center;
            width: 350px;
        }
        h2 {
            color: #333;
            margin-bottom: 25px;
        }
        input[type=number] {
            padding: 10px;
            width: 80%;
            font-size: 16px;
            border-radius: 5px;
            border: 1px solid #ccc;
            margin-bottom: 20px;
        }
        button {
            padding: 10px 25px;
            font-size: 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background: #0056b3;
        }
        .message {
            margin-top: 15px;
            color: green;
        }
        .error {
            color: red;
        }
    </style>
</head>
<body>
<div class="admin-container">
    <h2>Manage Commission %</h2>
    <form method="post">
        <input type="number" name="commission" value="<?= htmlspecialchars($current_percentage) ?>" step="0.01" min="0" max="100" required> %
        <br>
        <button type="submit">Update</button>
    </form>
    <?php if (!empty($success)) echo "<div class='message'>$success</div>"; ?>
    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>
</div>
</body>
</html>

<?php
$conn->close();
?>
