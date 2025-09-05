<?php
include '../config/db.php';

$bankId = $_GET['bank_id'] ?? 0;

if (!$bankId) {
    echo "<p class='text-center text-red-500'>Invalid bank selected.</p>";
    exit;
}

// Fetch all transaction history for the selected bank, ordered newest to oldest
$sql = "
    SELECT 
        id,
        transaction_id,
        transaction_type,
        amount,
        balance_before,
        balance_after,
        created_at
    FROM banks_transactions_history
    WHERE bank_id = ?
    ORDER BY created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$bankId]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$transactions) {
    echo "<p class='text-center text-gray-500 py-8'>No transaction history found for this bank.</p>";
    exit;
}

// Process transactions into a daily ledger format
$ledger = [];
foreach ($transactions as $txn) {
    $date = date('Y-m-d', strtotime($txn['created_at']));

    // Initialize the day if it's the first transaction we've seen for it
    if (!isset($ledger[$date])) {
        $ledger[$date] = [
            'date' => $date,
            'closing_balance' => null, // Will be the 'balance_after' of the last transaction of the day
            'opening_balance' => $txn['balance_before'], // Is the 'balance_before' of the first transaction we see (which is the last chronologically)
            'total_credits' => 0,
            'total_debits' => 0,
            'transactions' => []
        ];
    }
    
    // The closing balance for the day is from the very first transaction we process (which is the latest one)
    if ($ledger[$date]['closing_balance'] === null) {
        $ledger[$date]['closing_balance'] = $txn['balance_after'];
    }
    
    // The opening balance for the day will be the 'balance_before' of the last transaction we process for that day (the earliest one)
    $ledger[$date]['opening_balance'] = $txn['balance_before'];

    // Sum up credits and debits
    if (strtoupper($txn['transaction_type']) === 'CREDIT') {
        $ledger[$date]['total_credits'] += $txn['amount'];
    } else {
        $ledger[$date]['total_debits'] += $txn['amount'];
    }

    // Add the individual transaction to its day
    $ledger[$date]['transactions'][] = $txn;
}
?>

<style>
  /* Style for the expandable row */
  .daily-summary-row {
    cursor: pointer;
  }
  .daily-summary-row:hover {
    background-color: #f9fafb; /* gray-50 */
  }
  .details-row {
    display: none;
    background-color: #f1f5f9; /* slate-100 */
  }
  .details-row td {
    padding: 0 !important;
    border-top: 2px solid #6366f1; /* indigo-500 */
  }
  .icon-toggle {
    transition: transform 0.2s ease-in-out;
  }
  .rotated {
    transform: rotate(90deg);
  }
</style>

<table class="min-w-full bg-white shadow-md rounded-xl overflow-hidden">
  <thead class="bg-indigo-600 text-white">
    <tr>
      <th class="py-3 px-4 text-left w-12"></th>
      <th class="py-3 px-4 text-left">Date</th>
      <th class="py-3 px-4 text-left">Opening Balance</th>
      <th class="py-3 px-4 text-left">Credits (+)</th>
      <th class="py-3 px-4 text-left">Debits (-)</th>
      <th class="py-3 px-4 text-left">Closing Balance</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($ledger as $day): ?>
      <tr class="daily-summary-row border-b" onclick="toggleDetails(this)">
        <td class="py-3 px-4 text-center">
          <i class="fa-solid fa-chevron-right icon-toggle text-indigo-500"></i>
        </td>
        <td class="py-3 px-4 font-medium"><?= date("d M Y", strtotime($day['date'])) ?></td>
        <td class="py-3 px-4 text-blue-600 font-semibold">₹<?= number_format($day['opening_balance'], 2) ?></td>
        <td class="py-3 px-4 text-green-600">+ ₹<?= number_format($day['total_credits'], 2) ?></td>
        <td class="py-3 px-4 text-red-600">- ₹<?= number_format($day['total_debits'], 2) ?></td>
        <td class="py-3 px-4 text-purple-600 font-semibold">₹<?= number_format($day['closing_balance'], 2) ?></td>
      </tr>
      <tr class="details-row">
        <td colspan="6">
          <div class="p-4">
            <h4 class="font-semibold text-gray-700 mb-2">Transactions for <?= date("d M Y", strtotime($day['date'])) ?>:</h4>
            <table class="min-w-full bg-white rounded">
              <thead class="bg-gray-200 text-gray-600 text-sm">
                <tr>
                  <th class="py-2 px-3 text-left">Time</th>
                  <th class="py-2 px-3 text-left">Type</th>
                  <th class="py-2 px-3 text-left">Amount</th>
                  <th class="py-2 px-3 text-left">Balance After</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_reverse($day['transactions']) as $txn): // Reverse to show in chronological order ?>
                  <tr class="border-b">
                    <td class="py-2 px-3"><?= date("h:i:s A", strtotime($txn['created_at'])) ?></td>
                    <td class="py-2 px-3">
                      <?php if (strtoupper($txn['transaction_type']) === 'CREDIT'): ?>
                        <span class="font-semibold text-green-600">CREDIT</span>
                      <?php else: ?>
                        <span class="font-semibold text-red-600">DEBIT</span>
                      <?php endif; ?>
                    </td>
                    <td class="py-2 px-3 font-mono"><?= number_format($txn['amount'], 2) ?></td>
                    <td class="py-2 px-3 font-mono font-semibold"><?= number_format($txn['balance_after'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<script>
  // Simple function to toggle the visibility of the transaction details row
  function toggleDetails(rowElement) {
    const detailsRow = rowElement.nextElementSibling;
    const icon = rowElement.querySelector('.icon-toggle');
    
    if (detailsRow.style.display === 'table-row') {
      detailsRow.style.display = 'none';
      icon.classList.remove('rotated');
    } else {
      detailsRow.style.display = 'table-row';
      icon.classList.add('rotated');
    }
  }
</script>