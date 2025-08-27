<?php
session_start();

// --- SECURITY CHECK ---
// If the customer is not logged in, redirect them to the login page.
if (!isset($_SESSION['customer_id'])) {
    header("Location: Login.php");
    exit();
}

// --- DATABASE CONNECTION ---
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    die("Database configuration file not found.");
}

// --- INITIALIZATION ---
$customer_id = $_SESSION['customer_id'];
$transactions = [];

// --- FETCH TRANSACTION DATA FOR THE LOGGED-IN CUSTOMER ---
$sql = "
    SELECT 
        t.id as transaction_id,
        t.grand_total,
        t.transaction_date,
        t.payment_mode,
        t.bank_transaction_id,
        b.bank_name,
        td.detail_type,
        td.denomination_or_platform,
        td.quantity_or_utr,
        td.amount as detail_amount
    FROM transactions t
    LEFT JOIN banks b ON t.deposit_bank_id = b.id
    LEFT JOIN transaction_details td ON t.id = td.transaction_id
    WHERE t.customer_id = ?
    ORDER BY t.transaction_date DESC, t.id DESC
";

$stmt_trans = $conn->prepare($sql);
$stmt_trans->bind_param("i", $customer_id);
$stmt_trans->execute();
$result_trans = $stmt_trans->get_result();

// --- PROCESS AND GROUP RESULTS ---
if ($result_trans) {
    while ($row = $result_trans->fetch_assoc()) {
        $tid = $row['transaction_id'];
        if (!isset($transactions[$tid])) {
            $transactions[$tid] = [
                'details' => [
                    'transaction_date' => $row['transaction_date'],
                    'grand_total' => $row['grand_total'],
                    'payment_mode' => $row['payment_mode'],
                ],
                'cash_breakdown' => [],
                'online_breakdown' => []
            ];
        }
        if ($row['detail_type'] === 'cash') {
            $transactions[$tid]['cash_breakdown'][] = $row;
        } elseif ($row['detail_type'] === 'online') {
            $transactions[$tid]['online_breakdown'][] = $row;
        }
    }
}
$stmt_trans->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Transaction History</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: #f7fafc; 
        }
        .timeline-item:not(:last-child):before {
            content: '';
            position: absolute;
            left: 24px;
            top: 50px;
            bottom: -20px;
            width: 3px;
            background-color: #e2e8f0;
        }
        .timeline-icon {
            position: absolute;
            left: 0;
            top: 0;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 3px solid #f7fafc;
            background-color: #ffffff;
            z-index: 1;
            box-shadow: 0 0 0 4px #e2e8f0;
        }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-4xl mx-auto">
    
    <div class="bg-white rounded-2xl shadow-lg p-6 md:p-8" data-aos="fade-up">
        <!-- Page Header -->
        <div class="text-center mb-10">
            <h1 class="text-4xl font-bold text-gray-800">Transaction History</h1>
            <p class="text-gray-500 mt-2">A complete record of all your payments.</p>
        </div>

        <!-- Transaction Timeline -->
        <div class="relative pl-12">
            <?php if (empty($transactions)): ?>
                <div class="text-center py-16" data-aos="fade-up">
                    <i class="fas fa-receipt fa-4x text-gray-300"></i>
                    <h3 class="mt-4 text-2xl font-semibold text-gray-600">No Transactions Yet</h3>
                    <p class="text-gray-400 mt-1">Your payment history will appear here once you make a transaction.</p>
                </div>
            <?php else: ?>
                <?php foreach ($transactions as $tid => $t): ?>
                    <div class="timeline-item relative pb-10" data-aos="fade-up" data-aos-anchor-placement="top-bottom">
                        <div class="timeline-icon bg-indigo-500 text-white shadow-lg"><i class="fas fa-receipt"></i></div>
                        <div class="ml-8">
                            <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden hover:shadow-xl hover:border-indigo-300 transition-all duration-300">
                                <div class="p-4 bg-gray-50 flex flex-wrap justify-between items-center gap-2 border-b">
                                    <p class="font-bold text-gray-800">Transaction ID: <span class="font-mono text-indigo-600"><?php echo $tid; ?></span></p>
                                    <p class="text-xs text-gray-500 bg-white px-2 py-1 rounded-full border"><i class="far fa-calendar-alt mr-1"></i><?php echo date('d M Y, h:i A', strtotime($t['details']['transaction_date'])); ?></p>
                                </div>
                                <div class="p-5">
                                    <div class="flex justify-between items-baseline mb-4">
                                        <p class="font-bold text-2xl text-gray-800">₹<?php echo number_format($t['details']['grand_total'], 2); ?></p>
                                        <span class="text-sm font-semibold text-gray-600 px-3 py-1 bg-gray-200 rounded-full"><i class="fas fa-credit-card mr-2 text-gray-400"></i><?php echo htmlspecialchars($t['details']['payment_mode']); ?></span>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 <?php if (!empty($t['cash_breakdown']) && !empty($t['online_breakdown'])) echo 'md:grid-cols-2'; ?> gap-4">
                                        <?php if (!empty($t['cash_breakdown'])): ?>
                                            <div class="bg-emerald-50 p-3 rounded-lg border border-emerald-200">
                                                <h4 class="font-semibold text-sm text-emerald-800 mb-2"><i class="fas fa-money-bill-wave mr-2"></i>Cash Breakdown</h4>
                                                <table class="w-full text-xs"><tbody><?php foreach ($t['cash_breakdown'] as $cash): ?><tr><td class="py-1">₹ <?php echo number_format($cash['denomination_or_platform']); ?></td><td class="py-1 text-center">x <?php echo $cash['quantity_or_utr']; ?></td><td class="py-1 text-right font-bold">₹<?php echo number_format($cash['detail_amount'], 2); ?></td></tr><?php endforeach; ?></tbody></table>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($t['online_breakdown'])): ?>
                                            <div class="bg-sky-50 p-3 rounded-lg border border-sky-200">
                                                <h4 class="font-semibold text-sm text-sky-800 mb-2"><i class="fas fa-satellite-dish mr-2"></i>Online Breakdown</h4>
                                                <div class="space-y-1"><?php foreach ($t['online_breakdown'] as $online): ?><div class="text-xs"><div class="flex justify-between"><span><?php echo htmlspecialchars($online['denomination_or_platform']); ?></span><span class="font-bold">₹<?php echo number_format($online['detail_amount'], 2); ?></span></div><p class="text-gray-400 font-mono">UTR: <?php echo htmlspecialchars($online['quantity_or_utr']); ?></p></div><?php endforeach; ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Floating Logout Button -->
<a href="logout.php" title="Logout" class="fixed bottom-6 right-6 w-14 h-14 bg-red-500 hover:bg-red-600 text-white flex items-center justify-center rounded-full shadow-lg transition-transform hover:scale-110" data-aos="fade-up" data-aos-delay="500">
    <i class="fas fa-sign-out-alt fa-lg"></i>
</a>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({
        duration: 800,
        once: true,
    });
</script>

</body>
</html>
