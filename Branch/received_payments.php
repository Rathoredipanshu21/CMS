<?php
// --- DATABASE CONNECTION ---
// Ensure this path is correct for your project structure.
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    // Fallback for standalone testing (replace with your actual credentials)
    $conn = new mysqli("localhost", "root", "", "your_database_name");
    if ($conn->connect_error) {
        // We can't die here if it's an API call, so we handle it differently.
    }
}

// --- HANDLE AJAX REQUEST FOR UPDATING TRANSACTION ID ---
if (isset($_POST['action']) && $_POST['action'] === 'update_bank_txn_id') {
    header('Content-Type: application/json');

    if (!$conn || $conn->connect_error) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
        exit;
    }
    
    if (!isset($_POST['transaction_id']) || !isset($_POST['bank_transaction_id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing required parameters.']);
        exit;
    }

    $transaction_id = (int)$_POST['transaction_id'];
    $bank_transaction_id = trim($_POST['bank_transaction_id']);

    if ($transaction_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid Transaction ID.']);
        exit;
    }

    // Check if a record exists in bank_deposits for this transaction_id
    $check_stmt = $conn->prepare("SELECT id FROM bank_deposits WHERE transaction_id = ?");
    $check_stmt->bind_param("i", $transaction_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_stmt->close();

    if ($check_result->num_rows > 0) {
        // If it exists, UPDATE it
        $stmt = $conn->prepare("UPDATE bank_deposits SET bank_transaction_id = ? WHERE transaction_id = ?");
        $stmt->bind_param("si", $bank_transaction_id, $transaction_id);
    } else {
        // This case indicates a data inconsistency. We cannot update a non-existent record.
        echo json_encode(['success' => false, 'error' => 'No bank deposit record found for this transaction to update.']);
        $conn->close();
        exit;
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to execute statement: ' . $stmt->error]);
    }

    $stmt->close();
    $conn->close();
    exit; // IMPORTANT: Stop script execution after handling the AJAX request
}


// --- REGULAR PAGE LOAD LOGIC ---
$error = '';
$customers_with_transactions = [];

if ($conn && !$conn->connect_error) {
    // --- SQL QUERY TO FETCH AND JOIN DATA ---
    $sql = "
        SELECT 
            t.id AS transaction_id,
            t.customer_id,
            t.company_name,
            t.payment_mode,
            t.grand_total,
            t.actual_paid_amount,
            t.commission_amount,
            t.dues_amount,
            t.advance_amount,
            t.transaction_date,
            c.name AS customer_name,
            c.mobile_no AS customer_mobile,
            td.detail_type,
            td.denomination_or_platform,
            td.quantity_or_utr,
            td.amount AS detail_amount,
            b.bank_name,
            bd.amount AS deposit_amount,
            bd.bank_transaction_id
        FROM 
            transactions t
        JOIN 
            customers c ON t.customer_id = c.id
        LEFT JOIN 
            transaction_details td ON t.id = td.transaction_id
        LEFT JOIN 
            bank_deposits bd ON t.id = bd.transaction_id
        LEFT JOIN 
            banks b ON bd.bank_id = b.id
        ORDER BY 
            c.name ASC, t.transaction_date DESC, t.id DESC
    ";

    $result = $conn->query($sql);

    if ($result) {
        // --- PROCESS AND GROUP DATA BY CUSTOMER, THEN BY TRANSACTION ---
        while($row = $result->fetch_assoc()) {
            $customer_id = $row['customer_id'];
            $tx_id = $row['transaction_id'];

            if (!isset($customers_with_transactions[$customer_id])) {
                $customers_with_transactions[$customer_id] = [
                    'customer_name'   => htmlspecialchars($row['customer_name']),
                    'customer_mobile' => htmlspecialchars($row['customer_mobile']),
                    'total_amount'    => 0, // NEW: Initialize total amount
                    'transactions'    => []
                ];
            }

            if (!isset($customers_with_transactions[$customer_id]['transactions'][$tx_id])) {
                 // NEW: Add grand_total to the customer's total amount for each new transaction
                 $customers_with_transactions[$customer_id]['total_amount'] += (float)$row['grand_total'];

                 $customers_with_transactions[$customer_id]['transactions'][$tx_id] = [
                    'transaction_id'      => $row['transaction_id'],
                    'company_name'        => htmlspecialchars($row['company_name']),
                    'payment_mode'        => htmlspecialchars($row['payment_mode']),
                    'grand_total'         => (float)$row['grand_total'],
                    'actual_paid_amount'  => (float)$row['actual_paid_amount'],
                    'commission_amount'   => (float)$row['commission_amount'],
                    'dues_amount'         => (float)$row['dues_amount'],
                    'advance_amount'      => (float)$row['advance_amount'],
                    'transaction_date'    => (new DateTime($row['transaction_date']))->format('d M Y, h:i A'),
                    'raw_date'            => (new DateTime($row['transaction_date']))->format('Y-m-d'), // NEW: Add raw date for filtering
                    'bank_name'           => $row['bank_name'] ? htmlspecialchars($row['bank_name']) : null,
                    'deposit_amount'      => $row['deposit_amount'] ? (float)$row['deposit_amount'] : null,
                    'bank_transaction_id' => $row['bank_transaction_id'] ? htmlspecialchars($row['bank_transaction_id']) : 'N/A',
                    'details'             => []
                ];
            }

            if ($row['detail_type']) {
                $detail_key = $row['detail_type'] . '_' . $row['denomination_or_platform'] . '_' . $row['quantity_or_utr'] . '_' . $row['detail_amount'];
                if(!isset($customers_with_transactions[$customer_id]['transactions'][$tx_id]['details'][$detail_key])) {
                    $customers_with_transactions[$customer_id]['transactions'][$tx_id]['details'][$detail_key] = [
                        'type'      => $row['detail_type'],
                        'platform'  => htmlspecialchars($row['denomination_or_platform']),
                        'qty_utr'   => htmlspecialchars($row['quantity_or_utr']),
                        'amount'    => (float)$row['detail_amount']
                    ];
                }
            }
        }
    } else {
        $error = "Error fetching transactions: " . $conn->error;
    }
    $conn->close();
} else {
    $error = "Database connection could not be established.";
}

function format_inr($amount) {
    return '₹ ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; }
        .modal-backdrop { transition: opacity 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
        .icon-wrapper { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin-right: 12px; flex-shrink: 0; }
        .details-list li { padding: 8px 12px; border-radius: 6px; transition: background-color 0.2s ease; }
        .accordion-content { max-height: 0; overflow: hidden; transition: max-height 0.5s ease-in-out, padding 0.5s ease-in-out; padding: 0 1.25rem; }
        .toast {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 100;
            opacity: 0;
            transition: opacity 0.3s, bottom 0.3s;
        }
        .toast.show { opacity: 1; bottom: 40px; }
        .toast.success { background-color: #28a745; }
        .toast.error { background-color: #dc3545; }
        /* Style for date input clear button */
        input[type="date"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
        }
        input[type="date"]::-webkit-clear-button {
            display: none;
        }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-5xl mx-auto">
    <header class="text-center mb-10" data-aos="fade-down">
        <h1 class="text-4xl sm:text-5xl font-bold text-gray-800 tracking-tight"><i class="fas fa-receipt text-blue-500"></i> Transaction History</h1>
        <p class="text-lg text-gray-500 mt-2">A complete record of all transactions, grouped by customer.</p>
    </header>

    <!-- UPDATED: FILTER SECTION -->
    <div class="mb-6 bg-white p-4 rounded-xl shadow-md" data-aos="fade-up">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Name Filter -->
            <div class="relative md:col-span-1">
                <label for="filter-name" class="block text-sm font-medium text-gray-700 mb-1">Filter by Name</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" id="filter-name" placeholder="Search for a customer..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <!-- Date Filter -->
            <div class="relative md:col-span-1">
                <label for="filter-date" class="block text-sm font-medium text-gray-700 mb-1">Filter by Date</label>
                <div class="relative">
                    <i class="fas fa-calendar-alt absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="date" id="filter-date" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            <!-- Search Button -->
            <div class="md:col-span-1 flex items-end">
                <button id="search-btn" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors duration-200 flex items-center justify-center">
                    <i class="fas fa-search mr-2"></i> Search
                </button>
            </div>
        </div>
    </div>


    <?php if (!empty($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg" role="alert"><p><strong>Error:</strong> <?php echo $error; ?></p></div>
    <?php elseif (empty($customers_with_transactions)): ?>
        <div class="text-center py-16" data-aos="fade-up"><i class="fas fa-folder-open text-7xl text-gray-300"></i><h2 class="mt-4 text-2xl font-semibold text-gray-700">No Transactions Found</h2><p class="text-gray-500 mt-1">When transactions are made, they will appear here.</p></div>
    <?php else: ?>
        <div id="transaction-list" class="bg-white rounded-2xl shadow-lg overflow-hidden" data-aos="fade-up">
            <div class="divide-y divide-gray-200">
                <?php foreach ($customers_with_transactions as $customer): ?>
                <div class="accordion-item" data-customer-name="<?php echo strtolower($customer['customer_name']); ?>">
                    <button class="accordion-toggle w-full p-4 sm:p-5 text-left flex justify-between items-center hover:bg-gray-50 transition-colors duration-200 focus:outline-none">
                        <!-- Left Side: Customer Info -->
                        <div class="flex items-center">
                            <div class="icon-wrapper bg-blue-100 text-blue-600 mr-4"><i class="fas fa-user-tie"></i></div>
                            <div>
                                <h2 class="text-lg font-bold text-gray-800"><?php echo $customer['customer_name']; ?></h2>
                                <p class="text-sm text-gray-500"><i class="fas fa-mobile-alt mr-1"></i> <?php echo $customer['customer_mobile']; ?></p>
                            </div>
                        </div>
                        <!-- Right Side: Total Amount & Accordion Icon -->
                        <div class="flex items-center">
                            <!-- NEW: TOTAL AMOUNT DISPLAY -->
                            <div class="text-right mr-4">
                                <p class="text-sm text-gray-500">Total</p>
                                <p class="font-bold text-lg text-green-600"><?php echo format_inr($customer['total_amount']); ?></p>
                            </div>
                            <i class="fas fa-chevron-down text-gray-500 transition-transform transform"></i>
                        </div>
                    </button>
                    <div class="accordion-content bg-gray-50">
                        <div class="py-4">
                            <h3 class="text-md font-semibold text-gray-700 mb-3 px-1">Transactions:</h3>
                            <ul class="space-y-3">
                                <?php foreach ($customer['transactions'] as $tx): ?>
                                <!-- NEW: Added data-date attribute for JS filtering -->
                                <li class="transaction-entry bg-white p-3 rounded-lg shadow-sm flex flex-col sm:flex-row justify-between items-start sm:items-center" data-date="<?php echo $tx['raw_date']; ?>">
                                    <div>
                                        <p class="font-semibold text-gray-700"><?php echo $tx['company_name']; ?></p>
                                        <p class="text-xs text-gray-500">
                                            <i class="far fa-calendar-alt mr-1"></i> <?php echo $tx['transaction_date']; ?>
                                            <span class="mx-2 text-gray-300">|</span>
                                            <span class="font-bold"><?php echo format_inr($tx['grand_total']); ?></span>
                                        </p>
                                    </div>
                                    <div class="mt-3 sm:mt-0">
                                        <button class="view-details-btn bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded-lg transition-transform transform hover:scale-105 text-sm" data-transaction='<?php echo json_encode($tx); ?>'>
                                            <i class="fas fa-eye mr-2"></i>View Details
                                        </button>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- NEW: NO RESULTS MESSAGE -->
        <div id="no-results" class="text-center py-16 hidden" data-aos="fade-up">
            <i class="fas fa-search text-7xl text-gray-300"></i>
            <h2 class="mt-4 text-2xl font-semibold text-gray-700">No Matching Transactions Found</h2>
            <p class="text-gray-500 mt-1">Try adjusting your search filters.</p>
        </div>
    <?php endif; ?>
</div>

<div id="transaction-modal" class="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 z-50 hidden modal-backdrop opacity-0">
    <div id="modal-content" class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto transform scale-95 modal-content"></div>
</div>

<div id="toast-container"></div>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({ once: true, offset: 50, delay: 100 });

    // --- ACCORDION LOGIC ---
    document.querySelectorAll('.accordion-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const content = button.nextElementSibling;
            const icon = button.querySelector('i.fa-chevron-down');
            const isOpen = button.parentElement.classList.contains('is-open');

            // Close all other accordions
            document.querySelectorAll('.accordion-item.is-open').forEach(openItem => {
                if (openItem !== button.parentElement) {
                    openItem.classList.remove('is-open');
                    openItem.querySelector('i.fa-chevron-down').classList.remove('rotate-180');
                    const openContent = openItem.querySelector('.accordion-content');
                    openContent.style.maxHeight = null;
                    openContent.style.padding = "0 1.25rem";
                }
            });

            // Toggle current accordion
            if (!isOpen) {
                button.parentElement.classList.add('is-open');
                icon.classList.add('rotate-180');
                content.style.padding = "0 1.25rem 1.25rem";
                content.style.maxHeight = content.scrollHeight + "px";
            } else {
                button.parentElement.classList.remove('is-open');
                icon.classList.remove('rotate-180');
                content.style.maxHeight = null;
                content.style.padding = "0 1.25rem";
            }
        });
    });

    const modal = document.getElementById('transaction-modal');
    const modalContent = document.getElementById('modal-content');
    const transactionListContainer = document.getElementById('transaction-list');

    function formatINR(amount) {
        return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(amount);
    }

    function showToast(message, type = 'success') {
        const toastContainer = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }, 10);
    }

    // --- MODAL AND DETAILS LOGIC (using event delegation) ---
    // This listener is on the main list container. It will catch clicks on any 'View Details' button inside it.
    if (transactionListContainer) {
        transactionListContainer.addEventListener('click', function(e) {
            // Find the closest '.view-details-btn' that was clicked
            const viewDetailsButton = e.target.closest('.view-details-btn');
            if (!viewDetailsButton) {
                return; // Exit if the click was not on a details button
            }

            const txData = JSON.parse(viewDetailsButton.dataset.transaction);
            
            let cashDetailsHtml = '', onlineDetailsHtml = '', bankDetailsHtml = '';
            const cashDetails = Object.values(txData.details).filter(d => d.type === 'cash');
            const onlineDetails = Object.values(txData.details).filter(d => d.type === 'online');

            if (cashDetails.length > 0) {
                cashDetailsHtml = `<div class="flex items-center mb-2"><div class="icon-wrapper bg-green-100 text-green-600"><i class="fas fa-money-bill-wave"></i></div><h5 class="font-semibold text-gray-600">Cash Payments</h5></div><ul class="text-sm text-gray-700 space-y-1 details-list">${cashDetails.map(d => `<li class="bg-gray-50 p-2 rounded-md"><span class="font-mono">${formatINR(d.platform)} x ${d.qty_utr}</span><span class="float-right font-semibold">${formatINR(d.amount)}</span></li>`).join('')}</ul>`;
            }
            if (onlineDetails.length > 0) {
                onlineDetailsHtml = `<div class="flex items-center mb-2"><div class="icon-wrapper bg-blue-100 text-blue-600"><i class="fas fa-satellite-dish"></i></div><h5 class="font-semibold text-gray-600">Online Payments</h5></div><ul class="text-sm text-gray-700 space-y-1 details-list">${onlineDetails.map(d => `<li class="bg-gray-50 p-2 rounded-md"><div><span class="font-medium">${d.platform}</span><span class="float-right font-semibold">${formatINR(d.amount)}</span></div><div class="text-xs text-gray-500 font-mono">UTR: ${d.qty_utr}</div></li>`).join('')}</ul>`;
            }

            if (txData.bank_name && txData.deposit_amount > 0) {
                const bankTxnIdValue = (txData.bank_transaction_id === 'N/A') ? '' : txData.bank_transaction_id;
                bankDetailsHtml = `<div class="border-t border-gray-200 pt-4"><h4 class="text-md font-semibold text-gray-700 mb-3">Bank Deposit</h4><div class="bg-blue-50 p-3 rounded-lg border border-blue-200"><div class="flex justify-between items-center"><span class="font-semibold text-gray-700"><i class="fas fa-university mr-2 text-blue-500"></i>${txData.bank_name}</span><span class="font-bold text-lg text-blue-800">${formatINR(txData.deposit_amount)}</span></div><div class="text-xs text-gray-500 mt-2 font-mono flex items-center justify-between" data-tx-id="${txData.transaction_id}"><div class="flex items-center"><span>Txn ID: </span><span class="bank-txn-id-text ml-2 font-semibold text-gray-700">${txData.bank_transaction_id}</span><input type="text" class="bank-txn-id-input form-input text-xs p-1 ml-2 hidden w-48 border rounded" value="${bankTxnIdValue}"></div><div><button class="edit-txn-id-btn text-blue-600 hover:text-blue-800"><i class="fas fa-pencil-alt"></i></button><button class="save-txn-id-btn text-green-600 hover:text-green-800 hidden ml-2"><i class="fas fa-check"></i></button></div></div></div></div>`;
            }

            modalContent.innerHTML = `<div class="p-5 sm:p-6 border-b border-gray-200 bg-gray-50 rounded-t-2xl flex justify-between items-center sticky top-0 z-10"><div><h3 class="text-2xl font-bold text-gray-800">${txData.company_name}</h3><p class="text-sm text-gray-500">${txData.transaction_date}</p></div><button id="close-modal-btn" class="text-gray-400 hover:text-gray-600 transition-colors"><i class="fas fa-times fa-2x"></i></button></div><div class="p-5 sm:p-6 space-y-6"><div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-center"><div class="bg-gray-100 p-3 rounded-lg"><p class="text-xs text-gray-500 font-semibold uppercase">Grand Total</p><p class="text-lg font-bold text-gray-800">${formatINR(txData.grand_total)}</p></div><div class="bg-green-100 p-3 rounded-lg"><p class="text-xs text-green-600 font-semibold uppercase">Paid Amount</p><p class="text-lg font-bold text-green-800">${formatINR(txData.actual_paid_amount)}</p></div><div class="bg-purple-100 p-3 rounded-lg"><p class="text-xs text-purple-600 font-semibold uppercase">Commission</p><p class="text-lg font-bold text-purple-800">${formatINR(txData.commission_amount)}</p></div><div class="bg-red-100 p-3 rounded-lg col-span-1 sm:col-span-1"><p class="text-xs text-red-600 font-semibold uppercase">Dues</p><p class="text-lg font-bold text-red-800">${formatINR(txData.dues_amount)}</p></div><div class="bg-indigo-100 p-3 rounded-lg col-span-2 sm:col-span-2"><p class="text-xs text-indigo-600 font-semibold uppercase">Advance</p><p class="text-lg font-bold text-indigo-800">${formatINR(txData.advance_amount)}</p></div></div>${(cashDetailsHtml || onlineDetailsHtml) ? `<div class="border-t border-gray-200 pt-4"><h4 class="text-md font-semibold text-gray-700 mb-3">Payment Breakdown</h4><div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4"><div>${cashDetailsHtml}</div><div>${onlineDetailsHtml}</div></div></div>` : ''}${bankDetailsHtml}</div>`;
            modal.classList.remove('hidden');
            setTimeout(() => { modal.classList.remove('opacity-0'); modalContent.classList.remove('scale-95'); }, 10);
        });
    }


    function closeModal() {
        modal.classList.add('opacity-0');
        modalContent.classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); modalContent.innerHTML = ''; }, 300);
    }

    modal.addEventListener('click', function(e) {
        if (e.target.id === 'transaction-modal' || e.target.closest('#close-modal-btn')) { closeModal(); }
        
        const editBtn = e.target.closest('.edit-txn-id-btn');
        if (editBtn) {
            const container = editBtn.closest('[data-tx-id]');
            container.querySelector('.bank-txn-id-text').classList.add('hidden');
            editBtn.classList.add('hidden');
            container.querySelector('.bank-txn-id-input').classList.remove('hidden');
            container.querySelector('.save-txn-id-btn').classList.remove('hidden');
            container.querySelector('.bank-txn-id-input').focus();
        }

        const saveBtn = e.target.closest('.save-txn-id-btn');
        if (saveBtn) {
            const container = saveBtn.closest('[data-tx-id]');
            const transactionId = container.dataset.txId;
            const inputField = container.querySelector('.bank-txn-id-input');
            const newTxnId = inputField.value;
            
            // Post back to the same file.
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_bank_txn_id&transaction_id=${encodeURIComponent(transactionId)}&bank_transaction_id=${encodeURIComponent(newTxnId)}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    const newIdText = newTxnId || 'N/A';
                    container.querySelector('.bank-txn-id-text').textContent = newIdText;
                    
                    // Also update the original button's data attribute to persist the change without a page reload
                    const mainButton = document.querySelector(`.view-details-btn[data-transaction*='"transaction_id":${transactionId}']`);
                    if (mainButton) {
                        const txData = JSON.parse(mainButton.dataset.transaction);
                        txData.bank_transaction_id = newIdText;
                        mainButton.dataset.transaction = JSON.stringify(txData);
                    }
                    
                    container.querySelector('.bank-txn-id-text').classList.remove('hidden');
                    container.querySelector('.edit-txn-id-btn').classList.remove('hidden');
                    inputField.classList.add('hidden');
                    saveBtn.classList.add('hidden');
                    showToast('Transaction ID updated successfully!');
                } else {
                    showToast(data.error || 'Error updating Transaction ID.', 'error');
                    console.error('Update failed:', data.error);
                }
            })
            .catch(error => {
                showToast('A network or server error occurred.', 'error');
                console.error('Fetch error:', error);
            });
        }
    });

    document.addEventListener('keydown', (e) => { if (e.key === "Escape" && !modal.classList.contains('hidden')) closeModal(); });

    // --- UPDATED: FILTERING LOGIC ---
    const nameFilter = document.getElementById('filter-name');
    const dateFilter = document.getElementById('filter-date');
    const searchBtn = document.getElementById('search-btn');
    const noResultsMessage = document.getElementById('no-results');
    const accordionItems = document.querySelectorAll('.accordion-item');

    function applyFilters() {
        const nameQuery = nameFilter.value.toLowerCase().trim();
        const dateQuery = dateFilter.value;
        let visibleCustomers = 0;

        accordionItems.forEach(customerDiv => {
            const customerName = customerDiv.dataset.customerName;
            const nameMatch = customerName.includes(nameQuery);
            let visibleTransactions = 0;

            const transactions = customerDiv.querySelectorAll('.transaction-entry');
            transactions.forEach(txLi => {
                const txDate = txLi.dataset.date;
                // A transaction is visible if date filter is empty OR the date matches
                if (!dateQuery || txDate === dateQuery) {
                    txLi.style.display = 'flex';
                    visibleTransactions++;
                } else {
                    txLi.style.display = 'none';
                }
            });

            // A customer is visible if their name matches AND they have at least one visible transaction
            if (nameMatch && visibleTransactions > 0) {
                customerDiv.style.display = 'block';
                visibleCustomers++;
            } else {
                customerDiv.style.display = 'none';
            }
        });

        // Show or hide the "No results" message
        if (visibleCustomers === 0) {
            transactionListContainer.style.display = 'none';
            noResultsMessage.style.display = 'block';
        } else {
            transactionListContainer.style.display = 'block';
            noResultsMessage.style.display = 'none';
        }
    }

    // Live search for name
    nameFilter.addEventListener('input', applyFilters);
    // Search on button click for date
    searchBtn.addEventListener('click', applyFilters);
    // Also filter if the user clears the date input
    dateFilter.addEventListener('input', () => {
        if (dateFilter.value === '') {
            applyFilters();
        }
    });


</script>

</body>
</html>
