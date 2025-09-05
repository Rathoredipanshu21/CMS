<?php
session_start();

if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    $error = "Database configuration file not found. The form will not save data or load dynamic content.";
    $conn = null;
}

$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id']) && $conn) {
    $conn->begin_transaction();
    try {
        $customer_id = (int)$_POST['customer_id'];
        $company_name = $_POST['company_name'];
        // New field from the form
        $transaction_type_form = $_POST['transaction_type']; 
        
        $payment_modes = [];
        if (isset($_POST['is_cash_payment']) && (float)str_replace(',', '', $_POST['total_cash_amount']) > 0) $payment_modes[] = 'Cash';
        if (isset($_POST['is_online_payment']) && (float)str_replace(',', '', $_POST['total_online_amount']) > 0) $payment_modes[] = 'Online';
        $payment_mode_str = implode(' + ', $payment_modes);
        
        $grand_total = (float) str_replace(',', '', $_POST['grand_total']);
        $actual_paid_amount = (float) str_replace(',', '', $_POST['actual_paid_amount']);
        
        // --- Commission Calculation (Already correctly implemented) ---
        $commission_percentage = (float)$_POST['commission_percentage_hidden'];
        $commission_amount = $actual_paid_amount * ($commission_percentage / 100);

        $transaction_dues = (float) str_replace(',', '', $_POST['dues_amount']);
        $transaction_advance = (float) str_replace(',', '', $_POST['advance_amount']);

        $deposit_bank_id = !empty($_POST['deposit_bank_id']) ? (int)$_POST['deposit_bank_id'] : null;
        $bank_transaction_id = !empty($_POST['bank_transaction_id']) ? trim($_POST['bank_transaction_id']) : null;

        // --- INSERT INTO `transactions` TABLE ---
        // Added transaction_type column
        $stmt1 = $conn->prepare(
            "INSERT INTO transactions (customer_id, company_name, transaction_type, payment_mode, grand_total, actual_paid_amount, commission_amount, dues_amount, advance_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        // Updated bind_param to include the new transaction_type string
        $stmt1->bind_param(
            "isssddddd", 
            $customer_id, 
            $company_name,
            $transaction_type_form, 
            $payment_mode_str, 
            $grand_total,
            $actual_paid_amount,
            $commission_amount,
            $transaction_dues,      
            $transaction_advance    
        );
        $stmt1->execute();
        $transaction_id = $conn->insert_id;
        $stmt1->close();

        $stmt2 = $conn->prepare("INSERT INTO transaction_details (transaction_id, detail_type, denomination_or_platform, quantity_or_utr, amount) VALUES (?, ?, ?, ?, ?)");
        $total_cash_amount = (float) str_replace(',', '', $_POST['total_cash_amount']);
        $total_online_amount = (float) str_replace(',', '', $_POST['total_online_amount']);

        if ($total_cash_amount > 0 && isset($_POST['cash_qty'])) {
            foreach ($_POST['cash_qty'] as $denomination => $qty) {
                if (!empty($qty) && (int)$qty > 0) {
                    $detail_type = 'cash';
                    $amount = (float) $denomination * (int) $qty;
                    $stmt2->bind_param("isssd", $transaction_id, $detail_type, $denomination, $qty, $amount);
                    $stmt2->execute();
                }
            }
        }

        if ($total_online_amount > 0 && isset($_POST['online_platform'])) {
            for ($i = 0; $i < count($_POST['online_platform']); $i++) {
                $platform = $_POST['online_platform'][$i];
                $amount = (float) $_POST['online_amount'][$i];
                $utr = $_POST['online_utr'][$i];
                if (!empty($platform) && $amount > 0 && !empty($utr)) {
                    $detail_type = 'online';
                    $stmt2->bind_param("isssd", $transaction_id, $detail_type, $platform, $utr, $amount);
                    $stmt2->execute();
                }
            }
        }
        $stmt2->close();

        // --- PROCESS BANK TRANSACTION (DEPOSIT, BALANCE UPDATE, HISTORY) ---
        if ($actual_paid_amount > 0 && $deposit_bank_id !== null) {
            $amount_for_deposit = $actual_paid_amount;
            
            $stmt_get_balance = $conn->prepare("SELECT account_balance FROM banks WHERE id = ? FOR UPDATE");
            $stmt_get_balance->bind_param("i", $deposit_bank_id);
            $stmt_get_balance->execute();
            $result_balance = $stmt_get_balance->get_result();
            if($result_balance->num_rows === 0) throw new Exception("Selected bank not found.");
            $bank_data = $result_balance->fetch_assoc();
            $balance_before = (float)$bank_data['account_balance'];
            $stmt_get_balance->close();

            if ($balance_before < $amount_for_deposit) {
                throw new Exception("Insufficient funds in the selected bank account. Transaction rolled back.");
            }

            $stmt_bank = $conn->prepare("INSERT INTO bank_deposits (transaction_id, bank_id, amount, bank_transaction_id) VALUES (?, ?, ?, ?)");
            $stmt_bank->bind_param("iids", $transaction_id, $deposit_bank_id, $amount_for_deposit, $bank_transaction_id);
            $stmt_bank->execute();
            $stmt_bank->close();
            
            $balance_after = $balance_before - $amount_for_deposit;

            $stmt_update_balance = $conn->prepare("UPDATE banks SET account_balance = ? WHERE id = ?");
            $stmt_update_balance->bind_param("di", $balance_after, $deposit_bank_id);
            $stmt_update_balance->execute();
            $stmt_update_balance->close();

            $stmt_history = $conn->prepare("INSERT INTO banks_transactions_history (bank_id, transaction_id, transaction_type, amount, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
            $transaction_type_history = 'payment'; 
            $stmt_history->bind_param("iisddd", $deposit_bank_id, $transaction_id, $transaction_type_history, $amount_for_deposit, $balance_before, $balance_after);
            $stmt_history->execute();
            $stmt_history->close();
        }

        // --- UPDATE `customer_finances` TABLE for DUES/ADVANCES ---
        $stmt_get_finance = $conn->prepare("SELECT dues_amount, advance_amount FROM customer_finances WHERE customer_id = ? FOR UPDATE");
        $stmt_get_finance->bind_param("i", $customer_id);
        $stmt_get_finance->execute();
        $result_finance = $stmt_get_finance->get_result();
        $finance_data = $result_finance->fetch_assoc() ?: ['dues_amount' => 0.00, 'advance_amount' => 0.00];
        $current_dues = (float)$finance_data['dues_amount'];
        $current_advance = (float)$finance_data['advance_amount'];
        $stmt_get_finance->close();
        
        $applied_dues = isset($_POST['applied_dues_amount']) ? (float)$_POST['applied_dues_amount'] : 0.00;
        $applied_advance = isset($_POST['applied_advance_amount']) ? (float)$_POST['applied_advance_amount'] : 0.00;

        // Start with the existing balance
        $net_balance = $current_advance - $current_dues;
        
        // Adjust for the amounts applied in this transaction
        $net_balance += $applied_dues; // Applying dues reduces the negative balance (moves towards zero)
        $net_balance -= $applied_advance; // Applying advance reduces the positive balance
        
        // Add the net change from this specific transaction (new advance or new dues)
        $net_balance += ($transaction_advance - $transaction_dues);
        
        $final_dues = ($net_balance < 0) ? abs($net_balance) : 0.00;
        $final_advance = ($net_balance > 0) ? $net_balance : 0.00;

        $stmt_finance = $conn->prepare(
            "INSERT INTO customer_finances (customer_id, dues_amount, advance_amount) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE dues_amount = VALUES(dues_amount), advance_amount = VALUES(advance_amount)"
        );
        $stmt_finance->bind_param("idd", $customer_id, $final_dues, $final_advance);
        $stmt_finance->execute();
        $stmt_finance->close();
      
        $conn->commit();
        $message = "Transaction saved successfully!";

    } catch (Exception $exception) {
        $conn->rollback();
        $error = "Error saving transaction: " . $exception->getMessage();
    }
}

$customers = [];
$companies_data = [];
$banks = []; 
if ($conn) {
    $customer_result = $conn->query("SELECT id, name, mobile_no FROM customers ORDER BY name ASC");
    if ($customer_result) while($row = $customer_result->fetch_assoc()) $customers[] = $row; 
    
    $company_result = $conn->query("SELECT `id`, `company_name`, `commission_percentage` FROM `company_commissions` ORDER BY `company_name` ASC");
    if($company_result) while($row = $company_result->fetch_assoc()) $companies_data[] = $row;
    
    $banks_result = $conn->query("SELECT id, bank_name, account_balance FROM banks ORDER BY bank_name ASC");
    if($banks_result) while($row = $banks_result->fetch_assoc()) $banks[] = $row;
}

$cash_denominations = [
    500 => '../images/500.png', 200 => '../images/200.png', 100 => '../images/100.png', 50 => '../images/50.png', 20 => '../images/20.png',
    10 => '../images/10.png', 5 => '../images/5.png', 2 => '../images/2.png', 1 => '../images/1.png'
];
$online_platforms = ['Google Pay', 'PhonePe', 'Paytm', 'Amazon Pay', 'BHIM UPI', 'Airtel Bank', 'HDFC Bank', 'SBI Bank', 'Bank Of India', 'Axis Bank', 'Other'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Transaction Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-input, .form-select { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; transition: all 0.2s ease-in-out; background-color: #fff; }
        .form-input:focus, .form-select:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2); outline: none; }
        .btn { padding: 0.7rem 1.75rem; border-radius: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s ease; border: none; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .payment-type-card { border: 2px solid #e5e7eb; border-radius: 0.75rem; padding: 1.5rem; text-align: center; cursor: pointer; transition: all 0.2s ease-in-out; background-color: #fff; }
        .payment-type-card.selected { border-color: #4f46e5; background-color: #eef2ff; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.3); transform: translateY(-2px); }
        .payment-section.disabled { opacity: 0.5; pointer-events: none; }
        .select2-container .select2-selection--single { height: 46px !important; border: 1px solid #d1d5db; border-radius: 0.5rem; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 44px; padding-left: 0.85rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; }
        #bank-transaction-section.disabled { opacity: 0.5; pointer-events: none; }
        .modal.flex { display: flex; }
        .cash-denomination-box::-webkit-scrollbar { width: 8px; }
        .cash-denomination-box::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .cash-denomination-box::-webkit-scrollbar-thumb { background: #a7a7a7; border-radius: 10px; }
        .cash-denomination-box::-webkit-scrollbar-thumb:hover { background: #888; }
        .note-img { width: 50px; height: 25px; object-fit: cover; border-radius: 4px; margin-right: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .settlement-label-info { font-size: 0.75rem; line-height: 1rem; color: #4b5563; font-weight: 500; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-screen-2xl mx-auto bg-white rounded-2xl shadow-2xl p-6 sm:p-8">
    <form id="denominationForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
        
        <div class="text-center mb-8 border-b-2 border-gray-200 pb-6">
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-800 tracking-tight"><i class="fas fa-cash-register text-indigo-600"></i> Transaction Panel</h1>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-lg shadow-md" role="alert"><p class="font-bold"><i class="fas fa-check-circle mr-2"></i>Success</p><p><?php echo $message; ?></p></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg shadow-md" role="alert"><p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Error</p><p><?php echo $error; ?></p></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div>
                <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-user mr-2 text-gray-400"></i>Customer Name</label>
                <div class="flex items-center space-x-2">
                    <select id="customer_id" name="customer_id" class="form-select w-full" required>
                        <option value="">Select a Customer</option>
                        <?php foreach($customers as $customer): ?>
                            <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['mobile_no'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="add-customer-btn" class="btn bg-green-500 hover:bg-green-600 text-white !py-0 !px-4 h-[46px] flex-shrink-0"><i class="fas fa-plus"></i></button>
                </div>
            </div>
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-building mr-2 text-gray-400"></i>Select Company</label>
                <div class="flex items-center space-x-2">
                    <select id="company_name" name="company_name" class="form-select w-full" required>
                        <option value="" data-commission="0">Select Company</option>
                        <?php foreach($companies_data as $company): ?>
                            <option value="<?php echo htmlspecialchars($company['company_name']); ?>" data-commission="<?php echo $company['commission_percentage']; ?>"><?php echo htmlspecialchars($company['company_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                     <button type="button" id="add-company-btn" class="btn bg-green-500 hover:bg-green-600 text-white !py-0 !px-4 h-[46px] flex-shrink-0"><i class="fas fa-plus"></i></button>
                </div>
            </div>
            <div>
                <label for="transaction_type" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-exchange-alt mr-2 text-gray-400"></i>Transaction Type</label>
                <select id="transaction_type" name="transaction_type" class="form-select w-full" required>
                    <option value="">Select Type</option>
                    <option value="Cash Received">Cash Received</option>
                    <option value="Cash Payment">Cash Payment</option>
                    <option value="Cash Deposit In Bank">Online Received</option>
                    <option value="Online Payment">Online Payment</option>
                </select>
            </div>
        </div>
        
        <div class="mb-8">
             <label class="block text-lg font-semibold text-gray-800 mb-3">Choose Payment Modes</label>
            <div id="payment-type-selector" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="payment-type-card" data-type="cash" tabindex="0">
                    <i class="fas fa-money-bill-wave text-4xl text-green-500 mb-3"></i><h3 class="text-xl font-bold">Cash Payment</h3><input type="checkbox" name="is_cash_payment" class="hidden">
                </div>
                <div class="payment-type-card" data-type="online" tabindex="0">
                    <i class="fas fa-mobile-alt text-4xl text-blue-500 mb-3"></i><h3 class="text-xl font-bold">Online Payment</h3><input type="checkbox" name="is_online_payment" class="hidden">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
            
            <div class="xl:col-span-1 space-y-6">
                <div id="cash-section" class="payment-section disabled bg-gray-50 p-5 rounded-xl border-2 border-dashed">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Cash Denomination</h2>
                    <div class="space-y-3 max-h-[350px] overflow-y-auto pr-2 cash-denomination-box">
                        <?php foreach($cash_denominations as $value => $image_path): ?>
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-5 flex items-center"><img src="<?php echo $image_path; ?>" alt="<?php echo $value; ?> Rupee Note" class="note-img"><span class="font-semibold text-gray-600">₹ <?php echo $value; ?></span></div>
                            <div class="col-span-3"><input type="number" name="cash_qty[<?php echo $value; ?>]" class="form-input w-full text-center cash-qty" data-value="<?php echo $value; ?>" placeholder="Qty" min="0" disabled></div>
                            <div class="col-span-4"><input type="text" class="form-input w-full text-right bg-gray-200 cash-row-total" readonly value="0.00"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4 pt-4 border-t-2 border-dashed">
                        <div class="flex justify-between items-center bg-white p-3 rounded-lg">
                            <label class="text-md font-semibold text-gray-700">Total Notes</label>
                            <input type="text" id="total_notes_display" class="text-lg font-bold text-gray-800 text-right bg-transparent border-none p-0 focus:ring-0 w-24" value="0" readonly>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 p-5 rounded-xl border-2 border-gray-200 space-y-4 flex flex-col justify-center">
                     <h3 class="text-xl font-bold text-gray-800 text-center">Final Balance</h3>
                     
                     <div class="flex justify-between items-center bg-red-100 p-3 rounded-lg">
                        <label class="text-md font-semibold text-red-800">Advance (+)</label>
                        <input type="text" id="dues_amount_display" class="text-lg font-bold text-red-800 text-right bg-transparent border-none p-0 focus:ring-0 w-32" value="0.00" readonly>
                    </div>

                    <div class="flex justify-between items-center bg-green-100 p-3 rounded-lg">
                        <label class="text-md font-semibold text-green-800">Due (-)</label>
                        <input type="text" id="advance_amount_display" class="text-lg font-bold text-green-800 text-right bg-transparent border-none p-0 focus:ring-0 w-32" value="0.00" readonly>
                    </div>
                </div>
            </div>

            <div class="xl:col-span-2 space-y-6">
                <div id="online-section" class="payment-section disabled bg-gray-50 p-5 rounded-xl border-2 border-dashed">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Online Payment Details</h2>
                    <div id="online-payment-rows" class="space-y-4"></div>
                    <div class="mt-4"><button type="button" id="add-online-row" class="w-full text-blue-600 font-semibold py-2 px-4 border-2 border-dashed border-blue-400 rounded-lg hover:bg-blue-50 transition" disabled><i class="fas fa-plus-circle mr-2"></i> Add Online Payment</button></div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-yellow-50 p-5 rounded-xl border-2 border-yellow-200 space-y-4">
                        <h3 class="text-xl font-bold text-yellow-900 text-center">Payment & Settlement</h3>
                        <div>
                            <label for="actual_paid_amount" class="text-md font-semibold text-yellow-800">Actual Amount Paid</label>
                            <div class="relative mt-1">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3"><span class="text-gray-500 sm:text-sm">₹</span></div>
                                <input type="number" step="0.01" id="actual_paid_amount" name="actual_paid_amount" class="form-input w-full text-center text-2xl font-bold pl-7" placeholder="0.00">
                            </div>
                        </div>
                        <div id="previous-balance-container" class="space-y-2">
                            <div id="dues-settlement-section" class="hidden bg-red-50 border border-red-200 p-3 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="apply_dues" name="apply_dues" value="1" class="h-5 w-5 text-red-600 border-gray-300 rounded focus:ring-red-500 mr-3 cursor-pointer">
                                        <div>
                                            <label for="apply_dues" class="font-semibold text-red-700 cursor-pointer">Apply Previous Dues</label>
                                            <div id="applied_dues_info" class="settlement-label-info"></div>
                                        </div>
                                    </div>
                                    <input type="text" id="previous_dues_display" class="font-bold text-lg text-red-700 text-right bg-transparent border-none p-0 focus:ring-0 w-28" readonly>
                                </div>
                            </div>
                            <div id="advance-settlement-section" class="hidden bg-green-50 border border-green-200 p-3 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <input type="checkbox" id="apply_advance" name="apply_advance" value="1" class="h-5 w-5 text-green-600 border-gray-300 rounded focus:ring-green-500 mr-3 cursor-pointer">
                                        <div>
                                            <label for="apply_advance" class="font-semibold text-green-700 cursor-pointer">Apply Previous Advance</label>
                                            <div id="applied_advance_info" class="settlement-label-info"></div>
                                        </div>
                                    </div>
                                    <input type="text" id="previous_advance_display" class="font-bold text-lg text-green-700 text-right bg-transparent border-none p-0 focus:ring-0 w-28" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-indigo-50 p-5 rounded-xl border-2 border-indigo-200 space-y-4">
                        <h3 class="text-xl font-bold text-indigo-900 text-center mb-2">Totals</h3>
                        <div class="flex justify-between items-center bg-white p-3 rounded-lg">
                            <label class="text-md font-semibold text-gray-700"><i class="fas fa-money-bill-wave text-green-500 mr-2"></i>Cash Total</label>
                            <input type="text" id="total_cash_amount_display" class="text-lg font-bold text-gray-800 text-right bg-transparent border-none p-0 focus:ring-0 w-32" value="0.00" readonly>
                        </div>
                        <div class="flex justify-between items-center bg-white p-3 rounded-lg">
                            <label class="text-md font-semibold text-gray-700"><i class="fas fa-credit-card text-blue-500 mr-2"></i>Online Total</label>
                            <input type="text" id="total_online_amount_display" class="text-lg font-bold text-gray-800 text-right bg-transparent border-none p-0 focus:ring-0 w-32" value="0.00" readonly>
                        </div>
                        <hr class="border-t-2 border-dashed border-indigo-200 my-2">
                        <div class="flex justify-between items-center bg-indigo-100 p-3 rounded-lg">
                            <label class="text-xl font-extrabold text-indigo-900">Grand Total</label>
                            <input type="text" id="grand_total_display" class="text-2xl font-extrabold text-indigo-900 text-right bg-transparent border-none p-0 focus:ring-0 w-40" value="0.00" readonly>
                        </div>
                    </div>
                </div>

                <div id="bank-transaction-section" class="disabled bg-blue-50 p-5 rounded-xl border-2 border-blue-200">
                    <h2 class="text-2xl font-bold text-blue-900 text-center mb-4">Bank Transaction</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                        <div>
                            <label for="deposit_bank_id" class="block text-sm font-medium text-gray-700 mb-1">Select Bank</label>
                            <select id="deposit_bank_id" name="deposit_bank_id" class="form-select w-full" disabled>
                                <option value="">Select a Bank</option>
                                <?php foreach($banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $bank['account_balance']; ?>"><?php echo htmlspecialchars($bank['bank_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="bank_transaction_id" class="block text-sm font-medium text-gray-700 mt-4 mb-1">Bank Txn ID</label>
                            <input type="text" id="bank_transaction_id" name="bank_transaction_id" class="form-input w-full" placeholder="Optional" disabled>
                        </div>
                        <div class="bg-white p-4 rounded-lg space-y-3 border">
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-gray-600">Available Balance</span>
                                <span id="bank_balance_display" class="font-bold text-lg text-gray-800">0.00</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-gray-600">Admin Commission</span>
                                <span id="commission_amount_display" class="font-bold text-md text-gray-800">0.00</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-red-600">Transaction Amt (-)</span>
                                <span id="amount_to_deposit_display" class="font-bold text-lg text-red-600">0.00</span>
                            </div>
                            <hr>
                            <div class="flex justify-between items-center">
                                <span class="font-extrabold text-green-700">Remaining Balance</span>
                                <span id="bank_remaining_balance_display" class="font-extrabold text-xl text-green-700">0.00</span>
                            </div>
                            <p id="balance_error_msg" class="text-center text-red-600 font-bold text-sm h-5"></p> 
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-10 pt-6 border-t-2 border-gray-200 flex justify-center sm:justify-end space-x-4">
            <button type="submit" id="submitBtn" class="btn bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-8 shadow-lg" disabled><i class="fas fa-check-circle mr-2"></i>Submit Transaction</button>
            <button type="reset" class="btn bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-8 shadow-lg"><i class="fas fa-undo mr-2"></i>Clear Form</button>
        </div>
        
        <input type="hidden" name="total_cash_amount" id="total_cash_amount" value="0">
        <input type="hidden" name="total_online_amount" id="total_online_amount" value="0">
        <input type="hidden" name="grand_total" id="grand_total" value="0">
        <input type="hidden" name="commission_amount" id="commission_amount" value="0">
        <input type="hidden" name="commission_percentage_hidden" id="commission_percentage_hidden" value="0">
        <input type="hidden" name="dues_amount" id="dues_amount" value="0">
        <input type="hidden" name="advance_amount" id="advance_amount" value="0">
        <input type="hidden" id="previous_dues" value="0">
        <input type="hidden" id="previous_advance" value="0">
        <input type="hidden" name="applied_dues_amount" id="applied_dues_amount" value="0">
        <input type="hidden" name="applied_advance_amount" id="applied_advance_amount" value="0">
    </form>
</div>

<div id="formModal" class="modal fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl h-[90vh] flex flex-col">
        <div class="flex justify-between items-center p-4 border-b">
            <h3 id="modalTitle" class="text-xl font-bold text-gray-700">Add New</h3>
            <button type="button" class="close-modal-btn text-gray-500 hover:text-gray-800 focus:outline-none text-2xl">&times;</button>
        </div>
        <div class="flex-grow p-0 overflow-y-auto"><iframe id="modalIframe" src="" class="w-full h-full border-0"></iframe></div>
    </div>
</div>

<div id="settlementModal" class="modal fixed inset-0 bg-black bg-opacity-60 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-sm transform transition-all">
        <div class="p-6">
            <h3 id="settlementModalTitle" class="text-2xl font-bold text-gray-800 text-center mb-2"></h3>
            <p class="text-center text-gray-500 mb-6">Enter the amount you want to apply.</p>
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3"><span class="text-gray-500">₹</span></div>
                <input type="number" id="settlement_amount_input" class="form-input w-full text-center text-3xl font-bold pl-7" placeholder="0.00">
            </div>
            <p id="settlement_error_msg" class="text-red-600 text-sm mt-2 h-5 text-center"></p>
            <div class="mt-6 flex justify-between items-center bg-gray-100 p-3 rounded-md">
                <span class="text-sm font-medium text-gray-600">Available to Apply:</span>
                <span id="settlement_max_amount" class="text-sm font-bold text-gray-800"></span>
            </div>
        </div>
        <div class="bg-gray-50 px-6 py-4 flex justify-end space-x-3 rounded-b-lg">
            <button type="button" id="cancel_settlement_btn" class="btn bg-gray-200 hover:bg-gray-300 text-gray-800">Cancel</button>
            <button type="button" id="apply_settlement_btn" class="btn bg-indigo-600 hover:bg-indigo-700 text-white">Apply Amount</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // --- INITIALIZATION ---
    let commissionPercentage = 0.00;
    // MODIFIED: Added #transaction_type to the Select2 initializer
    $('#customer_id, #company_name, #deposit_bank_id, #transaction_type').select2({ width: '100%' });

    // --- HELPER & CALCULATION FUNCTIONS ---
    function formatCurrency(num) {
        return parseFloat(num).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateTotals() {
        let cashTotal = 0;
        let totalNotes = 0;
        if ($('#cash-section').is(':not(.disabled)')) {
            $('.cash-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                totalNotes += qty;
                let value = parseFloat($(this).data('value'));
                let rowTotal = qty * value;
                $(this).closest('.grid').find('.cash-row-total').val(formatCurrency(rowTotal));
                cashTotal += rowTotal;
            });
        }
        $('#total_cash_amount_display').val(formatCurrency(cashTotal));
        $('#total_cash_amount').val(cashTotal.toFixed(2));
        $('#total_notes_display').val(totalNotes);

        let onlineTotal = 0;
        if ($('#online-section').is(':not(.disabled)')) {
            $('.online-amount').each(function() { onlineTotal += parseFloat($(this).val()) || 0; });
        }
        $('#total_online_amount_display').val(formatCurrency(onlineTotal));
        $('#total_online_amount').val(onlineTotal.toFixed(2));

        let grandTotalDisplay = cashTotal + onlineTotal;
        $('#grand_total_display').val(formatCurrency(grandTotalDisplay));
        
        let appliedDues = parseFloat($('#applied_dues_amount').val()) || 0;
        let appliedAdvance = parseFloat($('#applied_advance_amount').val()) || 0;
        
        let netAmountToSettle = grandTotalDisplay - appliedDues + appliedAdvance;
        $('#grand_total').val(netAmountToSettle.toFixed(2));

        let actualPaidAmount = parseFloat($('#actual_paid_amount').val()) || 0;
        let difference = netAmountToSettle - actualPaidAmount;
        
        let duesAmount = (difference > 0) ? difference : 0;
        let advanceAmount = (difference < 0) ? -difference : 0;

        $('#dues_amount_display').val(formatCurrency(duesAmount));
        $('#dues_amount').val(duesAmount.toFixed(2));
        $('#advance_amount_display').val(formatCurrency(advanceAmount));
        $('#advance_amount').val(advanceAmount.toFixed(2));

        let commissionAmount = actualPaidAmount * (commissionPercentage / 100);
        $('#commission_amount_display').text(formatCurrency(commissionAmount));
        $('#commission_amount').val(commissionAmount.toFixed(2));
        
        $('#amount_to_deposit_display').text(formatCurrency(actualPaidAmount));
        
        if (actualPaidAmount > 0) {
            $('#bank-transaction-section').removeClass('disabled');
            $('#deposit_bank_id').prop('disabled', false).prop('required', true);
            $('#bank_transaction_id').prop('disabled', false);
        } else {
            $('#bank-transaction-section').addClass('disabled');
            $('#deposit_bank_id, #bank_transaction_id').prop('disabled', true).prop('required', false).val('');
        }
        $('#deposit_bank_id').trigger('change');

        checkFormValidity();
    }

    function togglePaymentSection(type, isSelected) {
        const section = $(`#${type}-section`);
        const inputs = section.find(':input:not(button)');
        
        if (isSelected) {
            section.removeClass('disabled');
            inputs.prop('disabled', false);
            if (type === 'online') {
                $('#add-online-row').prop('disabled', false);
                if ($('#online-payment-rows').is(':empty')) addOnlineRow();
            }
        } else {
            section.addClass('disabled');
            inputs.prop('disabled', true);
            if (type === 'cash') $('.cash-qty').val('');
            else if (type === 'online') {
                $('#online-payment-rows').empty();
                $('#add-online-row').prop('disabled', true);
            }
        }
        updateTotals();
    }

    function addOnlineRow() {
        const platforms = <?php echo json_encode($online_platforms); ?>;
        const options = platforms.map(p => `<option value="${p}">${p}</option>`).join('');
        const newRow = `<div class="grid grid-cols-12 gap-2 online-payment-row items-start p-2 bg-white rounded-md border"><div class="col-span-12 sm:col-span-4"><select name="online_platform[]" class="form-select w-full" required>${options}</select></div><div class="col-span-12 sm:col-span-3"><input type="number" name="online_amount[]" class="form-input w-full online-amount" placeholder="Amount" step="0.01" required></div><div class="col-span-10 sm:col-span-4"><input type="text" name="online_utr[]" class="form-input w-full" placeholder="UTR No." required></div><div class="col-span-2 sm:col-span-1"><button type="button" class="remove-online-row text-red-500 h-full w-full flex items-center justify-center text-lg hover:text-red-700"><i class="fas fa-times-circle"></i></button></div></div>`;
        $("#online-payment-rows").append(newRow).find("select").last().select2({ width: "100%" });
    }

    function checkFormValidity() {
        const hasCustomer = !!$('#customer_id').val();
        const hasCompany = !!$('#company_name').val();
        const hasTransactionType = !!$('#transaction_type').val(); // NEW check
        const transactionTotal = (parseFloat($('#total_cash_amount').val()) || 0) + (parseFloat($('#total_online_amount').val()) || 0);
        const actualPaid = parseFloat($('#actual_paid_amount').val()) || 0;
        
        let isBankDetailValid = true;
        let isBankBalanceSufficient = true; 

        if (actualPaid > 0) {
            const $bankSelect = $('#deposit_bank_id');
            if (!$bankSelect.val()) {
                isBankDetailValid = false;
            } else {
                const selectedBankBalance = parseFloat($bankSelect.find('option:selected').data('balance')) || 0;
                if (actualPaid > selectedBankBalance) isBankBalanceSufficient = false;
            }
        }
        // MODIFIED: Added hasTransactionType to the validation check
        $('#submitBtn').prop('disabled', !(hasCustomer && hasCompany && hasTransactionType && (transactionTotal > 0 || actualPaid > 0) && isBankDetailValid && isBankBalanceSufficient));
    }
    
    // --- EVENT LISTENERS ---
    $('#customer_id').on('change', function() {
        const customerId = $(this).val();
        $('#dues-settlement-section, #advance-settlement-section').addClass('hidden');
        $('#previous_dues, #previous_advance, #applied_dues_amount, #applied_advance_amount').val(0);
        $('#apply_dues, #apply_advance').prop('checked', false);
        $('#applied_dues_info, #applied_advance_info').text('');

        if (customerId) {
            fetch(`get_customer_balance.php?customer_id=${customerId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const dues = parseFloat(data.dues_amount) || 0;
                        const advance = parseFloat(data.advance_amount) || 0;
                        $('#previous_dues').val(dues);
                        $('#previous_advance').val(advance);
                        if (dues > 0) {
                            $('#previous_dues_display').val(formatCurrency(dues));
                            $('#dues-settlement-section').removeClass('hidden');
                        }
                        if (advance > 0) {
                            $('#previous_advance_display').val(formatCurrency(advance));
                            $('#advance-settlement-section').removeClass('hidden');
                        }
                    }
                    updateTotals();
                })
                .catch(error => console.error('Error fetching customer balance:', error));
        }
        checkFormValidity();
    });

    $('#company_name').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        commissionPercentage = parseFloat(selectedOption.data('commission')) || 0.00;
        $('#commission_percentage_hidden').val(commissionPercentage.toFixed(2));
        updateTotals();
    });

    $('#deposit_bank_id').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const balance = parseFloat(selectedOption.data('balance')) || 0;
        const actualPaid = parseFloat($('#actual_paid_amount').val()) || 0;
        const remainingBalance = balance - actualPaid;

        $('#bank_balance_display').text(formatCurrency(balance));
        $('#bank_remaining_balance_display').text(formatCurrency(remainingBalance));
        
        if (remainingBalance < 0) {
            $('#balance_error_msg').text('Insufficient funds.');
            $('#bank_remaining_balance_display').removeClass('text-green-700').addClass('text-red-600');
        } else {
            $('#balance_error_msg').text('');
            $('#bank_remaining_balance_display').removeClass('text-red-600').addClass('text-green-700');
        }
        checkFormValidity(); 
    });

    $('.payment-type-card').on('click keydown', function(e) {
        if (e.type === 'click' || e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).toggleClass('selected');
            const type = $(this).data('type');
            const isSelected = $(this).hasClass('selected');
            $(this).find('input[type="checkbox"]').prop('checked', isSelected);
            togglePaymentSection(type, isSelected);
        }
    });

    $('#denominationForm').on('input', '.cash-qty, .online-amount, #actual_paid_amount', updateTotals);
    // Added change listener for the new dropdown
    $('#denominationForm').on('change', '#customer_id, #company_name, #transaction_type', checkFormValidity);
    $('#add-online-row').on('click', addOnlineRow);
    $('#online-payment-rows').on('click', '.remove-online-row', function() {
        $(this).closest('.online-payment-row').remove();
        updateTotals();
    });

    $('#denominationForm').on('reset', function() {
        setTimeout(() => {
            // MODIFIED: Added #transaction_type to the reset handler
            $('#customer_id, #company_name, #deposit_bank_id, #transaction_type').val(null).trigger('change');
            $('.payment-type-card').removeClass('selected').find('input[type="checkbox"]').prop('checked', false);
            togglePaymentSection('cash', false);
            togglePaymentSection('online', false);
            $('#dues-settlement-section, #advance-settlement-section').addClass('hidden');
            $('#apply_dues, #apply_advance').prop('checked', false);
            $('#applied_dues_amount, #applied_advance_amount').val(0);
            $('#applied_dues_info, #applied_advance_info').text('');
            updateTotals();
        }, 0);
    });

    // --- MODAL LOGIC ---
    function openModal(url, title) {
        $('#modalTitle').text(title);
        $('#modalIframe').attr('src', url);
        $('#formModal').removeClass('hidden').addClass('flex');
    }
    function closeModal(modalId) {
        $(`#${modalId}`).addClass('hidden').removeClass('flex');
        if (modalId === 'formModal') $('#modalIframe').attr('src', '');
    }
    $('#add-customer-btn').on('click', () => openModal('customer_add.php', 'Add New Customer'));
    $('#add-company-btn').on('click', () => openModal('company_commission.php', 'Add New Company Commission'));
    $('.close-modal-btn').on('click', () => closeModal('formModal'));
    $('#formModal').on('click', e => { if ($(e.target).is('#formModal')) closeModal('formModal'); });

    // --- PARTIAL SETTLEMENT MODAL LOGIC ---
    let currentSettlementType = null;

    $('#apply_dues, #apply_advance').on('change', function(e) {
        e.preventDefault();
        const checkbox = $(this);
        currentSettlementType = checkbox.attr('id') === 'apply_dues' ? 'dues' : 'advance';
        
        if (checkbox.is(':checked')) {
            const maxAmount = parseFloat($(`#previous_${currentSettlementType}`).val()) || 0;
            if (maxAmount > 0) {
                $('#settlementModalTitle').text(`Apply Previous ${currentSettlementType.charAt(0).toUpperCase() + currentSettlementType.slice(1)}`);
                $('#settlement_max_amount').text(`₹ ${formatCurrency(maxAmount)}`);
                $('#settlement_amount_input').val(maxAmount.toFixed(2)).attr('max', maxAmount.toFixed(2));
                $('#settlement_error_msg').text('');
                $('#settlementModal').removeClass('hidden').addClass('flex');
            } else {
                checkbox.prop('checked', false);
            }
        } else {
            $(`#applied_${currentSettlementType}_amount`).val(0);
            $(`#applied_${currentSettlementType}_info`).text('');
            updateTotals();
        }
    });

    $('#apply_settlement_btn').on('click', function() {
        const amountInput = $('#settlement_amount_input');
        const amount = parseFloat(amountInput.val()) || 0;
        const maxAmount = parseFloat(amountInput.attr('max')) || 0;

        if (amount > maxAmount || amount < 0) {
            $('#settlement_error_msg').text(`Amount cannot exceed ₹ ${formatCurrency(maxAmount)}.`);
            return;
        }

        $(`#applied_${currentSettlementType}_amount`).val(amount.toFixed(2));
        if (amount > 0) {
            $(`#applied_${currentSettlementType}_info`).text(`Applying ₹ ${formatCurrency(amount)}`);
        } else {
            $(`#applied_${currentSettlementType}_info`).text('');
            $(`#apply_${currentSettlementType}`).prop('checked', false);
        }
        
        updateTotals();
        closeModal('settlementModal');
    });

    $('#cancel_settlement_btn').on('click', function() {
        $(`#apply_${currentSettlementType}`).prop('checked', false);
        closeModal('settlementModal');
    });

    updateTotals();
});
</script>
</body>
</html>