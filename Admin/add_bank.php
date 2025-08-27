<?php
session_start();
// --- DATABASE CONNECTION ---
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else if (file_exists('db.php')) {
    include 'db.php';
} else {
    die("Database configuration file not found.");
}

$message = '';
$error = '';

// --- HANDLE AJAX REQUESTS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_history' && isset($_GET['bank_id'])) {
        $bank_id = intval($_GET['bank_id']);
        $history = [];
        
        // Fetch deposits and transfer records
        $stmt = $conn->prepare("
            (SELECT amount, description, transaction_date, 'deposit' as type, null as from_bank, null as to_bank FROM bank_amount_transactions WHERE bank_id = ?)
            UNION ALL
            (SELECT amount, description, transfer_date as transaction_date, 'transfer_out' as type, (SELECT b.bank_name FROM banks b WHERE b.id = bt.from_bank_id) as from_bank, (SELECT b.bank_name FROM banks b WHERE b.id = bt.to_bank_id) as to_bank FROM bank_transfers bt WHERE bt.from_bank_id = ?)
            UNION ALL
            (SELECT amount, description, transfer_date as transaction_date, 'transfer_in' as type, (SELECT b.bank_name FROM banks b WHERE b.id = bt.from_bank_id) as from_bank, (SELECT b.bank_name FROM banks b WHERE b.id = bt.to_bank_id) as to_bank FROM bank_transfers bt WHERE bt.to_bank_id = ?)
            ORDER BY transaction_date DESC
        ");
        $stmt->bind_param("iii", $bank_id, $bank_id, $bank_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();
        
        echo json_encode(['success' => true, 'history' => $history]);
        $conn->close();
        exit;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- Handle Add New Bank ---
    if (isset($_POST['add_bank'])) {
        $bank_name = trim($_POST['bank_name']);
        $account_number = trim($_POST['account_number']);
        $initial_balance = !empty($_POST['initial_balance']) ? (float)$_POST['initial_balance'] : 0;

        if (!empty($bank_name) && !empty($account_number)) {
            $conn->begin_transaction();
            try {
                $stmt_insert = $conn->prepare("INSERT INTO banks (bank_name, account_number, account_balance) VALUES (?, ?, ?)");
                $stmt_insert->bind_param("ssd", $bank_name, $account_number, $initial_balance);
                $stmt_insert->execute();
                $new_bank_id = $stmt_insert->insert_id;
                $stmt_insert->close();

                if ($initial_balance > 0) {
                    $desc = "Initial account balance.";
                    $stmt_trans = $conn->prepare("INSERT INTO bank_amount_transactions (bank_id, amount, description) VALUES (?, ?, ?)");
                    $stmt_trans->bind_param("ids", $new_bank_id, $initial_balance, $desc);
                    $stmt_trans->execute();
                    $stmt_trans->close();
                }

                $conn->commit();
                $message = "Bank added successfully!";
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                $error = "Error: Could not add the bank. Account number might already exist.";
            }
        } else {
            $error = "Error: Bank name and account number are required.";
        }
    }

    // --- Handle Deposit Amount ---
    if (isset($_POST['deposit_amount'])) {
        $bank_id = intval($_POST['bank_id']);
        $amount_to_add = (float)$_POST['amount'];
        $description = trim($_POST['description']);
        $transaction_date = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d H:i:s');

        if ($bank_id > 0 && $amount_to_add > 0) {
            $conn->begin_transaction();
            try {
                $stmt_trans = $conn->prepare("INSERT INTO bank_amount_transactions (bank_id, amount, description, transaction_date) VALUES (?, ?, ?, ?)");
                $stmt_trans->bind_param("idss", $bank_id, $amount_to_add, $description, $transaction_date);
                $stmt_trans->execute();
                $stmt_trans->close();

                $stmt_update = $conn->prepare("UPDATE banks SET account_balance = account_balance + ? WHERE id = ?");
                $stmt_update->bind_param("di", $amount_to_add, $bank_id);
                $stmt_update->execute();
                $stmt_update->close();

                $conn->commit();
                $message = "Successfully deposited ₹ " . number_format($amount_to_add, 2) . "!";
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                $error = "Error: Could not process the deposit.";
            }
        } else {
            $error = "Error: Please select a bank and enter a valid amount.";
        }
    }
    
    // --- Handle Bank to Bank Transfer ---
    if (isset($_POST['transfer_funds'])) {
        $from_bank_id = intval($_POST['from_bank_id']);
        $to_bank_id = intval($_POST['to_bank_id']);
        $amount = (float)$_POST['transfer_amount'];
        $description = trim($_POST['transfer_description']);

        if ($from_bank_id > 0 && $to_bank_id > 0 && $amount > 0 && $from_bank_id !== $to_bank_id) {
            $conn->begin_transaction();
            try {
                // Check if sender has enough balance
                $stmt_check = $conn->prepare("SELECT account_balance FROM banks WHERE id = ?");
                $stmt_check->bind_param("i", $from_bank_id);
                $stmt_check->execute();
                $result = $stmt_check->get_result();
                $sender_bank = $result->fetch_assoc();
                $stmt_check->close();

                if ($sender_bank && $sender_bank['account_balance'] >= $amount) {
                    // Debit from sender
                    $stmt_debit = $conn->prepare("UPDATE banks SET account_balance = account_balance - ? WHERE id = ?");
                    $stmt_debit->bind_param("di", $amount, $from_bank_id);
                    $stmt_debit->execute();
                    $stmt_debit->close();

                    // Credit to receiver
                    $stmt_credit = $conn->prepare("UPDATE banks SET account_balance = account_balance + ? WHERE id = ?");
                    $stmt_credit->bind_param("di", $amount, $to_bank_id);
                    $stmt_credit->execute();
                    $stmt_credit->close();
                    
                    // Log the transfer
                    $stmt_transfer = $conn->prepare("INSERT INTO bank_transfers (from_bank_id, to_bank_id, amount, description) VALUES (?, ?, ?, ?)");
                    $stmt_transfer->bind_param("iids", $from_bank_id, $to_bank_id, $amount, $description);
                    $stmt_transfer->execute();
                    $stmt_transfer->close();

                    $conn->commit();
                    $message = "Successfully transferred ₹ " . number_format($amount, 2) . "!";
                } else {
                    $conn->rollback();
                    $error = "Error: Insufficient funds in the source account.";
                }
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                $error = "Error: Could not process the transfer.";
            }
        } else {
            $error = "Error: Please select valid source and destination banks and a valid amount.";
        }
    }

    // --- Handle Edit Bank ---
    if (isset($_POST['edit_bank'])) {
        $bank_id = intval($_POST['edit_bank_id']);
        $bank_name = trim($_POST['edit_bank_name']);
        $account_number = trim($_POST['edit_account_number']);

        if ($bank_id > 0 && !empty($bank_name) && !empty($account_number)) {
            $stmt = $conn->prepare("UPDATE banks SET bank_name = ?, account_number = ? WHERE id = ?");
            $stmt->bind_param("ssi", $bank_name, $account_number, $bank_id);
            if ($stmt->execute()) {
                $message = "Bank details updated successfully.";
            } else {
                $error = "Error updating bank details. Account number may already exist.";
            }
            $stmt->close();
        } else {
            $error = "Invalid data provided for updating bank.";
        }
    }
    
    // --- Handle Delete Bank ---
    if (isset($_POST['delete_bank'])) {
        $bank_id = intval($_POST['delete_bank_id']);
        if ($bank_id > 0) {
            $conn->begin_transaction();
            try {
                // Delete associated transactions and transfers first
                $stmt_del_trans = $conn->prepare("DELETE FROM bank_amount_transactions WHERE bank_id = ?");
                $stmt_del_trans->bind_param("i", $bank_id);
                $stmt_del_trans->execute();
                $stmt_del_trans->close();

                $stmt_del_transfers = $conn->prepare("DELETE FROM bank_transfers WHERE from_bank_id = ? OR to_bank_id = ?");
                $stmt_del_transfers->bind_param("ii", $bank_id, $bank_id);
                $stmt_del_transfers->execute();
                $stmt_del_transfers->close();

                // Delete the bank
                $stmt_del_bank = $conn->prepare("DELETE FROM banks WHERE id = ?");
                $stmt_del_bank->bind_param("i", $bank_id);
                $stmt_del_bank->execute();
                $stmt_del_bank->close();

                $conn->commit();
                $message = "Bank account and all its records have been deleted.";
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                $error = "Error deleting bank account.";
            }
        }
    }
}


// --- Fetch all existing banks ---
$banks = [];
$result = $conn->query("SELECT id, bank_name, account_number, account_balance, created_at FROM banks ORDER BY bank_name ASC");
if ($result) {
    while($row = $result->fetch_assoc()) {
        $banks[] = $row;
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Bank Ledger System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f0f2f5; }
        .form-input-icon { position: relative; }
        .form-input-icon i { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .form-input-icon input, .form-input-icon select { padding-left: 2.75rem; }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.6); display: flex; align-items: center; justify-content: center; z-index: 50; opacity: 0; visibility: hidden; transition: opacity 0.3s ease; backdrop-filter: blur(5px); }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal-content { background: white; padding: 1.5rem; border-radius: 1rem; width: 90%; max-width: 600px; transform: scale(0.95); transition: transform 0.3s ease; max-height: 80vh; display: flex; flex-direction: column; }
        .modal-overlay.active .modal-content { transform: scale(1); }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto">
    <header class="mb-10 text-center" data-aos="fade-down">
        <h1 class="text-4xl font-bold text-gray-800">Bank Ledger System</h1>
        <p class="text-gray-600 mt-2">Manage accounts, deposits, and transfers seamlessly.</p>
    </header>

    <?php if (!empty($message)): ?>
        <div data-aos="fade-left" class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-lg shadow-md" role="alert"><p><i class="fas fa-check-circle mr-2"></i><?php echo $message; ?></p></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div data-aos="fade-left" class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg shadow-md" role="alert"><p><i class="fas fa-times-circle mr-2"></i><?php echo $error; ?></p></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-1 space-y-8">
            <!-- Bank to Bank Transfer -->
            <div class="bg-white rounded-2xl shadow-lg p-6" data-aos="fade-right">
                <h2 class="text-xl font-semibold text-gray-700 mb-5 border-b pb-3"><i class="fas fa-exchange-alt mr-2 text-purple-500"></i>Bank to Bank Transfer</h2>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
                    <div class="space-y-5">
                        <div>
                            <label for="from_bank_id" class="block text-sm font-medium text-gray-700 mb-1">From Account</label>
                            <div class="form-input-icon">
                                <i class="fas fa-arrow-circle-up"></i>
                                <select id="from_bank_id" name="from_bank_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-200" required>
                                    <option value="">-- Select Source --</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name']); ?> (..<?php echo substr($bank['account_number'], -4); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="to_bank_id" class="block text-sm font-medium text-gray-700 mb-1">To Account</label>
                            <div class="form-input-icon">
                                <i class="fas fa-arrow-circle-down"></i>
                                <select id="to_bank_id" name="to_bank_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-200" required>
                                    <option value="">-- Select Destination --</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name']); ?> (..<?php echo substr($bank['account_number'], -4); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="transfer_amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                            <div class="form-input-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" step="0.01" id="transfer_amount" name="transfer_amount" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-200" placeholder="e.g., 2500" required>
                            </div>
                        </div>
                        <div>
                            <label for="transfer_description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                            <textarea id="transfer_description" name="transfer_description" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-200" placeholder="e.g., Monthly rent"></textarea>
                        </div>
                        <div>
                            <button type="submit" name="transfer_funds" class="w-full text-white font-bold py-3 px-4 rounded-lg bg-purple-500 hover:bg-purple-600 flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-all">
                                <i class="fas fa-paper-plane"></i> Transfer Funds
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Deposit Funds -->
            <div class="bg-white rounded-2xl shadow-lg p-6" data-aos="fade-right" data-aos-delay="100">
                <h2 class="text-xl font-semibold text-gray-700 mb-5 border-b pb-3"><i class="fas fa-money-bill-wave mr-2 text-green-500"></i>Deposit Funds</h2>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
                    <div class="space-y-5">
                        <div>
                            <label for="bank_id" class="block text-sm font-medium text-gray-700 mb-1">Select Bank</label>
                            <div class="form-input-icon">
                                <i class="fas fa-university"></i>
                                <select id="bank_id" name="bank_id" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-200" required>
                                    <option value="">-- Choose a Bank --</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name']); ?> (..<?php echo substr($bank['account_number'], -4); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                            <div class="form-input-icon">
                                <i class="fas fa-rupee-sign"></i>
                                <input type="number" step="0.01" id="amount" name="amount" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-200" placeholder="e.g., 5000" required>
                            </div>
                        </div>
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                            <textarea id="description" name="description" rows="2" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-200" placeholder="e.g., Cash deposit from sales"></textarea>
                        </div>
                        <div>
                            <button type="submit" name="deposit_amount" class="w-full text-white font-bold py-3 px-4 rounded-lg bg-green-500 hover:bg-green-600 flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-all">
                                <i class="fas fa-plus-circle"></i> Add Deposit
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Add New Bank -->
            <div class="bg-white rounded-2xl shadow-lg p-6" data-aos="fade-right" data-aos-delay="200">
                <h2 class="text-xl font-semibold text-gray-700 mb-5 border-b pb-3"><i class="fas fa-university mr-2 text-blue-500"></i>Add New Bank</h2>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
                    <div class="space-y-5">
                        <div>
                            <label for="bank_name" class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                            <input type="text" id="bank_name" name="bank_name" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-200" placeholder="e.g., HDFC Bank" required>
                        </div>
                         <div>
                            <label for="account_number" class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                            <input type="text" id="account_number" name="account_number" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-200" placeholder="Enter account number" required>
                        </div>
                        <div>
                            <label for="initial_balance" class="block text-sm font-medium text-gray-700 mb-1">Initial Balance (Optional)</label>
                            <input type="number" step="0.01" id="initial_balance" name="initial_balance" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-200" placeholder="e.g., 10000">
                        </div>
                        <div>
                            <button type="submit" name="add_bank" class="w-full text-white font-bold py-3 px-4 rounded-lg bg-blue-500 hover:bg-blue-600 flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-all">
                                <i class="fas fa-save"></i> Create Bank Account
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2" data-aos="fade-up" data-aos-delay="300">
            <div class="bg-white rounded-2xl shadow-lg p-6">
                <h2 class="text-xl font-semibold text-gray-700 mb-5">Available Bank Accounts</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-600 uppercase">Bank Details</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-600 uppercase">Balance</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-bold text-gray-600 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($banks)): ?>
                                <tr><td colspan="3" class="px-6 py-10 text-center text-gray-500">No banks added yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($banks as $bank): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($bank['bank_name']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($bank['account_number']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right">
                                            <div class="text-lg font-semibold text-green-600">₹ <?php echo number_format($bank['account_balance'], 2); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center space-x-4">
                                            <button onclick="viewHistory(<?php echo $bank['id']; ?>, '<?php echo htmlspecialchars(addslashes($bank['bank_name'])); ?>')" class="text-indigo-600 hover:text-indigo-900 transition-colors" title="View History">
                                                <i class="fas fa-history"></i>
                                            </button>
                                            <button onclick="openEditModal(<?php echo $bank['id']; ?>, '<?php echo htmlspecialchars(addslashes($bank['bank_name'])); ?>', '<?php echo htmlspecialchars(addslashes($bank['account_number'])); ?>')" class="text-blue-600 hover:text-blue-900 transition-colors" title="Edit Bank">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this bank and all its records? This action cannot be undone.');">
                                                <input type="hidden" name="delete_bank_id" value="<?php echo $bank['id']; ?>">
                                                <button type="submit" name="delete_bank" class="text-red-600 hover:text-red-900 transition-colors" title="Delete Bank">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- History Modal -->
<div id="historyModal" class="modal-overlay">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Transaction History</h2>
                <p id="historyBankName" class="text-sm text-gray-500"></p>
            </div>
            <button onclick="closeModal('historyModal')" class="text-gray-400 hover:text-gray-800 text-2xl">&times;</button>
        </div>
        <div id="historyContent" class="overflow-y-auto flex-grow">
            <p class="text-center py-8 text-gray-500">Loading history...</p>
        </div>
    </div>
</div>

<!-- Edit Bank Modal -->
<div id="editBankModal" class="modal-overlay">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-4 border-b pb-3">
            <h2 class="text-xl font-semibold text-gray-800"><i class="fas fa-pencil-alt mr-2"></i>Edit Bank Details</h2>
            <button onclick="closeModal('editBankModal')" class="text-gray-400 hover:text-gray-800 text-2xl">&times;</button>
        </div>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
            <input type="hidden" id="edit_bank_id" name="edit_bank_id">
            <div class="space-y-5 mt-4">
                <div>
                    <label for="edit_bank_name" class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                    <input type="text" id="edit_bank_name" name="edit_bank_name" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-200" required>
                </div>
                <div>
                    <label for="edit_account_number" class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                    <input type="text" id="edit_account_number" name="edit_account_number" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-200" required>
                </div>
                <div>
                    <button type="submit" name="edit_bank" class="w-full text-white font-bold py-3 px-4 rounded-lg bg-blue-500 hover:bg-blue-600 flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-all">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>


<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({ duration: 600, once: true });

    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }
    
    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(event) {
            if (event.target === this) {
                closeModal(this.id);
            }
        });
    });

    function openEditModal(id, name, number) {
        document.getElementById('edit_bank_id').value = id;
        document.getElementById('edit_bank_name').value = name;
        document.getElementById('edit_account_number').value = number;
        openModal('editBankModal');
    }

    function viewHistory(bankId, bankName) {
        document.getElementById('historyBankName').textContent = bankName;
        const historyContent = document.getElementById('historyContent');
        historyContent.innerHTML = '<p class="text-center py-8 text-gray-500">Loading history...</p>';
        openModal('historyModal');

        fetch(`?action=get_history&bank_id=${bankId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.history.length > 0) {
                    let html = '<table class="min-w-full divide-y divide-gray-200">';
                    html += '<thead class="bg-gray-50"><tr>';
                    html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>';
                    html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Details</th>';
                    html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>';
                    html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';

                    data.history.forEach(tx => {
                        let amountClass = 'text-gray-800';
                        let amountPrefix = '';
                        let details = tx.description || '';

                        if (tx.type === 'deposit' || tx.type === 'transfer_in') {
                            amountClass = 'text-green-600';
                            amountPrefix = '+ ';
                            if(tx.type === 'transfer_in') {
                                details = `From: ${tx.from_bank} - ${details}`;
                            }
                        } else if (tx.type === 'transfer_out') {
                            amountClass = 'text-red-600';
                            amountPrefix = '- ';
                             details = `To: ${tx.to_bank} - ${details}`;
                        }
                        
                        const date = new Date(tx.transaction_date).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit'});

                        html += `<tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">${date}</td>
                                    <td class="px-4 py-3 text-sm text-gray-800">${details}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-right ${amountClass}">${amountPrefix}₹ ${parseFloat(tx.amount).toLocaleString('en-IN')}</td>
                                 </tr>`;
                    });

                    html += '</tbody></table>';
                    historyContent.innerHTML = html;
                } else {
                    historyContent.innerHTML = '<p class="text-center py-8 text-gray-500">No transaction history found.</p>';
                }
            })
            .catch(error => {
                console.error('Error fetching history:', error);
                historyContent.innerHTML = '<p class="text-center py-8 text-red-500">Failed to load history.</p>';
            });
    }
</script>

</body>
</html>
