<?php
// --- FORM SUBMISSION LOGIC ---
session_start();
$message = '';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include 'config/db.php';

    // Begin a transaction
    $conn->begin_transaction();

    try {
        // --- Get Main Form Data ---
        $party_name = $_POST['party_name'];
        $company_name = $_POST['company_name'];
        $payment_mode = $_POST['payment_mode'];
        $total_cash_amount = (float) str_replace(',', '', $_POST['total_cash_amount']);
        $total_online_amount = (float) str_replace(',', '', $_POST['total_online_amount']);
        $grand_total = (float) str_replace(',', '', $_POST['grand_total']);

        // MODIFICATION: Get the manually entered dues and the final calculated amount
        $dues_amount = (float) str_replace(',', '', $_POST['dues_amount']);
        $final_payable_amount = (float) str_replace(',', '', $_POST['final_payable_amount']);


        // MODIFICATION: Updated INSERT statement to match new database structure
        $stmt1 = $conn->prepare("INSERT INTO transactions (party_name, company_name, payment_mode, total_cash_amount, total_online_amount, grand_total, dues_amount, final_payable_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt1->bind_param("sssddddd", $party_name, $company_name, $payment_mode, $total_cash_amount, $total_online_amount, $grand_total, $dues_amount, $final_payable_amount);
        $stmt1->execute();
        $transaction_id = $conn->insert_id; // Get the ID of the transaction we just inserted

        // --- Insert into `transaction_details` table ---
        $stmt2 = $conn->prepare("INSERT INTO transaction_details (transaction_id, detail_type, denomination_or_platform, quantity_or_utr, amount) VALUES (?, ?, ?, ?, ?)");

        // Insert cash denominations
        if (isset($_POST['cash_denomination']) && isset($_POST['cash_qty'])) {
            foreach ($_POST['cash_qty'] as $denomination => $qty) {
                if (!empty($qty) && $qty > 0) {
                    $detail_type = 'cash';
                    $amount = (float) $denomination * (int) $qty;
                    $stmt2->bind_param("isssd", $transaction_id, $detail_type, $denomination, $qty, $amount);
                    $stmt2->execute();
                }
            }
        }

        // Insert online payments
        if (isset($_POST['online_platform']) && isset($_POST['online_amount'])) {
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

        // If everything is successful, commit the transaction
        $conn->commit();
        $message = "Transaction saved successfully! Transaction ID: " . $transaction_id;

    } catch (mysqli_sql_exception $exception) {
        // If anything fails, roll back the transaction
        $conn->rollback();
        $error = "Error saving transaction: " . $exception->getMessage();
    }

    $stmt1->close();
    $stmt2->close();
    $conn->close();
}

// Data for form fields
$cash_denominations = [
    500 => 'images/500.png', 200 => 'images/200.png', 100 => 'images/100.png', 50 => 'images/50.png', 20 => 'images/20.png',
    10 => 'images/10.png', 5 => 'images/5.png', 2 => 'images/2.png', 1 => 'images/1.png'
];
$online_platforms = ['Google Pay', 'PhonePe', 'Paytm', 'Amazon Pay', 'Bhim UPI', 'Airtel Bank', 'HDFC Bank', 'SBI Bank', 'Bank Of India', 'Axis Bank', 'Other'];
$companies = ['Svatantra Microfin Private Limited', 'HDFC Bank Cash Deposit', 'Kotak Mahindra Bank Ltd', 'Asirvad API', 'Chaitanya NFI', 'Unity Small Finance Bank', 'Tata Capital Limited', 'Cholamandalam Finance', 'Vellita Credit Capital Ltd', 'Bank of India BC', 'DBCE Private Limited'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Denomination Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f0f4f8; }
        .form-input, .form-select { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.5rem 0.75rem; transition: all 0.2s ease-in-out; }
        .form-input:focus, .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.4); outline: none; }
        .btn { padding: 0.6rem 1.5rem; border-radius: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s ease; border: none; cursor: pointer; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1); }
        .btn-submit { background-color: #10b981; } .btn-submit:hover { background-color: #059669; }
        .btn-clear { background-color: #3b82f6; } .btn-clear:hover { background-color: #2563eb; }
        .btn-cancel { background-color: #ef4444; } .btn-cancel:hover { background-color: #dc2626; }
        .total-box { font-size: 1.25rem; font-weight: 700; padding: 0.75rem; border-radius: 0.5rem; text-align: center; }
        .note-img { width: 80px; height: 40px; object-fit: cover; border-radius: 4px; margin-right: 1rem; }
        .utr-error { color: #ef4444; font-size: 0.8rem; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto bg-white rounded-2xl shadow-xl p-6">
    <form id="denominationForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">

        <div class="text-center mb-6 border-b pb-4">
            <h1 class="text-3xl font-bold text-gray-800">Cash Denomination Form</h1>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded" role="alert"><p><?php echo $message; ?></p></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded" role="alert"><p><?php echo $error; ?></p></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div>
                <label for="party_name" class="block text-sm font-medium text-gray-700 mb-1">Party Name</label>
                <input type="text" id="party_name" name="party_name" class="form-input w-full" required>
            </div>
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Select Company</label>
                <select id="company_name" name="company_name" class="form-select w-full" required>
                    <option value="">-- Select --</option>
                    <?php foreach($companies as $company): ?>
                        <option value="<?php echo $company; ?>"><?php echo $company; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="payment_mode" class="block text-sm font-medium text-gray-700 mb-1">Type Mode</label>
                <select id="payment_mode" name="payment_mode" class="form-select w-full" required>
                    <option value="Online Payment">Online Payment</option>
                    <option value="Cash Payment">Cash Payment</option>
                    <option value="Cash Received">Cash Received</option>
                    <option value="Cash Deposit In Bank">Cash Deposit In Bank</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="bg-gray-50 p-4 rounded-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Cash Denomination</h2>
                <div class="space-y-2">
                    <?php foreach($cash_denominations as $value => $image_path): ?>
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-4 flex items-center">
                            <img src="<?php echo $image_path; ?>" alt="<?php echo $value; ?> Rupee Note" class="note-img">
                            <span class="font-semibold text-lg text-gray-600">₹</span>
                        </div>
                        <div class="col-span-4">
                            <input type="number" name="cash_qty[<?php echo $value; ?>]" class="form-input w-full text-center cash-qty" data-value="<?php echo $value; ?>" placeholder="Qty" min="0">
                        </div>
                        <div class="col-span-4">
                            <input type="text" class="form-input w-full text-right bg-gray-200 cash-row-total" readonly value="0.00">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 pt-4 border-t flex justify-between items-center">
                    <span class="text-lg font-bold text-gray-800">Cash Amount Total:</span>
                    <input type="text" id="total_cash_amount_display" class="total-box bg-blue-100 text-blue-800 w-48" value="0.00" readonly>
                    <input type="hidden" name="total_cash_amount" id="total_cash_amount" value="0">
                </div>
            </div>

            <div class="bg-gray-50 p-4 rounded-lg">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 text-center">Online Payment</h2>
                <div id="online-payment-rows" class="space-y-3">
                    </div>
                <div class="mt-3">
                    <button type="button" id="add-online-row" class="w-full text-blue-600 font-semibold py-2 px-4 border-2 border-dashed border-blue-400 rounded-lg hover:bg-blue-50 transition">
                        <i class="fas fa-plus-circle mr-2"></i> Add Online Payment
                    </button>
                </div>
                <div class="mt-4 pt-4 border-t flex justify-between items-center">
                    <span class="text-lg font-bold text-gray-800">Online Amount Total:</span>
                    <input type="text" id="total_online_amount_display" class="total-box bg-green-100 text-green-800 w-48" value="0.00" readonly>
                    <input type="hidden" name="total_online_amount" id="total_online_amount" value="0">
                </div>
            </div>
        </div>

        <div class="mt-8 pt-6 border-t-2 border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-center">
                <div class="flex flex-col items-center">
                    <label class="text-lg font-semibold text-gray-700">Grand Total</label>
                    <input type="text" id="grand_total_display" class="total-box bg-gray-200 text-gray-800 mt-2 w-full" value="0.00" readonly>
                    <input type="hidden" name="grand_total" id="grand_total" value="0">
                </div>
                <div class="flex flex-col items-center">
                    <label for="dues_amount" class="text-lg font-semibold text-gray-700">Add Previous Dues</label>
                    <input type="number" step="0.01" id="dues_amount" name="dues_amount" class="form-input w-full text-center text-xl font-bold mt-2" placeholder="0.00">
                </div>
                <div class="flex flex-col items-center">
                    <label class="text-lg font-semibold text-gray-700">Final Payable Amount</label>
                    <input type="text" id="final_payable_amount_display" class="total-box bg-purple-100 text-purple-800 mt-2 w-full" value="0.00" readonly>
                    <input type="hidden" name="final_payable_amount" id="final_payable_amount" value="0">
                </div>
            </div>
            <div class="mt-8 flex justify-end space-x-4">
                <button type="submit" id="submitBtn" class="btn btn-submit"><i class="fas fa-check mr-2"></i>Submit</button>
                <button type="reset" class="btn btn-clear"><i class="fas fa-undo mr-2"></i>Clear</button>
                <button type="button" class="btn btn-cancel" onclick="window.history.back();"><i class="fas fa-times mr-2"></i>Cancel</button>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    $(document).ready(function() {

        // Function to format numbers as Indian currency
        function formatCurrency(num) {
            return parseFloat(num).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // --- MODIFICATION: Core Calculation Function updated with new logic ---
        function updateTotals() {
            let cashTotal = 0;
            $('.cash-qty').each(function() {
                let qty = parseInt($(this).val()) || 0;
                let value = parseFloat($(this).data('value'));
                let rowTotal = qty * value;
                cashTotal += rowTotal;
                $(this).closest('.grid').find('.cash-row-total').val(formatCurrency(rowTotal));
            });
            $('#total_cash_amount_display').val(formatCurrency(cashTotal));
            $('#total_cash_amount').val(cashTotal.toFixed(2));

            let onlineTotal = 0;
            $('.online-amount').each(function() {
                onlineTotal += parseFloat($(this).val()) || 0;
            });
            $('#total_online_amount_display').val(formatCurrency(onlineTotal));
            $('#total_online_amount').val(onlineTotal.toFixed(2));

            let grandTotal = cashTotal + onlineTotal;
            $('#grand_total_display').val(formatCurrency(grandTotal));
            $('#grand_total').val(grandTotal.toFixed(2));
            
            // New calculation for Final Amount
            let duesAmount = parseFloat($('#dues_amount').val()) || 0;
            let finalPayableAmount = grandTotal + duesAmount; // Add dues to the grand total
            $('#final_payable_amount_display').val(formatCurrency(finalPayableAmount));
            $('#final_payable_amount').val(finalPayableAmount.toFixed(2));
        }

        // --- MODIFICATION: Event Handlers updated for the new #dues_amount input ---
        $('#denominationForm').on('input', '.cash-qty, .online-amount, #dues_amount', updateTotals);

        // Add a new row for online payment
        $('#add-online-row').on('click', function() {
            let platforms = <?php echo json_encode($online_platforms); ?>;
            let options = platforms.map(p => `<option value="${p}">${p}</option>`).join('');

            let newRow = `
                <div class="grid grid-cols-12 gap-2 online-payment-row items-start">
                    <div class="col-span-4">
                        <select name="online_platform[]" class="form-select w-full" required>${options}</select>
                    </div>
                    <div class="col-span-3">
                        <input type="number" name="online_amount[]" class="form-input w-full online-amount" placeholder="Amount" step="0.01" required>
                    </div>
                    <div class="col-span-4">
                        <input type="text" name="online_utr[]" class="form-input w-full online-utr" placeholder="UTR No" required>
                         <div class="utr-error-message text-red-500 text-xs mt-1"></div>
                    </div>
                    <div class="col-span-1">
                        <button type="button" class="remove-online-row text-red-500 hover:text-red-700 h-full w-full flex items-center justify-center">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>`;
            $('#online-payment-rows').append(newRow);
        });

        // Remove an online payment row
        $('#online-payment-rows').on('click', '.remove-online-row', function() {
            $(this).closest('.online-payment-row').remove();
            updateTotals();
        });
        
        // Check UTR for uniqueness
        $('#online-payment-rows').on('blur', '.online-utr', function() {
            let utrInput = $(this);
            let utrValue = utrInput.val().trim();
            let errorMessageDiv = utrInput.next('.utr-error-message');

            if (utrValue === '') {
                errorMessageDiv.text('');
                $('#submitBtn').prop('disabled', false);
                return;
            }

            $.ajax({
                url: 'check_utr.php',
                type: 'POST',
                dataType: 'json',
                data: { utr: utrValue },
                success: function(response) {
                    if (response.exists) {
                        errorMessageDiv.text('This UTR already exists!');
                        utrInput.addClass('border-red-500');
                        $('#submitBtn').prop('disabled', true);
                    } else {
                        errorMessageDiv.text('');
                        utrInput.removeClass('border-red-500');
                        // Check other UTR fields before enabling the button
                        let hasError = false;
                        $('.utr-error-message').each(function() {
                            if ($(this).text() !== '') {
                                hasError = true;
                            }
                        });
                        if (!hasError) {
                            $('#submitBtn').prop('disabled', false);
                        }
                    }
                },
                error: function() {
                    errorMessageDiv.text('Error checking UTR.');
                     $('#submitBtn').prop('disabled', true);
                }
            });
        });

        // Form reset handler
        $('#denominationForm').on('reset', function() {
            // Clear dynamic rows and reset totals
            $('#online-payment-rows').empty();
            setTimeout(updateTotals, 0);
            $('.utr-error-message').text('');
            $('.online-utr').removeClass('border-red-500');
            $('#submitBtn').prop('disabled', false);
        });

        // Initial calculation on page load
        updateTotals();
    });
</script>

</body>
</html>