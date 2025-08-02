<?php
// received_payments.php
include 'config/db.php';

// Fetch all transaction summaries
$sql = "SELECT id, party_name, company_name, payment_mode, grand_total, dues_amount, final_payable_amount, transaction_date FROM transactions ORDER BY id DESC";
$transactions = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Received Payments</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .table-header { background-color: #1f2937; color: #ffffff; }
        .modal-backdrop { background-color: rgba(0, 0, 0, 0.6); }
        .action-btn { transition: all 0.2s ease-in-out; }
        .action-btn:hover { transform: scale(1.1); }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8 text-center">
        <h1 class="text-4xl font-bold text-gray-800">Received Payments</h1>
        <p class="text-lg text-gray-500 mt-2">Summary of all transactions.</p>
    </div>

    <!-- Table Card -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-2xl font-semibold text-gray-700">Transactions</h2>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-search text-gray-400"></i></span>
                <input type="text" id="searchInput" placeholder="Search..." class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-600">
                <thead class="table-header">
                    <tr>
                        <th class="px-6 py-3">ID</th>
                        <th class="px-6 py-3">Party Name</th>
                        <th class="px-6 py-3 hidden md:table-cell">Company</th>
                        <th class="px-6 py-3">Final Amount</th>
                        <th class="px-6 py-3 hidden sm:table-cell">Date</th>
                        <th class="px-6 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="transactionTableBody">
                    <?php if ($transactions->num_rows > 0): ?>
                        <?php while($row = $transactions->fetch_assoc()): ?>
                            <tr class="border-b hover:bg-gray-100">
                                <td class="px-6 py-4 font-bold text-gray-800">#<?php echo $row['id']; ?></td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($row['party_name']); ?></td>
                                <td class="px-6 py-4 hidden md:table-cell"><?php echo htmlspecialchars($row['company_name']); ?></td>
                                <td class="px-6 py-4 font-semibold text-green-600">₹<?php echo number_format($row['final_payable_amount'], 2); ?></td>
                                <td class="px-6 py-4 hidden sm:table-cell"><?php echo date("d M, Y", strtotime($row['transaction_date'])); ?></td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick="openModal(<?php echo $row['id']; ?>)" class="action-btn text-blue-500 hover:text-blue-700" title="View Details">
                                        <i class="fas fa-eye fa-lg"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-10 text-gray-500">No transactions found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="fixed inset-0 z-50 hidden items-center justify-center modal-backdrop">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl mx-4 transform transition-transform duration-300 scale-95">
        <div class="flex justify-between items-center p-5 border-b">
            <h3 class="text-2xl font-semibold text-gray-800">Transaction Details</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times fa-2x"></i></button>
        </div>
        <div class="p-6 max-h-[70vh] overflow-y-auto" id="modalBody">
            <!-- Details will be loaded here by JavaScript -->
            <div class="text-center py-10">
                <i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i>
                <p class="mt-4 text-gray-600">Loading Details...</p>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // --- Live Search ---
    $('#searchInput').on('keyup', function() {
        const filter = $(this).val().toLowerCase();
        $('#transactionTableBody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(filter) > -1)
        });
    });

    // --- Modal Logic ---
    const modal = $('#detailsModal');

    function openModal(transactionId) {
        modal.removeClass('hidden').addClass('flex');
        setTimeout(() => modal.find('.transform').removeClass('scale-95'), 10);

        // AJAX call to get details
        $.ajax({
            url: 'get_transaction_details.php',
            type: 'GET',
            data: { id: transactionId },
            dataType: 'json',
            success: function(response) {
                if (response.error) {
                    $('#modalBody').html(`<p class="text-red-500 text-center">${response.error}</p>`);
                    return;
                }
                
                let cashHtml = '<div class="mb-6"> <h4 class="text-xl font-semibold text-gray-700 mb-3 border-b pb-2"><i class="fas fa-money-bill-wave mr-2 text-green-500"></i>Cash Payments</h4>';
                if (response.cash_details.length > 0) {
                    cashHtml += '<ul class="space-y-2">';
                    response.cash_details.forEach(item => {
                        cashHtml += `<li class="flex justify-between items-center p-2 rounded-md bg-gray-50">
                                        <div><i class="fas fa-rupee-sign mr-2 text-gray-400"></i><span class="font-bold">${item.denomination_or_platform}</span> x ${item.quantity_or_utr}</div>
                                        <div class="font-semibold text-gray-800">₹${parseFloat(item.amount).toLocaleString('en-IN')}</div>
                                     </li>`;
                    });
                    cashHtml += '</ul>';
                } else {
                    cashHtml += '<p class="text-gray-500">No cash payments recorded.</p>';
                }
                cashHtml += '</div>';

                let onlineHtml = '<div> <h4 class="text-xl font-semibold text-gray-700 mb-3 border-b pb-2"><i class="fas fa-credit-card mr-2 text-blue-500"></i>Online Payments</h4>';
                if (response.online_details.length > 0) {
                    onlineHtml += '<ul class="space-y-2">';
                    response.online_details.forEach(item => {
                        onlineHtml += `<li class="flex justify-between items-center p-2 rounded-md bg-gray-50">
                                        <div><span class="font-bold">${item.denomination_or_platform}</span><br><span class="text-xs text-gray-500">UTR: ${item.quantity_or_utr}</span></div>
                                        <div class="font-semibold text-gray-800">₹${parseFloat(item.amount).toLocaleString('en-IN')}</div>
                                     </li>`;
                    });
                    onlineHtml += '</ul>';
                } else {
                    onlineHtml += '<p class="text-gray-500">No online payments recorded.</p>';
                }
                onlineHtml += '</div>';

                $('#modalBody').html(cashHtml + onlineHtml);
            },
            error: function() {
                $('#modalBody').html('<p class="text-red-500 text-center">Failed to load transaction details.</p>');
            }
        });
    }

    function closeModal() {
        modal.find('.transform').addClass('scale-95');
        setTimeout(() => {
            modal.addClass('hidden').removeClass('flex');
            $('#modalBody').html('<div class="text-center py-10"><i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i><p class="mt-4 text-gray-600">Loading Details...</p></div>');
        }, 300);
    }

    // Close modal on backdrop click
    modal.on('click', function(event) {
        if (event.target === this) {
            closeModal();
        }
    });
</script>

</body>
</html>