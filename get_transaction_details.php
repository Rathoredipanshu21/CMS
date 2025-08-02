<?php
// get_transaction_details.php

header('Content-Type: application/json');
include 'config/db.php';

$transaction_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($transaction_id === 0) {
    echo json_encode(['error' => 'Invalid Transaction ID']);
    exit;
}

$response = [
    'cash_details' => [],
    'online_details' => []
];

$stmt = $conn->prepare("SELECT detail_type, denomination_or_platform, quantity_or_utr, amount FROM transaction_details WHERE transaction_id = ? ORDER BY detail_type, amount DESC");
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    if ($row['detail_type'] === 'cash') {
        $response['cash_details'][] = $row;
    } elseif ($row['detail_type'] === 'online') {
        $response['online_details'][] = $row;
    }
}

$stmt->close();
$conn->close();

echo json_encode($response);
?>