<?php
// --- CONFIGURATION & DATABASE CONNECTION ---
include '../config/db.php'; // Ensure this path is correct

// A function to check if a remote or local file exists.
function file_exists_check($path) {
    if (strpos($path, 'http') !== 0) {
        return file_exists($path);
    }
    $headers = @get_headers($path);
    return $headers && strpos($headers[0], '200');
}

// --- AJAX REQUEST HANDLER ---
// This block handles all asynchronous requests for viewing, updating, and deleting customers.
if (isset($_REQUEST['action'])) {
    header('Content-Type: application/json');
    $action = $_REQUEST['action'];

    // --- UPDATE CUSTOMER ACTION ---
    if ($action == 'update_customer') {
        $id = $_POST['customer_id'];
        $name = $_POST['name'];
        $mobile_no = $_POST['mobile_no'];

        $stmt = $conn->prepare("UPDATE customers SET name = ?, mobile_no = ? WHERE id = ?");
        $stmt->bind_param("ssi", $name, $mobile_no, $id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Customer updated successfully.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update customer.']);
        }
        $stmt->close();
        $conn->close();
        exit();
    }

    // --- DELETE CUSTOMER ACTION ---
    if ($action == 'delete_customer') {
        $id = $_POST['customer_id'];
        
        $conn->begin_transaction();
        try {
            // Select file paths for deletion
            $stmt_select_files = $conn->prepare("SELECT photo_path FROM customers WHERE id = ? UNION ALL SELECT document_image_path FROM customer_documents WHERE customer_id = ?");
            $stmt_select_files->bind_param("ii", $id, $id);
            $stmt_select_files->execute();
            $result_files = $stmt_select_files->get_result();

            while ($file = $result_files->fetch_assoc()) {
                $filePath = $file[array_keys($file)[0]];
                if (!empty($filePath) && file_exists_check($filePath)) {
                    unlink($filePath);
                }
            }
            $stmt_select_files->close();

            // Delete associated records
            $stmt_delete_docs = $conn->prepare("DELETE FROM customer_documents WHERE customer_id = ?");
            $stmt_delete_docs->bind_param("i", $id);
            $stmt_delete_docs->execute();
            $stmt_delete_docs->close();

            $stmt_delete_customer = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt_delete_customer->bind_param("i", $id);
            $stmt_delete_customer->execute();
            $stmt_delete_customer->close();

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Customer and all associated data deleted successfully.']);

        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete customer: ' . $exception->getMessage()]);
        }
        $conn->close();
        exit();
    }

    // --- FETCH CUSTOMER DETAILS FOR VIEW MODAL ---
    if ($action == 'get_details') {
        $customerId = $_GET['id'];

        $stmt = $conn->prepare("SELECT customer_uid, name, mobile_no, photo_path, created_at FROM customers WHERE id = ?");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $customer_result = $stmt->get_result();
        $customer_data = $customer_result->fetch_assoc();
        $stmt->close();
        
        $stmt_docs = $conn->prepare("SELECT document_type, document_number, document_image_path FROM customer_documents WHERE customer_id = ?");
        $stmt_docs->bind_param("i", $customerId);
        $stmt_docs->execute();
        $documents_result = $stmt_docs->get_result();
        $documents = [];
        while ($row = $documents_result->fetch_assoc()) {
            $documents[] = $row;
        }
        $stmt_docs->close();

        $customer_data['documents'] = $documents;
        
        echo json_encode($customer_data);
        $conn->close();
        exit();
    }
}

// --- INITIAL PAGE LOAD & FILTERING LOGIC ---
$customers = [];
$companies = [];
$search_params = [
    'keyword' => '',
    'customer_uid' => '',
    'name' => '',
    'mobile_no' => '',
    'company_name' => '',
    'start_date' => '',
    'end_date' => ''
];

// Fetch Companies for Dropdown Filter
if ($conn && !$conn->connect_error) {
    $company_result = $conn->query("SELECT DISTINCT company_name FROM company_commissions WHERE company_name IS NOT NULL AND company_name != '' ORDER BY company_name ASC");
    if ($company_result) {
        while ($row = $company_result->fetch_assoc()) {
            $companies[] = $row['company_name'];
        }
    }
}

// Build SQL Query based on Filters
$sql = "SELECT id, customer_uid, name, mobile_no, company_name, photo_path, created_at FROM customers";
$where_clauses = [];
$param_types = '';
$param_values = [];

if ($_SERVER["REQUEST_METHOD"] == "GET" && !empty($_GET)) {
    foreach ($search_params as $key => &$value) {
        if (isset($_GET[$key])) {
            $value = htmlspecialchars(trim($_GET[$key]));
        }
    }
    unset($value);

    if (!empty($search_params['keyword'])) {
        $keyword_like = "%" . $search_params['keyword'] . "%";
        $where_clauses[] = "(customer_uid LIKE ? OR name LIKE ? OR mobile_no LIKE ? OR company_name LIKE ?)";
        for ($i = 0; $i < 4; $i++) {
            $param_types .= 's';
            $param_values[] = $keyword_like;
        }
    }
    if (!empty($search_params['customer_uid'])) {
        $where_clauses[] = "customer_uid LIKE ?";
        $param_types .= 's';
        $param_values[] = "%" . $search_params['customer_uid'] . "%";
    }
    if (!empty($search_params['name'])) {
        $where_clauses[] = "name LIKE ?";
        $param_types .= 's';
        $param_values[] = "%" . $search_params['name'] . "%";
    }
    if (!empty($search_params['mobile_no'])) {
        $where_clauses[] = "mobile_no LIKE ?";
        $param_types .= 's';
        $param_values[] = "%" . $search_params['mobile_no'] . "%";
    }
    if (!empty($search_params['company_name'])) {
        $where_clauses[] = "company_name = ?";
        $param_types .= 's';
        $param_values[] = $search_params['company_name'];
    }
    if (!empty($search_params['start_date'])) {
        $where_clauses[] = "DATE(created_at) >= ?";
        $param_types .= 's';
        $param_values[] = $search_params['start_date'];
    }
    if (!empty($search_params['end_date'])) {
        $where_clauses[] = "DATE(created_at) <= ?";
        $param_types .= 's';
        $param_values[] = $search_params['end_date'];
    }

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
}

$sql .= " ORDER BY created_at DESC";

// Execute Query and Fetch Results for page display
if ($conn && !$conn->connect_error) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($param_types) && !empty($param_values)) {
            $stmt->bind_param($param_types, ...$param_values);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $customers = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #334155; }
        .container { max-width: 1400px; margin: 2rem auto; }
        .filter-container { background-color: white; border-radius: 0.75rem; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05); padding: 1.5rem; margin-bottom: 2rem; }
        .results-container { background-color: white; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); overflow-x: auto; padding: 1rem; }
        .form-input, .form-select { border: 1px solid #d1d5db; border-radius: 8px; transition: all 0.3s ease; padding: 10px; width: 100%; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #4a90e2; box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); }
        .btn { cursor: pointer; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.5rem; }
        .btn-search { background-color: #4a90e2; color: white; } .btn-search:hover { background-color: #357abd; }
        .btn-reset { background-color: #6b7280; color: white; } .btn-reset:hover { background-color: #4b5563; }
        .btn-view { background-color: #e0f2fe; color: #0c4a6e; } .btn-view:hover { background-color: #bae6fd; }
        .btn-edit { background-color: #fef9c3; color: #713f12; } .btn-edit:hover { background-color: #fde047; }
        .btn-delete { background-color: #fee2e2; color: #991b1b; } .btn-delete:hover { background-color: #fca5a5; }
        .table-photo { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; }
        thead th { background-color: #f8fafc; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.75rem 1rem; text-align: left; }
        td { padding: 0.75rem 1rem; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
        .modal-content { background-color: #ffffff; margin: 10% auto; padding: 2rem; border: none; width: 90%; max-width: 600px; border-radius: 0.75rem; position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0; }
        .modal-header h2 { font-size: 1.25rem; font-weight: 600; }
        .close-btn { color: #64748b; font-size: 1.5rem; font-weight: bold; cursor: pointer; } .close-btn:hover { color: #1e293b; }
        #viewModalContent .profile-view { display: flex; align-items: center; gap: 1.5rem; margin: 1.5rem 0; }
        #viewModalContent .profile-pic-large { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; }
        #viewModalContent .doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 1rem; margin-top: 1.5rem; }
    </style>
</head>
<body>

    <div class="container mx-auto p-4 md:p-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Customer Management</h1>

        <!-- Filter Form -->
        <div class="filter-container">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="get">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div>
                        <label for="keyword" class="block text-sm font-medium text-gray-700 mb-1">Keyword Search</label>
                        <input type="text" id="keyword" name="keyword" class="form-input" placeholder="Search ID, name, mobile..." value="<?php echo $search_params['keyword']; ?>">
                    </div>
                    <div>
                        <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                        <select id="company_name" name="company_name" class="form-select">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo htmlspecialchars($company); ?>" <?php echo ($search_params['company_name'] == $company) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-input" value="<?php echo $search_params['start_date']; ?>">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-input" value="<?php echo $search_params['end_date']; ?>">
                    </div>
                </div>
                 <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-4">
                    <div>
                        <label for="customer_uid" class="block text-sm font-medium text-gray-700 mb-1">Customer UID</label>
                        <input type="text" id="customer_uid" name="customer_uid" class="form-input" placeholder="e.g., DBCE-CMS-001" value="<?php echo $search_params['customer_uid']; ?>">
                    </div>
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                        <input type="text" id="name" name="name" class="form-input" placeholder="Enter name" value="<?php echo $search_params['name']; ?>">
                    </div>
                    <div>
                        <label for="mobile_no" class="block text-sm font-medium text-gray-700 mb-1">Mobile Number</label>
                        <input type="text" id="mobile_no" name="mobile_no" class="form-input" placeholder="Enter 10-digit number" value="<?php echo $search_params['mobile_no']; ?>">
                    </div>
                 </div>
                <div class="mt-6 flex justify-end space-x-4">
                    <button type="submit" class="btn btn-search"><i class="fas fa-search mr-2"></i>Filter</button>
                    <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" class="btn btn-reset"><i class="fas fa-undo mr-2"></i>Reset</a>
                </div>
            </form>
        </div>

        <!-- Results Table -->
        <div class="results-container">
            <table class="w-full text-sm text-left text-gray-600">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Photo</th>
                        <th>Customer ID</th>
                        <th>Name</th>
                        <th>Mobile No</th>
                        <th>Company Name</th>
                        <th>Registered On</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($customers)): ?>
                        <?php $s_no = 1; ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr id="customer-row-<?php echo $customer['id']; ?>" class="bg-white hover:bg-gray-50">
                                <td><?php echo $s_no++; ?></td>
                                <td>
                                    <?php 
                                        $photo_path = !empty($customer['photo_path']) && file_exists_check($customer['photo_path']) 
                                                      ? $customer['photo_path'] 
                                                      : 'https://placehold.co/100x100/EBF4FF/7F9CF5?text=N/A';
                                    ?>
                                    <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="Profile" class="table-photo">
                                </td>
                                <td class="font-medium text-gray-900"><?php echo htmlspecialchars($customer['customer_uid']); ?></td>
                                <td data-label="name"><?php echo htmlspecialchars($customer['name']); ?></td>
                                <td data-label="mobile"><?php echo htmlspecialchars($customer['mobile_no']); ?></td>
                                <td><?php echo htmlspecialchars($customer['company_name']); ?></td>
                                <td><?php echo date("d M, Y", strtotime($customer['created_at'])); ?></td>
                                <td class="text-right">
                                    <div class="flex gap-2 justify-end">
                                        <button class="btn btn-view" data-id="<?php echo $customer['id']; ?>"><i class="fas fa-eye"></i> View</button>
                                        <button class="btn btn-edit" data-id="<?php echo $customer['id']; ?>" data-name="<?php echo htmlspecialchars($customer['name']); ?>" data-mobile="<?php echo htmlspecialchars($customer['mobile_no']); ?>"><i class="fas fa-pencil-alt"></i> Edit</button>
                                        <button class="btn btn-delete" data-id="<?php echo $customer['id']; ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-10 text-gray-500">
                                <i class="fas fa-exclamation-circle fa-3x mb-4 text-gray-400"></i>
                                <p class="text-lg">No customers found matching your criteria.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Customer Details</h2>
                <span class="close-btn">&times;</span>
            </div>
            <div id="viewModalContent" class="mt-4">
                <!-- Content will be loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
             <div class="modal-header">
                <h2>Edit Customer</h2>
                <span class="close-btn">&times;</span>
            </div>
            <form id="editForm" class="mt-6">
                <input type="hidden" id="edit-customer-id" name="customer_id">
                <input type="hidden" name="action" value="update_customer">
                <div class="mb-4">
                    <label for="edit-name" class="block text-sm font-medium text-gray-700 mb-1">Name:</label>
                    <input type="text" id="edit-name" name="name" required class="form-input">
                </div>
                <div class="mb-6">
                    <label for="edit-mobile" class="block text-sm font-medium text-gray-700 mb-1">Mobile Number:</label>
                    <input type="text" id="edit-mobile" name="mobile_no" required class="form-input">
                </div>
                <div class="text-right">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    $(document).ready(function() {
        const viewModal = $('#viewModal');
        const editModal = $('#editModal');

        function closeModal() {
            $('.modal').hide();
        }

        $('.close-btn').on('click', closeModal);
        $(window).on('click', function(event) {
            if ($(event.target).is('.modal')) {
                closeModal();
            }
        });

        // --- VIEW ACTION ---
        $('body').on('click', '.btn-view', function() {
            const customerId = $(this).data('id');
            $.ajax({
                url: '<?php echo basename(__FILE__); ?>',
                type: 'GET',
                data: { action: 'get_details', id: customerId },
                dataType: 'json',
                success: function(response) {
                    const photoUrl = (response.photo_path && response.photo_path.length > 0) 
                                    ? response.photo_path 
                                    : 'https://placehold.co/100x100/EBF4FF/7F9CF5?text=N/A';

                    let content = `
                        <div class="profile-view">
                            <img src="${photoUrl}" alt="Profile" class="profile-pic-large">
                            <div class="info">
                                <h3 class="text-xl font-bold">${response.name}</h3>
                                <p><strong>ID:</strong> ${response.customer_uid}</p>
                                <p><strong>Mobile:</strong> ${response.mobile_no}</p>
                                <p><strong>Member Since:</strong> ${new Date(response.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}</p>
                            </div>
                        </div>
                        <hr class="my-4">
                        <h3 class="text-lg font-semibold mb-2">Documents</h3>`;

                    if (response.documents && response.documents.length > 0) {
                        content += '<div class="doc-grid">';
                        response.documents.forEach(doc => {
                            content += `<div class="text-center border p-2 rounded-md bg-gray-50">
                                            <a href="${doc.document_image_path}" target="_blank">
                                                <img src="${doc.document_image_path}" alt="${doc.document_type}" class="h-20 w-full object-cover rounded-md mb-2">
                                            </a>
                                            <h4 class="text-sm font-medium">${doc.document_type}</h4>
                                            <p class="text-xs text-gray-500">${doc.document_number}</p>
                                        </div>`;
                        });
                        content += '</div>';
                    } else {
                        content += '<p class="text-gray-500">No documents found for this customer.</p>';
                    }
                    
                    $('#viewModalContent').html(content);
                    viewModal.show();
                },
                error: function() {
                    alert('Error fetching customer details.');
                }
            });
        });

        // --- EDIT ACTION ---
        $('body').on('click', '.btn-edit', function() {
            $('#edit-customer-id').val($(this).data('id'));
            $('#edit-name').val($(this).data('name'));
            $('#edit-mobile').val($(this).data('mobile'));
            editModal.show();
        });

        $('#editForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();
            const customerId = $('#edit-customer-id').val();

            $.ajax({
                url: '<?php echo basename(__FILE__); ?>',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        const row = $('#customer-row-' + customerId);
                        row.find('td[data-label="name"]').text($('#edit-name').val());
                        row.find('td[data-label="mobile"]').text($('#edit-mobile').val());
                        row.find('.btn-edit').data('name', $('#edit-name').val());
                        row.find('.btn-edit').data('mobile', $('#edit-mobile').val());
                        closeModal();
                        alert(response.message);
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('An error occurred while updating.');
                }
            });
        });

        // --- DELETE ACTION ---
        $('body').on('click', '.btn-delete', function() {
            const customerId = $(this).data('id');
            if (confirm('Are you sure you want to delete this customer? This will also delete their profile photo and all documents. This action cannot be undone.')) {
                $.ajax({
                    url: '<?php echo basename(__FILE__); ?>',
                    type: 'POST',
                    data: { action: 'delete_customer', customer_id: customerId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#customer-row-' + customerId).fadeOut(500, function() {
                                $(this).remove();
                            });
                            alert(response.message);
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred during deletion.');
                    }
                });
            }
        });
    });
    </script>
</body>
</html>
