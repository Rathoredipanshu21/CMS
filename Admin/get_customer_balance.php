<?php
// Set the content type to JSON for the response
header('Content-Type: application/json');

// --- DATABASE CONNECTION ---
// Ensure this path is correct for your project structure
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    // Fallback for standalone testing. Replace with your actual connection details.
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'cash';
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        // If connection fails, send a JSON error response
        echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
        exit;
    }
}

// Initialize response array
$response = [
    'success' => false,
    'dues_amount' => '0.00',
    'advance_amount' => '0.00'
];

// Check if customer_id is provided in the request
if (isset($_GET['customer_id']) && !empty($_GET['customer_id'])) {
    
    $customer_id = (int)$_GET['customer_id'];

    // Prepare a statement to prevent SQL injection
    $stmt = $conn->prepare("SELECT dues_amount, advance_amount FROM customer_finances WHERE customer_id = ?");
    
    if ($stmt) {
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // If a record is found, update the response
        if ($result->num_rows > 0) {
            $finance_data = $result->fetch_assoc();
            $response['success'] = true;
            $response['dues_amount'] = $finance_data['dues_amount'];
            $response['advance_amount'] = $finance_data['advance_amount'];
        } else {
            // If no record is found, it's not an error, the customer just has no balance.
            // The default values of 0.00 are correct. We can set success to true.
            $response['success'] = true; 
        }
        
        $stmt->close();
    } else {
        // Handle potential SQL error
        $response['message'] = 'Failed to prepare the database statement.';
    }

} else {
    // Handle case where no customer_id is sent
    $response['message'] = 'No customer ID provided.';
}

// Close the database connection
if ($conn) {
    $conn->close();
}

// Send the JSON response back to the JavaScript
echo json_encode($response);
?>
