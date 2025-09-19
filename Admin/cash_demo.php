<?php
session_start();

$message = '';
$error = '';
$pdo = null;

// Ensure database connection is established
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    $error = "Database configuration file not found. The form will not save data or load dynamic content.";
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id']) && $pdo) {
    $pdo->beginTransaction();
    try {
        // --- Common POST variables ---
        $customer_id = (int)$_POST['customer_id'];
        $company_name = $_POST['company_name'];
        $transaction_type_form = $_POST['transaction_type'];
        $grand_total = (float) str_replace(',', '', $_POST['grand_total']);
        $actual_paid_amount = (float) str_replace(',', '', $_POST['actual_paid_amount']);
        $commission_percentage = (float)$_POST['commission_percentage_hidden'];
        $commission_amount = $grand_total * ($commission_percentage / 100); // Commission is now based on grand_total
        $transaction_dues = (float) str_replace(',', '', $_POST['dues_amount']);
        $transaction_advance = (float) str_replace(',', '', $_POST['advance_amount']);
        $payment_modes = [];

        // --- Base Transaction Insert ---
        $sql_insert_base = "INSERT INTO transactions (customer_id, company_name, transaction_type, payment_mode, grand_total, actual_paid_amount, commission_amount, dues_amount, advance_amount) 
                            VALUES (:customer_id, :company_name, :transaction_type, :payment_mode, :grand_total, :actual_paid_amount, :commission_amount, :dues_amount, :advance_amount)";
        $stmt_base = $pdo->prepare($sql_insert_base);
        $stmt_base->execute([
            ':customer_id' => $customer_id,
            ':company_name' => $company_name,
            ':transaction_type' => $transaction_type_form,
            ':payment_mode' => 'PENDING', // Will be updated later
            ':grand_total' => $grand_total,
            ':actual_paid_amount' => $actual_paid_amount,
            ':commission_amount' => $commission_amount,
            ':dues_amount' => $transaction_dues,
            ':advance_amount' => $transaction_advance
        ]);
        $transaction_id = $pdo->lastInsertId();

        // --- Prepare statement for transaction details (cash/online) ---
        $stmt_details = $pdo->prepare("INSERT INTO transaction_details (transaction_id, detail_type, denomination_or_platform, quantity_or_utr, amount) VALUES (?, ?, ?, ?, ?)");

        // --- Logic based on Transaction Type ---
        switch ($transaction_type_form) {

            case 'Cash Received':
                $payment_modes[] = 'Cash';
                $settlement_bank_id = !empty($_POST['deposit_bank_id']) ? (int)$_POST['deposit_bank_id'] : null;
                if (!$settlement_bank_id) throw new Exception("A bank must be selected for settlement.");

                // Log cash denominations
                if (isset($_POST['cash_qty'])) {
                    foreach ($_POST['cash_qty'] as $d => $q) { if (!empty($q)&&(int)$q>0) $stmt_details->execute([$transaction_id, 'cash', $d, $q, (float)$d*(int)$q]); }
                }

                // Settle with company from bank
                $stmt_get = $pdo->prepare("SELECT account_balance FROM banks WHERE id = ? FOR UPDATE"); $stmt_get->execute([$settlement_bank_id]);
                $bank = $stmt_get->fetch();
                if (!$bank) throw new Exception("Bank for settlement not found.");
                $balance_before = (float)$bank['account_balance'];
                if ($balance_before < $grand_total) throw new Exception("Insufficient funds in selected bank for settlement.");
                $balance_after = $balance_before - $grand_total;
                $pdo->prepare("UPDATE banks SET account_balance = ? WHERE id = ?")->execute([$balance_after, $settlement_bank_id]);
                $pdo->prepare("INSERT INTO banks_transactions_history (bank_id, transaction_id, transaction_type, amount, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?)")->execute([$settlement_bank_id, $transaction_id, 'payment', $grand_total, $balance_before, $balance_after]);
                break;

            case 'Online Received':
                $payment_modes[] = 'Online';
                $settlement_bank_id = !empty($_POST['deposit_bank_id']) ? (int)$_POST['deposit_bank_id'] : null;
                
                // Log online transaction details
                if (isset($_POST['online_bank_id'])) {
                    for ($i = 0; $i < count($_POST['online_bank_id']); $i++) {
                        if ((float)$_POST['online_amount'][$i] > 0) $stmt_details->execute([$transaction_id, 'online', $_POST['online_bank_name'][$i], $_POST['online_utr'][$i], (float)$_POST['online_amount'][$i]]);
                    }
                }

                // Settle with company from selected bank
                if ($settlement_bank_id && $grand_total > 0) {
                     $stmt_get = $pdo->prepare("SELECT account_balance FROM banks WHERE id = ? FOR UPDATE"); $stmt_get->execute([$settlement_bank_id]);
                     $bank = $stmt_get->fetch();
                     if (!$bank) throw new Exception("Bank for settlement not found.");
                     $balance_before = (float)$bank['account_balance'];
                     if ($balance_before < $grand_total) throw new Exception("Insufficient funds for company settlement.");
                     $balance_after = $balance_before - $grand_total;
                     $pdo->prepare("UPDATE banks SET account_balance = ? WHERE id = ?")->execute([$balance_after, $settlement_bank_id]);
                     $pdo->prepare("INSERT INTO banks_transactions_history (bank_id, transaction_id, transaction_type, amount, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?)")->execute([$settlement_bank_id, $transaction_id, 'payment', $grand_total, $balance_before, $balance_after]);
                }
                break;

            case 'Cash Payment':
                // This transaction type now represents receiving an online payment and immediately paying it out in cash.
                if ((float)str_replace(',', '', $_POST['total_online_amount']) > 0) $payment_modes[] = 'Online';
                if ((float)str_replace(',', '', $_POST['total_cash_amount']) > 0) $payment_modes[] = 'Cash';

                // Log online transaction details used for funding this cash payout
                // Note: The actual bank deposit is handled via AJAX on the frontend and is not part of this main transaction submission logic.
                if (isset($_POST['online_bank_id'])) {
                    for ($i = 0; $i < count($_POST['online_bank_id']); $i++) {
                        if ((float)$_POST['online_amount'][$i] > 0) $stmt_details->execute([$transaction_id, 'online', $_POST['online_bank_name'][$i], $_POST['online_utr'][$i], (float)$_POST['online_amount'][$i]]);
                    }
                }

                // Log cash denominations paid out
                if (isset($_POST['cash_qty'])) {
                    foreach ($_POST['cash_qty'] as $d => $q) { if (!empty($q)&&(int)$q>0) $stmt_details->execute([$transaction_id, 'cash', $d, $q, (float)$d*(int)$q]); }
                }

                // The bank withdrawal logic is intentionally removed for this case.
                // The transaction is funded by the online payment, which is deposited via AJAX.
                // This process essentially converts an online receipt into a cash payout.
                break;

            case '(Cash + Online) Received':
                if ((float)str_replace(',', '', $_POST['total_cash_amount']) > 0) $payment_modes[] = 'Cash';
                if ((float)str_replace(',', '', $_POST['total_online_amount']) > 0) $payment_modes[] = 'Online';
                
                // Log cash denominations
                if (isset($_POST['cash_qty'])) {
                    foreach ($_POST['cash_qty'] as $d => $q) { if (!empty($q)&&(int)$q>0) $stmt_details->execute([$transaction_id, 'cash', $d, $q, (float)$d*(int)$q]); }
                }
                // Log online details
                if (isset($_POST['online_bank_id'])) {
                    for ($i = 0; $i < count($_POST['online_bank_id']); $i++) {
                        if ((float)$_POST['online_amount'][$i] > 0) $stmt_details->execute([$transaction_id, 'online', $_POST['online_bank_name'][$i], $_POST['online_utr'][$i], (float)$_POST['online_amount'][$i]]);
                    }
                }
                
                // Settle with company for the full grand total
                $settlement_bank_id = !empty($_POST['deposit_bank_id']) ? (int)$_POST['deposit_bank_id'] : null;
                if ($settlement_bank_id && $grand_total > 0) {
                     $stmt_get = $pdo->prepare("SELECT account_balance FROM banks WHERE id = ? FOR UPDATE"); $stmt_get->execute([$settlement_bank_id]);
                     $bank = $stmt_get->fetch();
                     if (!$bank) throw new Exception("Bank for settlement not found.");
                     $balance_before = (float)$bank['account_balance'];
                     if ($balance_before < $grand_total) throw new Exception("Insufficient funds for company settlement.");
                     $balance_after = $balance_before - $grand_total; // Settle the full amount
                     $pdo->prepare("UPDATE banks SET account_balance = ? WHERE id = ?")->execute([$balance_after, $settlement_bank_id]);
                     $pdo->prepare("INSERT INTO banks_transactions_history (bank_id, transaction_id, transaction_type, amount, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?)")->execute([$settlement_bank_id, $transaction_id, 'payment', $grand_total, $balance_before, $balance_after]);
                }
                break;
        }

        // Finalize transaction by setting the payment mode string
        $payment_mode_str = implode(' + ', $payment_modes);
        $pdo->prepare("UPDATE transactions SET payment_mode = ? WHERE id = ?")->execute([$payment_mode_str, $transaction_id]);
        
        $pdo->commit();
        $message = "Transaction #$transaction_id saved successfully! (Type: $transaction_type_form)";
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error saving transaction: " . $exception->getMessage();
    }
}

// Fetch data for form dropdowns
$customers = []; $companies_data = []; $banks = [];
if ($pdo) {
    $customers = $pdo->query("SELECT id, name, mobile_no FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $companies_data = $pdo->query("SELECT `id`, `company_name`, `commission_percentage` FROM `company_commissions` ORDER BY `company_name` ASC")->fetchAll(PDO::FETCH_ASSOC);
    $banks = $pdo->query("SELECT id, bank_name, account_number, account_balance FROM banks ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}
$cash_denominations = [500 => '../images/500.png', 200 => '../images/200.png', 100 => '../images/100.png', 50 => '../images/50.png', 20 => '../images/20.png', 10 => '../images/10.png', 5 => '../images/5.png', 2 => '../images/2.png', 1 => '../images/1.png'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-input, .form-select { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; }
        .payment-section, .settlement-section-ui, #bank-transaction-section { display: none; }
        .note-img { width: 50px; height: 25px; object-fit: cover; border-radius: 4px; margin-right: 0.75rem; }
        .select2-container .select2-selection--single { height: 46px !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 44px; padding-left: 0.85rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; }
        button:disabled { opacity: 0.6; cursor: not-allowed !important; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-screen-2xl mx-auto bg-white rounded-2xl shadow-2xl p-6 sm:p-8">
    <form id="denominationForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
        
        <div class="text-center mb-8 border-b-2 border-gray-200 pb-6">
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-800 tracking-tight"><i class="fas fa-cash-register text-indigo-600"></i> Transaction Panel</h1>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-lg" role="alert"><p><b>Success:</b> <?php echo $message; ?></p></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg" role="alert"><p><b>Error:</b> <?php echo $error; ?></p></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
             <div>
                <label for="transaction_type" class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                <select id="transaction_type" name="transaction_type" class="form-select w-full" required>
                    <option value="">Select Type</option>
                    <option value="(Cash + Online) Received">(Cash + Online) Received</option>
                    <option value="Cash Received">Cash Received</option>
                    <option value="Online Received">Online Received</option>
                    <option value="Cash Payment">Cash Payment (Cash Out)</option>
                </select>
            </div>
            <div>
                <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                <select id="customer_id" name="customer_id" class="form-select w-full" required>
                    <option value="">Select a Customer</option>
                    <?php foreach($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['mobile_no'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Select Company</label>
                <select id="company_name" name="company_name" class="form-select w-full" required>
                    <option value="" data-commission="0">Select Company</option>
                    <?php foreach($companies_data as $company): ?>
                        <option value="<?php echo htmlspecialchars($company['company_name']); ?>" data-commission="<?php echo $company['commission_percentage']; ?>"><?php echo htmlspecialchars($company['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
            <div class="xl:col-span-1 space-y-6">
                <div id="cash-section" class="payment-section bg-gray-50 p-5 rounded-xl border-2 border-dashed">
                    <h2 id="cash-section-title" class="text-xl font-semibold text-gray-700 mb-4 text-center">Cash Details</h2>
                    <div class="space-y-3 max-h-[350px] overflow-y-auto pr-2">
                        <?php foreach($cash_denominations as $value => $image_path): ?>
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-5 flex items-center"><img src="<?php echo $image_path; ?>" alt="<?php echo $value; ?>" class="note-img"><span class="font-semibold text-gray-600">₹ <?php echo $value; ?></span></div>
                            <div class="col-span-3"><input type="number" name="cash_qty[<?php echo $value; ?>]" class="form-input w-full text-center cash-qty" data-value="<?php echo $value; ?>" placeholder="Qty" min="0"></div>
                            <div class="col-span-4"><input type="text" class="form-input w-full text-right bg-gray-200 cash-row-total" readonly value="0.00"></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-4 pt-3 border-t-2 border-dashed flex justify-between font-bold text-lg"><span class="text-gray-700">Total Notes:</span><span id="total_notes_display">0</span></div>
                </div>
            </div>

            <div class="xl:col-span-2 space-y-6">
                <div id="online-section" class="payment-section bg-gray-50 p-5 rounded-xl border-2 border-dashed">
                     <h2 id="online-section-title" class="text-xl font-semibold text-gray-700 mb-4 text-center">Online Details</h2>
                     <div id="online-payment-rows" class="space-y-4"></div>
                     <div class="mt-4"><button type="button" id="add-online-row" class="w-full text-blue-600 font-semibold py-2 px-4 border-2 border-dashed rounded-lg"><i class="fas fa-plus-circle mr-2"></i> Add Online Payment</button></div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-indigo-50 p-5 rounded-xl border-2 border-indigo-200 space-y-4">
                        <h3 class="text-xl font-bold text-indigo-900 text-center mb-2">Totals</h3>
                        <div class="flex justify-between items-center bg-white p-3 rounded-lg"><label>Cash Total</label><input type="text" id="total_cash_amount_display" class="font-bold text-right bg-transparent border-none p-0" value="0.00" readonly></div>
                        <div class="flex justify-between items-center bg-white p-3 rounded-lg"><label>Online Total</label><input type="text" id="total_online_amount_display" class="font-bold text-right bg-transparent border-none p-0" value="0.00" readonly></div>
                        <hr class="border-t-2 border-dashed"><div class="flex justify-between items-center bg-indigo-100 p-3 rounded-lg"><label class="text-xl font-extrabold">Grand Total</label><input type="text" id="grand_total_display" class="text-2xl font-extrabold text-right bg-transparent border-none p-0" value="0.00" readonly></div>
                    </div>
                    <div id="settlement-section" class="settlement-section-ui bg-yellow-50 p-5 rounded-xl border-2 border-yellow-200 space-y-4">
                        <h3 class="text-xl font-bold text-yellow-900 text-center">Payment & Dues</h3>
                        <div>
                            <label for="actual_paid_amount" class="text-md font-semibold text-yellow-800">Actual Amount Paid by Customer</label>
                            <div class="relative mt-1"><span class="absolute left-3 top-1/2 -translate-y-1/2">₹</span><input type="number" step="0.01" id="actual_paid_amount" name="actual_paid_amount" class="form-input w-full text-center text-2xl font-bold pl-7" placeholder="0.00"></div>
                        </div>
                         <div class="flex justify-between items-center bg-green-100 p-2 rounded-lg"><label class="text-sm font-semibold text-green-800">Advance (+)</label><input type="text" id="dues_amount_display" class="font-bold text-green-800 text-right bg-transparent border-none p-0 w-24" value="0.00" readonly></div>
                         <div class="flex justify-between items-center bg-red-100 p-2 rounded-lg"><label class="text-sm font-semibold text-red-800">New Dues (-)</label><input type="text" id="advance_amount_display" class="font-bold text-red-800 text-right bg-transparent border-none p-0 w-24" value="0.00" readonly></div>
                    </div>
                </div>

                <div id="bank-transaction-section" class="bg-blue-50 p-5 rounded-xl border-2 border-blue-200">
                    <h2 id="bank-section-title" class="text-2xl font-bold text-blue-900 text-center mb-4">Bank Transaction</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                        <div>
                            <label for="deposit_bank_id" class="block text-sm font-medium text-gray-700 mb-1">Select Bank Account</label>
                            <select id="deposit_bank_id" name="deposit_bank_id" class="form-select w-full"><option value="">Select a Bank</option><?php foreach($banks as $bank): ?><option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $bank['account_balance']; ?>"><?php echo htmlspecialchars($bank['bank_name']); ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="bg-white p-4 rounded-lg space-y-3 border">
                            <div class="flex justify-between"><span>Available Balance</span><span id="bank_balance_display" class="font-bold">0.00</span></div>
                            <div class="flex justify-between">
                                <span>Commission (%)</span>
                                <input type="number" id="commission_percentage_input" class="font-bold text-right bg-transparent border-b w-20 p-0" value="0.00" step="0.01">
                            </div>
                            <div class="flex justify-between"><span id="bank-transaction-amount-label">Amount</span><span id="amount_for_bank_display" class="font-bold">0.00</span></div><hr>
                            <div class="flex justify-between"><span>New Balance</span><span id="bank_remaining_balance_display" class="font-extrabold text-xl text-green-700">0.00</span></div>
                            <p id="balance_error_msg" class="text-center text-red-600 font-bold text-sm h-5"></p> 
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-10 pt-6 border-t-2 border-gray-200 flex justify-end space-x-4">
            <button type="submit" id="submitBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-lg" disabled><i class="fas fa-check-circle mr-2"></i>Submit Transaction</button>
            <button type="reset" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-6 rounded-lg"><i class="fas fa-undo mr-2"></i>Clear Form</button>
        </div>
        <input type="hidden" name="total_cash_amount" id="total_cash_amount"><input type="hidden" name="total_online_amount" id="total_online_amount"><input type="hidden" name="grand_total" id="grand_total"><input type="hidden" name="commission_amount" id="commission_amount"><input type="hidden" name="commission_percentage_hidden" id="commission_percentage_hidden"><input type="hidden" name="dues_amount" id="dues_amount"><input type="hidden" name="advance_amount" id="advance_amount">
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    let ALL_BANKS = <?php echo json_encode($banks); ?>;
    $('#customer_id, #company_name, #deposit_bank_id').select2({ width: '100%' });

    function formatCurrency(num) { return parseFloat(num).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    
    function resetFormState() {
        $('.payment-section, .settlement-section-ui, #bank-transaction-section').hide();
        $('#online-payment-rows').empty();
        $('#commission_percentage_input').prop('disabled', true);
        $('#denominationForm').find('input[type=number], input[type=text]:not([readonly])').val('');
        $('.cash-row-total, #total_cash_amount_display, #total_online_amount_display, #grand_total_display, #dues_amount_display, #advance_amount_display').val('0.00');
        updateTotals();
    }

    $('#transaction_type').on('change', function() {
        const type = $(this).val();
        resetFormState();
        if (!type) return;

        $('#commission_percentage_input').prop('disabled', true); // Disable by default

        switch(type) {
            case 'Cash Received':
                $('#cash-section, #bank-transaction-section, .settlement-section-ui').show();
                $('#commission_percentage_input').prop('disabled', false); // Enable commission
                $('#cash-section-title').text('Cash Received From Customer');
                $('#bank-section-title').text('Company Settlement (Withdrawal)');
                $('#bank-transaction-amount-label').text('Amount to Pay');
                break;
            case 'Online Received':
                $('#online-section, .settlement-section-ui, #bank-transaction-section').show(); // Show bank section for settlement
                $('#commission_percentage_input').prop('disabled', false); // Enable commission
                $('#online-section-title').text('Online Amount Received');
                $('#bank-section-title').text('Company Settlement (Withdrawal)');
                $('#bank-transaction-amount-label').text('Amount to Pay Company');
                break;
            case 'Cash Payment':
                // MODIFIED: Show online funding and cash payout sections. Hide the direct bank withdrawal section.
                $('#cash-section, #online-section, .settlement-section-ui').show();
                $('#cash-section-title').text('Cash Paid Out (Cash Out)');
                $('#online-section-title').text('Online Amount Received (Funding)');
                // Commission is not typically applied to a simple cash-out, so it remains disabled.
                break;
            case '(Cash + Online) Received':
                $('#cash-section, #online-section, #bank-transaction-section, .settlement-section-ui').show();
                $('#commission_percentage_input').prop('disabled', false);
                $('#cash-section-title').text('Cash Received');
                $('#online-section-title').text('Online Received');
                $('#bank-section-title').text('Company Settlement (Withdrawal)');
                $('#bank-transaction-amount-label').text('Amount to Pay Company');
                break;
        }
        updateTotals();
    });

    function updateTotals() {
        let cashTotal = 0, onlineTotal = 0, totalNotes = 0;
        $('.cash-qty').each(function() {
            let qty = parseInt($(this).val()) || 0;
            let rowTotal = qty * parseFloat($(this).data('value'));
            $(this).closest('.grid').find('.cash-row-total').val(formatCurrency(rowTotal));
            cashTotal += rowTotal;
            totalNotes += qty;
        });
        $('.online-amount-saved').each(function() { onlineTotal += parseFloat($(this).val()) || 0; });
        
        $('#total_notes_display').text(totalNotes);
        $('#total_cash_amount_display').val(formatCurrency(cashTotal));
        $('#total_cash_amount').val(cashTotal.toFixed(2));
        $('#total_online_amount_display').val(formatCurrency(onlineTotal));
        $('#total_online_amount').val(onlineTotal.toFixed(2));

        const type = $('#transaction_type').val();
        let grandTotal = 0;
        // The "Grand Total" of the transaction record should be the primary amount being processed.
        // For "Cash Payment", this is the cash being paid out. The online amount is just the funding method.
        if (type === 'Online Received') grandTotal = onlineTotal;
        else if (type === '(Cash + Online) Received') grandTotal = cashTotal + onlineTotal;
        else grandTotal = cashTotal; // Applies to 'Cash Received' and 'Cash Payment'
        
        $('#grand_total_display').val(formatCurrency(grandTotal));
        $('#grand_total').val(grandTotal.toFixed(2));
        
        let actualPaidAmount = parseFloat($('#actual_paid_amount').val()) || 0;
        let difference = grandTotal - actualPaidAmount;
        $('#dues_amount_display').val(formatCurrency((difference > 0) ? difference : 0));
        $('#dues_amount').val(((difference > 0) ? difference : 0).toFixed(2));
        $('#advance_amount_display').val(formatCurrency((difference < 0) ? -difference : 0));
        $('#advance_amount').val(((difference < 0) ? -difference : 0).toFixed(2));
        
        // This will only affect visible bank sections, so it's safe to call.
        $('#deposit_bank_id').trigger('change');
        checkFormValidity();
    }
    
    $('#deposit_bank_id').on('change', function() {
        const balance = parseFloat($(this).find('option:selected').attr('data-balance')) || 0;
        const type = $('#transaction_type').val();
        const grandTotal = parseFloat($('#grand_total').val()) || 0;
        
        let amountForBank = 0;
        let isWithdrawal = false;

        switch(type) {
            case 'Cash Received':
            case 'Online Received':
            case '(Cash + Online) Received':
                amountForBank = grandTotal; 
                isWithdrawal = true; // These are settlements, so it's a withdrawal/payment from our account
                break;
            // The original 'Cash Payment' logic is no longer needed here as the section is hidden.
        }

        $('#amount_for_bank_display').text(formatCurrency(amountForBank));
        let remaining = isWithdrawal ? (balance - amountForBank) : (balance + amountForBank);
        
        $('#bank_balance_display').text(formatCurrency(balance));
        $('#bank_remaining_balance_display').text(formatCurrency(remaining))
            .toggleClass('text-red-600', remaining < 0).toggleClass('text-green-700', remaining >= 0);
        $('#balance_error_msg').text(remaining < 0 && isWithdrawal ? 'Insufficient funds.' : '');
    });

    $('#add-online-row').on('click', function() {
        const bankOptions = ALL_BANKS.map(b => `<option value="${b.id}">${b.bank_name} (...${b.account_number.slice(-4)})</option>`).join('');
        const newRow = $(`
            <div class="online-payment-row grid grid-cols-12 gap-2 items-center p-2 bg-white rounded-md border">
                <div class="col-span-4"><select class="form-select w-full online-bank-select">${bankOptions}</select></div>
                <div class="col-span-3"><input type="number" class="form-input w-full online-amount" placeholder="Amount"></div>
                <div class="col-span-3"><input type="text" class="form-input w-full online-utr" placeholder="UTR No."></div>
                <div class="col-span-2 text-center">
                    <button type="button" class="save-online-row bg-green-500 text-white font-bold py-2 px-3 rounded" title="Save row"><i class="fas fa-check"></i></button>
                    <button type="button" class="remove-online-row bg-red-500 text-white font-bold py-2 px-3 rounded hidden" title="Reverse entry"><i class="fas fa-trash"></i></button>
                </div>
                <input type="hidden" class="online-bank-id-hidden" name="online_bank_id[]"><input type="hidden" class="online-bank-name-hidden" name="online_bank_name[]">
                <input type="hidden" class="online-amount-saved" name="online_amount[]"><input type="hidden" class="online-utr-hidden" name="online_utr[]">
                <input type="hidden" class="provisional-log-id">
            </div>`);
        $('#online-payment-rows').append(newRow);
        newRow.find('.online-bank-select').select2({width: '100%'});
    });
    
    function updateClientSideBankBalance(bankId, newBalance) {
        const bankIndex = ALL_BANKS.findIndex(b => b.id == bankId);
        if (bankIndex > -1) { ALL_BANKS[bankIndex].account_balance = newBalance; }
        $('#deposit_bank_id').find(`option[value="${bankId}"]`).attr('data-balance', newBalance);
        // Refresh the balance display if the currently selected settlement bank was updated
        if ($('#deposit_bank_id').val() == bankId) { $('#deposit_bank_id').trigger('change'); }
    }
    
    // Assumes an endpoint `update_bank_balance.php` exists to handle these provisional AJAX updates
    $('#online-payment-rows').on('click', '.save-online-row', function() {
        const row = $(this).closest('.online-payment-row'), btn = $(this);
        const bankSelect = row.find('.online-bank-select'), bank_id = bankSelect.val();
        const bank_name = bankSelect.find('option:selected').text();
        const amount = parseFloat(row.find('.online-amount').val());
        const utr = row.find('.online-utr').val();

        if (!bank_id || !amount || amount <= 0 || !utr) { alert('Please fill all fields for the online payment row.'); return; }
        
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
        
        $.post('update_bank_balance.php', { action: 'add', bank_id, amount, utr })
            .done(data => {
                if (data.success) {
                    row.find('.online-bank-select, .online-amount, .online-utr').prop('disabled', true);
                    row.find('.online-bank-id-hidden').val(bank_id);
                    row.find('.online-bank-name-hidden').val(bank_name);
                    row.find('.online-amount-saved').val(amount);
                    row.find('.online-utr-hidden').val(utr);
                    row.find('.provisional-log-id').val(data.log_id);
                    btn.hide(); row.find('.remove-online-row').show();
                    updateClientSideBankBalance(bank_id, data.new_balance);
                    updateTotals();
                } else {
                    alert('Error: ' + data.message);
                    btn.prop('disabled', false).html('<i class="fas fa-check"></i>');
                }
            }).fail(() => {
                alert('A server error occurred. Ensure update_bank_balance.php exists.');
                btn.prop('disabled', false).html('<i class="fas fa-check"></i>');
            });
    });

    $('#online-payment-rows').on('click', '.remove-online-row', function() {
        if (!confirm('Are you sure you want to remove this entry? This will reverse the amount from the bank.')) return;
        
        const row = $(this).closest('.online-payment-row'), btn = $(this);
        const bank_id = row.find('.online-bank-id-hidden').val();
        const amount = row.find('.online-amount-saved').val();
        const log_id = row.find('.provisional-log-id').val();

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.post('update_bank_balance.php', { action: 'subtract', bank_id, amount, utr: `Reversal for log ${log_id}` })
            .done(data => {
                if (data.success) {
                    updateClientSideBankBalance(bank_id, data.new_balance);
                    row.remove();
                    updateTotals();
                } else {
                    alert('Error: ' + data.message);
                    btn.prop('disabled', false).html('<i class="fas fa-trash"></i>');
                }
            }).fail(() => {
                alert('A server error occurred.');
                btn.prop('disabled', false).html('<i class="fas fa-trash"></i>');
            });
    });

    function checkFormValidity() { $('#submitBtn').prop('disabled', !($('#transaction_type').val() && $('#customer_id').val() && $('#company_name').val())); }
    
    $('#company_name').on('change', function() { 
        let comm = parseFloat($(this).find('option:selected').data('commission')) || 0;
        $('#commission_percentage_input').val(comm.toFixed(2)).trigger('input');
    });

    $('#commission_percentage_input').on('input', function() {
        $('#commission_percentage_hidden').val($(this).val());
    });

    $('#denominationForm').on('input', '.cash-qty, #actual_paid_amount', updateTotals);
    $('#denominationForm').on('reset', function() { setTimeout(() => { $('#customer_id, #company_name, #deposit_bank_id, #transaction_type').val(null).trigger('change'); }, 0); });
    $('#transaction_type, #customer_id, #company_name').on('change', checkFormValidity);
});
</script>
</body>
</html>