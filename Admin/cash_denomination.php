<?php
// File: cash_denomination.php
session_start();
$pdo = null;
include '../config/db.php'; // This now includes the PDO connection

// ACTION: HANDLE AJAX REQUEST FOR MODAL DETAILS
if (isset($_GET['action']) && $_GET['action'] == 'get_details' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM cash_denominations WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// ACTION: HANDLE FORM SUBMISSION TO SAVE DATA
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

// --- LIVE CASH STATUS LOGIC ---
$today = date('Y-m-d');
$todays_opening = false;
$todays_received_raw = [];
$todays_paid_raw = [];
$deposit_done_today = false;
$opening_exists_today = false;

$denominations_data_live = [
    ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['500']], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['200']],
    ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['100']], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['50']],
    ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['20']], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Notes', 'icon' => 'fa-money-bill-wave', 'db_keys' => ['10']],
    ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coins', 'icon' => 'fa-coins', 'db_keys' => []], 
    ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coins', 'icon' => 'fa-coins', 'db_keys' => ['5']],
    ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coins', 'icon' => 'fa-coins', 'db_keys' => ['2']], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coins', 'icon' => 'fa-coins', 'db_keys' => ['1']],
];
$all_denominations = [];

if ($pdo) {
    // Check if an opening entry for today exists
    $stmt_opening_check = $pdo->prepare("SELECT COUNT(*) FROM cash_denominations WHERE entry_date = ?");
    $stmt_opening_check->execute([$today]);
    $opening_exists_today = $stmt_opening_check->fetchColumn() > 0;

    // Check if deposit has been made for today
    $stmt_deposit_check = $pdo->prepare("SELECT COUNT(*) FROM cash_deposits WHERE deposit_date = ?");
    $stmt_deposit_check->execute([$today]);
    $deposit_done_today = $stmt_deposit_check->fetchColumn() > 0;

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
                     <?php
                        $denominations_form_data = [
                            ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 x'], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 x'],
                            ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 x'], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 x'],
                            ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 x'], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Note x'],
                            ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coin x'], ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coin x'],
                            ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coin x'], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coin x'],
                        ];
                    ?>
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
                <form action="cash_to_bank.php" method="POST" id="depositForm">
                    <div class="flex justify-between items-center border-b pb-4 mb-6">
                        <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-chart-line mr-2 text-green-500"></i> Today's Live Cash Status (<?= date("d M Y") ?>)</h2>
                        <?php if ($deposit_done_today): ?>
                             <button type="button" class="btn bg-gray-400 text-white cursor-not-allowed" disabled><i class="fas fa-check-circle mr-2"></i> Deposited</button>
                        <?php else: ?>
                            <button type="submit" class="btn bg-green-600 hover:bg-green-700 text-white"><i class="fas fa-university mr-2"></i> Deposit to Bank</button>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Denomination</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Opening</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Received (+)</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid Out (-)</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Closing Qty</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Closing Amount</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                                $total_opening_amt = 0; $total_received_amt = 0; $total_paid_amt = 0; $total_closing_amt = 0;
                                foreach ($denominations_data_live as $d) {
                                    $db_col = $d['name'];
                                    $value = (int)$d['value'];
                                    
                                    $opening_qty = $todays_opening[$db_col] ?? 0;
                                    $received_qty = 0;
                                    foreach($d['db_keys'] as $key) { $received_qty += $todays_received_raw[$key] ?? 0; }
                                    $paid_qty = 0;
                                    foreach($d['db_keys'] as $key) { $paid_qty += $todays_paid_raw[$key] ?? 0; }
                                    
                                    $closing_qty = $opening_qty + $received_qty - $paid_qty;
                                    echo '<input type="hidden" name="closing_qty[' . $d['name'] . ']" value="' . $closing_qty . '">';

                                    $total_opening_amt += $opening_qty * $value;
                                    $total_received_amt += $received_qty * $value;
                                    $total_paid_amt += $paid_qty * $value;
                                    $total_closing_amt += $closing_qty * $value;
                            ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap"><div class="flex items-center"><i class="fas <?= $d['icon'] ?> text-indigo-500 mr-3"></i><span class="font-semibold text-gray-800"><?= $d['label'] ?></span></div></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-gray-600"><?= $opening_qty ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-green-600"><?= $received_qty ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right font-medium text-red-600"><?= $paid_qty ?></td>
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
                                    <td class="px-6 py-4 text-right"></td>
                                    <td class="px-6 py-4 text-right text-xl text-indigo-800">₹ <?= number_format($total_closing_amt, 2) ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </form>
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

<script>
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
</script>

</body>
</html>