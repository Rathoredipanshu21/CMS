<?php
// --- CONFIGURATION & DATABASE CONNECTION ---
include '../config/db.php'; // Include your database configuration file

// A function to check if a remote or local file exists.
function file_exists_check($path) {
    // For local files, use the standard file_exists
    if (strpos($path, 'http') !== 0) {
        return file_exists($path);
    }
    // For remote files, check headers
    $headers = @get_headers($path);
    return $headers && strpos($headers[0], '200');
}


// --- AJAX REQUEST HANDLER ---
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
            // Find profile photo and document images to delete them from the server
            $stmt_select_files = $conn->prepare("
                SELECT photo_path FROM customers WHERE id = ?
                UNION ALL
                SELECT document_image_path FROM customer_documents WHERE customer_id = ?
            ");
            $stmt_select_files->bind_param("ii", $id, $id);
            $stmt_select_files->execute();
            $result_files = $stmt_select_files->get_result();

            while ($file = $result_files->fetch_assoc()) {
                // Use the key of the first element, which will be 'photo_path'
                $filePath = $file[array_keys($file)[0]];
                if (!empty($filePath) && file_exists_check($filePath)) {
                    unlink($filePath); // Delete the file
                }
            }
            $stmt_select_files->close();

            // Delete document records from the database
            $stmt_delete_docs = $conn->prepare("DELETE FROM customer_documents WHERE customer_id = ?");
            $stmt_delete_docs->bind_param("i", $id);
            $stmt_delete_docs->execute();
            $stmt_delete_docs->close();

            // Delete the customer record
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

    // --- AJAX: FETCH CUSTOMER DETAILS FOR VIEW MODAL ---
    if ($action == 'get_details') {
        $customerId = $_GET['id'];

        // Fetch customer base details
        $stmt = $conn->prepare("SELECT customer_uid, name, mobile_no, photo_path, created_at FROM customers WHERE id = ?");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $customer_result = $stmt->get_result();
        $customer_data = $customer_result->fetch_assoc();
        $stmt->close();
        
        // Fetch associated documents
        $stmt = $conn->prepare("SELECT document_type, document_number, document_image_path FROM customer_documents WHERE customer_id = ?");
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $documents_result = $stmt->get_result();
        $documents = [];
        while ($row = $documents_result->fetch_assoc()) {
            $documents[] = $row;
        }
        $stmt->close();

        $customer_data['documents'] = $documents;
        
        echo json_encode($customer_data);
        $conn->close();
        exit();
    }
}


// --- INITIAL PAGE LOAD: FETCH ALL CUSTOMERS FOR THE TABLE ---
// Updated SQL to fetch the new columns and show latest first
$sql = "SELECT c.id, c.customer_uid, c.name, c.mobile_no, c.photo_path, c.created_at FROM customers c ORDER BY c.id DESC";
$result = $conn->query($sql);
$customers = [];
if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f1f5f9; 
            color: #334155;
        }
        .container { 
            max-width: 1400px; 
            margin: 2rem auto; 
            background: #ffffff; 
            padding: 2rem; 
            border-radius: 0.75rem; 
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
        }
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .table-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: #1e293b;
        }
        .table-wrapper {
            overflow-x: auto;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 0.875rem;
        }
        th, td { 
            padding: 0.75rem 1rem; 
            border-bottom: 1px solid #e2e8f0; 
            text-align: left; 
            vertical-align: middle;
        }
        thead th { 
            background-color: #f8fafc; 
            font-weight: 600; 
            color: #475569; 
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        tbody tr:hover { 
            background-color: #f1f5f9; 
        }
        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
        }
        .actions-cell {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        .btn {
            cursor: pointer;
            border: none;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        .btn-view { background-color: #e0f2fe; color: #0c4a6e; } .btn-view:hover { background-color: #bae6fd; }
        .btn-edit { background-color: #fef9c3; color: #713f12; } .btn-edit:hover { background-color: #fde047; }
        .btn-delete { background-color: #fee2e2; color: #991b1b; } .btn-delete:hover { background-color: #fca5a5; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
        .modal-content { background-color: #ffffff; margin: 10% auto; padding: 2rem; border: none; width: 90%; max-width: 600px; border-radius: 0.75rem; position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0; }
        .modal-header h2 { font-size: 1.25rem; font-weight: 600; }
        .close-btn { color: #64748b; font-size: 1.5rem; font-weight: bold; cursor: pointer; } .close-btn:hover { color: #1e293b; }
        
        /* View Modal Specifics */
        #viewModalContent .profile-view { display: flex; align-items: center; gap: 1.5rem; margin: 1.5rem 0; }
        #viewModalContent .profile-pic-large { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; }
        #viewModalContent .info p { margin: 0.25rem 0; }
        #viewModalContent .info p strong { color: #475569; }
        #viewModalContent .doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 1rem; margin-top: 1.5rem; }
        #viewModalContent .doc-item { text-align: center; border: 1px solid #e2e8f0; padding: 0.75rem; border-radius: 0.5rem; background-color: #f8fafc; }
        #viewModalContent .doc-item img { max-width: 100%; height: 80px; object-fit: cover; border-radius: 0.375rem; margin-bottom: 0.5rem; }
        #viewModalContent .doc-item h4 { font-size: 0.875rem; font-weight: 500; }
        #viewModalContent .doc-item p { font-size: 0.75rem; color: #64748b; }
    </style>
</head>
<body>

<div class="container">
    <div class="table-header">
        <h1>Customer List</h1>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>S.No</th>
                    <th>Photo</th>
                    <th>Customer ID</th>
                    <th>Name</th>
                    <th>Mobile Number</th>
                    <th>Registered On</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($customers)): ?>
                    <?php $s_no = 1; ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr id="customer-row-<?php echo $customer['id']; ?>">
                            <td><?php echo $s_no++; ?></td>
                            <td>
                                <?php 
                                    $photo_path = !empty($customer['photo_path']) && file_exists_check($customer['photo_path']) 
                                                    ? $customer['photo_path'] 
                                                    : 'https://placehold.co/100x100/EBF4FF/7F9CF5?text=N/A';
                                ?>
                                <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="Profile" class="profile-pic">
                            </td>
                            <td><?php echo htmlspecialchars($customer['customer_uid']); ?></td>
                            <td data-label="name"><?php echo htmlspecialchars($customer['name']); ?></td>
                            <td data-label="mobile"><?php echo htmlspecialchars($customer['mobile_no']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></td>
                            <td>
                                <div class="actions-cell">
                                    <button class="btn btn-view" data-id="<?php echo $customer['id']; ?>"><i class="fas fa-eye"></i> View</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-10">No customers found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="view-modal-title">Customer Details</h2>
            <span class="close-btn">&times;</span>
        </div>
        <div id="viewModalContent">
            </div>
    </div>
</div>

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
                <input type="text" id="edit-name" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div class="mb-6">
                <label for="edit-mobile" class="block text-sm font-medium text-gray-700 mb-1">Mobile Number:</label>
                <input type="text" id="edit-mobile" name="mobile_no" required class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
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
    // --- MODAL HANDLING ---
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
                        content += `<div class="doc-item">
                                        <a href="${doc.document_image_path}" target="_blank">
                                            <img src="${doc.document_image_path}" alt="${doc.document_type}">
                                        </a>
                                        <h4>${doc.document_type}</h4>
                                        <p>${doc.document_number}</p>
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