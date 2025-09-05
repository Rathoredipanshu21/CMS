<?php
// Include database connection
include '../config/db.php';

// --- LOGIC FOR HANDLING FORM SUBMISSIONS ---

// ADD OR UPDATE EXPENSE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_expense'])) {
    $title = $conn->real_escape_string(trim($_POST['title']));
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $category = $conn->real_escape_string(trim($_POST['category']));
    $expense_date = $conn->real_escape_string(trim($_POST['expense_date']));
    $notes = $conn->real_escape_string(trim($_POST['notes']));
    $id = isset($_POST['expense_id']) ? intval($_POST['expense_id']) : 0;

    // Basic validation
    if (!empty($title) && $amount > 0 && !empty($category) && !empty($expense_date)) {
        if ($id > 0) {
            // Update existing expense
            $stmt = $conn->prepare("UPDATE expenses SET title=?, amount=?, category=?, expense_date=?, notes=? WHERE id=?");
            $stmt->bind_param("sdsssi", $title, $amount, $category, $expense_date, $notes, $id);
        } else {
            // Insert new expense
            $stmt = $conn->prepare("INSERT INTO expenses (title, amount, category, expense_date, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sdsss", $title, $amount, $category, $expense_date, $notes);
        }
        
        if ($stmt->execute()) {
            // Redirect to avoid form resubmission
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } else {
            $error_message = "Database error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// DELETE EXPENSE
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $error_message = "Failed to delete expense.";
    }
    $stmt->close();
}


// --- DATA FETCHING FOR DISPLAY ---

// Get all expenses, ordered by most recent
$expenses = [];
$result = $conn->query("SELECT * FROM expenses ORDER BY expense_date DESC, created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $expenses[] = $row;
    }
}

// Calculate summary stats
$total_expenses = 0;
$monthly_expenses = 0;
$current_month = date('Y-m');

foreach ($expenses as $expense) {
    $total_expenses += $expense['amount'];
    if (substr($expense['expense_date'], 0, 7) === $current_month) {
        $monthly_expenses += $expense['amount'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Expense Management</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        /* A little custom style for a better look */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* A light gray background */
        }
        @import url('https://rsms.me/inter/inter.css');
        .icon-btn {
            @apply text-gray-500 hover:text-gray-800 transition-colors duration-200;
        }
        .modal-overlay {
            transition: opacity 0.3s ease;
        }
        .modal-container {
            transition: transform 0.3s ease;
        }
    </style>
</head>
<body class="antialiased text-gray-800">

    <div class="container mx-auto p-4 md:p-8">
        
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 flex items-center">
                <i class="fas fa-wallet text-indigo-500 mr-3"></i>
                Expense Management
            </h1>
            <p class="text-gray-600 mt-1">Track and manage all company expenditures from here.</p>
        </header>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-xl shadow-md flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500">Total Expenses</p>
                    <p class="text-2xl font-bold text-gray-900">₹<?php echo number_format($total_expenses, 2); ?></p>
                </div>
                <div class="bg-green-100 text-green-600 p-3 rounded-full">
                    <i class="fas fa-rupee-sign fa-lg"></i>
                </div>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-md flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500">Expenses This Month</p>
                    <p class="text-2xl font-bold text-gray-900">₹<?php echo number_format($monthly_expenses, 2); ?></p>
                </div>
                <div class="bg-blue-100 text-blue-600 p-3 rounded-full">
                    <i class="fas fa-calendar-alt fa-lg"></i>
                </div>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-md flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-gray-500">Total Transactions</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($expenses); ?></p>
                </div>
                <div class="bg-yellow-100 text-yellow-600 p-3 rounded-full">
                    <i class="fas fa-receipt fa-lg"></i>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Add Expense Form -->
            <div class="lg:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-md">
                    <h2 class="text-xl font-bold mb-4 flex items-center" id="form-title">
                        <i class="fas fa-plus-circle text-indigo-500 mr-2"></i>
                        Add New Expense
                    </h2>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4" role="alert">
                            <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
                        </div>
                    <?php endif; ?>

                    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="space-y-4">
                        <input type="hidden" name="expense_id" value="0">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
                            <input type="text" name="title" id="title" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g. Box of A4 Paper">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="amount" class="block text-sm font-medium text-gray-700">Amount (₹)</label>
                                <input type="number" name="amount" id="amount" step="0.01" min="0" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g. 550.00">
                            </div>
                            <div>
                                <label for="expense_date" class="block text-sm font-medium text-gray-700">Date</label>
                                <input type="date" name="expense_date" id="expense_date" required value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
                            <select name="category" id="category" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="Pantry Supplies">Pantry Supplies (Chai, Coffee)</option>
                                <option value="Stationery">Stationery (Pens, Paper)</option>
                                <option value="Cleaning Supplies">Cleaning Supplies</option>
                                <option value="Repairs & Maintenance">Repairs & Maintenance</option>
                                <option value="Employee Welfare">Employee Welfare</option>
                                <option value="Utilities">Utilities (Bills)</option>
                                <option value="Miscellaneous">Miscellaneous</option>
                            </select>
                        </div>
                        <div>
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes (Optional)</label>
                            <textarea name="notes" id="notes" rows="3" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" placeholder="Any additional details..."></textarea>
                        </div>
                        <button type="submit" name="submit_expense" class="w-full flex justify-center items-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            <i class="fas fa-check mr-2"></i><span>Add Expense</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Expenses List -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="p-6">
                        <h2 class="text-xl font-bold flex items-center"><i class="fas fa-list-ul text-indigo-500 mr-2"></i>All Expenses</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-500">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3">Title</th>
                                    <th scope="col" class="px-6 py-3">Amount</th>
                                    <th scope="col" class="px-6 py-3">Category</th>
                                    <th scope="col" class="px-6 py-3">Date</th>
                                    <th scope="col" class="px-6 py-3 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($expenses)): ?>
                                    <tr class="bg-white border-b">
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                            <i class="fas fa-folder-open mr-2"></i>No expenses recorded yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($expenses as $expense): ?>
                                    <tr class="bg-white border-b hover:bg-gray-50">
                                        <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                            <?php echo htmlspecialchars($expense['title']); ?>
                                            <?php if (!empty($expense['notes'])): ?>
                                                <p class="text-xs text-gray-500 font-normal"><?php echo htmlspecialchars($expense['notes']); ?></p>
                                            <?php endif; ?>
                                        </th>
                                        <td class="px-6 py-4 text-green-600 font-semibold">
                                            ₹<?php echo number_format($expense['amount'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-2 py-1 text-xs font-medium text-indigo-800 bg-indigo-100 rounded-full"><?php echo htmlspecialchars($expense['category']); ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php echo date('M d, Y', strtotime($expense['expense_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 text-center">
                                            <div class="flex items-center justify-center space-x-4">
                                                <button onclick='openEditModal(<?php echo json_encode($expense); ?>)' class="icon-btn" title="Edit">
                                                    <i class="fas fa-pencil-alt"></i>
                                                </button>
                                                <button onclick="openDeleteModal(<?php echo $expense['id']; ?>)" class="icon-btn text-red-500 hover:text-red-700" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Expense Modal -->
    <div id="edit-modal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div class="modal-container bg-white w-full max-w-md mx-auto rounded-xl shadow-lg z-50 transform scale-95">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold flex items-center"><i class="fas fa-edit text-indigo-500 mr-2"></i>Edit Expense</h2>
                    <button onclick="closeModal('edit-modal')" class="text-gray-400 hover:text-gray-600">&times;</button>
                </div>
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="space-y-4">
                    <input type="hidden" name="expense_id" id="edit_expense_id">
                    <div>
                        <label for="edit_title" class="block text-sm font-medium text-gray-700">Title</label>
                        <input type="text" name="title" id="edit_title" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_amount" class="block text-sm font-medium text-gray-700">Amount (₹)</label>
                            <input type="number" name="amount" id="edit_amount" step="0.01" min="0" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        <div>
                            <label for="edit_expense_date" class="block text-sm font-medium text-gray-700">Date</label>
                            <input type="date" name="expense_date" id="edit_expense_date" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>
                    <div>
                        <label for="edit_category" class="block text-sm font-medium text-gray-700">Category</label>
                        <select name="category" id="edit_category" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                             <option value="Pantry Supplies">Pantry Supplies (Chai, Coffee)</option>
                             <option value="Stationery">Stationery (Pens, Paper)</option>
                             <option value="Cleaning Supplies">Cleaning Supplies</option>
                             <option value="Repairs & Maintenance">Repairs & Maintenance</option>
                             <option value="Employee Welfare">Employee Welfare</option>
                             <option value="Utilities">Utilities (Bills)</option>
                             <option value="Miscellaneous">Miscellaneous</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_notes" class="block text-sm font-medium text-gray-700">Notes (Optional)</label>
                        <textarea name="notes" id="edit_notes" rows="3" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                    </div>
                    <div class="flex justify-end space-x-3 pt-2">
                        <button type="button" onclick="closeModal('edit-modal')" class="py-2 px-4 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                        <button type="submit" name="submit_expense" class="py-2 px-4 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"><i class="fas fa-check mr-2"></i>Update Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="modal-overlay fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 hidden z-50">
        <div class="modal-container bg-white w-full max-w-sm mx-auto rounded-xl shadow-lg z-50 transform scale-95">
            <div class="p-6 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 fa-lg"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900">Delete Expense</h3>
                <p class="mt-2 text-sm text-gray-500">Are you sure you want to delete this expense? This action cannot be undone.</p>
                <div class="mt-6 flex justify-center space-x-3">
                    <button type="button" onclick="closeModal('delete-modal')" class="py-2 px-4 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Cancel</button>
                    <a id="confirm-delete-btn" href="#" class="py-2 px-4 bg-red-600 text-white rounded-md hover:bg-red-700">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const editModal = document.getElementById('edit-modal');
        const deleteModal = document.getElementById('delete-modal');

        function openEditModal(expense) {
            // Populate modal form
            document.getElementById('edit_expense_id').value = expense.id;
            document.getElementById('edit_title').value = expense.title;
            document.getElementById('edit_amount').value = expense.amount;
            document.getElementById('edit_category').value = expense.category;
            document.getElementById('edit_expense_date').value = expense.expense_date;
            document.getElementById('edit_notes').value = expense.notes;
            
            // Show modal
            editModal.classList.remove('hidden');
            setTimeout(() => { // For transition effect
                editModal.querySelector('.modal-container').classList.remove('scale-95');
                editModal.classList.remove('opacity-0');
            }, 50);
        }

        function openDeleteModal(id) {
            // Set the confirmation link
            const confirmBtn = document.getElementById('confirm-delete-btn');
            confirmBtn.href = `?action=delete&id=${id}`;
            
            // Show modal
            deleteModal.classList.remove('hidden');
            setTimeout(() => { // For transition effect
                deleteModal.querySelector('.modal-container').classList.remove('scale-95');
                deleteModal.classList.remove('opacity-0');
            }, 50);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.querySelector('.modal-container').classList.add('scale-95');
            modal.classList.add('opacity-0');
            setTimeout(() => { // Wait for transition to finish
                modal.classList.add('hidden');
            }, 300);
        }

        // Close modal on escape key press
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (!editModal.classList.contains('hidden')) {
                    closeModal('edit-modal');
                }
                if (!deleteModal.classList.contains('hidden')) {
                    closeModal('delete-modal');
                }
            }
        });
    </script>

</body>
</html>

