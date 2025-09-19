<?php
session_start();
$pdo = null;
include '../config/db.php'; // Ensure this path is correct

$banks = $pdo->query("SELECT id, bank_name, account_number FROM banks ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$closing_quantities = isset($_POST['closing_qty']) ? $_POST['closing_qty'] : [];

// Denomination structure for the form
$denominations_form_data = [
    ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 x'], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 x'],
    ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 x'], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 x'],
    ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 x'], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Note x'],
    ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coin x'], ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coin x'],
    ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coin x'], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coin x'],
];

// Handle the final deposit submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_deposit'])) {
    $pdo->beginTransaction();
    try {
        $deposit_date = $_POST['deposit_date'];

        // SERVER-SIDE CHECK: Prevent more than one deposit per day
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM cash_deposits WHERE deposit_date = ?");
        $stmt_check->execute([$deposit_date]);
        if ($stmt_check->fetchColumn() > 0) {
            throw new Exception("A deposit has already been processed for this date. Cannot proceed.");
        }

        $bank_id = (int)$_POST['bank_id'];
        $description = $_POST['description'];
        
        $deposited_quantities = [];
        $remaining_quantities = [];
        $total_deposited_amount = 0;
        $total_remaining_amount = 0;

        // Calculate deposited, remaining, and total amounts
        foreach ($denominations_form_data as $d) {
            $name = $d['name'];
            $value = $d['value'];
            
            $original_qty = isset($_POST['original_closing_qty'][$name]) ? (int)$_POST['original_closing_qty'][$name] : 0;
            $deposited_qty = isset($_POST['qty'][$name]) ? (int)$_POST['qty'][$name] : 0;
            
            if ($deposited_qty > $original_qty) {
                throw new Exception("Cannot deposit more than the available closing quantity for ₹{$value}.");
            }

            $deposited_quantities[$name] = $deposited_qty;
            $remaining_quantities[$name] = $original_qty - $deposited_qty;
            
            $total_deposited_amount += $deposited_qty * $value;
            $total_remaining_amount += $remaining_quantities[$name] * $value;
        }

        if (empty($bank_id)) {
            throw new Exception("Please select a bank account.");
        }
        // Allow zero deposits if user wants to carry forward all cash
        if ($total_deposited_amount < 0) {
            throw new Exception("Deposit amount cannot be negative.");
        }


        // Only process bank transactions if amount is greater than zero
        if ($total_deposited_amount > 0) {
            // Get current bank balance for history logging
            $stmt_get_balance = $pdo->prepare("SELECT account_balance FROM banks WHERE id = ? FOR UPDATE");
            $stmt_get_balance->execute([$bank_id]);
            $bank = $stmt_get_balance->fetch(PDO::FETCH_ASSOC);
            if (!$bank) {
                throw new Exception("Selected bank not found.");
            }
            $balance_before = (float)$bank['account_balance'];
            $balance_after = $balance_before + $total_deposited_amount;

            // Update bank balance
            $stmt_update_bank = $pdo->prepare("UPDATE banks SET account_balance = ? WHERE id = ?");
            $stmt_update_bank->execute([$balance_after, $bank_id]);
        } else {
             $balance_before = null;
             $balance_after = null;
        }

        // 1. Insert into cash_deposits log
        $sql_log = "INSERT INTO cash_deposits (deposit_date, bank_id, description, n500, n200, n100, n50, n20, n10, c10, c5, c2, c1, total_deposited_amount) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_log = $pdo->prepare($sql_log);
        $stmt_log->execute([
            $deposit_date, $bank_id, $description,
            $deposited_quantities['n500'], $deposited_quantities['n200'], $deposited_quantities['n100'], $deposited_quantities['n50'], $deposited_quantities['n20'], $deposited_quantities['n10'],
            $deposited_quantities['c10'], $deposited_quantities['c5'], $deposited_quantities['c2'], $deposited_quantities['c1'],
            $total_deposited_amount
        ]);
        $deposit_id = $pdo->lastInsertId();

        // 2. Add to bank transaction history only if a deposit was made
        if ($total_deposited_amount > 0) {
            $stmt_history = $pdo->prepare("INSERT INTO banks_transactions_history (bank_id, transaction_id, transaction_type, amount, balance_before, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_history->execute([$bank_id, $deposit_id, 'deposit', $total_deposited_amount, $balance_before, $balance_after]);
        }

        // 3. Set next day's opening balance with remaining cash
        $next_day_date = date('Y-m-d', strtotime($deposit_date . ' +1 day'));
        $sql_next_day = "INSERT INTO cash_denominations (entry_date, description, n500, n200, n100, n50, n20, n10, c10, c5, c2, c1, total_amount) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE 
                         description = VALUES(description), n500 = VALUES(n500), n200 = VALUES(n200), n100 = VALUES(n100), n50 = VALUES(n50), n20 = VALUES(n20),
                         n10 = VALUES(n10), c10 = VALUES(c10), c5 = VALUES(c5), c2 = VALUES(c2), c1 = VALUES(c1), total_amount = VALUES(total_amount)";
        $stmt_next_day = $pdo->prepare($sql_next_day);
        $stmt_next_day->execute([
            $next_day_date, "Opening Balance (Carry Forward)",
            $remaining_quantities['n500'], $remaining_quantities['n200'], $remaining_quantities['n100'], $remaining_quantities['n50'], $remaining_quantities['n20'], $remaining_quantities['n10'],
            $remaining_quantities['c10'], $remaining_quantities['c5'], $remaining_quantities['c2'], $remaining_quantities['c1'],
            $total_remaining_amount
        ]);

        $pdo->commit();
        $_SESSION['message'] = "Successfully processed transaction for {$deposit_date}. Next day's opening balance of ₹" . number_format($total_remaining_amount, 2) . " has been set.";
        $_SESSION['msg_type'] = "success";
        header("Location: cash_denomination.php");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['message'] = "Error: " . $e->getMessage();
        $_SESSION['msg_type'] = "danger";
        $_SESSION['form_data'] = $_POST;
        header("Location: cash_to_bank.php");
        exit();
    }
}

if (isset($_SESSION['form_data'])) {
    $closing_quantities = $_SESSION['form_data']['original_closing_qty'];
    unset($_SESSION['form_data']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Cash to Bank</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-input { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; transition: all 0.2s ease-in-out; background-color: #fff; }
        .form-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2); outline: none; }
        .btn { padding: 0.7rem 1.75rem; border-radius: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s ease; border: none; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">
<div class="max-w-4xl mx-auto space-y-8">
    <header class="flex justify-between items-center">
        <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight"><i class="fas fa-university text-indigo-600"></i> Deposit Cash to Bank</h1>
        <a href="cash_denomination.php" class="btn bg-gray-500 hover:bg-gray-600 text-white"><i class="fas fa-times-circle mr-2"></i> Cancel</a>
    </header>

    <?php if (isset($_SESSION['message'])): ?>
    <div class="bg-<?= $_SESSION['msg_type'] == 'success' ? 'green' : 'red' ?>-100 border-l-4 border-<?= $_SESSION['msg_type'] == 'success' ? 'green' : 'red' ?>-500 text-<?= $_SESSION['msg_type'] == 'success' ? 'green' : 'red' ?>-800 p-4 rounded-lg shadow-md" role="alert">
        <p><?= htmlspecialchars($_SESSION['message']) ?></p>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['msg_type']); endif; ?>

    <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8">
        <form action="cash_to_bank.php" method="POST" id="depositForm">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label for="deposit_date" class="block text-sm font-medium text-gray-700 mb-1">Deposit Date</label>
                    <input type="date" id="deposit_date" name="deposit_date" value="<?= date('Y-m-d') ?>" required class="form-input w-full bg-gray-200" readonly>
                </div>
                <div>
                    <label for="bank_id" class="block text-sm font-medium text-gray-700 mb-1">Select Bank Account</label>
                    <select id="bank_id" name="bank_id" required class="form-input w-full">
                        <option value="">-- Choose a Bank --</option>
                        <?php foreach($banks as $bank): ?>
                            <option value="<?= $bank['id'] ?>"><?= htmlspecialchars($bank['bank_name'] . ' (' . $bank['account_number'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                 <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description (Optional)</label>
                    <input type="text" id="description" name="description" placeholder="e.g., End of day cash deposit" class="form-input w-full">
                </div>
            </div>
            
            <h3 class="text-xl font-bold text-gray-700 border-t pt-6 mt-6 mb-4">Edit Denominations to Deposit</h3>
            <p class="text-sm text-gray-500 mb-6">The quantities below are from today's closing balance. You can edit them if you are not depositing all the cash.</p>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php foreach($denominations_form_data as $d): 
                    $qty = $closing_quantities[$d['name']] ?? 0;
                ?>
                    <div class="flex items-center space-x-2 bg-gray-50 p-3 rounded-lg border">
                        <label for="<?= $d['name'] ?>" class="font-semibold text-gray-600 w-28"><?= $d['label'] ?></label>
                        <input type="number" name="qty[<?= $d['name'] ?>]" id="<?= $d['name'] ?>" value="<?= $qty ?>" min="0" placeholder="Qty" class="form-input w-24 text-center qty-input" data-value="<?= $d['value'] ?>">
                        <input type="hidden" name="original_closing_qty[<?= $d['name'] ?>]" value="<?= $qty ?>">
                        <span class="sub-total font-bold text-indigo-700 w-28 text-right" id="total_<?= $d['name'] ?>">₹ 0.00</span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-8 p-4 bg-indigo-600 text-white rounded-lg text-center">
                <h3 class="text-2xl font-extrabold">TOTAL DEPOSIT AMOUNT: <span id="grandTotal">₹ 0.00</span></h3>
            </div>

            <div class="mt-8 text-right">
                <button type="submit" name="process_deposit" class="btn bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 shadow-lg"><i class="fas fa-check-circle mr-2"></i> Confirm & Process Deposit</button>
            </div>
        </form>
    </div>
</div>

<script>
    const depositForm = document.getElementById('depositForm');
    if (depositForm) {
        const qtyInputs = depositForm.querySelectorAll('.qty-input');
        const grandTotalEl = document.getElementById('grandTotal');
        
        function calculateTotal() {
            let grandTotal = 0;
            qtyInputs.forEach(input => {
                const value = parseFloat(input.dataset.value);
                const qty = parseInt(input.value) || 0;
                const subTotal = value * qty;
                grandTotal += subTotal;
                const subTotalEl = document.getElementById(`total_${input.id}`);
                if (subTotalEl) {
                   subTotalEl.innerText = `₹ ${subTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
                }
            });
            grandTotalEl.innerText = `₹ ${grandTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
        }

        qtyInputs.forEach(input => {
            input.addEventListener('input', calculateTotal);
        });

        // Initial calculation on page load
        calculateTotal();
    }
</script>

</body>
</html>