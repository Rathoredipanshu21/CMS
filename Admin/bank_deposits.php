<?php
session_start();

// --- (Optional) Admin Login Check ---
// if (!isset($_SESSION['admin_id'])) {
//     header("Location: admin_login.php");
//     exit();
// }

// --- DATABASE CONNECTION ---
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    die("Database configuration file not found.");
}

// --- INITIALIZATION ---
$banks_data = [];

// --- FETCH AND PROCESS DATA ---
// This query gets all transactions with a valid bank deposit, joining customer and bank info.
$sql = "
    SELECT
        b.id as bank_id,
        b.bank_name,
        t.final_payable_amount,
        t.transaction_date,
        t.bank_transaction_id,
        c.name as customer_name,
        c.customer_uid
    FROM transactions t
    JOIN banks b ON t.deposit_bank_id = b.id
    JOIN customers c ON t.customer_id = c.id
    WHERE t.deposit_bank_id IS NOT NULL AND t.final_payable_amount > 0
    ORDER BY b.bank_name ASC, t.transaction_date DESC
";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bank_id = $row['bank_id'];

        // Create bank entry if it doesn't exist
        if (!isset($banks_data[$bank_id])) {
            $banks_data[$bank_id] = [
                'bank_details' => [
                    'name' => $row['bank_name'],
                    'total_deposited' => 0,
                    'transaction_count' => 0
                ],
                'transactions' => []
            ];
        }

        // Add transaction details to the bank's record
        $banks_data[$bank_id]['transactions'][] = [
            'date' => $row['transaction_date'],
            'amount' => $row['final_payable_amount'],
            'customer_name' => $row['customer_name'],
            'customer_uid' => $row['customer_uid'],
            'bank_transaction_id' => $row['bank_transaction_id']
        ];

        // Update totals
        $banks_data[$bank_id]['bank_details']['total_deposited'] += $row['final_payable_amount'];
        $banks_data[$bank_id]['bank_details']['transaction_count']++;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Bank Deposit Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f8fafc; }
        .bank-card { transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .bank-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1); }
        .modal { transition: opacity 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-md p-6 mb-8 text-center" data-aos="fade-down">
        <div class="text-indigo-600 text-4xl mb-2"><i class="fas fa-university"></i></div>
        <h1 class="text-4xl font-bold text-gray-800">Bank Deposit Report</h1>
        <p class="text-gray-500 mt-1">Summary of total amounts deposited into each bank.</p>
    </div>

    <!-- Bank Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        <?php if (empty($banks_data)): ?>
            <div class="col-span-full text-center py-16 bg-white rounded-2xl shadow-md" data-aos="fade-up">
                <i class="fas fa-search-dollar fa-4x text-gray-300"></i>
                <h2 class="mt-4 text-2xl font-semibold text-gray-700">No Deposits Found</h2>
                <p class="text-gray-500 mt-2">There are no recorded bank deposits in the system yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($banks_data as $bid => $data): ?>
                <a href="#" class="bank-card block bg-white rounded-2xl shadow-lg overflow-hidden" data-bank-id="<?php echo $bid; ?>" data-aos="fade-up">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="bg-indigo-100 text-indigo-600 p-4 rounded-full">
                                <i class="fas fa-landmark fa-2x"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($data['bank_details']['name']); ?></p>
                            </div>
                        </div>
                        <div class="mt-6">
                            <p class="text-sm text-gray-500 uppercase font-semibold">Total Amount Deposited</p>
                            <p class="text-4xl font-extrabold text-gray-900">₹<?php echo number_format($data['bank_details']['total_deposited'], 2); ?></p>
                        </div>
                    </div>
                    <div class="px-6 pb-4 text-sm text-gray-500">
                        From <?php echo $data['bank_details']['transaction_count']; ?> transactions
                    </div>
                    <div class="p-3 bg-gray-100 text-center text-indigo-600 font-semibold">
                        View Details <i class="fas fa-arrow-right ml-2"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal for displaying transactions -->
<div id="deposit-modal" class="modal fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 z-50 hidden opacity-0">
    <div class="modal-content bg-gray-100 rounded-2xl shadow-2xl w-full max-w-4xl h-full max-h-[90vh] transform scale-95">
        <div class="flex justify-between items-center p-5 border-b border-gray-200 bg-white rounded-t-2xl">
            <div id="modal-header" class="flex items-center">
                <!-- Header content will be injected by JS -->
            </div>
            <button id="close-modal" class="text-gray-400 hover:text-red-600 text-3xl">&times;</button>
        </div>
        <div id="modal-body" class="p-2 sm:p-6 overflow-y-auto h-[calc(100%-72px)]">
            <div class="bg-white rounded-xl shadow-inner">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Customer</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Txn ID</th>
                            <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="modal-table-body" class="divide-y divide-gray-200">
                        <!-- Transaction rows will be injected by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Hidden data block for JS -->
<div id="all-banks-data" class="hidden">
    <?php echo json_encode($banks_data); ?>
</div>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        AOS.init({
            duration: 600,
            once: true,
        });

        const banksData = JSON.parse(document.getElementById('all-banks-data').textContent);
        const modal = document.getElementById('deposit-modal');
        const modalContent = modal.querySelector('.modal-content');
        const modalHeader = document.getElementById('modal-header');
        const modalTableBody = document.getElementById('modal-table-body');
        const closeModalBtn = document.getElementById('close-modal');

        document.querySelectorAll('.bank-card').forEach(card => {
            card.addEventListener('click', function(e) {
                e.preventDefault();
                const bankId = this.dataset.bankId;
                const bankData = banksData[bankId];
                
                populateModal(bankData);
                showModal();
            });
        });

        function populateModal(data) {
            // Populate Header
            modalHeader.innerHTML = `
                <div class="bg-indigo-100 text-indigo-600 p-3 rounded-full"><i class="fas fa-landmark fa-lg"></i></div>
                <div class="ml-4">
                    <p class="text-2xl font-bold text-gray-800">${data.bank_details.name}</p>
                    <p class="text-sm text-gray-500">Deposit History</p>
                </div>
            `;

            // Populate Table Body
            let transactionsHTML = '';
            data.transactions.forEach(t => {
                const transactionDate = new Date(t.date).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
                const amount = parseFloat(t.amount).toLocaleString('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 });

                transactionsHTML += `
                    <tr class="hover:bg-gray-50">
                        <td class="py-4 px-4 whitespace-nowrap text-sm text-gray-600">${transactionDate}</td>
                        <td class="py-4 px-4 whitespace-nowrap text-sm text-gray-800 hidden sm:table-cell">
                            <div class="font-medium">${t.customer_name}</div>
                            <div class="text-xs text-gray-500 font-mono">${t.customer_uid}</div>
                        </td>
                        <td class="py-4 px-4 whitespace-nowrap text-sm text-gray-500 font-mono">${t.bank_transaction_id || 'N/A'}</td>
                        <td class="py-4 px-4 whitespace-nowrap text-sm text-gray-900 font-bold text-right">${amount}</td>
                    </tr>
                `;
            });
            modalTableBody.innerHTML = transactionsHTML;
        }

        function showModal() {
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.classList.remove('opacity-0');
                modalContent.classList.remove('scale-95');
            }, 10);
        }

        function hideModal() {
            modal.classList.add('opacity-0');
            modalContent.classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        closeModalBtn.addEventListener('click', hideModal);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                hideModal();
            }
        });
    });
</script>

</body>
</html>
