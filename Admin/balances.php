<?php
include '../config/db.php'; // Your DB connection

// Fetch all banks to populate the dropdown and the summary cards
$banks = $pdo->query("SELECT * FROM banks ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);

// --- NEW: CALCULATE TODAY'S TOTAL BALANCES ---

// 1. Get the total current balance by summing up the balances of all banks.
$sqlClosing = "SELECT SUM(account_balance) as total_closing FROM banks;";
$totalClosingResult = $pdo->query($sqlClosing)->fetch(PDO::FETCH_ASSOC);
$totalClosing = $totalClosingResult['total_closing'] ?? 0;

// 2. Calculate the net change (credits - debits) for today.
$sqlNetToday = "
    SELECT
        SUM(CASE WHEN transaction_type = 'CREDIT' THEN amount ELSE 0 END) as total_credits,
        SUM(CASE WHEN transaction_type = 'DEBIT' THEN amount ELSE 0 END) as total_debits
    FROM banks_transactions_history
    WHERE DATE(created_at) = CURDATE()
";
$netTodayResult = $pdo->query($sqlNetToday)->fetch(PDO::FETCH_ASSOC);
$netChangeToday = ($netTodayResult['total_credits'] ?? 0) - ($netTodayResult['total_debits'] ?? 0);

// 3. The opening balance for today is the current balance minus today's net change.
$totalOpening = $totalClosing - $netChangeToday;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Account Ledger</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="bg-gray-100 text-gray-800">

  <div class="p-6 bg-gradient-to-r from-indigo-600 to-purple-600 text-white text-center shadow-lg">
    <h1 class="text-3xl font-bold flex items-center justify-center gap-3">
      <i class="fa-solid fa-file-invoice-dollar"></i> Account Ledger
    </h1>
    <p class="text-sm mt-1">Daily Opening & Closing Balance Overview</p>
  </div>

  <div class="text-center bg-white py-2 shadow-sm border-b">
      <p class="text-sm text-gray-600">
          <strong class="font-normal">Today's Total Summary:</strong>
          <span class="font-semibold mx-2">Day Opening: <span class="text-blue-600">₹<?= number_format($totalOpening, 2) ?></span></span> |
          <span class="font-semibold mx-2">Current Balance: <span class="text-green-600">₹<?= number_format($totalClosing, 2) ?></span></span>
      </p>
  </div>


  <div class="container mx-auto px-6 py-8">
    <h2 class="text-2xl font-semibold mb-4 flex items-center gap-2">
      <i class="fa-solid fa-piggy-bank text-pink-500"></i> All Banks - Current Balances
    </h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
      <?php if (!empty($banks)): ?>
        <?php foreach ($banks as $bank): ?>
          <div class="bg-white shadow-lg rounded-2xl p-5 hover:scale-105 transition">
            <div class="flex items-center justify-between">
              <h3 class="font-semibold text-lg flex items-center gap-2">
                <i class="fa-solid fa-landmark text-indigo-500"></i> <?= htmlspecialchars($bank['bank_name']) ?>
              </h3>
              <i class="fa-solid fa-credit-card text-gray-400"></i>
            </div>
            <p class="text-sm text-gray-500 mt-1">Acc No: <?= htmlspecialchars($bank['account_number']) ?></p>
            <p class="mt-4 text-xl font-bold text-green-600">
              ₹<?= number_format($bank['account_balance'], 2) ?>
            </p>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="text-center text-gray-500 col-span-3">No banks found. Please add a bank first.</p>
      <?php endif; ?>
    </div>

    <h2 class="text-2xl font-semibold mt-10 mb-4 flex items-center gap-2">
      <i class="fa-solid fa-calendar-day text-blue-500"></i> View Daily Ledger for a Bank
    </h2>
    
    <div class="bg-white p-6 rounded-2xl shadow-lg">
      <div class="mb-4">
        <label for="bank-selector" class="block text-sm font-medium text-gray-700 mb-2">Select a Bank:</label>
        <select id="bank-selector" class="block w-full md:w-1/3 p-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <?php if (!empty($banks)): ?>
            <?php foreach ($banks as $bank): ?>
              <option value="<?= $bank['id'] ?>"><?= htmlspecialchars($bank['bank_name']) ?></option>
            <?php endforeach; ?>
          <?php else: ?>
            <option value="">No banks available</option>
          <?php endif; ?>
        </select>
      </div>

      <div id="bank-details-container" class="overflow-x-auto">
        <p class="text-center text-gray-500 p-8">Please select a bank to view its daily balance ledger.</p>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const bankSelector = document.getElementById('bank-selector');
      const container = document.getElementById('bank-details-container');

      // Function to fetch and display bank balances
      const getBankDetails = (bankId) => {
        if (!bankId) {
          container.innerHTML = '<p class="text-center text-gray-500 p-8">Please select a bank to view its daily balance ledger.</p>';
          return;
        }

        // Show a loading message
        container.innerHTML = '<p class="text-center text-blue-500 p-8 font-semibold">Loading daily ledger...</p>';

        // Fetch the data from the server
        fetch(`get_bank_balances.php?bank_id=${bankId}`)
          .then(response => response.text())
          .then(html => {
            container.innerHTML = html;
          })
          .catch(error => {
            console.error('Error fetching bank details:', error);
            container.innerHTML = '<p class="text-center text-red-500 p-8">Failed to load data. Please try again.</p>';
          });
      };

      // Add event listener to the dropdown
      bankSelector.addEventListener('change', () => {
        getBankDetails(bankSelector.value);
      });

      // Automatically load the details for the first bank on page load
      if (bankSelector.value) {
        getBankDetails(bankSelector.value);
      }
    });
  </script>
</body>
</html>