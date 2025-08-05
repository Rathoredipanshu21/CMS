<?php
// It's good practice to have a secure way to check if the user is an admin.
// For now, we'll proceed, but in a real application, you'd have an admin login system.
// session_start();
// if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
//     header("Location: admin_login.php");
//     exit();
// }

include '../config/db.php'; // Ensure this path is correct

$message = '';
$error = '';

// --- Handle POST Requests for Add, Update, and Delete ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // --- ADD A NEW COMMISSION ---
    if (isset($_POST['add_commission'])) {
        $company_name = trim($_POST['company_name']);
        $commission_percentage = $_POST['commission_percentage'];

        if (empty($company_name) || !is_numeric($commission_percentage)) {
            $error = "Please enter a valid company name and a numeric percentage.";
        } elseif ($commission_percentage < 0 || $commission_percentage > 100) {
            $error = "Commission percentage must be between 0 and 100.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO company_commissions (company_name, commission_percentage) VALUES (?, ?)");
                $stmt->bind_param("sd", $company_name, $commission_percentage);
                if ($stmt->execute()) {
                    $message = "Successfully added commission for " . htmlspecialchars($company_name) . ".";
                }
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) {
                    $error = "Error: A commission for '" . htmlspecialchars($company_name) . "' already exists.";
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // --- UPDATE AN EXISTING COMMISSION ---
    if (isset($_POST['update_commission'])) {
        $id = intval($_POST['edit_id']);
        $company_name = trim($_POST['edit_company_name']);
        $commission_percentage = $_POST['edit_commission_percentage'];

        if (empty($company_name) || !is_numeric($commission_percentage) || $id <= 0) {
            $error = "Invalid data provided for update.";
        } elseif ($commission_percentage < 0 || $commission_percentage > 100) {
            $error = "Commission percentage must be between 0 and 100.";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE company_commissions SET company_name = ?, commission_percentage = ? WHERE id = ?");
                $stmt->bind_param("sdi", $company_name, $commission_percentage, $id);
                if ($stmt->execute()) {
                    $message = "Successfully updated commission for " . htmlspecialchars($company_name) . ".";
                }
                $stmt->close();
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1062) {
                    $error = "Error: Another company with the name '" . htmlspecialchars($company_name) . "' already exists.";
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // --- DELETE A COMMISSION ---
    if (isset($_POST['delete_commission'])) {
        $id = intval($_POST['delete_id']);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM company_commissions WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $message = "Commission record deleted successfully.";
            } else {
                $error = "Error deleting record.";
            }
            $stmt->close();
        }
    }
}

// --- Fetch All Existing Company Commissions to Display ---
$commissions = [];
$sql = "SELECT id, company_name, commission_percentage, created_at FROM company_commissions ORDER BY company_name ASC";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $commissions[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Commissions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f0f4f8; 
        }
        .card { 
            background-color: white; 
            border-radius: 1rem; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        .table-header { 
            background-color: #f1f5f9; 
            border-bottom: 2px solid #e2e8f0;
        }
        .btn-primary {
            background: linear-gradient(to right, #4f46e5, #818cf8);
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.2);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(79, 70, 229, 0.3);
        }
        .modal-backdrop { 
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
        }
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease-in-out;
        }
        .action-btn:hover {
            transform: scale(1.1);
        }
        .action-btn-edit { background-color: #e0e7ff; color: #4338ca; }
        .action-btn-edit:hover { background-color: #c7d2fe; }
        .action-btn-delete { background-color: #fee2e2; color: #b91c1c; }
        .action-btn-delete:hover { background-color: #fecaca; }
        
        /* ** NEW ** Stylish Input Class */
        .form-input {
            background-color: #f1f5f9;
            border: 2px solid transparent;
            border-radius: 0.75rem; /* 12px */
            padding-top: 0.625rem; /* 10px */
            padding-bottom: 0.625rem; /* 10px */
            transition: all 0.3s ease;
        }
        .form-input:focus {
            background-color: white;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
            outline: none;
        }
        .form-input::placeholder {
            color: #9ca3af;
        }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-5xl mx-auto">
    <header class="text-center mb-10" data-aos="fade-down">
        <h1 class="text-4xl font-extrabold text-gray-800 tracking-tight">Commission Control Panel</h1>
        <p class="text-lg text-gray-500 mt-2">Manage all company commission rates from one place.</p>
    </header>

    <!-- Add New Commission Form -->
    <div class="card p-6 md:p-8 mb-10" data-aos="fade-up">
        <h2 class="text-2xl font-bold text-gray-800 mb-5">Add New Company</h2>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-5 rounded-lg" role="alert"><p><?php echo $message; ?></p></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-5 rounded-lg" role="alert"><p><?php echo $error; ?></p></div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
            <div>
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-building text-gray-400"></i></div><input type="text" id="company_name" name="company_name" class="form-input block w-full pl-10 sm:text-sm" placeholder="Enter company name" required></div>
            </div>
            <div>
                <label for="commission_percentage" class="block text-sm font-medium text-gray-700 mb-1">Commission (%)</label>
                <div class="relative"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-percent text-gray-400"></i></div><input type="number" step="0.01" id="commission_percentage" name="commission_percentage" class="form-input block w-full pl-10 sm:text-sm" placeholder="e.g., 15.5" required></div>
            </div>
            <div><button type="submit" name="add_commission" class="w-full btn-primary flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"><i class="fas fa-plus mr-2"></i> Add Commission</button></div>
        </form>
    </div>

    <!-- Existing Commissions Table -->
    <div class="card overflow-hidden" data-aos="fade-up" data-aos-delay="100">
        <div class="p-6 border-b border-gray-200"><h2 class="text-2xl font-bold text-gray-800">Existing Records</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-600">
                <thead class="table-header text-xs text-gray-700 uppercase tracking-wider">
                    <tr>
                        <th scope="col" class="px-6 py-4">Company Name</th>
                        <th scope="col" class="px-6 py-4 text-center">Commission</th>
                        <th scope="col" class="px-6 py-4 hidden sm:table-cell text-center">Date Added</th>
                        <th scope="col" class="px-6 py-4 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (!empty($commissions)): ?>
                        <?php foreach($commissions as $commission): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-200">
                                <td class="px-6 py-4 font-semibold text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($commission['company_name']); ?></td>
                                <td class="px-6 py-4 text-center font-mono text-indigo-600 font-bold"><?php echo htmlspecialchars(number_format($commission['commission_percentage'], 2)); ?>%</td>
                                <td class="px-6 py-4 hidden sm:table-cell text-center"><?php echo date("d M, Y", strtotime($commission['created_at'])); ?></td>
                                <td class="px-6 py-4 text-center space-x-2">
                                    <button onclick="openEditModal(<?php echo $commission['id']; ?>, '<?php echo htmlspecialchars(addslashes($commission['company_name'])); ?>', <?php echo $commission['commission_percentage']; ?>)" class="action-btn action-btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></button>
                                    <button onclick="openDeleteModal(<?php echo $commission['id']; ?>)" class="action-btn action-btn-delete" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center py-12 text-gray-500"><i class="fas fa-folder-open fa-3x mb-3 text-gray-400"></i><p class="text-lg">No records found.</p><p class="text-sm">Add a new commission using the form above.</p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden modal-backdrop">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto" data-aos="zoom-in-up">
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
            <div class="p-6">
                <h3 class="text-xl font-bold text-gray-900">Edit Commission</h3>
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="mt-5">
                    <label for="edit_company_name" class="block text-sm font-medium text-gray-700">Company Name</label>
                    <input type="text" id="edit_company_name" name="edit_company_name" class="form-input mt-1 block w-full px-3" required>
                </div>
                <div class="mt-4">
                    <label for="edit_commission_percentage" class="block text-sm font-medium text-gray-700">Commission (%)</label>
                    <input type="number" step="0.01" id="edit_commission_percentage" name="edit_commission_percentage" class="form-input mt-1 block w-full px-3" required>
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-4 sm:flex sm:flex-row-reverse rounded-b-xl">
                <button type="submit" name="update_commission" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Update</button>
                <button type="button" onclick="closeModal('editModal')" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden modal-backdrop">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-auto" data-aos="zoom-in-up">
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post">
            <div class="p-6 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="fas fa-exclamation-triangle text-2xl text-red-600"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mt-5">Are you sure?</h3>
                <p class="mt-2 text-sm text-gray-500">Do you really want to delete this record? This action cannot be undone.</p>
                <input type="hidden" name="delete_id" id="delete_id">
            </div>
            <div class="bg-gray-50 px-6 py-4 sm:flex sm:flex-row-reverse rounded-b-xl">
                <button type="submit" name="delete_commission" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 sm:ml-3 sm:w-auto sm:text-sm">Delete</button>
                <button type="button" onclick="closeModal('deleteModal')" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:w-auto sm:text-sm">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
<script>
    AOS.init({
        once: true,
        duration: 600
    });

    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.remove('hidden');
        // Trigger AOS for modal content
        const aosEl = modal.querySelector('[data-aos]');
        if(aosEl) {
            aosEl.classList.remove('aos-animate');
            setTimeout(() => {
                aosEl.classList.add('aos-animate');
            }, 50);
        }
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }

    function openEditModal(id, name, percentage) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_company_name').value = name;
        document.getElementById('edit_commission_percentage').value = percentage;
        openModal('editModal');
    }

    function openDeleteModal(id) {
        document.getElementById('delete_id').value = id;
        openModal('deleteModal');
    }
</script>

</body>
</html>
