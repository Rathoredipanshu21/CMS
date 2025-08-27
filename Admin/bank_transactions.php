<?php
session_start();

// --- DATABASE CONNECTION ---
// Ensure this path is correct for your project structure.
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    $error = "Database configuration file not found.";
    $conn = null;
}

// --- INITIALIZE VARIABLES ---
$banks = [];
$transactions = [];
$selected_bank_id = null;
$selected_bank_name = "All Banks";
$current_balance = null;

// --- FETCH DATA ---
if ($conn) {
    // Fetch all banks for the dropdown selector
    $banks_result = $conn->query("SELECT id, bank_name, account_balance FROM banks ORDER BY bank_name ASC");
    if ($banks_result) {
        while ($row = $banks_result->fetch_assoc()) {
            $banks[] = $row;
        }
    }

    // Check if a specific bank has been selected from the form
    if (isset($_GET['bank_id']) && !empty($_GET['bank_id'])) {
        $selected_bank_id = (int)$_GET['bank_id'];

        // Prepare the SQL query to fetch history for the selected bank
        $stmt = $conn->prepare(
            "SELECT 
                h.id, 
                h.transaction_type, 
                h.amount, 
                h.balance_before, 
                h.balance_after, 
                h.created_at,
                b.bank_name,
                b.account_balance
            FROM banks_transactions_history h
            JOIN banks b ON h.bank_id = b.id
            WHERE h.bank_id = ?
            ORDER BY h.created_at DESC"
        );
        $stmt->bind_param("i", $selected_bank_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
            // Get the bank name and current balance for the header
            if (!empty($transactions)) {
                $selected_bank_name = $transactions[0]['bank_name'];
                $current_balance = $transactions[0]['account_balance'];
            } else {
                // If no transactions, still get bank name from the banks array
                foreach($banks as $bank) {
                    if ($bank['id'] == $selected_bank_id) {
                        $selected_bank_name = $bank['bank_name'];
                        $current_balance = $bank['account_balance'];
                        break;
                    }
                }
            }
        }
        $stmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Transaction Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .form-select { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; }
        .btn { padding: 0.6rem 1.5rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s ease; border: none; cursor: pointer; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto bg-white rounded-2xl shadow-lg p-6 sm:p-8">
    
    <!-- Header -->
    <div class="text-center mb-8 border-b border-gray-200 pb-6">
        <h1 class="text-3xl sm:text-4xl font-bold text-gray-800 tracking-tight">Bank Transaction History</h1>
        <p class="text-lg text-gray-500 mt-2">View historical transactions for a selected bank account.</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg" role="alert">
            <p><?php echo $error; ?></p>
        </div>
    <?php endif; ?>

    <!-- Bank Selection Form -->
    <div class="mb-8 bg-gray-50 p-6 rounded-lg border border-gray-200">
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="get" class="flex flex-col sm:flex-row items-center sm:items-end gap-4">
            <div class="flex-grow w-full sm:w-auto">
                <label for="bank_id" class="block text-sm font-medium text-gray-700 mb-1">Select a Bank</label>
                <select id="bank_id" name="bank_id" class="form-select w-full" required>
                    <option value="">-- Choose a Bank --</option>
                    <?php foreach($banks as $bank): ?>
                        <option value="<?php echo $bank['id']; ?>" <?php echo ($selected_bank_id == $bank['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($bank['bank_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-shrink-0">
                <button type="submit" class="btn bg-blue-600 hover:bg-blue-700 text-white w-full sm:w-auto">
                    <i class="fas fa-search mr-2"></i>View History
                </button>
            </div>
        </form>
    </div>

    <!-- Transaction Details Section -->
    <?php if ($selected_bank_id): ?>
    <div id="transaction-details">
        <div class="flex flex-col sm:flex-row justify-between items-center mb-4 pb-4 border-b-2 border-gray-200">
            <h2 class="text-2xl font-bold text-gray-700">
                History for: <span class="text-blue-600"><?php echo htmlspecialchars($selected_bank_name); ?></span>
            </h2>
            <?php if($current_balance !== null): ?>
            <div class="text-xl font-semibold text-gray-800 bg-green-100 text-green-800 px-4 py-2 rounded-lg mt-2 sm:mt-0">
                Current Balance: ₹<?php echo number_format($current_balance, 2); ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border border-gray-200 rounded-lg">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="text-left py-3 px-4 font-semibold text-sm text-gray-600 uppercase tracking-wider">Date & Time</th>
                        <th class="text-left py-3 px-4 font-semibold text-sm text-gray-600 uppercase tracking-wider">Transaction Type</th>
                        <th class="text-right py-3 px-4 font-semibold text-sm text-gray-600 uppercase tracking-wider">Amount</th>
                        <th class="text-right py-3 px-4 font-semibold text-sm text-gray-600 uppercase tracking-wider">Balance Before</th>
                        <th class="text-right py-3 px-4 font-semibold text-sm text-gray-600 uppercase tracking-wider">Balance After</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700">
                    <?php if (!empty($transactions)): ?>
                        <?php foreach($transactions as $txn): ?>
                        <tr class="border-b border-gray-200 hover:bg-gray-50">
                            <td class="py-3 px-4"><?php echo date('d M, Y h:i A', strtotime($txn['created_at'])); ?></td>
                            <td class="py-3 px-4">
                                <?php 
                                    $type = htmlspecialchars(ucfirst($txn['transaction_type']));
                                    if ($type == 'Payment') {
                                        echo "<span class='bg-red-100 text-red-800 font-medium py-1 px-3 rounded-full text-xs'>{$type}</span>";
                                    } else {
                                        echo "<span class='bg-green-100 text-green-800 font-medium py-1 px-3 rounded-full text-xs'>{$type}</span>";
                                    }
                                ?>
                            </td>
                            <td class="py-3 px-4 text-right font-mono <?php echo ($txn['transaction_type'] == 'payment') ? 'text-red-600' : 'text-green-600'; ?>">
                                <?php echo ($txn['transaction_type'] == 'payment') ? '-' : '+'; ?> ₹<?php echo number_format($txn['amount'], 2); ?>
                            </td>
                            <td class="py-3 px-4 text-right font-mono text-gray-500">₹<?php echo number_format($txn['balance_before'], 2); ?></td>
                            <td class="py-3 px-4 text-right font-mono font-semibold">₹<?php echo number_format($txn['balance_after'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-10 text-gray-500">
                                <i class="fas fa-info-circle text-2xl mb-2"></i>
                                <p>No transactions found for this bank.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
