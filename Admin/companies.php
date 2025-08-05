<?php
session_start();

// --- AJAX REQUEST ROUTER ---
// This block handles all AJAX requests and exits immediately, preventing any HTML output.
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    // Set the content type to JSON for all API responses.
    header('Content-Type: application/json');

    // Establish a dedicated database connection for the API request.
    if (file_exists('../config/db.php')) {
        include '../config/db.php';
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database configuration file not found.']);
        exit;
    }

    if (!$conn) {
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['status' => 'error', 'message' => 'Invalid action specified.'];

    try {
        switch ($action) {
            // --- ADD COMPANY ---
            case 'add_company':
                $name = trim($_POST['company_name'] ?? '');
                $commission = $_POST['commission_percentage'] ?? 0;
                if (empty($name) || !is_numeric($commission) || $commission < 0) {
                    throw new Exception("Invalid data provided. Please check your inputs.");
                }
                $stmt = $conn->prepare("INSERT INTO company_commissions (company_name, commission_percentage) VALUES (?, ?)");
                $stmt->bind_param("sd", $name, $commission);
                $stmt->execute();
                $newId = $stmt->insert_id;
                $stmt->close();

                // Fetch the newly created company to return to the client
                $stmt = $conn->prepare("SELECT id, company_name, commission_percentage, created_at FROM company_commissions WHERE id = ?");
                $stmt->bind_param("i", $newId);
                $stmt->execute();
                $newCompany = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $response = ['status' => 'success', 'message' => 'Company added successfully.', 'data' => $newCompany];
                break;

            // --- GET COMPANY DETAILS FOR EDIT MODAL ---
            case 'get_company':
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception("Invalid Company ID.");
                
                $stmt = $conn->prepare("SELECT id, company_name, commission_percentage FROM company_commissions WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $company = $result->fetch_assoc();
                $stmt->close();

                if ($company) {
                    $response = ['status' => 'success', 'data' => $company];
                } else {
                    $response = ['status' => 'error', 'message' => 'Company not found.'];
                }
                break;

            // --- UPDATE COMPANY ---
            case 'update_company':
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['company_name'] ?? '');
                $commission = $_POST['commission_percentage'] ?? 0;
                if ($id <= 0 || empty($name) || !is_numeric($commission) || $commission < 0) {
                     throw new Exception("Invalid data for update. Please check your inputs.");
                }
                $stmt = $conn->prepare("UPDATE company_commissions SET company_name = ?, commission_percentage = ? WHERE id = ?");
                $stmt->bind_param("sdi", $name, $commission, $id);
                $stmt->execute();
                $stmt->close();
                $response = ['status' => 'success', 'message' => 'Company updated successfully.'];
                break;

            // --- DELETE COMPANY ---
            case 'delete_company':
                $id = (int)($_POST['id'] ?? 0);
                 if ($id <= 0) {
                     throw new Exception("Invalid ID for deletion.");
                }
                $stmt = $conn->prepare("DELETE FROM company_commissions WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->close();
                $response = ['status' => 'success', 'message' => 'Company deleted successfully.'];
                break;
        }
    } catch (Exception $e) {
        http_response_code(400); // Bad Request
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }

    // Close the connection and send the JSON response.
    $conn->close();
    echo json_encode($response);
    exit;
}


// --- INITIAL PAGE LOAD LOGIC ---
// This part only runs for non-AJAX requests (the initial page visit).
$companies = [];
$error_message = null;

if (file_exists('../config/db.php')) {
    include '../config/db.php';
} else {
    $error_message = "Database configuration file not found.";
    $conn = null;
}

if ($conn) {
    $result = $conn->query("SELECT `id`, `company_name`, `commission_percentage`, `created_at` FROM `company_commissions` ORDER BY `company_name` ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $companies[] = $row;
        }
    } else {
        $error_message = "Error fetching companies: " . $conn->error;
    }
    $conn->close(); // Close connection after page load data is fetched.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Manage Companies</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(4px); }
        .modal-content { background-color: #ffffff; margin: 10% auto; padding: 2rem; border-radius: 0.75rem; max-width: 500px; animation: slide-down 0.4s ease-out; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        @keyframes slide-down { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .form-input { border-radius: 0.5rem; border: 1px solid #d1d5db; padding: 0.75rem 1rem; transition: all 0.2s ease-in-out; background-color: #f9fafb; }
        .form-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; background-color: #fff; }
        .btn { padding: 0.65rem 1.5rem; border-radius: 0.5rem; font-weight: 600; transition: all 0.2s ease; border: none; cursor: pointer; }
        .company-card { transition: transform 0.2s ease-out, box-shadow 0.2s ease-out; }
        .company-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
        #toast { position: fixed; bottom: 20px; right: 20px; padding: 1rem 1.5rem; border-radius: 0.5rem; color: white; font-weight: 500; z-index: 2000; display: none; animation: fade-in-out 4s ease-in-out; }
        @keyframes fade-in-out { 0%, 100% { opacity: 0; transform: translateY(20px); } 10%, 90% { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Company Management</h1>
            <p class="text-gray-500 mt-1">Add, edit, or remove company details.</p>
        </div>
        <button id="addCompanyBtn" class="btn bg-blue-600 hover:bg-blue-700 text-white flex items-center gap-2">
            <i class="fas fa-plus-circle"></i> Add New Company
        </button>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
            <p class="font-bold">Error</p>
            <p><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>

    <div id="company-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php foreach ($companies as $company): ?>
            <div id="company-card-<?php echo $company['id']; ?>" class="company-card bg-white rounded-xl shadow-md overflow-hidden p-6 border border-gray-200">
                <div class="flex flex-col h-full">
                    <div class="flex-grow">
                        <h2 class="text-xl font-bold text-gray-800 truncate"><?php echo htmlspecialchars($company['company_name']); ?></h2>
                        <div class="mt-2 text-sm text-gray-500">
                            <i class="fas fa-percentage fa-fw mr-1"></i> Commission: <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($company['commission_percentage']); ?>%</span>
                        </div>
                        <div class="mt-1 text-sm text-gray-500">
                            <i class="fas fa-calendar-alt fa-fw mr-1"></i> Created: <span class="font-semibold text-gray-700"><?php echo date('d M, Y', strtotime($company['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="mt-6 pt-4 border-t border-gray-100 flex justify-end gap-3">
                        <button class="edit-btn btn text-sm bg-gray-200 hover:bg-gray-300 text-gray-700" data-id="<?php echo $company['id']; ?>">
                            <i class="fas fa-pencil-alt mr-1"></i> Edit
                        </button>
                        <button class="delete-btn btn text-sm bg-red-100 hover:bg-red-200 text-red-700" data-id="<?php echo $company['id']; ?>" data-name="<?php echo htmlspecialchars($company['company_name']); ?>">
                            <i class="fas fa-trash-alt mr-1"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p id="no-companies-msg" class="text-center text-gray-500 mt-10 <?php echo empty($companies) ? '' : 'hidden'; ?>">No companies found. Click "Add New Company" to get started.</p>
</div>

<!-- Add/Edit Modal -->
<div id="companyModal" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center pb-4 border-b border-gray-200">
            <h2 id="modalTitle" class="text-2xl font-bold text-gray-800">Add New Company</h2>
            <button id="closeModalBtn" class="text-gray-400 hover:text-gray-700 text-3xl font-light">&times;</button>
        </div>
        <form id="companyForm" class="mt-6">
            <input type="hidden" id="companyId" name="id">
            <input type="hidden" id="action" name="action" value="add_company">
            <div>
                <label for="companyName" class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" id="companyName" name="company_name" class="form-input w-full" required>
            </div>
            <div class="mt-4">
                <label for="commissionPercentage" class="block text-sm font-medium text-gray-700 mb-1">Commission Percentage (%)</label>
                <input type="number" id="commissionPercentage" name="commission_percentage" class="form-input w-full" step="0.01" min="0" required>
            </div>
            <div class="mt-8 flex justify-end gap-3">
                <button type="button" id="cancelBtn" class="btn bg-gray-200 hover:bg-gray-300 text-gray-800">Cancel</button>
                <button type="submit" id="saveBtn" class="btn bg-blue-600 hover:bg-blue-700 text-white">Save Company</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content text-center">
        <input type="hidden" id="deleteCompanyId">
        <i class="fas fa-exclamation-triangle text-5xl text-red-500 mb-4"></i>
        <h2 class="text-2xl font-bold text-gray-800">Are you sure?</h2>
        <p class="text-gray-600 mt-2">Do you really want to delete <strong id="deleteCompanyName"></strong>? This process cannot be undone.</p>
        <div class="mt-8 flex justify-center gap-4">
            <button id="cancelDeleteBtn" class="btn bg-gray-200 hover:bg-gray-300 text-gray-800 px-8">Cancel</button>
            <button id="confirmDeleteBtn" class="btn bg-red-600 hover:bg-red-700 text-white px-8">Delete</button>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function() {
    const companyModal = $('#companyModal');
    const deleteModal = $('#deleteModal');

    // --- UTILITY FUNCTIONS ---
    function showToast(message, isError = false) {
        const toast = $('#toast');
        toast.text(message);
        toast.css('background-color', isError ? '#ef4444' : '#22c55e');
        toast.fadeIn(400).css('display', 'block');
        setTimeout(() => toast.fadeOut(400), 3600);
    }
    
    function checkCompanyList() {
        if ($('#company-list').children().length === 0) {
            $('#no-companies-msg').removeClass('hidden');
        } else {
            $('#no-companies-msg').addClass('hidden');
        }
    }

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function createCompanyCard(company) {
        const date = new Date(company.created_at);
        const formattedDate = date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

        return `
            <div id="company-card-${company.id}" class="company-card bg-white rounded-xl shadow-md overflow-hidden p-6 border border-gray-200" style="display:none;">
                <div class="flex flex-col h-full">
                    <div class="flex-grow">
                        <h2 class="text-xl font-bold text-gray-800 truncate">${escapeHtml(company.company_name)}</h2>
                        <div class="mt-2 text-sm text-gray-500">
                            <i class="fas fa-percentage fa-fw mr-1"></i> Commission: <span class="font-semibold text-gray-700">${escapeHtml(company.commission_percentage)}%</span>
                        </div>
                        <div class="mt-1 text-sm text-gray-500">
                            <i class="fas fa-calendar-alt fa-fw mr-1"></i> Created: <span class="font-semibold text-gray-700">${formattedDate}</span>
                        </div>
                    </div>
                    <div class="mt-6 pt-4 border-t border-gray-100 flex justify-end gap-3">
                        <button class="edit-btn btn text-sm bg-gray-200 hover:bg-gray-300 text-gray-700" data-id="${company.id}">
                            <i class="fas fa-pencil-alt mr-1"></i> Edit
                        </button>
                        <button class="delete-btn btn text-sm bg-red-100 hover:bg-red-200 text-red-700" data-id="${company.id}" data-name="${escapeHtml(company.company_name)}">
                            <i class="fas fa-trash-alt mr-1"></i> Delete
                        </button>
                    </div>
                </div>
            </div>`;
    }

    // --- MODAL CONTROL ---
    function openModal(modal) { modal.fadeIn(200).css('display', 'flex'); }
    function closeModal(modal) { modal.fadeOut(200); }

    $('#addCompanyBtn').on('click', function() {
        $('#companyForm')[0].reset();
        $('#modalTitle').text('Add New Company');
        $('#action').val('add_company');
        $('#companyId').val('');
        openModal(companyModal);
    });

    $('#closeModalBtn, #cancelBtn').on('click', () => closeModal(companyModal));
    $('#cancelDeleteBtn').on('click', () => closeModal(deleteModal));
    
    // --- EVENT HANDLERS ---
    // Edit Company
    $('#company-list').on('click', '.edit-btn', function() {
        const id = $(this).data('id');
        $.ajax({
            url: window.location.href, // Post to the same page
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_company', id: id },
            success: function(response) {
                if (response.status === 'success' && response.data) {
                    $('#modalTitle').text('Edit Company');
                    $('#action').val('update_company');
                    $('#companyId').val(response.data.id);
                    $('#companyName').val(response.data.company_name);
                    $('#commissionPercentage').val(response.data.commission_percentage);
                    openModal(companyModal);
                } else {
                    showToast(response.message || 'Failed to fetch company details.', true);
                }
            },
            error: function() { showToast('An error occurred while fetching data.', true); }
        });
    });

    // Add/Update Form Submission
    $('#companyForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serializeArray();
        const action = $('#action').val();
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            dataType: 'json',
            data: $(this).serialize(),
            success: function(response) {
                if (response.status === 'success') {
                    showToast(response.message);
                    closeModal(companyModal);
                    
                    if (action === 'update_company') {
                        let companyData = {};
                        formData.forEach(item => { companyData[item.name] = item.value; });
                        const card = $('#company-card-' + companyData.id);
                        card.find('h2').text(companyData.company_name);
                        card.find('.fa-percentage').next('span').text(companyData.commission_percentage + '%');
                        card.find('.delete-btn').data('name', companyData.company_name);
                    } else if (action === 'add_company' && response.data) {
                        const newCardHtml = createCompanyCard(response.data);
                        $('#company-list').prepend(newCardHtml);
                        $('#company-card-' + response.data.id).fadeIn(400);
                        checkCompanyList();
                    }
                } else {
                    showToast(response.message || 'An unknown error occurred.', true);
                }
            },
            error: function() { showToast('A server error occurred.', true); }
        });
    });

    // Delete Company
    $('#company-list').on('click', '.delete-btn', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        $('#deleteCompanyId').val(id);
        $('#deleteCompanyName').text(name);
        openModal(deleteModal);
    });

    $('#confirmDeleteBtn').on('click', function() {
        const id = $('#deleteCompanyId').val();
        $.ajax({
            url: window.location.href,
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_company', id: id },
            success: function(response) {
                if (response.status === 'success') {
                    showToast(response.message);
                    $('#company-card-' + id).fadeOut(400, function() { 
                        $(this).remove();
                        checkCompanyList();
                    });
                } else {
                    showToast(response.message || 'Failed to delete company.', true);
                }
            },
            error: function() { showToast('A server error occurred during deletion.', true); },
            complete: function() { closeModal(deleteModal); }
        });
    });
    
    // --- INITIALIZATION ---
    checkCompanyList();
});
</script>
</body>
</html>
