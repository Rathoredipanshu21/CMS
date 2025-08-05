<?php
session_start();

// --- DATABASE CONNECTION ---
// Ensure this file contains your database connection logic (e.g., $conn = new mysqli(...)
if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    // Fallback if the include fails or for environments without it
    $error = "Database configuration file not found. The form will not save data or load dynamic content.";
    $conn = null;
}

$message = '';
$error = '';

// --- FORM SUBMISSION HANDLING ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['customer_id']) && $conn) {
    $conn->begin_transaction();
    try {
        // --- DATA SANITIZATION AND COLLECTION ---
        $customer_id = (int)$_POST['customer_id'];
        $company_name = $_POST['company_name'];
        // Payment mode is now dynamic based on what is submitted
        $payment_modes = [];
        if (isset($_POST['is_cash_payment']) && (float)str_replace(',', '', $_POST['total_cash_amount']) > 0) $payment_modes[] = 'Cash';
        if (isset($_POST['is_online_payment']) && (float)str_replace(',', '', $_POST['total_online_amount']) > 0) $payment_modes[] = 'Online';
        $payment_mode_str = implode(' + ', $payment_modes);
        
        $total_cash_amount = (float) str_replace(',', '', $_POST['total_cash_amount']);
        $total_online_amount = (float) str_replace(',', '', $_POST['total_online_amount']);
        
        $grand_total = (float) str_replace(',', '', $_POST['grand_total']);
        $commission_amount = (float) str_replace(',', '', $_POST['commission_amount']);
        $dues_amount = (float) str_replace(',', '', $_POST['dues_amount']);
        $final_payable_amount = (float) str_replace(',', '', $_POST['final_payable_amount']);

        // --- INSERT INTO `transactions` TABLE ---
        $stmt1 = $conn->prepare(
            "INSERT INTO transactions (customer_id, company_name, payment_mode, total_cash_amount, total_online_amount, grand_total, commission_amount, dues_amount, final_payable_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt1->bind_param(
            "issdddddd", 
            $customer_id, 
            $company_name, 
            $payment_mode_str, 
            $total_cash_amount, 
            $total_online_amount, 
            $grand_total, 
            $commission_amount, 
            $dues_amount, 
            $final_payable_amount
        );
        $stmt1->execute();
        $transaction_id = $conn->insert_id;

        // --- INSERT INTO `transaction_details` TABLE ---
        $stmt2 = $conn->prepare("INSERT INTO transaction_details (transaction_id, detail_type, denomination_or_platform, quantity_or_utr, amount) VALUES (?, ?, ?, ?, ?)");

        // Process cash details if cash payment was made
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

        // Process online details if online payment was made
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

        $conn->commit();
        $message = "Transaction saved successfully!";

    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        $error = "Error saving transaction: " . $exception->getMessage();
    } finally {
        if(isset($stmt1)) $stmt1->close();
        if(isset($stmt2)) $stmt2->close();
    }
}

// --- DATA FOR FORM FIELDS ---
$customers = [];
$companies_data = []; // Will hold both name and commission
if ($conn) {
    // Fetch customers
    $customer_result = $conn->query("SELECT id, name, mobile_no FROM customers ORDER BY name ASC");
    if ($customer_result) {
        while($row = $customer_result->fetch_assoc()) { 
            $customers[] = $row; 
        }
    }
    // Fetch companies and their commission percentages from the new table
    $company_result = $conn->query("SELECT `id`, `company_name`, `commission_percentage` FROM `company_commissions` ORDER BY `company_name` ASC");
    if($company_result) {
        while($row = $company_result->fetch_assoc()) {
            $companies_data[] = $row;
        }
    }
    // No need to close connection here if it's used later in an API call
}

// Static data for form elements
$cash_denominations = [
    500 => 'images/500.png', 200 => 'images/200.png', 100 => 'images/100.png', 50 => 'images/50.png', 20 => 'images/20.png',
    10 => 'images/10.png', 5 => 'images/5.png', 2 => 'images/2.png', 1 => 'images/1.png'
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
        .utr-input-wrapper { position: relative; }
        .utr-spinner { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); display: none; }
        .utr-input.is-invalid { border-color: #ef4444; background-color: #fee2e2; }
        .utr-input.is-invalid:focus { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.3); }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 2rem; border-radius: 0.75rem; max-width: 500px; animation: slide-down 0.3s ease-out; }
        @keyframes slide-down { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
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
                    <option value="">-- Select a Customer --</option>
                    <?php foreach($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name'] . ' (' . $customer['mobile_no'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Select Company</label>
                <select id="company_name" name="company_name" class="form-select w-full" required>
                    <option value="" data-commission="0">-- Select Company --</option>
                    <?php foreach($companies_data as $company): ?>
                        <option value="<?php echo htmlspecialchars($company['company_name']); ?>" data-commission="<?php echo $company['commission_percentage']; ?>">
                            <?php echo htmlspecialchars($company['company_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-8">
             <label class="block text-lg font-semibold text-gray-800 mb-3">Choose Payment Modes (You can select both)</label>
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
                        <div class="col-span-5 flex items-center">
                            <img src="<?php echo $image_path; ?>" alt="<?php echo $value; ?> Rupee Note" class="note-img" onerror="this.style.display='none'">
                            <span class="font-semibold text-lg text-gray-600">₹ <?php echo $value; ?></span>
                        </div>
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

        <div class="mt-8 pt-8 border-t-2 border-gray-200">
             <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 items-end">
                <div class="flex flex-col"><label class="text-md font-semibold text-gray-700">Cash Total</label><input type="text" id="total_cash_amount_display" class="total-box bg-blue-100 text-blue-800 mt-1" value="0.00" readonly></div>
                <div class="flex flex-col"><label class="text-md font-semibold text-gray-700">Online Total</label><input type="text" id="total_online_amount_display" class="total-box bg-green-100 text-green-800 mt-1" value="0.00" readonly></div>
                <div class="flex flex-col"><label class="text-md font-semibold text-gray-700">Grand Total</label><input type="text" id="grand_total_display" class="total-box bg-gray-200 text-gray-800 mt-1" value="0.00" readonly></div>
                <div class="flex flex-col"><label for="dues_amount" class="text-md font-semibold text-gray-700">Previous Dues</label><input type="number" step="0.01" id="dues_amount" name="dues_amount" class="form-input w-full text-center text-xl font-bold mt-1" placeholder="0.00"></div>
                <div class="flex flex-col">
                    <label class="text-md font-semibold text-red-600">Admin Commission (<span id="commission_rate_display">0.00</span>%)</label>
                    <input type="text" id="commission_amount_display" class="total-box bg-red-100 text-red-800 mt-1" value="0.00" readonly>
                </div>
            </div>
            <div class="mt-6 bg-gray-100 p-6 rounded-lg text-center">
                <label class="text-2xl font-bold text-gray-800">Final Payable Amount</label>
                <input type="text" id="final_payable_amount_display" class="total-box bg-purple-100 text-purple-800 mt-2 w-full max-w-md mx-auto text-3xl" value="0.00" readonly>
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
        <input type="hidden" name="final_payable_amount" id="final_payable_amount" value="0">
    </form>
</div>

<div id="utrExistsModal" class="modal">
    <div class="modal-content">
         <div class="flex justify-between items-center pb-4 border-b">
            <h2 class="text-2xl font-bold text-red-600"><i class="fas fa-exclamation-triangle mr-3"></i>UTR Already Exists</h2>
            <button id="closeModalBtn" class="text-gray-400 hover:text-gray-700 text-2xl">&times;</button>
        </div>
        <div class="mt-5 space-y-3">
            <p class="text-gray-600">The UTR number <strong id="modal-utr" class="text-gray-800"></strong> is already associated with a transaction:</p>
            <div class="bg-gray-50 p-4 rounded-lg border text-sm space-y-2">
                <p><strong>Transaction ID:</strong> <span id="modal-tx-id"></span></p>
                <p><strong>Customer:</strong> <span id="modal-customer-name"></span> (<span id="modal-customer-mobile"></span>)</p>
                <p><strong>Amount:</strong> ₹<span id="modal-amount"></span></p>
                <p><strong>Date:</strong> <span id="modal-date"></span></p>
            </div>
            <p class="text-sm text-red-700 font-semibold mt-4">Please correct the UTR number before submitting.</p>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // --- INITIALIZATION ---
    let commissionPercentage = 0.00;
    $('#customer_id, #company_name').select2({ width: '100%' });

    // --- HELPER & CALCULATION FUNCTIONS ---
    function formatCurrency(num) {
        return parseFloat(num).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateTotals() {
        // Calculate cash total only if cash section is active
        let cashTotal = 0;
        if ($('#cash-section').is(':not(.disabled)')) {
            $('.cash-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                let value = parseFloat($(this).data('value'));
                let rowTotal = qty * value;
                cashTotal += rowTotal;
                $(this).closest('.grid').find('.cash-row-total').val(formatCurrency(rowTotal));
            });
        }
        $('#total_cash_amount_display').val(formatCurrency(cashTotal));
        $('#total_cash_amount').val(cashTotal.toFixed(2));

        // Calculate online total only if online section is active
        let onlineTotal = 0;
        if ($('#online-section').is(':not(.disabled)')) {
            $('.online-amount').each(function() {
                onlineTotal += parseFloat($(this).val()) || 0;
            });
        }
        $('#total_online_amount_display').val(formatCurrency(onlineTotal));
        $('#total_online_amount').val(onlineTotal.toFixed(2));

        // Calculate grand total and other financials
        let grandTotal = cashTotal + onlineTotal;
        $('#grand_total_display').val(formatCurrency(grandTotal));
        $('#grand_total').val(grandTotal.toFixed(2));

        let commissionAmount = grandTotal * commissionPercentage / 100;
        $('#commission_amount_display').val(formatCurrency(commissionAmount));
        $('#commission_amount').val(commissionAmount.toFixed(2));

        let duesAmount = parseFloat($('#dues_amount').val()) || 0;
        let finalPayableAmount = grandTotal + duesAmount; // Commission is for tracking, not added to payable
        $('#final_payable_amount_display').val(formatCurrency(finalPayableAmount));
        $('#final_payable_amount').val(finalPayableAmount.toFixed(2));
        
        checkFormValidity();
    }

    // --- UI/UX LOGIC ---
    function togglePaymentSection(type, isSelected) {
        const section = $(`#${type}-section`);
        const inputs = section.find(':input:not(button)');
        
        if (isSelected) {
            section.removeClass('disabled');
            inputs.prop('disabled', false);
            if (type === 'online') {
                 $('#add-online-row').prop('disabled', false);
                 if ($('#online-payment-rows').is(':empty')) {
                    addOnlineRow();
                 }
            }
        } else {
            section.addClass('disabled');
            inputs.prop('disabled', true);
            if (type === 'cash') {
                $('.cash-qty').val('');
            } else if (type === 'online') {
                $('#online-payment-rows').empty();
                $('#add-online-row').prop('disabled', true);
            }
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
                <div class="col-span-10 sm:col-span-4 utr-input-wrapper">
                    <input type="text" name="online_utr[]" class="form-input w-full utr-input" placeholder="UTR No." required>
                    <i class="fas fa-spinner fa-spin utr-spinner"></i>
                </div>
                <div class="col-span-2 sm:col-span-1"><button type="button" class="remove-online-row text-red-500 h-full w-full flex items-center justify-center text-lg"><i class="fas fa-times-circle"></i></button></div>
            </div>`;
        $("#online-payment-rows").append(newRow).find("select").last().select2({ width: "100%" });
    }
    
    let utrCheckRequest = null;
    function checkUtr(inputElement) {
        const input = $(inputElement);
        const utr = input.val().trim();
        const wrapper = input.closest('.utr-input-wrapper');
        const spinner = wrapper.find('.utr-spinner');
        
        input.removeClass('is-invalid');
        if (utr.length < 5) {
            checkFormValidity();
            return;
        }

        if (utrCheckRequest) utrCheckRequest.abort();
        spinner.show();
        
        utrCheckRequest = $.ajax({
            url: "check_utr.php", // Make sure this PHP file exists for checking UTR
            type: "POST",
            data: { utr: utr },
            dataType: "json",
            success: function(response) {
                if (response && response.exists) {
                    input.addClass('is-invalid');
                    $('#modal-utr').text(input.val());
                    $('#modal-tx-id').text(response.details.transaction_id);
                    $('#modal-customer-name').text(response.details.customer_name);
                    $('#modal-customer-mobile').text(response.details.customer_mobile);
                    $('#modal-amount').text(formatCurrency(response.details.amount));
                    $('#modal-date').text(response.details.transaction_date);
                    $('#utrExistsModal').fadeIn(200);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                if (textStatus !== 'abort') {
                    console.error("UTR check failed:", errorThrown);
                }
            },
            complete: function() {
                spinner.hide();
                checkFormValidity();
            }
        });
    }

    function checkFormValidity() {
        const isCashSelected = $('#cash-section').is(':not(.disabled)');
        const isOnlineSelected = $('#online-section').is(':not(.disabled)');
        const hasPaymentMethod = isCashSelected || isOnlineSelected;
        const hasInvalidUtr = $('.utr-input.is-invalid').length > 0;
        
        const cashTotal = parseFloat($('#total_cash_amount').val()) || 0;
        const onlineTotal = parseFloat($('#total_online_amount').val()) || 0;
        
        let isTotalValid = true;
        if(isCashSelected && !isOnlineSelected && cashTotal <= 0) isTotalValid = false;
        if(!isCashSelected && isOnlineSelected && onlineTotal <= 0) isTotalValid = false;
        if(isCashSelected && isOnlineSelected && (cashTotal + onlineTotal) <= 0) isTotalValid = false;

        if (hasPaymentMethod && !hasInvalidUtr && isTotalValid) {
            $('#submitBtn').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
        } else {
            $('#submitBtn').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
        }
    }
    
    // --- EVENT LISTENERS ---
    $('#company_name').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        commissionPercentage = parseFloat(selectedOption.data('commission')) || 0.00;
        $('#commission_rate_display').text(commissionPercentage.toFixed(2));
        updateTotals();
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

    $('#denominationForm').on('input', '.cash-qty, .online-amount, #dues_amount', updateTotals);
    $('#add-online-row').on('click', addOnlineRow);
    $('#online-payment-rows').on('click', '.remove-online-row', function() {
        $(this).closest('.online-payment-row').remove();
        updateTotals();
    });
    $('#online-payment-rows').on('blur', '.utr-input', function() { checkUtr(this); });

    $('#denominationForm').on('submit', function(e) {
        const cashSelected = $('#cash-section').is(':not(.disabled)');
        const onlineSelected = $('#online-section').is(':not(.disabled)');
        
        if (!cashSelected && !onlineSelected) {
            alert('Please select at least one payment mode (Cash or Online).');
            e.preventDefault();
            return;
        }
        
        if ($('.utr-input.is-invalid').length > 0) {
            alert('A duplicate UTR was found. Please correct it before submitting.');
            e.preventDefault();
            return;
        }

        const cashTotal = parseFloat($('#total_cash_amount').val()) || 0;
        const onlineTotal = parseFloat($('#total_online_amount').val()) || 0;

        if ((cashSelected && cashTotal <= 0) && (onlineSelected && onlineTotal <= 0)) {
             alert('The total for the selected payment mode(s) cannot be zero.');
             e.preventDefault();
        } else if (cashSelected && !onlineSelected && cashTotal <= 0) {
            alert('Cash total cannot be zero when it is the only payment mode selected.');
            e.preventDefault();
        } else if (!cashSelected && onlineSelected && onlineTotal <= 0) {
            alert('Online total cannot be zero when it is the only payment mode selected.');
            e.preventDefault();
        }
    });

    $('#denominationForm').on('reset', function() {
        setTimeout(() => {
            $('#customer_id, #company_name').val(null).trigger('change');
            $('.payment-type-card').removeClass('selected');
            $('#online-payment-rows').empty();
            togglePaymentSection('cash', false);
            togglePaymentSection('online', false);
            updateTotals();
            checkFormValidity();
        }, 0);
    });

    $('#closeModalBtn, .modal').on('click', function(e) {
        if (e.target === this || $(e.target).is('#closeModalBtn') || $(e.target).is('.fa-times')) {
            $('#utrExistsModal').fadeOut(200);
        }
    });

    // --- INITIAL STATE ---
    updateTotals();
});
</script>
</body>
</html>