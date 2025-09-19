<?php
// File: update_bank_balance.php
session_start();
header('Content-Type: application/json');

function send_json_response($success, $message, $data = []) {
    $response = ['success' => $success, 'message' => $message];
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response);
    exit();
}

if (!file_exists('../config/db.php')) {
    send_json_response(false, 'Database configuration not found.');
}
include '../config/db.php'; 

if (!$pdo) {
    send_json_response(false, 'Database connection object ($pdo) not found.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(false, 'Invalid request method.');
}

$bank_id = isset($_POST['bank_id']) ? (int)$_POST['bank_id'] : 0;
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$action = isset($_POST['action']) ? $_POST['action'] : ''; // 'add' or 'subtract'

if ($bank_id <= 0 || $amount <= 0 || !in_array($action, ['add', 'subtract'])) {
    send_json_response(false, 'Invalid parameters provided.');
}

$pdo->beginTransaction();
try {
    $stmt_get = $pdo->prepare("SELECT account_balance FROM banks WHERE id = :bank_id FOR UPDATE");
    $stmt_get->execute([':bank_id' => $bank_id]);
    $bank_data = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$bank_data) {
        throw new Exception('Bank record not found.');
    }
    $current_balance = (float)$bank_data['account_balance'];
    $new_balance = 0;
    $history_type = '';
    
    if ($action === 'add') {
        $new_balance = $current_balance + $amount;
        $history_type = 'deposit_provisional';
    } else { // subtract
        if ($current_balance < $amount) {
            throw new Exception('Insufficient funds to reverse the transaction.');
        }
        $new_balance = $current_balance - $amount;
        $history_type = 'reversal_provisional';
    }
    
    $stmt_update = $pdo->prepare("UPDATE banks SET account_balance = :new_balance WHERE id = :bank_id");
    $stmt_update->execute([':new_balance' => $new_balance, ':bank_id' => $bank_id]);

    // **FIXED**: Removed the 'notes' column from this query.
    $stmt_log = $pdo->prepare(
        "INSERT INTO banks_transactions_history (bank_id, transaction_type, amount, balance_before, balance_after) 
         VALUES (:bank_id, :type, :amount, :before, :after)"
    );
    $stmt_log->execute([
        ':bank_id' => $bank_id,
        ':type' => $history_type,
        ':amount' => $amount,
        ':before' => $current_balance,
        ':after' => $new_balance
    ]);
    
    $provisional_log_id = $pdo->lastInsertId();

    $pdo->commit();

    send_json_response(true, 'Bank balance updated successfully.', [
        'new_balance' => $new_balance,
        'log_id' => $provisional_log_id
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    send_json_response(false, 'Database operation failed: ' . $e->getMessage());
}
?>