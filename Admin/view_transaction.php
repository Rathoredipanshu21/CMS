<?php
session_start();

// --- DATABASE CONNECTION ---
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    // Create a dummy connection object if the file doesn't exist to avoid fatal errors
    $conn = null;
    $error_message = "Database configuration file not found. Cannot display transaction data.";
}

$transactions = [];

if ($conn) {
    try {
        // Main query to get transaction summary with customer and bank details
        $sql = "SELECT 
                    t.id AS transaction_id,
                    t.transaction_date,
                    t.company_name,
                    t.payment_mode,
                    t.grand_total,
                    t.actual_paid_amount,
                    t.commission_amount,
                    t.dues_amount,
                    t.advance_amount,
                    c.name AS customer_name,
                    c.mobile_no AS customer_mobile,
                    bd.amount AS deposit_amount,
                    bd.bank_transaction_id AS deposit_txn_id,
                    b.bank_name
                FROM transactions t
                JOIN customers c ON t.customer_id = c.id
                LEFT JOIN bank_deposits bd ON t.id = bd.transaction_id
                LEFT JOIN banks b ON bd.bank_id = b.id
                ORDER BY t.transaction_date DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();

        $transactions_base = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Prepare statement to get transaction details (cash/online breakdown)
        $detail_sql = "SELECT detail_type, denomination_or_platform, quantity_or_utr, amount 
                       FROM transaction_details 
                       WHERE transaction_id = ?";
        $detail_stmt = $conn->prepare($detail_sql);

        // Loop through each transaction to fetch its details
        foreach ($transactions_base as $transaction) {
            $detail_stmt->bind_param("i", $transaction['transaction_id']);
            $detail_stmt->execute();
            $detail_result = $detail_stmt->get_result();
            $transaction['details'] = $detail_result->fetch_all(MYSQLI_ASSOC);
            $transactions[] = $transaction;
        }
        $detail_stmt->close();

    } catch (mysqli_sql_exception $exception) {
        $error_message = "Error fetching transactions: " . $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Transactions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5; /* Lighter gray background */
        }
        .card {
            background-color: #ffffff;
            border-radius: 1rem; /* More rounded corners */
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid #e5e7eb;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 30px -10px rgba(0, 0, 0, 0.1);
        }
        .icon-style {
            width: 1.5rem;
            height: 1.5rem;
            margin-right: 0.75rem;
            color: #4f46e5; /* Indigo color for icons */
        }
        .section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1f2937;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: #4b5563;
        }
        .detail-value {
            font-weight: 600;
            color: #1f2937;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.8rem;
        }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

    <div class="max-w-7xl mx-auto">
        <header class="text-center mb-12">
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-800 tracking-tight">Transaction History</h1>
            <p class="mt-3 text-lg text-gray-500">A complete record of all transactions.</p>
        </header>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg" role="alert">
                <p class="font-bold">Error</p>
                <p><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        <?php endif; ?>

        <?php if (empty($transactions) && !isset($error_message)): ?>
            <div class="text-center bg-white p-12 rounded-lg shadow-md">
                <i class="fas fa-box-open fa-4x text-gray-300 mb-4"></i>
                <h2 class="text-2xl font-semibold text-gray-700">No Transactions Found</h2>
                <p class="text-gray-500 mt-2">There are no transaction records to display at the moment.</p>
            </div>
        <?php else: ?>
            <div class="space-y-8">
                <?php foreach ($transactions as $txn): ?>
                <div class="card" data-aos="fade-up" data-aos-duration="600">
                    <div class="p-6">
                        <!-- Card Header -->
                        <div class="flex flex-wrap justify-between items-center border-b border-gray-200 pb-4 mb-6">
                            <div>
                                <h2 class="text-2xl font-bold text-indigo-600">Transaction #<?php echo htmlspecialchars($txn['transaction_id']); ?></h2>
                                <p class="text-sm text-gray-500 mt-1"><i class="fas fa-calendar-alt mr-2"></i><?php echo date("F j, Y, g:i a", strtotime($txn['transaction_date'])); ?></p>
                            </div>
                            <div class="mt-3 sm:mt-0">
                                <span class="badge bg-blue-100 text-blue-800"><?php echo htmlspecialchars($txn['payment_mode']); ?></span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-10 gap-y-8">
                            
                            <!-- Left Column: Customer & Financials -->
                            <div class="space-y-6">
                                <!-- Customer Details -->
                                <div>
                                    <h3 class="section-title"><i class="fas fa-user-circle icon-style"></i>Customer & Company</h3>
                                    <div class="detail-item">
                                        <span class="detail-label">Customer</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($txn['customer_name']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Mobile</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($txn['customer_mobile']); ?></span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Company</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($txn['company_name']); ?></span>
                                    </div>
                                </div>
                                <!-- Financial Summary -->
                                <div>
                                    <h3 class="section-title"><i class="fas fa-file-invoice-dollar icon-style"></i>Financial Summary</h3>
                                    <div class="detail-item"><span class="detail-label">Grand Total</span><span class="detail-value text-blue-600">₹<?php echo number_format($txn['grand_total'], 2); ?></span></div>
                                    <div class="detail-item"><span class="detail-label">Amount Paid</span><span class="detail-value text-green-600">₹<?php echo number_format($txn['actual_paid_amount'], 2); ?></span></div>
                                    <div class="detail-item"><span class="detail-label">Commission</span><span class="detail-value text-purple-600">₹<?php echo number_format($txn['commission_amount'], 2); ?></span></div>
                                    <div class="detail-item"><span class="detail-label">Dues (-)</span><span class="detail-value text-red-600">₹<?php echo number_format($txn['dues_amount'], 2); ?></span></div>
                                    <div class="detail-item"><span class="detail-label">Advance (+)</span><span class="detail-value text-yellow-600">₹<?php echo number_format($txn['advance_amount'], 2); ?></span></div>
                                </div>
                            </div>

                            <!-- Right Column: Payment & Deposit -->
                            <div class="space-y-6">
                                <!-- Payment Breakdown -->
                                <div>
                                    <h3 class="section-title"><i class="fas fa-money-check-alt icon-style"></i>Payment Breakdown</h3>
                                    <?php 
                                    $cash_details = array_filter($txn['details'], fn($d) => $d['detail_type'] == 'cash');
                                    $online_details = array_filter($txn['details'], fn($d) => $d['detail_type'] == 'online');
                                    ?>
                                    <?php if (!empty($cash_details)): ?>
                                        <div class="mb-4">
                                            <p class="font-semibold text-gray-700 mb-2"><i class="fas fa-money-bill-wave text-green-500 mr-2"></i>Cash Payments</p>
                                            <?php foreach ($cash_details as $detail): ?>
                                                <div class="detail-item text-sm">
                                                    <span class="detail-label">₹<?php echo htmlspecialchars($detail['denomination_or_platform']); ?> x <?php echo htmlspecialchars($detail['quantity_or_utr']); ?></span>
                                                    <span class="detail-value">₹<?php echo number_format($detail['amount'], 2); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                     <?php if (!empty($online_details)): ?>
                                        <div>
                                            <p class="font-semibold text-gray-700 mb-2"><i class="fas fa-mobile-alt text-blue-500 mr-2"></i>Online Payments</p>
                                            <?php foreach ($online_details as $detail): ?>
                                                <div class="detail-item text-sm">
                                                    <span class="detail-label"><?php echo htmlspecialchars($detail['denomination_or_platform']); ?> <br><small class="text-gray-400">UTR: <?php echo htmlspecialchars($detail['quantity_or_utr']); ?></small></span>
                                                    <span class="detail-value">₹<?php echo number_format($detail['amount'], 2); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Bank Deposit Details -->
                                <?php if ($txn['bank_name']): ?>
                                <div>
                                    <h3 class="section-title"><i class="fas fa-university icon-style"></i>Bank Deposit</h3>
                                    <div class="detail-item"><span class="detail-label">Bank Name</span><span class="detail-value"><?php echo htmlspecialchars($txn['bank_name']); ?></span></div>
                                    <div class="detail-item"><span class="detail-label">Amount Deposited</span><span class="detail-value">₹<?php echo number_format($txn['deposit_amount'], 2); ?></span></div>
                                    <div class="detail-item"><span class="detail-label">Bank Txn ID</span><span class="detail-value"><?php echo htmlspecialchars($txn['deposit_txn_id'] ?? 'N/A'); ?></span></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({
            once: true, // Whether animation should happen only once - while scrolling down
            anchorPlacement: 'top-bottom', // Defines which position of the element regarding to window should trigger the animation
        });
    </script>
</body>
</html>
