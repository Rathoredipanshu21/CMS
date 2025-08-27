<?php
session_start();

// --- Security Check: Ensure user is logged in ---
if (!isset($_SESSION['branch_id'])) {
    die("Access Denied. You must be logged in to perform transactions.");
}

// --- DATABASE CONNECTION ---
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    $error = "Database configuration file not found.";
    $conn = null;
}

$message = '';
$error = '';

// --- Get the branch name of the currently logged-in user ---
$loggedInBranchName = 'N/A';
if ($conn) {
    $stmt_branch = $conn->prepare("SELECT branch_name FROM branch WHERE id = ?");
    if ($stmt_branch) {
        $stmt_branch->bind_param("i", $_SESSION['branch_id']);
        $stmt_branch->execute();
        $result_branch = $stmt_branch->get_result();
        if ($row_branch = $result_branch->fetch_assoc()) {
            $loggedInBranchName = $row_branch['branch_name'];
        }
        $stmt_branch->close();
    }
}

// --- FORM SUBMISSION HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id']) && $conn) {
    $conn->begin_transaction();
    try {
        // --- DATA SANITIZATION AND COLLECTION ---
        $customer_id = (int)$_POST['customer_id'];
        $company_name = $_POST['company_name'];
        $payment_modes = [];
        if (isset($_POST['is_cash_payment']) && (float)str_replace(',', '', $_POST['total_cash_amount']) > 0) $payment_modes[] = 'Cash';
        if (isset($_POST['is_online_payment']) && (float)str_replace(',', '', $_POST['total_online_amount']) > 0) $payment_modes[] = 'Online';
        $payment_mode_str = implode(' + ', $payment_modes);
        
        $grand_total = (float) str_replace(',', '', $_POST['grand_total']);
        $actual_paid_amount = (float) str_replace(',', '', $_POST['actual_paid_amount']);
        
        $commission_percentage = (float)$_POST['commission_percentage_hidden'];
        $commission_amount = $actual_paid_amount * ($commission_percentage / 100);

        $dues_amount = (float) str_replace(',', '', $_POST['dues_amount']);
        $advance_amount = (float) str_replace(',', '', $_POST['advance_amount']);

        $deposit_bank_id = !empty($_POST['deposit_bank_id']) ? (int)$_POST['deposit_bank_id'] : null;
        $bank_transaction_id = !empty($_POST['bank_transaction_id']) ? trim($_POST['bank_transaction_id']) : null;

        // --- INSERT INTO `transactions` TABLE with branch_name ---
        $stmt1 = $conn->prepare(
            "INSERT INTO transactions (customer_id, company_name, payment_mode, grand_total, actual_paid_amount, commission_amount, dues_amount, advance_amount, branch_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt1->bind_param(
            "issddddds", 
            $customer_id, 
            $company_name, 
            $payment_mode_str, 
            $grand_total,
            $actual_paid_amount,
            $commission_amount,
            $dues_amount,
            $advance_amount,
            $loggedInBranchName
        );
        $stmt1->execute();
        $transaction_id = $conn->insert_id;
        $stmt1->close();

        // --- INSERT INTO `transaction_details` TABLE ---
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

        // --- INSERT INTO `bank_deposits` TABLE ---
        if ($actual_paid_amount > 0 && $deposit_bank_id !== null) {
            $stmt_bank = $conn->prepare(
                "INSERT INTO bank_deposits (transaction_id, bank_id, amount, bank_transaction_id) VALUES (?, ?, ?, ?)"
            );
            $amount_for_deposit = $actual_paid_amount;
            $stmt_bank->bind_param("iids", $transaction_id, $deposit_bank_id, $amount_for_deposit, $bank_transaction_id);
            $stmt_bank->execute();
            $stmt_bank->close();
        }

        // --- UPDATE `customer_finances` TABLE for DUES/ADVANCES ---
        $stmt_finance = $conn->prepare(
            "INSERT INTO customer_finances (customer_id, dues_amount, advance_amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE dues_amount = dues_amount + VALUES(dues_amount), advance_amount = advance_amount + VALUES(advance_amount)"
        );
        $stmt_finance->bind_param("idd", $customer_id, $dues_amount, $advance_amount);
        $stmt_finance->execute();
        $stmt_finance->close();

        $conn->commit();
        $message = "Transaction saved successfully!";

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        $error = "Error saving transaction: " . $exception->getMessage();
    }
}

// --- DATA FOR FORM FIELDS ---
$customers = [];
$companies_data = [];
$banks = []; 
if ($conn) {
    // Fetch customers
    $customer_result = $conn->query("SELECT id, name, mobile_no FROM customers ORDER BY name ASC");
    if ($customer_result) {
        while($row = $customer_result->fetch_assoc()) { 
            $customers[] = $row; 
        }
    }
    // Fetch companies
    $company_result = $conn->query("SELECT `id`, `company_name`, `commission_percentage` FROM `company_commissions` ORDER BY `company_name` ASC");
    if($company_result) {
        while($row = $company_result->fetch_assoc()) {
            $companies_data[] = $row;
        }
    }
    // Fetch banks
    $banks_result = $conn->query("SELECT id, bank_name, account_balance FROM banks ORDER BY bank_name ASC");
    if($banks_result) {
        while($row = $banks_result->fetch_assoc()) {
            $banks[] = $row;
        }
    }
}

// Static data
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
    <title>Transaction Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .form-input, .form-select { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; transition: all 0.2s ease-in-out; background-color: #fff; }
        .form-input:focus, .form-select:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; }
        .btn { padding: 0.7rem 1.75rem; border-radius: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s ease; border: none; cursor: pointer; }
        .total-box { font-size: 1.25rem; font-weight: 700; padding: 0.75rem; border-radius: 0.5rem; text-align: center; }
        .note-img { width: 70px; height: 35px; object-fit: cover; border-radius: 4px; margin-right: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .payment-type-card { border: 2px solid #e5e7eb; border-radius: 0.75rem; padding: 1.5rem; text-align: center; cursor: pointer; transition: all 0.2s ease-in-out; }
        .payment-type-card.selected { border-color: #2563eb; background-color: #eff6ff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3); }
        .payment-section.disabled { opacity: 0.5; pointer-events: none; }
        .select2-container .select2-selection--single { height: 46px !important; border: 1px solid #d1d5db; border-radius: 0.5rem; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 44px; padding-left: 0.85rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto bg-white rounded-2xl shadow-lg p-6 sm:p-8">
    <form id="denominationForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
        
        <div class="text-center mb-8 border-b border-gray-200 pb-6">
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-800 tracking-tight"> Transaction Form</h1>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-lg" role="alert"><p><?php echo $message; ?></p></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg" role="alert"><p><?php echo $error; ?></p></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div>
                <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                <select id="customer_id" name="customer_id" class="form-select w-full" required>
                    <option value=""> Select a Customer </option>
                    <?php foreach($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['mobile_no'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Select Company</label>
                <select id="company_name" name="company_name" class="form-select w-full" required>
                    <option value="" data-commission="0"> Select Company </option>
                    <?php foreach($companies_data as $company): ?>
                        <option value="<?php echo htmlspecialchars($company['company_name']); ?>" data-commission="<?php echo $company['commission_percentage']; ?>">
                            <?php echo htmlspecialchars($company['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-8">
             <label class="block text-lg font-semibold text-gray-800 mb-3">Choose Payment Modes</label>
            <div id="payment-type-selector" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="payment-type-card" data-type="cash" tabindex="0">
                    <i class="fas fa-money-bill-wave text-4xl text-green-500 mb-3"></i>
                    <h3 class="text-xl font-bold">Cash Payment</h3>
                    <input type="checkbox" name="is_cash_payment" class="hidden">
                </div>
                <div class="payment-type-card" data-type="online" tabindex="0">
                    <i class="fas fa-mobile-alt text-4xl text-blue-500 mb-3"></i>
                    <h3 class="text-xl font-bold">Online Payment</h3>
                    <input type="checkbox" name="is_online_payment" class="hidden">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div id="cash-section" class="payment-section disabled bg-gray-50 p-5 rounded-lg border">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Cash Denomination</h2>
                <div class="space-y-3">
                    <?php foreach($cash_denominations as $value => $image_path): ?>
                    <div class="grid grid-cols-12 gap-3 items-center">
                        <div class="col-span-5 flex items-center"><img src="<?php echo $image_path; ?>" alt="<?php echo $value; ?> Rupee Note" class="note-img"><span class="font-semibold text-lg text-gray-600">₹ <?php echo $value; ?></span></div>
                        <div class="col-span-3"><input type="number" name="cash_qty[<?php echo $value; ?>]" class="form-input w-full text-center cash-qty" data-value="<?php echo $value; ?>" placeholder="Qty" min="0" disabled></div>
                        <div class="col-span-4"><input type="text" class="form-input w-full text-right bg-gray-200 cash-row-total" readonly value="0.00"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="online-section" class="payment-section disabled bg-gray-50 p-5 rounded-lg border">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Online Payment</h2>
                <div id="online-payment-rows" class="space-y-4"></div>
                <div class="mt-4"><button type="button" id="add-online-row" class="w-full text-blue-600 font-semibold py-2 px-4 border-2 border-dashed border-blue-400 rounded-lg hover:bg-blue-50 transition" disabled><i class="fas fa-plus-circle mr-2"></i> Add Online Payment</button></div>
            </div>
        </div>

        <div class="mt-8 pt-8 border-t-2 border-gray-200 space-y-6">
             <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div><label class="text-md font-semibold text-gray-700">Cash Total</label><input type="text" id="total_cash_amount_display" class="total-box bg-blue-100 text-blue-800 mt-1 w-full" value="0.00" readonly></div>
                <div><label class="text-md font-semibold text-gray-700">Online Total</label><input type="text" id="total_online_amount_display" class="total-box bg-green-100 text-green-800 mt-1 w-full" value="0.00" readonly></div>
            </div>
             <div class="bg-gray-100 p-4 rounded-lg">
                <label class="text-xl font-bold text-gray-800">Grand Total</label>
                <input type="text" id="grand_total_display" class="total-box bg-gray-200 text-gray-800 mt-2 w-full text-2xl" value="0.00" readonly>
            </div>
             <div class="bg-yellow-50 p-4 rounded-lg">
                <label for="actual_paid_amount" class="text-xl font-bold text-yellow-800">Actual Amount Paid by Customer</label>
                <input type="number" step="0.01" id="actual_paid_amount" name="actual_paid_amount" class="form-input w-full text-center text-2xl font-bold mt-2 border-yellow-400" placeholder="0.00">
            </div>
             <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div><label class="text-md font-semibold text-red-600">Dues Amount (-)</label><input type="text" id="dues_amount_display" class="total-box bg-red-100 text-red-800 mt-1 w-full" value="0.00" readonly></div>
                <div><label class="text-md font-semibold text-indigo-600">Advance Amount (+)</label><input type="text" id="advance_amount_display" class="total-box bg-indigo-100 text-indigo-800 mt-1 w-full" value="0.00" readonly></div>
            </div>
        </div>

            <hr class="my-8 border-gray-300 border-dashed">
            
            <div id="bank-transaction-section" class="disabled">
                <h2 class="text-2xl font-bold text-gray-800 text-center mb-4">Make Transaction</h2>
                <div class="max-w-5xl mx-auto bg-blue-50 p-6 rounded-lg border border-blue-200">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                        <div class="md:col-span-1">
                            <label for="deposit_bank_id" class="block text-sm font-medium text-gray-700 mb-1">Select Bank</label>
                            <select id="deposit_bank_id" name="deposit_bank_id" class="form-select w-full" disabled>
                                <option value=""> Select a Bank </option>
                                <?php foreach($banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $bank['account_balance']; ?>">
                                        <?php echo htmlspecialchars($bank['bank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Available Balance</label>
                            <input type="text" id="bank_balance_display" class="form-input w-full bg-gray-200 font-bold text-center" readonly value="0.00">
                        </div>
                        <div class="md:col-span-1">
                            <label for="bank_transaction_id" class="block text-sm font-medium text-gray-700 mb-1">Bank Txn ID (Optional)</label>
                            <input type="text" id="bank_transaction_id" name="bank_transaction_id" class="form-input w-full" placeholder="Enter deposit transaction ID" disabled>
                        </div>
                        <div class="md:col-span-3 grid grid-cols-2 gap-6 mt-4">
                            <div>
                                <label for="amount_to_deposit_display" class="block text-sm font-medium text-gray-700 mb-1">Amount for Deposit</label>
                                <input type="text" id="amount_to_deposit_display" class="form-input w-full bg-gray-200 font-bold text-center" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Commission (<span id="commission_rate_display">0.00</span>%)</label>
                                <input type="text" id="commission_amount_display" class="form-input w-full bg-gray-200 font-bold text-center" readonly>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-8 flex justify-center sm:justify-end space-x-4">
                <button type="submit" id="submitBtn" class="btn bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6" disabled><i class="fas fa-check-circle mr-2"></i>Submit Transaction</button>
                <button type="reset" class="btn bg-gray-500 hover:bg-gray-600 text-white font-bold py-3 px-6"><i class="fas fa-undo mr-2"></i>Clear Form</button>
            </div>
        </div>
        
        <input type="hidden" name="total_cash_amount" id="total_cash_amount" value="0">
        <input type="hidden" name="total_online_amount" id="total_online_amount" value="0">
        <input type="hidden" name="grand_total" id="grand_total" value="0">
        <input type="hidden" name="commission_amount" id="commission_amount" value="0">
        <input type="hidden" name="commission_percentage_hidden" id="commission_percentage_hidden" value="0">
        <input type="hidden" name="dues_amount" id="dues_amount" value="0">
        <input type="hidden" name="advance_amount" id="advance_amount" value="0">
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    let commissionPercentage = 0.00;
    $('#customer_id, #company_name, #deposit_bank_id').select2({ width: '100%' });

    function formatCurrency(num) {
        return parseFloat(num).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateTotals() {
        let cashTotal = 0;
        if ($('#cash-section').is(':not(.disabled)')) {
            $('.cash-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                let value = parseFloat($(this).data('value'));
                let rowTotal = qty * value;
                $(this).closest('.grid').find('.cash-row-total').val(formatCurrency(rowTotal));
                cashTotal += rowTotal;
            });
        }
        $('#total_cash_amount_display').val(formatCurrency(cashTotal));
        $('#total_cash_amount').val(cashTotal.toFixed(2));

        let onlineTotal = 0;
        if ($('#online-section').is(':not(.disabled)')) {
            $('.online-amount').each(function() {
                onlineTotal += parseFloat($(this).val()) || 0;
            });
        }
        $('#total_online_amount_display').val(formatCurrency(onlineTotal));
        $('#total_online_amount').val(onlineTotal.toFixed(2));

        let grandTotal = cashTotal + onlineTotal;
        $('#grand_total_display').val(formatCurrency(grandTotal));
        $('#grand_total').val(grandTotal.toFixed(2));

        let actualPaidAmount = parseFloat($('#actual_paid_amount').val()) || 0;
        
        // --- CORRECTED LOGIC FOR DUES AND ADVANCE ---
        let difference = actualPaidAmount - grandTotal;
        let duesAmount = 0;
        let advanceAmount = 0;

        if (difference < 0) { // Customer paid LESS than grand total
            duesAmount = -difference; // The amount still owed is Dues
        } else { // Customer paid MORE than or equal to grand total
            advanceAmount = difference; // The overpayment is Advance
        }

        $('#dues_amount_display').val(formatCurrency(duesAmount));
        $('#dues_amount').val(duesAmount.toFixed(2));
        $('#advance_amount_display').val(formatCurrency(advanceAmount));
        $('#advance_amount').val(advanceAmount.toFixed(2));

        let commissionAmount = actualPaidAmount * (commissionPercentage / 100);
        $('#commission_amount_display').val(formatCurrency(commissionAmount));
        $('#commission_amount').val(commissionAmount.toFixed(2));

        $('#amount_to_deposit_display').val(formatCurrency(actualPaidAmount));
        
        if (actualPaidAmount > 0) {
            $('#bank-transaction-section').removeClass('disabled');
            $('#deposit_bank_id').prop('disabled', false).prop('required', true);
            $('#bank_transaction_id').prop('disabled', false);
        } else {
            $('#bank-transaction-section').addClass('disabled');
            $('#deposit_bank_id, #bank_transaction_id').prop('disabled', true).prop('required', false).val('');
            $('#deposit_bank_id').trigger('change');
        }
        checkFormValidity();
    }

    function togglePaymentSection(type, isSelected) {
        const section = $(`#${type}-section`);
        const inputs = section.find(':input:not(button)');
        
        if (isSelected) {
            section.removeClass('disabled');
            inputs.prop('disabled', false);
            if (type === 'online' && $('#online-payment-rows').is(':empty')) {
                addOnlineRow();
            }
        } else {
            section.addClass('disabled');
            inputs.prop('disabled', true);
            if (type === 'cash') $('.cash-qty').val('');
            else if (type === 'online') $('#online-payment-rows').empty();
        }
        updateTotals();
    }

    function addOnlineRow() {
        const platforms = <?php echo json_encode($online_platforms); ?>;
        const options = platforms.map(p => `<option value="${p}">${p}</option>`).join('');
        const newRow = `
            <div class="grid grid-cols-12 gap-2 online-payment-row items-start p-2 bg-white rounded-md border">
                <div class="col-span-12 sm:col-span-4"><select name="online_platform[]" class="form-select w-full" required>${options}</select></div>
                <div class="col-span-12 sm:col-span-3"><input type="number" name="online_amount[]" class="form-input w-full online-amount" placeholder="Amount" step="0.01" required></div>
                <div class="col-span-10 sm:col-span-4"><input type="text" name="online_utr[]" class="form-input w-full" placeholder="UTR No." required></div>
                <div class="col-span-2 sm:col-span-1"><button type="button" class="remove-online-row text-red-500 h-full w-full flex items-center justify-center text-lg"><i class="fas fa-times-circle"></i></button></div>
            </div>`;
        $("#online-payment-rows").append(newRow).find("select").last().select2({ width: "100%" });
    }

    function checkFormValidity() {
        const hasCustomer = !!$('#customer_id').val();
        const hasCompany = !!$('#company_name').val();
        const actualPaid = parseFloat($('#actual_paid_amount').val()) || 0;
        let isBankDetailValid = (actualPaid > 0) ? !!$('#deposit_bank_id').val() : true;
        $('#submitBtn').prop('disabled', !(hasCustomer && hasCompany && actualPaid > 0 && isBankDetailValid));
    }
    
    $('#company_name').on('change', function() {
        commissionPercentage = parseFloat($(this).find('option:selected').data('commission')) || 0.00;
        $('#commission_rate_display').text(commissionPercentage.toFixed(2));
        $('#commission_percentage_hidden').val(commissionPercentage.toFixed(2));
        updateTotals();
    });

    $('#deposit_bank_id').on('change', function() {
        const balance = $(this).find('option:selected').data('balance') || 0;
        $('#bank_balance_display').val(formatCurrency(balance));
        checkFormValidity();
    });

    $('.payment-type-card').on('click keydown', function(e) {
        if (e.type === 'click' || e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).toggleClass('selected');
            const type = $(this).data('type');
            togglePaymentSection(type, $(this).hasClass('selected'));
        }
    });

    $('#denominationForm').on('input', '.cash-qty, .online-amount, #actual_paid_amount', updateTotals);
    $('#denominationForm').on('change', '#customer_id, #company_name', updateTotals);

    $('#add-online-row').on('click', addOnlineRow);
    $('#online-payment-rows').on('click', '.remove-online-row', function() {
        $(this).closest('.online-payment-row').remove();
        updateTotals();
    });

    $('#denominationForm').on('reset', function() {
        setTimeout(() => {
            $('#customer_id, #company_name, #deposit_bank_id').val(null).trigger('change');
            $('.payment-type-card').removeClass('selected');
            $('#online-payment-rows').empty();
            togglePaymentSection('cash', false);
            togglePaymentSection('online', false);
            $('#actual_paid_amount').val('');
            updateTotals();
        }, 0);
    });
    
    updateTotals();
});
</script>
</body>
</html>
