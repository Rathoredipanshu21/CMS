<?php
// --- DATABASE CONNECTION & AJAX HANDLING ---

// Use a single connection for the entire script.
// IMPORTANT: Ensure this path is correct for your project structure.
@include '../config/db.php';

// AJAX Request Handler for UTR Management
// This block will execute only when an AJAX request is sent from the page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json'); // Set header for JSON response
    $response = [];

    if (!isset($conn) || $conn->connect_error) {
        $response['error'] = 'Database connection failed.';
        echo json_encode($response);
        exit();
    }

    // ACTION: Fetch transactions for a specific date
    if ($_POST['action'] === 'get_transactions' && isset($_POST['date'])) {
        $date = $_POST['date'];
        
        // This query joins the three tables to get all necessary information.
        // It selects bank transactions for the chosen date.
        $stmt = $conn->prepare("
            SELECT 
                td.id as detail_id, 
                t.id as transaction_id, 
                c.name as customer_name,
                t.company_name, 
                td.amount, 
                td.quantity_or_utr
            FROM transaction_details td
            JOIN transactions t ON td.transaction_id = t.id
            JOIN customers c ON t.customer_id = c.id
            WHERE td.detail_type = 'BANK' AND DATE(t.transaction_date) = ?
        ");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
        
        $response['success'] = true;
        $response['transactions'] = $transactions;
        $stmt->close();
    }

    // ACTION: Update UTRs for a list of transaction detail IDs
    if ($_POST['action'] === 'update_utrs' && isset($_POST['new_utr'], $_POST['detail_ids'])) {
        $new_utr = $_POST['new_utr'];
        $detail_ids = $_POST['detail_ids'];

        if (empty($detail_ids)) {
             $response['error'] = 'No transactions selected for update.';
        } else {
            // Create placeholders for the IN clause to prevent SQL injection
            $placeholders = implode(',', array_fill(0, count($detail_ids), '?'));
            $types = 's' . str_repeat('i', count($detail_ids));
            $params = array_merge([$new_utr], $detail_ids);

            $stmt = $conn->prepare("UPDATE transaction_details SET quantity_or_utr = ? WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Successfully updated " . $stmt->affected_rows . " transactions.";
            } else {
                $response['error'] = "Failed to update transactions: " . $stmt->error;
            }
            $stmt->close();
        }
    }

    echo json_encode($response);
    $conn->close();
    exit(); // Stop script execution after handling AJAX request
}


// --- INITIAL PAGE LOAD DATA FETCHING (for the company dashboard) ---
$companies_json = '[]';
$error_message = '';

if (isset($conn) && !$conn->connect_error) {
    try {
        $companies_sql = "SELECT id, company_name, commission_percentage FROM company_commissions";
        $companies_result = $conn->query($companies_sql);
        $companies = [];
        if ($companies_result && $companies_result->num_rows > 0) {
            while ($row = $companies_result->fetch_assoc()) {
                $row['customers'] = [];
                $companies[$row['id']] = $row;
            }
        }

        $customers_sql = "SELECT id, name, father_name, mobile_no, email, company_id FROM customers";
        $customers_result = $conn->query($customers_sql);
        if ($customers_result && $customers_result->num_rows > 0) {
            while ($row = $customers_result->fetch_assoc()) {
                if (isset($companies[$row['company_id']])) {
                    $companies[$row['company_id']]['customers'][] = $row;
                }
            }
        }
        $companies_json = json_encode(array_values($companies));
    } catch (Exception $e) {
        $error_message = 'An error occurred while fetching company data: ' . $e->getMessage();
    }
} else {
    $error_message = 'Database connection failed. Please check your config/db.php file.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company & UTR Management</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .modal-body::-webkit-scrollbar { width: 8px; }
        .modal-body::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .modal-body::-webkit-scrollbar-thumb { background: #888; border-radius: 10px; }
        .modal-body::-webkit-scrollbar-thumb:hover { background: #555; }
        .modal-backdrop.hidden, .utr-results.hidden { display: none; }
        .modal-backdrop { animation: fadeIn 0.3s ease-out forwards; }
        .modal-content { animation: slideUp 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body class="antialiased text-gray-800">

    <!-- Section 1: Company Dashboard -->
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <header class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900">Company Dashboard</h1>
            <p class="mt-2 text-lg text-gray-600">Overview of Customers by Company</p>
        </header>
        <div id="company-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
            <p id="loading-state" class="col-span-full text-center text-gray-500">Loading company data...</p>
        </div>
    </div>

    <!-- Section 2: UTR Management -->
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-12 border-t-2 border-gray-200">
        <header class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900">Update Bank Transaction UTRs</h1>
            <p class="mt-2 text-lg text-gray-600">Select a date to bulk update UTR numbers for bank transactions.</p>
        </header>
        
        <!-- UTR Controls -->
        <div class="max-w-xl mx-auto bg-white p-8 rounded-2xl shadow-lg">
            <div class="space-y-4">
                <div>
                    <label for="transaction-date" class="block text-sm font-medium text-gray-700">Select Transaction Date</label>
                    <input type="date" id="transaction-date" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                </div>
                <button id="fetch-transactions-btn" class="w-full bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-indigo-700 transition duration-200 flex items-center justify-center">
                    <svg xmlns="http://www.w.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="m21 21-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
                    Fetch Transactions
                </button>
            </div>
        </div>

        <!-- UTR Results Area -->
        <div id="utr-results" class="utr-results hidden mt-12">
             <!-- Notification Area -->
            <div id="notification-area" class="mb-6"></div>

            <div class="max-w-4xl mx-auto bg-white p-8 rounded-2xl shadow-lg">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                    <input type="text" id="new-utr-input" placeholder="Enter New Bank Txn ID (UTR) for all" class="w-full md:w-2/3 px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <button id="update-all-btn" class="w-full md:w-auto bg-green-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-green-700 transition duration-200 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Update All
                    </button>
                </div>
                <!-- Transactions Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer Name</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Bank Txn ID (UTR)</th>
                            </tr>
                        </thead>
                        <tbody id="transactions-table-body" class="bg-white divide-y divide-gray-200">
                            <!-- Rows will be inserted here by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <!-- Customer Modal (from original code) -->
    <div id="customer-modal" class="modal-backdrop fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 z-50 hidden">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col transform">
            <div class="flex items-center justify-between p-6 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/><path d="M16 14h.01"/></svg>
                    <h2 id="modal-company-name" class="text-2xl font-semibold text-gray-900">Company Customers</h2>
                </div>
                <button id="close-modal-btn" class="text-gray-400 hover:text-gray-700 transition duration-150"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
            <div id="modal-body" class="modal-body flex-grow p-6 overflow-y-auto"><ul id="customer-list" class="space-y-4"></ul></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- Elements for Company Dashboard ---
            const companyGrid = document.getElementById('company-grid');
            const loadingState = document.getElementById('loading-state');
            const modal = document.getElementById('customer-modal');
            const modalCompanyName = document.getElementById('modal-company-name');
            const customerList = document.getElementById('customer-list');
            const closeModalBtn = document.getElementById('close-modal-btn');
            
            // --- Elements for UTR Management ---
            const transactionDateInput = document.getElementById('transaction-date');
            const fetchBtn = document.getElementById('fetch-transactions-btn');
            const utrResultsDiv = document.getElementById('utr-results');
            const tableBody = document.getElementById('transactions-table-body');
            const newUtrInput = document.getElementById('new-utr-input');
            const updateAllBtn = document.getElementById('update-all-btn');
            const notificationArea = document.getElementById('notification-area');
            let currentDetailIds = []; // To store IDs of displayed transactions for updating

            // ==========================================================
            // LOGIC FOR COMPANY DASHBOARD
            // ==========================================================
            function processAndRenderDashboard() {
                const errorMessage = '<?php echo addslashes($error_message); ?>';
                if (errorMessage) {
                    loadingState.innerHTML = `<p class="text-red-500 font-semibold">${errorMessage}</p>`;
                    return;
                }
                try {
                    const data = JSON.parse('<?php echo $companies_json; ?>');
                    renderCompanyCards(data);
                } catch (error) {
                    loadingState.innerHTML = `<p class="text-red-500 font-semibold">Failed to render company data.</p>`;
                }
            }

            function renderCompanyCards(companies) {
                companyGrid.innerHTML = '';
                if (companies.length === 0) {
                    companyGrid.innerHTML = `<p class="text-gray-500 col-span-full text-center">No companies found.</p>`;
                    return;
                }
                companies.forEach(company => {
                    const customerCount = company.customers.length;
                    const card = document.createElement('div');
                    card.className = "bg-white rounded-2xl shadow-lg hover:shadow-xl transition-shadow duration-300 p-6 flex flex-col";
                    card.innerHTML = `
                        <div class="flex items-center gap-4 mb-4">
                            <div class="bg-indigo-100 p-3 rounded-full"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/></svg></div>
                            <h3 class="text-xl font-bold text-gray-800 truncate" title="${company.company_name}">${company.company_name}</h3>
                        </div>
                        <div class="flex-grow space-y-4 mb-6"><div class="flex items-start text-gray-600"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mr-3 mt-1 text-green-500 flex-shrink-0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><div><span class="font-semibold text-3xl text-gray-800">${customerCount}</span><span class="text-sm"> Total Customers</span></div></div></div>
                        <button class="view-customers-btn mt-auto w-full bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-indigo-700 transition duration-200">View All Customers</button>
                    `;
                    card.querySelector('.view-customers-btn').addEventListener('click', () => openModal(company.company_name, company.customers));
                    companyGrid.appendChild(card);
                });
            }

            function openModal(companyName, customers) {
                modalCompanyName.textContent = `${companyName} - Customers`;
                customerList.innerHTML = '';
                if (!customers || customers.length === 0) {
                    customerList.innerHTML = '<li><p class="text-gray-500 text-center">No customers found.</p></li>';
                } else {
                    customers.forEach(customer => {
                        const li = document.createElement('li');
                        li.className = 'flex items-center p-4 bg-gray-50 rounded-lg border';
                        li.innerHTML = `<div class="flex-shrink-0 bg-gray-200 h-12 w-12 rounded-full flex items-center justify-center mr-4"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-500"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div><div class="flex-grow"><p class="font-bold text-gray-800">${customer.name}</p><p class="text-sm text-gray-500">${customer.email || 'No email'}</p></div><div class="text-right text-sm"><p class="text-gray-600">${customer.mobile_no || 'No mobile'}</p></div>`;
                        customerList.appendChild(li);
                    });
                }
                modal.classList.remove('hidden');
            }

            function closeModal() { modal.classList.add('hidden'); }
            closeModalBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
            
            // ==========================================================
            // LOGIC FOR UTR MANAGEMENT
            // ==========================================================

            // --- Function to display notifications ---
            function showNotification(message, isError = false) {
                const color = isError ? 'red' : 'green';
                notificationArea.innerHTML = `<div class="p-4 mb-4 text-sm text-${color}-700 bg-${color}-100 rounded-lg" role="alert"><span class="font-medium">${isError ? 'Error!' : 'Success!'}</span> ${message}</div>`;
            }

            // --- Event handler for fetching transactions ---
            fetchBtn.addEventListener('click', async () => {
                const date = transactionDateInput.value;
                if (!date) {
                    showNotification('Please select a date first.', true);
                    return;
                }
                
                showNotification('Fetching data...', false);
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center p-4">Loading...</td></tr>';
                utrResultsDiv.classList.remove('hidden');

                const formData = new FormData();
                formData.append('action', 'get_transactions');
                formData.append('date', date);

                try {
                    const response = await fetch('utr_management.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.error) {
                        throw new Error(result.error);
                    }

                    renderTransactionsTable(result.transactions);
                    notificationArea.innerHTML = ''; // Clear notification on success
                } catch (error) {
                    console.error('Fetch error:', error);
                    showNotification(error.message, true);
                    tableBody.innerHTML = `<tr><td colspan="4" class="text-center p-4 text-red-500">Failed to load transactions.</td></tr>`;
                }
            });

            // --- Function to render the transactions table ---
            function renderTransactionsTable(transactions) {
                tableBody.innerHTML = '';
                currentDetailIds = []; // Reset the list of IDs

                if (transactions.length === 0) {
                    tableBody.innerHTML = '<tr><td colspan="4" class="text-center p-4">No bank transactions found for the selected date.</td></tr>';
                    return;
                }

                transactions.forEach(tx => {
                    currentDetailIds.push(tx.detail_id);
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${tx.customer_name}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${tx.company_name}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${parseFloat(tx.amount).toFixed(2)}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${tx.quantity_or_utr || '<span class="text-red-500">Not Set</span>'}</td>
                    `;
                    tableBody.appendChild(row);
                });
            }

            // --- Event handler for updating all UTRs ---
            updateAllBtn.addEventListener('click', async () => {
                const newUtr = newUtrInput.value.trim();
                if (!newUtr) {
                    showNotification('Please enter a new Bank Txn ID (UTR).', true);
                    return;
                }
                if (currentDetailIds.length === 0) {
                    showNotification('There are no transactions to update.', true);
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'update_utrs');
                formData.append('new_utr', newUtr);
                // Send the array of IDs
                currentDetailIds.forEach(id => formData.append('detail_ids[]', id));
                
                try {
                    const response = await fetch('utr_management.php', { method: 'POST', body: formData });
                    const result = await response.json();

                    if (result.error) {
                        throw new Error(result.error);
                    }
                    
                    showNotification(result.message, false);
                    // Refresh the table to show the updated UTRs
                    fetchBtn.click();
                    newUtrInput.value = ''; // Clear input on success
                } catch (error) {
                    console.error('Update error:', error);
                    showNotification(error.message, true);
                }
            });

            // --- INITIALIZATION ---
            processAndRenderDashboard();
        });
    </script>
</body>
</html>

