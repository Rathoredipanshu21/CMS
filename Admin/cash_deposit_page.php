<?php
session_start();

// --- Database Connection ---
// Ensure this path is correct for your project structure.
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    // Fallback for standalone testing, replace with your actual DB credentials if needed.
    $conn = new mysqli('localhost', 'root', '', 'your_database_name');
    if ($conn->connect_error) {
        $error = "Database connection failed: " . $conn->connect_error;
        $conn = null;
    }
}

$message = '';
$error = '';

// --- FORM SUBMISSION HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id']) && $conn) {
    $conn->begin_transaction();
    try {
        // 1. Get Form Inputs
        $customer_id = (int)$_POST['customer_id'];
        $company_name = $_POST['company_name'];
        $transaction_type = $_POST['transaction_type'];
        $deposit_bank_id = (int)$_POST['deposit_bank_id'];
        $cash_denominations = $_POST['cash_qty'] ?? [];

        // 2. Calculate Totals from Denominations
        $total_cash_amount = 0;
        $total_note_count = 0;
        foreach ($cash_denominations as $denomination => $qty) {
            if (!empty($qty) && (int)$qty > 0) {
                $quantity = (int)$qty;
                $total_cash_amount += (float)$denomination * $quantity;
                $total_note_count += $quantity;
            }
        }

        if ($total_cash_amount <= 0) {
            throw new Exception("Deposit amount must be greater than zero.");
        }
        if (empty($deposit_bank_id)) {
            throw new Exception("A bank must be selected for the deposit.");
        }

        // 3. Create Main Transaction Record
        $payment_mode_str = 'Cash';
        $stmt_main_trans = $conn->prepare("INSERT INTO transactions (customer_id, company_name, transaction_type, payment_mode, grand_total, actual_paid_amount) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_main_trans->bind_param("isssdd", $customer_id, $company_name, $transaction_type, $payment_mode_str, $total_cash_amount, $total_cash_amount);
        $stmt_main_trans->execute();
        $transaction_id = $conn->insert_id;
        $stmt_main_trans->close();

        // 4. Insert Denomination Details
        $stmt_details = $conn->prepare("INSERT INTO transaction_details (transaction_id, detail_type, denomination_or_platform, quantity_or_utr, amount) VALUES (?, ?, ?, ?, ?)");
        foreach ($cash_denominations as $denomination => $qty) {
            if (!empty($qty) && (int)$qty > 0) {
                $detail_type = 'cash';
                $amount = (float)$denomination * (int)$qty;
                $stmt_details->bind_param("isssd", $transaction_id, $detail_type, $denomination, $qty, $amount);
                $stmt_details->execute();
            }
        }
        $stmt_details->close();

        // 5. Update Bank Balance & History
        $stmt_get_balance = $conn->prepare("SELECT account_balance FROM banks WHERE id = ? FOR UPDATE");
        $stmt_get_balance->bind_param("i", $deposit_bank_id);
        $stmt_get_balance->execute();
        $result_balance = $stmt_get_balance->get_result();
        if($result_balance->num_rows === 0) throw new Exception("Selected bank not found.");
        $bank_data = $result_balance->fetch_assoc();
        $balance_before = (float)$bank_data['account_balance'];
        $stmt_get_balance->close();

        $balance_after = $balance_before + $total_cash_amount;

        $stmt_update_balance = $conn->prepare("UPDATE banks SET account_balance = ? WHERE id = ?");
        $stmt_update_balance->bind_param("di", $balance_after, $deposit_bank_id);
        $stmt_update_balance->execute();
        $stmt_update_balance->close();

        $stmt_history = $conn->prepare("INSERT INTO banks_transactions_history (bank_id, transaction_id, transaction_type, amount, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
        $transaction_type_history = 'deposit';
        $stmt_history->bind_param("iisddd", $deposit_bank_id, $transaction_id, $transaction_type_history, $total_cash_amount, $balance_before, $balance_after);
        $stmt_history->execute();
        $stmt_history->close();

        $conn->commit();
        $message = "₹" . number_format($total_cash_amount, 2) . " deposited successfully!";

    } catch (Exception $e) {
        $conn->rollback();
        $error = "Transaction failed: " . $e->getMessage();
    }
}

// --- DATA FETCHING FOR DROPDOWNS ---
$customers = [];
$companies_data = [];
$banks = [];
if ($conn) {
    $customer_result = $conn->query("SELECT id, name, mobile_no FROM customers ORDER BY name ASC");
    if ($customer_result) while($row = $customer_result->fetch_assoc()) $customers[] = $row;

    $company_result = $conn->query("SELECT id, company_name FROM company_commissions ORDER BY company_name ASC");
    if($company_result) while($row = $company_result->fetch_assoc()) $companies_data[] = $row;

    $banks_result = $conn->query("SELECT id, bank_name, account_balance FROM banks ORDER BY bank_name ASC");
    if($banks_result) while($row = $banks_result->fetch_assoc()) $banks[] = $row;
}

// Cash denominations and their image paths. Assumes an 'images' folder in the same directory.
$cash_denominations_data = [
    500 => '../images/500.png', 200 => '../images/200.png', 100 => '../images/100.png', 50 => '../images/50.png', 20 => '../images/20.png',
    10 => '../images/10.png', 5 => '../images/5.png', 2 => '../images/2.png', 1 => '../images/1.png'
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Deposit Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f9ff; }
        .form-input, .form-select { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; transition: all 0.2s ease-in-out; background-color: #fff; }
        .form-input:focus, .form-select:focus { border-color: #0891b2; box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.2); outline: none; }
        .btn { padding: 0.75rem 2rem; border-radius: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s ease; border: none; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .btn-primary { background-color: #06b6d4; color: white; }
        .btn-primary:hover { background-color: #0891b2; }
        .btn-primary:disabled { background-color: #9ca3af; cursor: not-allowed; }
        .select2-container .select2-selection--single { height: 46px !important; border-radius: 0.5rem; }
        .select2-container--default .select2-selection--single { border: 1px solid #d1d5db !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 44px; padding-left: 0.85rem; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; }
        .select2-container--open .select2-dropdown--below { border-color: #0891b2; }
        .note-img { width: 60px; height: 30px; object-fit: cover; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .denomination-box::-webkit-scrollbar { width: 8px; }
        .denomination-box::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .denomination-box::-webkit-scrollbar-thumb { background: #a7a7a7; border-radius: 10px; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-screen-xl mx-auto">
    <form id="depositForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
        <div class="text-center mb-8 border-b-2 border-gray-200 pb-6">
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-800 tracking-tight">
                <i class="fas fa-university text-cyan-600"></i> Cash Deposit Panel
            </h1>
        </div>

        <?php if (!empty($message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-800 p-4 mb-6 rounded-lg shadow-md" role="alert">
                <p class="font-bold"><i class="fas fa-check-circle mr-2"></i>Success</p>
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-800 p-4 mb-6 rounded-lg shadow-md" role="alert">
                <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Error</p>
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Column: Denominations -->
            <div class="lg:col-span-1 bg-white p-6 rounded-2xl shadow-lg">
                <h2 class="text-2xl font-bold text-gray-700 mb-5 text-center border-b pb-4">Cash Denominations</h2>
                <div class="space-y-4 max-h-[500px] overflow-y-auto pr-2 denomination-box">
                    <?php foreach($cash_denominations_data as $value => $image_path): ?>
                    <div class="grid grid-cols-12 gap-3 items-center">
                        <div class="col-span-5 flex items-center">
                            <img src="<?php echo $image_path; ?>" alt="<?php echo $value; ?> Rupee Note" class="note-img mr-3">
                            <span class="font-semibold text-gray-600 text-lg">₹ <?php echo $value; ?></span>
                        </div>
                        <div class="col-span-3">
                            <input type="number" name="cash_qty[<?php echo $value; ?>]" class="form-input w-full text-center cash-qty" data-value="<?php echo $value; ?>" placeholder="Qty" min="0">
                        </div>
                        <div class="col-span-4">
                            <input type="text" class="form-input w-full text-right bg-gray-100 cash-row-total" readonly value="0.00">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-6 pt-6 border-t-2 border-dashed">
                    <div class="space-y-4">
                        <div class="flex justify-between items-center bg-gray-100 p-3 rounded-lg">
                            <label class="text-md font-semibold text-gray-700">Total Notes</label>
                            <input type="text" id="total_notes_display" class="text-lg font-bold text-gray-800 text-right bg-transparent border-none p-0 focus:ring-0 w-24" value="0" readonly>
                        </div>
                        <div class="flex justify-between items-center bg-cyan-100 p-4 rounded-lg">
                            <label class="text-xl font-extrabold text-cyan-900">Total Amount</label>
                            <input type="text" id="total_amount_display" class="text-2xl font-extrabold text-cyan-900 text-right bg-transparent border-none p-0 focus:ring-0 w-40" value="0.00" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Transaction Details -->
            <div class="lg:col-span-2 bg-white p-6 rounded-2xl shadow-lg space-y-6">
                <h2 class="text-2xl font-bold text-gray-700 text-center border-b pb-4">Transaction Details</h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="customer_id" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-user mr-2 text-gray-400"></i>Customer Name</label>
                        <select id="customer_id" name="customer_id" class="form-select w-full" required>
                            <option value="">Select a Customer</option>
                            <?php foreach($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['mobile_no'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-building mr-2 text-gray-400"></i>Company</label>
                        <select id="company_name" name="company_name" class="form-select w-full" required>
                            <option value="">Select Company</option>
                            <?php foreach($companies_data as $company): ?>
                                <option value="<?php echo htmlspecialchars($company['company_name']); ?>"><?php echo htmlspecialchars($company['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label for="transaction_type" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-exchange-alt mr-2 text-gray-400"></i>Transaction Type</label>
                    <select id="transaction_type" name="transaction_type" class="form-select w-full" required>
                        <option value="Cash Deposit In Bank">Cash Deposit In Bank</option>
                        <option value="Cash Received">Cash Received</option>
                        <option value="Cash Payment">Cash Payment</option>
                    </select>
                </div>

                <div class="!mt-8 pt-6 border-t-2 border-dashed">
                     <h3 class="text-xl font-bold text-gray-700 text-center mb-4">Deposit Details</h3>
                     <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                        <div>
                            <label for="deposit_bank_id" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-landmark mr-2 text-gray-400"></i>Select Bank to Deposit</label>
                            <select id="deposit_bank_id" name="deposit_bank_id" class="form-select w-full" required>
                                <option value="">Select a Bank</option>
                                <?php foreach($banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>" data-balance="<?php echo $bank['account_balance']; ?>">
                                        <?php echo htmlspecialchars($bank['bank_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="bg-gray-50 p-4 rounded-lg space-y-3 border">
                             <div class="flex justify-between items-center">
                                <span class="font-semibold text-gray-600">Current Balance</span>
                                <span id="bank_balance_display" class="font-bold text-lg text-gray-800">0.00</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="font-semibold text-green-600">Deposit Amt (+)</span>
                                <span id="amount_to_deposit_display" class="font-bold text-lg text-green-600">0.00</span>
                            </div>
                            <hr>
                            <div class="flex justify-between items-center">
                                <span class="font-extrabold text-blue-700">New Balance</span>
                                <span id="bank_new_balance_display" class="font-extrabold text-xl text-blue-700">0.00</span>
                            </div>
                        </div>
                     </div>
                </div>

                <div class="mt-10 pt-6 flex justify-center">
                    <button type="submit" id="submitBtn" class="btn btn-primary w-full md:w-auto text-lg" disabled>
                        <i class="fas fa-check-circle mr-2"></i>Deposit Amount
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // --- INITIALIZATION ---
    $('#customer_id, #company_name, #deposit_bank_id, #transaction_type').select2({ width: '100%' });

    // --- HELPER & CALCULATION FUNCTIONS ---
    function formatCurrency(num) {
        return parseFloat(num).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateTotals() {
        let totalAmount = 0;
        let totalNotes = 0;
        
        $('.cash-qty').each(function() {
            let qty = parseInt($(this).val()) || 0;
            totalNotes += qty;
            let value = parseFloat($(this).data('value'));
            let rowTotal = qty * value;
            $(this).closest('.grid').find('.cash-row-total').val(formatCurrency(rowTotal));
            totalAmount += rowTotal;
        });

        $('#total_notes_display').val(totalNotes);
        $('#total_amount_display').val(formatCurrency(totalAmount));
        
        // Update deposit information display
        $('#amount_to_deposit_display').text(formatCurrency(totalAmount));
        $('#deposit_bank_id').trigger('change'); // Trigger change to update new balance

        checkFormValidity();
    }
    
    function checkFormValidity() {
        const hasCustomer = !!$('#customer_id').val();
        const hasCompany = !!$('#company_name').val();
        const hasBank = !!$('#deposit_bank_id').val();
        const totalAmount = parseFloat($('#total_amount_display').val().replace(/,/g, '')) || 0;

        const isValid = hasCustomer && hasCompany && hasBank && totalAmount > 0;
        $('#submitBtn').prop('disabled', !isValid);
    }

    // --- EVENT LISTENERS ---
    $('#depositForm').on('input', '.cash-qty', updateTotals);

    $('#deposit_bank_id').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const balance = parseFloat(selectedOption.data('balance')) || 0;
        const depositAmount = parseFloat($('#total_amount_display').val().replace(/,/g, '')) || 0;
        const newBalance = balance + depositAmount;

        $('#bank_balance_display').text(formatCurrency(balance));
        $('#bank_new_balance_display').text(formatCurrency(newBalance));
        checkFormValidity();
    });
    
    $('#customer_id, #company_name').on('change', checkFormValidity);

    $('#depositForm').on('reset', function() {
        setTimeout(() => {
            $('#customer_id, #company_name, #deposit_bank_id, #transaction_type').val(null).trigger('change');
            updateTotals();
        }, 0);
    });

    // Initial check
    updateTotals();
});
</script>

</body>
</html>
