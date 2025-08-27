<?php
// --- START SESSION and include DB connection ---
session_start(); 
include '../config/db.php'; // Ensure this path is correct

// --- IDENTIFY CUSTOMER: Check session first, then GET parameter ---
$customer_id = 0;

// 1. Check if a customer is logged in via session
if (isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id'])) {
    $customer_id = $_SESSION['customer_id'];
} 
// 2. If no session, check for an ID in the URL (for admin linking)
elseif (isset($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $customer_id = $_GET['id'];
}

// 3. If no ID is found in either session or GET, stop.
if ($customer_id === 0) {
    die("A valid customer ID is required. Please log in or provide an ID in the URL.");
}


// --- FETCH CUSTOMER's PRIMARY DETAILS ---
$stmt = $conn->prepare("SELECT customer_uid, name, photo_path FROM customers WHERE id = ?");
if ($stmt === false) {
    die("Failed to prepare statement to fetch customer details: " . $conn->error);
}
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
$customer = $result->fetch_assoc();
$stmt->close();

if (!$customer) {
    die("Customer not found.");
}

// --- CALCULATE FINANCIAL STATS (DUE / ADVANCE) ---
// This assumes you have a 'transactions' table.
$total_due = 0;
$total_advance = 0;
$balance = 0;

// Get total of all DEBIT transactions (money the customer owes)
$stmt_debit = $conn->prepare("SELECT SUM(total_amount) as total_debits FROM transactions WHERE customer_id = ? AND transaction_type = 'Debit'");
$stmt_debit->bind_param("i", $customer_id);
$stmt_debit->execute();
$debit_result = $stmt_debit->get_result()->fetch_assoc();
$total_debits = $debit_result['total_debits'] ?? 0;
$stmt_debit->close();

// Get total of all CREDIT transactions (money the customer has paid)
$stmt_credit = $conn->prepare("SELECT SUM(total_amount) as total_credits FROM transactions WHERE customer_id = ? AND transaction_type = 'Credit'");
$stmt_credit->bind_param("i", $customer_id);
$stmt_credit->execute();
$credit_result = $stmt_credit->get_result()->fetch_assoc();
$total_credits = $credit_result['total_credits'] ?? 0;
$stmt_credit->close();

$balance = $total_debits - $total_credits;

if ($balance > 0) {
    $total_due = $balance;
} else {
    $total_advance = abs($balance);
}


// --- FETCH ALL TRANSACTIONS WITH THEIR DETAILS ---
$transactions = [];
$sql = "
    SELECT
        t.transaction_id,
        t.transaction_type,
        t.total_amount,
        t.transaction_date,
        td.detail_type,
        td.denomination_or_platform,
        td.quantity_or_utr,
        td.amount as detail_amount
    FROM
        transactions t
    JOIN
        transaction_details td ON t.transaction_id = td.transaction_id
    WHERE
        t.customer_id = ?
    ORDER BY
        t.transaction_date DESC, t.id DESC
";

$stmt_trans = $conn->prepare($sql);
if ($stmt_trans === false) {
    die("Failed to prepare statement to fetch transactions: " . $conn->error);
}
$stmt_trans->bind_param("i", $customer_id);
$stmt_trans->execute();
$result_trans = $stmt_trans->get_result();

while ($row = $result_trans->fetch_assoc()) {
    // Group details by transaction_id
    $transactions[$row['transaction_id']]['details'][] = $row;
    // Store main transaction info only once
    if (!isset($transactions[$row['transaction_id']]['type'])) {
        $transactions[$row['transaction_id']]['type'] = $row['transaction_type'];
        $transactions[$row['transaction_id']]['total'] = $row['total_amount'];
        $transactions[$row['transaction_id']]['date'] = $row['transaction_date'];
    }
}
$stmt_trans->close();
$conn->close();

// Helper function to check for file existence
function file_exists_check($path) {
    if (empty($path)) return false;
    if (strpos($path, 'http') !== 0) {
        return file_exists($path);
    }
    $headers = @get_headers($path);
    return $headers && strpos($headers[0], '200');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f0f2f5;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div class="container mx-auto p-4 md:p-8 max-w-7xl">

        <!-- Customer Header -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 flex items-center space-x-6">
            <?php 
                $photo_path = !empty($customer['photo_path']) && file_exists_check($customer['photo_path']) 
                              ? $customer['photo_path'] 
                              : 'https://placehold.co/150x150/EBF4FF/7F9CF5?text=N/A';
            ?>
            <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="Profile Photo" class="w-24 h-24 rounded-full border-4 border-white shadow-md">
            <div>
                <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></h1>
                <p class="text-md text-gray-500 font-mono">Customer ID: <?php echo htmlspecialchars($customer['customer_uid']); ?></p>
            </div>
        </div>

        <!-- Financial Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
            <div class="bg-red-50 border-l-4 border-red-500 text-red-800 p-6 rounded-r-lg shadow-md">
                <h2 class="text-lg font-semibold mb-2">Total Due</h2>
                <p class="text-4xl font-bold">₹ <?php echo number_format($total_due, 2); ?></p>
            </div>
            <div class="bg-green-50 border-l-4 border-green-500 text-green-800 p-6 rounded-r-lg shadow-md">
                <h2 class="text-lg font-semibold mb-2">Advance Payment</h2>
                <p class="text-4xl font-bold">₹ <?php echo number_format($total_advance, 2); ?></p>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="bg-white rounded-xl shadow-lg p-6 md:p-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6 border-b pb-4">Transaction History</h2>
            <div class="space-y-6">
                <?php if (!empty($transactions)): ?>
                    <?php foreach ($transactions as $txn_id => $txn_data): ?>
                        <?php
                            $is_credit = $txn_data['type'] === 'Credit';
                            $card_color_class = $is_credit ? 'border-green-500' : 'border-red-500';
                            $text_color_class = $is_credit ? 'text-green-600' : 'text-red-600';
                            $icon_class = $is_credit ? 'fa-arrow-down' : 'fa-arrow-up';
                        ?>
                        <div class="border-l-4 <?php echo $card_color_class; ?> bg-gray-50 rounded-r-lg p-4 transition hover:shadow-md">
                            <!-- Transaction Header -->
                            <div class="flex flex-wrap justify-between items-center gap-4">
                                <div class="flex items-center gap-3">
                                    <i class="fas <?php echo $icon_class; ?> <?php echo $text_color_class; ?>"></i>
                                    <span class="font-bold text-lg"><?php echo htmlspecialchars($txn_data['type']); ?></span>
                                    <span class="text-sm text-gray-500 font-mono hidden md:inline"><?php echo htmlspecialchars($txn_id); ?></span>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-xl <?php echo $text_color_class; ?>">₹ <?php echo number_format($txn_data['total'], 2); ?></p>
                                    <p class="text-sm text-gray-500"><?php echo date('F j, Y, g:i a', strtotime($txn_data['date'])); ?></p>
                                </div>
                            </div>
                            
                            <!-- Transaction Details Table -->
                            <div class="mt-4 border-t pt-4">
                                <h4 class="font-semibold mb-2 text-gray-700">Details:</h4>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-gray-200">
                                            <tr>
                                                <th class="text-left p-2">Type</th>
                                                <th class="text-left p-2">Denomination / Platform</th>
                                                <th class="text-left p-2">Quantity / UTR</th>
                                                <th class="text-right p-2">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($txn_data['details'] as $detail): ?>
                                                <tr class="border-b">
                                                    <td class="p-2"><?php echo htmlspecialchars($detail['detail_type']); ?></td>
                                                    <td class="p-2"><?php echo htmlspecialchars($detail['denomination_or_platform']); ?></td>
                                                    <td class="p-2"><?php echo htmlspecialchars($detail['quantity_or_utr']); ?></td>
                                                    <td class="text-right p-2 font-mono">₹ <?php echo number_format($detail['detail_amount'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-10 px-6 bg-gray-50 rounded-lg">
                        <i class="fas fa-receipt fa-3x text-gray-400 mb-4"></i>
                        <h3 class="text-xl font-semibold text-gray-700">No Transactions Found</h3>
                        <p class="text-gray-500 mt-2">There is no transaction history for this customer yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
