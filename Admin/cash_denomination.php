<?php
// File: cash_denomination.php
session_start();
// IMPORTANT: Make sure this path is correct
include '../config/db.php';

// --- PHP LOGIC PART ---

// ACTION: HANDLE AJAX REQUEST FOR MODAL DETAILS
if (isset($_GET['action']) && $_GET['action'] == 'get_details' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM cash_denominations WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($data);
    exit(); // Stop script execution after sending JSON
}

// ACTION: HANDLE FORM SUBMISSION TO SAVE DATA
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_denomination'])) {
    $entry_date = $conn->real_escape_string($_POST['entry_date']);
    $description = $conn->real_escape_string($_POST['description']);
    
    $denominations = ['n500', 'n200', 'n100', 'n50', 'n20', 'n10', 'c10', 'c5', 'c2', 'c1'];
    $values = [];
    foreach ($denominations as $d) {
        $values[$d] = isset($_POST[$d]) ? (int)$_POST[$d] : 0;
    }

    $total_amount = ($values['n500'] * 500) + ($values['n200'] * 200) + ($values['n100'] * 100) + 
                    ($values['n50'] * 50) + ($values['n20'] * 20) + ($values['n10'] * 10) + 
                    ($values['c10'] * 10) + ($values['c5'] * 5) + ($values['c2'] * 2) + ($values['c1'] * 1);

    $stmt = $conn->prepare(
        "INSERT INTO cash_denominations (entry_date, description, n500, n200, n100, n50, n20, n10, c10, c5, c2, c1, total_amount) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("ssiiiiiiiiiid", $entry_date, $description, $values['n500'], $values['n200'], 
        $values['n100'], $values['n50'], $values['n20'], $values['n10'], $values['c10'], 
        $values['c5'], $values['c2'], $values['c1'], $total_amount);

    if ($stmt->execute()) {
        $_SESSION['message'] = "Denomination for $entry_date added successfully!";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = $conn->errno == 1062 ? "Error: An entry for $entry_date already exists." : "Error: " . $stmt->error;
        $_SESSION['msg_type'] = "danger";
    }
    $stmt->close();
    header("Location: cash_denomination.php");
    exit();
}

// --- LIVE CASH STATUS LOGIC ---
$today = date('Y-m-d');
$todays_opening = [];
$todays_spent_raw = [];
$todays_spent = [];
$live_total = 0;

// 1. Fetch Today's Opening Balance
$stmt_opening = $conn->prepare("SELECT * FROM cash_denominations WHERE entry_date = ?");
if ($stmt_opening) {
    $stmt_opening->bind_param("s", $today);
    $stmt_opening->execute();
    $result_opening = $stmt_opening->get_result();
    if ($result_opening->num_rows > 0) {
        $todays_opening = $result_opening->fetch_assoc();
    }
    $stmt_opening->close();
}

// 2. Fetch Today's Spent Cash Denominations using the correct `transaction_date` column
$sql_spent = "SELECT td.denomination_or_platform, SUM(td.quantity_or_utr) AS total_quantity_spent
              FROM transaction_details td
              JOIN transactions t ON td.transaction_id = t.id
              WHERE td.detail_type = 'cash' AND DATE(t.transaction_date) = ?
              GROUP BY td.denomination_or_platform";

$stmt_spent = $conn->prepare($sql_spent);

if ($stmt_spent === false) {
    // This error handling is kept for safety, but the query should now work correctly.
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px;'>";
    echo "<b>Database Query Error:</b> Could not prepare the statement to fetch spent cash. Please check table and column names.";
    echo "<br><br>Error details: " . htmlspecialchars($conn->error);
    echo "</div>";
} else {
    $stmt_spent->bind_param("s", $today);
    $stmt_spent->execute();
    $result_spent = $stmt_spent->get_result();
    while($row = $result_spent->fetch_assoc()){
        $todays_spent_raw[$row['denomination_or_platform']] = $row['total_quantity_spent'];
    }
    $stmt_spent->close();
}

// Helper to map denomination values to database column names
$denom_map_to_db = [
    '500' => 'n500', '200' => 'n200', '100' => 'n100', '50' => 'n50', '20' => 'n20',
    '10' => 'n10',  
    '5' => 'c5', '2' => 'c2', '1' => 'c1'
];

// Populate the structured $todays_spent array
foreach($denom_map_to_db as $value => $db_col){
    $todays_spent[$db_col] = isset($todays_spent_raw[$value]) ? (int)$todays_spent_raw[$value] : 0;
}
$todays_spent['c10'] = isset($todays_spent_raw['10_coin']) ? (int)$todays_spent_raw['10_coin'] : 0;


// ACTION: FETCH ALL RECORDS FOR THE LIST VIEW
$all_denominations = [];
$result = $conn->query("SELECT id, entry_date, description, total_amount FROM cash_denominations ORDER BY entry_date DESC");
if ($result) {
    while($row = $result->fetch_assoc()) {
        $all_denominations[] = $row;
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cash Denomination Manager</title>
    
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #4a90e2;
            --secondary-color: #50e3c2;
            --bg-color: #f4f7f6;
            --card-bg: #ffffff;
            --text-color: #333;
            --light-text: #777;
            --border-color: #e0e0e0;
            --shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .main-header h1 {
            color: var(--primary-color);
            margin: 0;
            font-weight: 700;
        }
        .btn {
            text-decoration: none;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
        }
        .btn-primary { background-color: var(--primary-color); }
        .btn-primary:hover { background-color: #357abd; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(74,144,226,0.4); }
        .btn-success { background-color: #28a745; }
        .btn-success:hover { background-color: #218838; }
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 30px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }
        .card-header {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .input-group {
            display: flex;
            align-items: center;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
            transition: box-shadow 0.3s ease;
        }
        .input-group:focus-within { box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.3); }
        .input-group .icon {
            padding: 12px;
            background: var(--bg-color);
            color: var(--primary-color);
            font-size: 1.1rem;
            min-width: 70px;
            text-align: center;
        }
        .input-group input {
            border: none;
            outline: none;
            padding: 12px;
            width: 100%;
            font-size: 1rem;
        }
        .input-group .sub-total {
            padding: 12px;
            font-weight: 600;
            background: #e9f2fe;
            color: var(--primary-color);
            min-width: 100px;
            text-align: right;
        }
        .grand-total {
            margin-top: 30px;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            text-align: center;
            border-radius: 8px;
        }
        .grand-total h3 { margin: 0; font-size: 1.8rem; letter-spacing: 1px;}
        .form-actions { margin-top: 30px; text-align: right; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            padding: 15px;
            text-align: left;
        }
        .data-table thead {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        .data-table tbody tr {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        .data-table tbody tr:last-child { border-bottom: none; }
        .data-table tbody tr:hover { background-color: #f9f9f9; }
        .action-btn {
            background: none; border: none; cursor: pointer; color: var(--primary-color); font-size: 1.2rem;
            padding: 5px; border-radius: 50%; width: 35px; height: 35px; line-height: 25px;
            transition: background-color 0.2s, color 0.2s;
        }
        .action-btn:hover { background-color: var(--primary-color); color: white; }
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: var(--card-bg); margin: auto; padding: 30px;
            border-radius: 12px; max-width: 600px; width: 90%;
            position: relative; box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            animation: slide-in 0.4s ease-out;
        }
        @keyframes slide-in { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px; }
        .modal-header h2 { margin: 0; color: var(--primary-color); }
        .close-btn { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; transition: color 0.3s; }
        .close-btn:hover, .close-btn:focus { color: #333; }
        .modal-body { max-height: 60vh; overflow-y: auto; }
        .detail-list { list-style: none; padding: 0; }
        .detail-list li { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
        .detail-list li:last-child { border-bottom: none; }
        .detail-list .label { font-weight: 600; }
        .detail-list .value { color: var(--primary-color); font-weight: 600; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 8px; color: white; position: relative; }
        .alert-success { background-color: #28a745; }
        .alert-danger { background-color: #dc3545; }
        .alert .close-alert { float: right; font-size: 20px; font-weight: bold; cursor: pointer; }

        /* LIVE STATUS STYLES */
        .live-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .status-item { background: #f8f9fa; border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; }
        .status-item-header { display: flex; align-items: center; font-size: 1.2rem; font-weight: 600; margin-bottom: 10px; }
        .status-item-header .icon { color: var(--primary-color); margin-right: 10px; }
        .status-details { list-style: none; padding: 0; margin: 0; }
        .status-details li { display: flex; justify-content: space-between; padding: 6px 0; font-size: 0.95rem; }
        .status-details .label { color: var(--light-text); }
        .status-details .value { font-weight: 600; }
        .status-details .value.spent { color: #dc3545; }
        .status-details .value.remaining { color: #28a745; font-size: 1.1rem; }
    </style>
</head>
<body>
    <div class="container">
        <header class="main-header" data-aos="fade-down">
            <h1><i class="fas fa-cash-register"></i> Cash Manager</h1>
            <?php if (isset($_GET['action']) && $_GET['action'] == 'add'): ?>
                <a href="cash_denomination.php" class="btn btn-primary"><i class="fas fa-list"></i> View All Entries</a>
            <?php else: ?>
                <a href="cash_denomination.php?action=add" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Add New Entry</a>
            <?php endif; ?>
        </header>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['msg_type'] ?>" data-aos="fade-left">
                <?= $_SESSION['message'] ?>
                <span class="close-alert" onclick="this.parentElement.style.display='none';">&times;</span>
            </div>
        <?php unset($_SESSION['message']); unset($_SESSION['msg_type']); endif; ?>

        <?php if (isset($_GET['action']) && $_GET['action'] == 'add'): ?>
            <div class="card" data-aos="fade-up">
                <div class="card-header"><i class="fas fa-calculator"></i> Add New Day's Denomination</div>
                <form action="cash_denomination.php" method="POST" id="denominationForm">
                    <div class="form-grid">
                        <div class="form-group"><label for="entry_date">Date</label><div class="input-group"><span class="icon"><i class="fas fa-calendar-alt"></i></span><input type="date" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required></div></div>
                        <div class="form-group"><label for="description">Description</label><div class="input-group"><span class="icon"><i class="fas fa-tag"></i></span><input type="text" id="description" name="description" value="Morning Opening Cash" required></div></div>
                    </div>
                    <hr style="margin: 30px 0; border: 0; border-top: 1px solid var(--border-color);">
                    <div class="form-grid">
                        <?php
                            $denominations_data = [
                                ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 x', 'icon' => 'fa-money-bill-wave'], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 x', 'icon' => 'fa-money-bill-wave'],
                                ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 x', 'icon' => 'fa-money-bill-wave'], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 x', 'icon' => 'fa-money-bill-wave'],
                                ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 x', 'icon' => 'fa-money-bill-wave'], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Note x', 'icon' => 'fa-money-bill-wave'],
                                ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coin x', 'icon' => 'fa-coins'], ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coin x', 'icon' => 'fa-coins'],
                                ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coin x', 'icon' => 'fa-coins'], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coin x', 'icon' => 'fa-coins'],
                            ];
                        ?>
                        <?php foreach($denominations_data as $d): ?>
                            <div class="form-group"><div class="input-group"><label for="<?= $d['name'] ?>" class="icon"><i class="fas <?= $d['icon'] ?>"></i> &nbsp;<?= $d['label'] ?></label><input type="number" name="<?= $d['name'] ?>" id="<?= $d['name'] ?>" min="0" placeholder="Qty" class="qty-input" data-value="<?= $d['value'] ?>"><span class="sub-total" id="total_<?= $d['name'] ?>">₹ 0</span></div></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="grand-total" data-aos="zoom-in"><h3>GRAND TOTAL: <span id="grandTotal">₹ 0.00</span></h3></div>
                    <div class="form-actions"><button type="submit" name="save_denomination" class="btn btn-success"><i class="fas fa-save"></i> Save Entry</button></div>
                </form>
            </div>
        <?php else: ?>
            <?php if (!empty($todays_opening)): ?>
                <div class="card" data-aos="fade-up">
                    <div class="card-header"><i class="fas fa-chart-line"></i> Today's Live Cash Status (<?= date("d M Y") ?>)</div>
                    <div class="live-status-grid">
                        <?php
                        $live_total_amount = 0;
                        $denominations_data_live = [
                                ['name' => 'n500', 'value' => 500, 'label' => '₹ 500 Notes', 'icon' => 'fa-money-bill-wave'], ['name' => 'n200', 'value' => 200, 'label' => '₹ 200 Notes', 'icon' => 'fa-money-bill-wave'],
                                ['name' => 'n100', 'value' => 100, 'label' => '₹ 100 Notes', 'icon' => 'fa-money-bill-wave'], ['name' => 'n50', 'value' => 50, 'label' => '₹ 50 Notes', 'icon' => 'fa-money-bill-wave'],
                                ['name' => 'n20', 'value' => 20, 'label' => '₹ 20 Notes', 'icon' => 'fa-money-bill-wave'], ['name' => 'n10', 'value' => 10, 'label' => '₹ 10 Notes', 'icon' => 'fa-money-bill-wave'],
                                ['name' => 'c10', 'value' => 10, 'label' => '₹ 10 Coins', 'icon' => 'fa-coins'], ['name' => 'c5', 'value' => 5, 'label' => '₹ 5 Coins', 'icon' => 'fa-coins'],
                                ['name' => 'c2', 'value' => 2, 'label' => '₹ 2 Coins', 'icon' => 'fa-coins'], ['name' => 'c1', 'value' => 1, 'label' => '₹ 1 Coins', 'icon' => 'fa-coins'],
                            ];
                        
                        foreach($denominations_data_live as $d):
                            $db_col = $d['name'];
                            $value = (int)$d['value'];

                            $opening_qty = isset($todays_opening[$db_col]) ? (int)$todays_opening[$db_col] : 0;
                            $spent_qty = isset($todays_spent[$db_col]) ? (int)$todays_spent[$db_col] : 0;
                            $remaining_qty = $opening_qty - $spent_qty;
                            
                            $live_total_amount += ($remaining_qty * $value);
                        ?>
                            <div class="status-item">
                                <div class="status-item-header">
                                    <i class="icon fas <?= $d['icon'] ?>"></i> <?= $d['label'] ?>
                                </div>
                                <ul class="status-details">
                                    <li><span class="label">Opening Qty:</span> <span class="value"><?= $opening_qty ?></span></li>
                                    <li><span class="label">Spent Qty:</span> <span class="value spent">- <?= $spent_qty ?></span></li>
                                    <hr style="border: 0; border-top: 1px dashed #ccc; margin: 4px 0;">
                                    <li><span class="label">Remaining Qty:</span> <span class="value remaining"><?= $remaining_qty ?></span></li>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="grand-total" data-aos="zoom-in" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <h3>REMAINING CASH IN HAND: <span>₹ <?= number_format($live_total_amount, 2) ?></span></h3>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-danger" data-aos="fade-left">
                    No opening cash denomination has been added for today (<?= date("d M Y") ?>). Add an entry to see the live status.
                </div>
            <?php endif; ?>

            <div class="card" data-aos="fade-up" data-aos-delay="100">
                <div class="card-header"><i class="fas fa-history"></i> Historical Entries</div>
                <table class="data-table">
                    <thead>
                        <tr><th>Date</th><th>Description</th><th>Opening Amount</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_denominations)): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 40px;">No entries found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($all_denominations as $row): ?>
                                <tr data-aos="fade-right" data-aos-delay="150">
                                    <td><?= date("D, j M Y", strtotime($row['entry_date'])) ?></td>
                                    <td><?= htmlspecialchars($row['description']) ?></td>
                                    <td><strong>₹ <?= number_format($row['total_amount'], 2) ?></strong></td>
                                    <td>
                                        <button class="action-btn view-btn" data-id="<?= $row['id'] ?>" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="detailsModal" class="modal">
        <div class="modal-content"><div class="modal-header"><h2 id="modalTitle">Denomination Details</h2><span class="close-btn">&times;</span></div><div class="modal-body" id="modalBody"></div></div>
    </div>
    
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ once: true, duration: 600 });
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
                    document.getElementById(`total_${input.name}`).innerText = `₹ ${subTotal.toLocaleString('en-IN')}`;
                });
                grandTotalEl.innerText = `₹ ${grandTotal.toLocaleString('en-IN', {minimumFractionDigits: 2})}`;
            }
            qtyInputs.forEach(input => { input.addEventListener('input', calculateTotal); });
        }
        const modal = document.getElementById('detailsModal');
        const closeBtn = document.querySelector('.close-btn');
        const viewBtns = document.querySelectorAll('.view-btn');
        viewBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                fetch(`cash_denomination.php?action=get_details&id=${id}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data) {
                            const modalTitle = document.getElementById('modalTitle');
                            const modalBody = document.getElementById('modalBody');
                            const entryDate = new Date(data.entry_date + 'T00:00:00');
                            const formattedDate = entryDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
                            modalTitle.innerHTML = `Details for <span style="color:#50e3c2;">${formattedDate}</span>`;
                            let detailsHtml = `<ul class="detail-list"><li><span class="label">Description:</span> <span class="value">${data.description}</span></li><hr>`;
                            const denoms = [
                                {label: '₹ 500 Notes', key: 'n500', value: 500}, {label: '₹ 200 Notes', key: 'n200', value: 200},
                                {label: '₹ 100 Notes', key: 'n100', value: 100}, {label: '₹ 50 Notes', key: 'n50', value: 50},
                                {label: '₹ 20 Notes', key: 'n20', value: 20}, {label: '₹ 10 Notes', key: 'n10', value: 10},
                                {label: '₹ 10 Coins', key: 'c10', value: 10}, {label: '₹ 5 Coins', key: 'c5', value: 5},
                                {label: '₹ 2 Coins', key: 'c2', value: 2}, {label: '₹ 1 Coins', key: 'c1', value: 1}
                            ];
                            denoms.forEach(d => {
                                if(data[d.key] > 0) {
                                    const subTotal = (data[d.key] * d.value).toLocaleString('en-IN');
                                    detailsHtml += `<li><span class="label">${d.label} (x${data[d.key]})</span> <span class="value">₹ ${subTotal}</span></li>`;
                                }
                            });
                            detailsHtml += `<hr><li style="font-size: 1.2rem;"><span class="label">TOTAL</span> <span class="value">₹ ${parseFloat(data.total_amount).toLocaleString('en-IN', {minimumFractionDigits: 2})}</span></li></ul>`;
                            modalBody.innerHTML = detailsHtml;
                            modal.style.display = 'flex';
                        }
                    })
                    .catch(error => console.error('Error fetching details:', error));
            });
        });
        function closeModal() { modal.style.display = 'none'; }
        closeBtn.addEventListener('click', closeModal);
        window.addEventListener('click', function(event) { if (event.target == modal) { closeModal(); } });
        window.addEventListener('keydown', function(event) { if (event.key === 'Escape') { closeModal(); } });
    </script>
</body>
</html>