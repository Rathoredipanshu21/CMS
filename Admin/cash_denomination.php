<?php
// File: cash_denomination.php
session_start();
$pdo = null;
include '../config/db.php'; // This now includes the PDO connection

// ACTION: HANDLE AJAX REQUEST FOR MODAL DETAILS
if (isset($_GET['action']) && $_GET['action'] == 'get_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("SELECT * FROM cash_denominations WHERE id = ?");
        $stmt->execute([(int)$_GET['id']]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($data ?: []);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// ACTION: HANDLE FORM SUBMISSION TO SAVE OPENING DATA
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_denomination'])) {
    $entry_date = $_POST['entry_date'];
    $description = $_POST['description'];
    
    $denominations = ['n500', 'n200', 'n100', 'n50', 'n20', 'n10', 'c10', 'c5', 'c2', 'c1'];
    $values = [];
    foreach ($denominations as $d) {
        $values[$d] = isset($_POST[$d]) ? (int)$_POST[$d] : 0;
    }

    $total_amount = ($values['n500'] * 500) + ($values['n200'] * 200) + ($values['n100'] * 100) + 
                    ($values['n50'] * 50) + ($values['n20'] * 20) + ($values['n10'] * 10) + 
                    ($values['c10'] * 10) + ($values['c5'] * 5) + ($values['c2'] * 2) + ($values['c1'] * 1);

    try {
        $sql = "INSERT INTO cash_denominations (entry_date, description, n500, n200, n100, n50, n20, n10, c10, c5, c2, c1, total_amount) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$entry_date, $description, $values['n500'], $values['n200'], $values['n100'], $values['n50'], $values['n20'], $values['n10'], $values['c10'], $values['c5'], $values['c2'], $values['c1'], $total_amount]);
        $_SESSION['message'] = "Denomination for $entry_date added successfully!";
        $_SESSION['msg_type'] = "success";
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (duplicate entry)
            $_SESSION['message'] = "Error: An entry for $entry_date already exists.";
        } else {
            $_SESSION['message'] = "Error: " . $e->getMessage();
        }
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: cash_denomination.php");
    exit();
}

// ACTION: HANDLE FORM SUBMISSION TO DEPOSIT CASH TO BANK (FROM MODAL)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_deposit_modal'])) {
    $deposit_date = $_POST['deposit_date_modal'];
    $deposits = $_POST['deposits'] ?? [];
    $available_denominations = json_decode($_POST['available_denominations'], true);
    $total_deposit_amount = 0;
    $validation_failed = false;

    // Server-side validation
    $total_post_denominations = [];
    foreach ($deposits as $deposit) {
        foreach ($deposit['denominations'] as $d_name => $d_qty_str) {
            $d_qty = (int)$d_qty_str;
            if ($d_qty > 0) {
                 $total_post_denominations[$d_name] = ($total_post_denominations[$d_name] ?? 0) + $d_qty;
            }
        }
    }
    
    // Fetch total deposited quantities for today *before* this transaction
    $stmt_deposited_denoms = $pdo->prepare("
        SELECT SUM(dd.n500) as n500, SUM(dd.n200) as n200, SUM(dd.n100) as n100, SUM(dd.n50) as n50, SUM(dd.n20) as n20, SUM(dd.n10) as n10, SUM(dd.c10) as c10, SUM(dd.c5) as c5, SUM(dd.c2) as c2, SUM(dd.c1) as c1
        FROM cash_deposit_details dd JOIN cash_deposits d ON dd.cash_deposit_id = d.id WHERE d.deposit_date = ?
    ");
    $stmt_deposited_denoms->execute([date('Y-m-d')]);
    $already_deposited = $stmt_deposited_denoms->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($total_post_denominations as $d_name => $d_qty) {
        $total_available = $available_denominations[$d_name] ?? 0;
        $already_deposited_qty = (int)($already_deposited[$d_name] ?? 0);
        $remaining_to_deposit = $total_available - $already_deposited_qty;
        
        if ($d_qty > $remaining_to_deposit) {
            $_SESSION['message'] = "Error: Your deposit of $d_qty for ($d_name) exceeds the remaining available quantity of $remaining_to_deposit.";
            $_SESSION['msg_type'] = "danger";
            $validation_failed = true;
            break;
        }
    }

    if (!$validation_failed && !empty($deposits)) {
        try {
            $pdo->beginTransaction();

            foreach ($deposits as $deposit) {
                $bank_id = $deposit['bank_id'];
                $denominations = $deposit['denominations'];
                $deposit_amount_val = (float)($deposit['total_amount'] ?? 0);

                if ($bank_id && $deposit_amount_val > 0.009) {
                    // 1. Insert into cash_deposits table
                    $sql_deposit = "INSERT INTO cash_deposits (deposit_date, bank_id, amount) VALUES (?, ?, ?)";
                    $stmt_deposit = $pdo->prepare($sql_deposit);
                    $stmt_deposit->execute([$deposit_date, $bank_id, $deposit_amount_val]);
                    $cash_deposit_id = $pdo->lastInsertId();

                    // 2. Insert into cash_deposit_details
                    $sql_details = "INSERT INTO cash_deposit_details (cash_deposit_id, n500, n200, n100, n50, n20, n10, c10, c5, c2, c1) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_details = $pdo->prepare($sql_details);
                    $stmt_details->execute([
                        $cash_deposit_id,
                        (int)($denominations['n500'] ?? 0), (int)($denominations['n200'] ?? 0),
                        (int)($denominations['n100'] ?? 0), (int)($denominations['n50'] ?? 0),
                        (int)($denominations['n20'] ?? 0), (int)($denominations['n10'] ?? 0),
                        (int)($denominations['c10'] ?? 0), (int)($denominations['c5'] ?? 0),
                        (int)($denominations['c2'] ?? 0), (int)($denominations['c1'] ?? 0)
                    ]);

                    // 3. Update bank balance
                    $sql_update_bank = "UPDATE banks SET account_balance = account_balance + ? WHERE id = ?";
                    $stmt_update_bank = $pdo->prepare($sql_update_bank);
                    $stmt_update_bank->execute([$deposit_amount_val, $bank_id]);
                    $total_deposit_amount += $deposit_amount_val;
                }
            }

            $pdo->commit();
            $_SESSION['message'] = "₹" . number_format($total_deposit_amount, 2) . " deposited successfully!";
            $_SESSION['msg_type'] = "success";

        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['message'] = "Database Error: " . $e->getMessage();
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: cash_denomination.php");
    exit();
}


// --- DATA FETCHING & STATUS LOGIC ---
$today = date('Y-m-d');
$todays_opening = false;
$todays_received_raw = [];
$todays_paid_raw = [];
$todays_deposited_denominations_raw = []; 
$total_deposited_today = 0;
$opening_exists_today = false;
$banks = [];
$available_denominations_for_deposit = [];
$remaining_denominations_for_deposit = []; // **FIX:** New array for correct pre-fill quantities

$denominations_data_live = [
    ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['500']], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['200']],
    ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['100']], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['50']],
    ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['20']], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['10']],
    ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coins', 'icon' => 'fa-coins', 'db_keys' => []], 
    ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coins', 'icon' => 'fa-coins', 'db_keys' => ['5']],
    ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coins', 'icon' => 'fa-coins', 'db_keys' => ['2']], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coins', 'icon' => 'fa-coins', 'db_keys' => ['1']],
];

$denominations_form_data = [
    ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 x'], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 x'],
    ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 x'], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 x'],
    ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 x'], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Note x'],
    ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coin x'], ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coin x'],
    ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coin x'], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coin x'],
];

$all_denominations = [];

if ($pdo) {
    // Check if an opening entry for today exists
    $stmt_opening_check = $pdo->prepare("SELECT COUNT(*) FROM cash_denominations WHERE entry_date = ?");
    $stmt_opening_check->execute([$today]);
    $opening_exists_today = $stmt_opening_check->fetchColumn() > 0;

    // Fetch total amount deposited today
    $stmt_deposits = $pdo->prepare("SELECT SUM(amount) FROM cash_deposits WHERE deposit_date = ?");
    $stmt_deposits->execute([$today]);
    $total_deposited_today = $stmt_deposits->fetchColumn() ?? 0;

    // Fetch available banks for the deposit form
    $banks = $pdo->query("SELECT id, bank_name, account_number FROM banks ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Today's Opening Balance
    $stmt_opening = $pdo->prepare("SELECT * FROM cash_denominations WHERE entry_date = ?");
    $stmt_opening->execute([$today]);
    $todays_opening = $stmt_opening->fetch(PDO::FETCH_ASSOC);

    // Fetch Today's RECEIVED Cash (Cash IN)
    $sql_received = "SELECT td.denomination_or_platform, SUM(td.quantity_or_utr) AS total_quantity FROM transaction_details td JOIN transactions t ON td.transaction_id = t.id WHERE td.detail_type = 'cash' AND t.transaction_type IN ('Cash Received', '(Cash + Online) Received') AND DATE(t.created_at) = ? GROUP BY td.denomination_or_platform";
    $stmt_received = $pdo->prepare($sql_received);
    $stmt_received->execute([$today]);
    $todays_received_raw = $stmt_received->fetchAll(PDO::FETCH_KEY_PAIR);

    // Fetch Today's PAID OUT Cash (Cash OUT)
    $sql_paid = "SELECT td.denomination_or_platform, SUM(td.quantity_or_utr) AS total_quantity FROM transaction_details td JOIN transactions t ON td.transaction_id = t.id WHERE td.detail_type = 'cash' AND t.transaction_type IN ('Cash Payment') AND DATE(t.created_at) = ? GROUP BY td.denomination_or_platform";
    $stmt_paid = $pdo->prepare($sql_paid);
    $stmt_paid->execute([$today]);
    $todays_paid_raw = $stmt_paid->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Fetch Today's Deposited Denominations
    $sql_deposited_denominations = "
        SELECT 
            SUM(dd.n500) as n500, SUM(dd.n200) as n200, SUM(dd.n100) as n100,
            SUM(dd.n50) as n50, SUM(dd.n20) as n20, SUM(dd.n10) as n10,
            SUM(dd.c10) as c10, SUM(dd.c5) as c5, SUM(dd.c2) as c2, SUM(dd.c1) as c1
        FROM cash_deposit_details dd
        JOIN cash_deposits d ON dd.cash_deposit_id = d.id
        WHERE d.deposit_date = ?
    ";
    $stmt_deposited_denoms = $pdo->prepare($sql_deposited_denominations);
    $stmt_deposited_denoms->execute([$today]);
    $todays_deposited_denominations_raw = $stmt_deposited_denoms->fetch(PDO::FETCH_ASSOC) ?: [];


    // Fetch All Historical Records
    $all_denominations = $pdo->query("SELECT id, entry_date, description, total_amount FROM cash_denominations ORDER BY entry_date DESC")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Denomination Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-input { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.6rem 0.85rem; transition: all 0.2s ease-in-out; background-color: #fff; }
        .form-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2); outline: none; }
        .btn { padding: 0.7rem 1.75rem; border-radius: 0.5rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s ease; border: none; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .modal-input { padding: 0.5rem; text-align: center; }
        .input-error { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2); }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto space-y-8">
    <header class="flex justify-between items-center">
        <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight"><i class="fas fa-coins text-indigo-600"></i> Cash Manager</h1>
        <?php if (isset($_GET['action']) && $_GET['action'] == 'add'): ?>
            <a href="cash_denomination.php" class="btn bg-indigo-600 hover:bg-indigo-700 text-white"><i class="fas fa-list mr-2"></i> View All Entries</a>
        <?php else: ?>
            <?php if ($opening_exists_today): ?>
                <span class="btn bg-gray-400 text-white cursor-not-allowed" title="An opening entry for today already exists."><i class="fas fa-plus-circle mr-2"></i> Add New Entry</span>
            <?php else: ?>
                <a href="cash_denomination.php?action=add" class="btn bg-indigo-600 hover:bg-indigo-700 text-white"><i class="fas fa-plus-circle mr-2"></i> Add New Entry</a>
            <?php endif; ?>
        <?php endif; ?>
    </header>

    <?php if (isset($_SESSION['message'])): ?>
    <div class="bg-<?= $_SESSION['msg_type'] == 'success' ? 'green' : 'red' ?>-100 border-l-4 border-<?= $_SESSION['msg_type'] == 'success' ? 'green' : 'red' ?>-500 text-<?= $_SESSION['msg_type'] == 'success' ? 'green' : 'red' ?>-800 p-4 rounded-lg shadow-md" role="alert">
        <p class="font-bold"><?= $_SESSION['msg_type'] == 'success' ? 'Success' : 'Error' ?></p>
        <p><?= htmlspecialchars($_SESSION['message']) ?></p>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['msg_type']); endif; ?>

    <?php if (isset($_GET['action']) && $_GET['action'] == 'add'): ?>
        <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8">
            <h2 class="text-2xl font-bold text-gray-800 border-b pb-4 mb-6">Add New Day's Opening Balance</h2>
            <form action="cash_denomination.php" method="POST" id="denominationForm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label for="entry_date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required class="form-input w-full">
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <input type="text" id="description" name="description" value="Morning Opening Cash" required class="form-input w-full">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <?php foreach($denominations_form_data as $d): ?>
                        <div class="flex items-center space-x-2 bg-gray-50 p-3 rounded-lg border">
                            <label for="<?= $d['name'] ?>" class="font-semibold text-gray-600 w-28"><?= $d['label'] ?></label>
                            <input type="number" name="<?= $d['name'] ?>" id="<?= $d['name'] ?>" min="0" placeholder="Qty" class="form-input w-24 text-center qty-input" data-value="<?= $d['value'] ?>">
                            <span class="sub-total font-bold text-indigo-700 w-28 text-right" id="total_<?= $d['name'] ?>">₹ 0.00</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                 <div class="mt-8 p-4 bg-indigo-600 text-white rounded-lg text-center">
                    <h3 class="text-2xl font-extrabold">GRAND TOTAL: <span id="grandTotal">₹ 0.00</span></h3>
                </div>
                <div class="mt-8 text-right">
                    <button type="submit" name="save_denomination" class="btn bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 shadow-lg"><i class="fas fa-save mr-2"></i> Save Entry</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <?php if ($todays_opening): ?>
            <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8">
                <h2 class="text-2xl font-bold text-gray-800 border-b pb-4 mb-6"><i class="fas fa-chart-line mr-2 text-green-500"></i> Today's Live Cash Status (<?= date("d M Y") ?>)</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Denomination</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Opening</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Received (+)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid Out (-)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Deposited (-)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Closing Qty</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Closing Amount</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                            $total_opening_amt = 0; $total_received_amt = 0; $total_paid_amt = 0; $total_deposited_amt = 0; $total_closing_amt = 0;
                            foreach ($denominations_data_live as $d) {
                                $db_col = $d['name'];
                                $value = (int)$d['value'];
                                
                                $opening_qty = $todays_opening[$db_col] ?? 0;
                                $received_qty = 0;
                                foreach($d['db_keys'] as $key) { $received_qty += $todays_received_raw[$key] ?? 0; }
                                $paid_qty = 0;
                                foreach($d['db_keys'] as $key) { $paid_qty += $todays_paid_raw[$key] ?? 0; }
                                $deposited_qty = (int)($todays_deposited_denominations_raw[$db_col] ?? 0);
                                
                                // This is the total available for the day, used for validation
                                $available_denominations_for_deposit[$db_col] = $opening_qty + $received_qty - $paid_qty;
                                
                                // This is the final closing quantity, used for display AND for pre-filling the modal
                                $closing_qty = $available_denominations_for_deposit[$db_col] - $deposited_qty;
                                $remaining_denominations_for_deposit[$db_col] = $closing_qty;


                                $total_opening_amt += $opening_qty * $value;
                                $total_received_amt += $received_qty * $value;
                                $total_paid_amt += $paid_qty * $value;
                                $total_deposited_amt += $deposited_qty * $value;
                                $total_closing_amt += $closing_qty * $value;
                        ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><div class="flex items-center"><i class="fas <?= $d['icon'] ?> text-indigo-500 mr-3"></i><span class="font-semibold text-gray-800"><?= $d['label'] ?></span></div></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-gray-600"><?= $opening_qty ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-green-600"><?= $received_qty ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-red-600"><?= $paid_qty ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-orange-600"><?= $deposited_qty ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-lg font-bold text-blue-800"><?= $closing_qty ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right font-semibold text-gray-900">₹ <?= number_format($closing_qty * $value, 2) ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                        <tfoot class="bg-gray-100">
                            <tr class="font-extrabold text-gray-900">
                                <td class="px-6 py-4 text-left text-sm uppercase">Total</td>
                                <td class="px-6 py-4 text-right">₹ <?= number_format($total_opening_amt, 2) ?></td>
                                <td class="px-6 py-4 text-right text-green-700">₹ <?= number_format($total_received_amt, 2) ?></td>
                                <td class="px-6 py-4 text-right text-red-700">₹ <?= number_format($total_paid_amt, 2) ?></td>
                                <td class="px-6 py-4 text-right text-orange-700">₹ <?= number_format($total_deposited_today, 2) ?></td>
                                <td class="px-6 py-4 text-right"></td>
                                <td class="px-6 py-4 text-right text-xl text-indigo-800">₹ <?= number_format($total_closing_amt, 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-8 border-t pt-6">
                    <?php
                        $cash_before_deposit = $total_opening_amt + $total_received_amt - $total_paid_amt;
                        $remaining_cash_to_deposit = $cash_before_deposit - $total_deposited_today;
                    ?>
                    <div class="grid md:grid-cols-3 gap-6 items-center">
                        <div class="bg-blue-50 p-4 rounded-lg text-center">
                            <h4 class="text-sm font-semibold text-blue-800 uppercase">Final Cash In Hand</h4>
                            <p class="text-2xl font-extrabold text-blue-900">₹ <?= number_format($total_closing_amt, 2) ?></p>
                        </div>
                        <div class="bg-red-50 p-4 rounded-lg text-center">
                            <h4 class="text-sm font-semibold text-red-800 uppercase">Amount Deposited Today</h4>
                            <p class="text-2xl font-extrabold text-red-900">₹ <?= number_format($total_deposited_today, 2) ?></p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg text-center">
                            <h4 class="text-sm font-semibold text-green-800 uppercase">Cash Available For Deposit</h4>
                            <p class="text-2xl font-extrabold text-green-900">₹ <?= number_format($remaining_cash_to_deposit, 2) ?></p>
                        </div>
                    </div>

                    
                    <?php if ($remaining_cash_to_deposit > 0.009): // Use small tolerance for float comparison ?>
                        <div class="mt-6 text-center">
                            <button id="openDepositModalBtn" class="btn bg-green-600 hover:bg-green-700 text-white shadow-lg py-3 px-8 font-bold">
                                <i class="fas fa-university mr-2"></i> Deposit Cash to Bank
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="mt-6 bg-green-100 border-l-4 border-green-500 text-green-800 p-4 rounded-lg text-center" role="alert">
                            <p class="font-bold"><i class="fas fa-check-circle mr-2"></i> All available cash for today has been deposited.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
             <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-800 p-4 rounded-lg shadow-md" role="alert">
                <p class="font-bold">Attention</p>
                <p>No opening cash balance has been added for today (<?= date("d M Y") ?>). Add an entry to see the live status.</p>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8">
            <h2 class="text-2xl font-bold text-gray-800 border-b pb-4 mb-6"><i class="fas fa-history mr-2 text-gray-500"></i> Historical Entries</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Opening Amount</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($all_denominations)): ?>
                            <tr><td colspan="4" class="text-center py-10 text-gray-500">No entries found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_denominations as $row): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-800"><?= date("D, j M Y", strtotime($row['entry_date'])) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-gray-600"><?= htmlspecialchars($row['description']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right font-bold text-gray-900">₹ <?= number_format($row['total_amount'], 2) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <button class="action-btn view-btn text-indigo-600 hover:text-indigo-900" data-id="<?= $row['id'] ?>" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<div id="depositModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col">
        <header class="p-5 border-b flex justify-between items-center">
            <h2 class="text-xl font-bold text-gray-800"><i class="fas fa-university mr-2"></i> Deposit Cash to Bank</h2>
            <button id="closeDepositModalBtn" class="text-gray-500 hover:text-gray-800 text-2xl leading-none">&times;</button>
        </header>

        <form action="cash_denomination.php" method="POST" id="depositModalForm" class="flex-grow overflow-y-auto">
            <div class="p-6 space-y-4">
                <input type="hidden" name="deposit_date_modal" value="<?= htmlspecialchars($today) ?>">
                <input type="hidden" name="available_denominations" id="available_denominations_json" value='<?= json_encode($available_denominations_for_deposit) ?>'>
                <input type="hidden" id="quantities_to_prefill_json" value='<?= json_encode($remaining_denominations_for_deposit) ?>'>

                <div id="depositRowsContainer" class="space-y-6">
                    </div>

                <div class="pt-4">
                    <button type="button" id="addBankDepositBtn" class="btn bg-blue-500 hover:bg-blue-600 text-white w-full"><i class="fas fa-plus mr-2"></i> Add Another Bank Deposit</button>
                </div>
            </div>

            <footer class="p-5 border-t bg-gray-50 rounded-b-2xl sticky bottom-0">
                <div class="flex justify-between items-center mb-4">
                    <div class="text-sm">
                        <p class="text-gray-600">Available to Deposit:</p>
                        <p class="font-bold text-lg text-green-700">₹ <?= number_format($remaining_cash_to_deposit ?? 0, 2) ?></p>
                    </div>
                    <div class="text-right">
                         <p class="text-gray-600">Total Depositing:</p>
                        <p class="font-extrabold text-2xl text-indigo-700" id="grandDepositTotal">₹ 0.00</p>
                    </div>
                </div>
                <button type="submit" name="submit_deposit_modal" id="submitDepositBtn" class="btn bg-green-600 hover:bg-green-700 text-white w-full font-bold py-3"><i class="fas fa-check-circle mr-2"></i> Confirm & Save Deposits</button>
            </footer>
        </form>
    </div>
</div>

<div id="depositRowTemplate" class="hidden">
    <div class="deposit-row bg-gray-50 border rounded-lg p-4 space-y-3 relative">
        <button type="button" class="remove-row-btn absolute -top-2 -right-2 bg-red-500 text-white rounded-full h-6 w-6 flex items-center justify-center font-bold text-sm">&times;</button>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Bank</label>
                <select name="deposits[__INDEX__][bank_id]" class="form-input w-full bank-selector" required>
                    <option value="">-- Choose a bank --</option>
                    <?php foreach($banks as $bank): ?>
                        <option value="<?= $bank['id'] ?>"><?= htmlspecialchars($bank['bank_name'] . ' (' . $bank['account_number'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="text-right">
                <label class="block text-sm font-medium text-gray-700 mb-1">Bank Subtotal</label>
                <input type="hidden" class="row-total-hidden" name="deposits[__INDEX__][total_amount]" value="0">
                <p class="row-total-display text-2xl font-bold text-gray-800">₹ 0.00</p>
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 pt-2 border-t">
            <?php foreach($denominations_form_data as $d): ?>
                <div>
                    <label class="block text-xs font-medium text-center text-gray-600"><?= str_replace(' x', '', $d['label']) ?></label>
                    <input type="number" name="deposits[__INDEX__][denominations][<?= $d['name'] ?>]" min="0" placeholder="Qty" class="form-input modal-input w-full denomination-qty" data-value="<?= $d['value'] ?>" data-name="<?= $d['name'] ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>


<script>
    // Script for the "Add New Entry" form total calculation
    const denominationForm = document.getElementById('denominationForm');
    if (denominationForm) {
        const qtyInputs = denominationForm.querySelectorAll('.qty-input');
        const grandTotalEl = document.getElementById('grandTotal');
        function calculateTotal() {
            let grandTotal = 0;
            qtyInputs.forEach(input => {
                const value = parseFloat(input.dataset.value);
                const qty = parseInt(input.value) || 0;
                const subTotal = value * qty;
                grandTotal += subTotal;
                document.getElementById(`total_${input.name}`).innerText = `₹ ${subTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
            });
            grandTotalEl.innerText = `₹ ${grandTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
        }
        qtyInputs.forEach(input => { input.addEventListener('input', calculateTotal); });
    }

    // SCRIPT FOR DEPOSIT MODAL LOGIC
    document.addEventListener('DOMContentLoaded', function() {
        const openModalBtn = document.getElementById('openDepositModalBtn');
        if (!openModalBtn) return;

        const modal = document.getElementById('depositModal');
        const closeModalBtn = document.getElementById('closeDepositModalBtn');
        const addBankBtn = document.getElementById('addBankDepositBtn');
        const rowsContainer = document.getElementById('depositRowsContainer');
        const template = document.getElementById('depositRowTemplate');
        const grandTotalDisplay = document.getElementById('grandDepositTotal');
        const submitBtn = document.getElementById('submitDepositBtn');
        
        // Used for VALIDATION (total cash available for the day)
        const availableDenominations = JSON.parse(document.getElementById('available_denominations_json').value);
        
        // **FIX:** Used for PRE-FILLING (current cash remaining right now)
        const quantitiesToPrefill = JSON.parse(document.getElementById('quantities_to_prefill_json').value);
        
        const maxDepositAmount = <?= $remaining_cash_to_deposit ?? 0 ?>;

        let depositIndex = 0;

        function addDepositRow() {
            const newRowHTML = template.innerHTML.replace(/__INDEX__/g, depositIndex);
            rowsContainer.insertAdjacentHTML('beforeend', newRowHTML);
            const newRowElement = rowsContainer.lastElementChild;

            // Pre-fill the first row with the CURRENT REMAINING cash
            if (rowsContainer.children.length === 1) {
                newRowElement.querySelectorAll('.denomination-qty').forEach(input => {
                    const dName = input.dataset.name;
                    // **FIX:** Use the quantitiesToPrefill object here
                    if (quantitiesToPrefill[dName] && quantitiesToPrefill[dName] > 0) {
                        input.value = quantitiesToPrefill[dName];
                    }
                });
            }
            
            depositIndex++;
            updateEventListeners();
            updateAllCalculations(); // Update totals immediately after adding/pre-filling
        }

        function removeDepositRow(e) {
            if (e.target.classList.contains('remove-row-btn')) {
                e.target.closest('.deposit-row').remove();
                updateAllCalculations();
            }
        }
        
        function updateAllCalculations() {
            let grandTotal = 0;
            const depositedQuantities = {};

            document.querySelectorAll('.denomination-qty').forEach(input => {
                const dName = input.dataset.name;
                const qty = parseInt(input.value) || 0;
                depositedQuantities[dName] = (depositedQuantities[dName] || 0) + qty;
            });

            document.querySelectorAll('.deposit-row').forEach(row => {
                let rowTotal = 0;
                row.querySelectorAll('.denomination-qty').forEach(input => {
                    const value = parseFloat(input.dataset.value);
                    const qty = parseInt(input.value) || 0;
                    rowTotal += value * qty;
                });
                row.querySelector('.row-total-display').innerText = `₹ ${rowTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
                row.querySelector('.row-total-hidden').value = rowTotal;
                grandTotal += rowTotal;
            });
            
            grandTotalDisplay.innerText = `₹ ${grandTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
            validateDenominations(depositedQuantities, grandTotal);
        }

        function validateDenominations(depositedQuantities, grandTotal) {
            let isValid = true;
            
            // Re-calculate how much is actually left to deposit for validation
            const alreadyDepositedToday = {};
            for(const dName in availableDenominations) {
                alreadyDepositedToday[dName] = availableDenominations[dName] - quantitiesToPrefill[dName];
            }

            document.querySelectorAll('.denomination-qty').forEach(input => {
                const dName = input.dataset.name;
                const totalAvailableForDay = availableDenominations[dName] || 0;
                const currentAttempt = depositedQuantities[dName] || 0;
                
                // The total you are trying to deposit now, plus what's already deposited, cannot exceed the day's total available
                if ((currentAttempt + (alreadyDepositedToday[dName] || 0)) > totalAvailableForDay) {
                     isValid = false;
                    document.querySelectorAll(`.denomination-qty[data-name="${dName}"]`).forEach(el => el.classList.add('input-error'));
                } else {
                    document.querySelectorAll(`.denomination-qty[data-name="${dName}"]`).forEach(el => el.classList.remove('input-error'));
                }
            });

            if (grandTotal > (maxDepositAmount + 0.01)) { 
                isValid = false;
                grandTotalDisplay.classList.add('text-red-600');
            } else {
                grandTotalDisplay.classList.remove('text-red-600');
            }

            submitBtn.disabled = !isValid;
            submitBtn.classList.toggle('opacity-50', !isValid);
            submitBtn.classList.toggle('cursor-not-allowed', !isValid);
        }

        function updateEventListeners() {
            rowsContainer.removeEventListener('input', updateAllCalculations);
            rowsContainer.removeEventListener('click', removeDepositRow);
            rowsContainer.addEventListener('input', updateAllCalculations);
            rowsContainer.addEventListener('click', removeDepositRow);
        }

        openModalBtn.addEventListener('click', () => {
            modal.classList.remove('hidden');
            if(rowsContainer.children.length === 0){
                addDepositRow();
            }
        });
        closeModalBtn.addEventListener('click', () => {
            // Clear rows when closing so it's fresh next time
            rowsContainer.innerHTML = '';
            depositIndex = 0;
            modal.classList.add('hidden');
        });
        addBankBtn.addEventListener('click', addDepositRow);

        updateEventListeners();
    });

</script>

</body>
</html>