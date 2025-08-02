<?php
// check_utr.php
header('Content-Type: application/json');
include 'config/db.php';

$response = ['exists' => false];
$utr = isset($_POST['utr']) ? trim($_POST['utr']) : '';

if (!empty($utr)) {
    // We check for the UTR in the `quantity_or_utr` column where the type is 'online'
    $stmt = $conn->prepare("SELECT id FROM transaction_details WHERE detail_type = 'online' AND quantity_or_utr = ?");
    $stmt->bind_param("s", $utr);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $response['exists'] = true;
    }

    $stmt->close();
}

$conn->close();
echo json_encode($response);
?>