<?php
// check_utr.php
header('Content-Type: application/json');
include '../config/db.php'; // Ensure you have your database connection here

$response = ['exists' => false, 'details' => null];
$utr = isset($_POST['utr']) ? trim($_POST['utr']) : '';

if (!empty($utr) && $conn) {
    // This query now joins the transactions and customers tables to get all relevant details
    $sql = "SELECT 
                td.transaction_id,
                td.amount,
                t.company_name,
                t.payment_mode,
                DATE_FORMAT(t.transaction_date, '%d-%b-%Y %h:%i %p') as transaction_date,
                c.name as customer_name,
                c.mobile_no as customer_mobile
            FROM 
                transaction_details td
            JOIN 
                transactions t ON td.transaction_id = t.id
            JOIN 
                customers c ON t.customer_id = c.id
            WHERE 
                td.detail_type = 'online' AND td.quantity_or_utr = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $utr);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $response['exists'] = true;
        $response['details'] = $result->fetch_assoc();
    }

    $stmt->close();
}

$conn->close();
echo json_encode($response);
?>