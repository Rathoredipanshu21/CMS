<?php
session_start(); // Start the session to access logged-in user data

// --- Security Check: Ensure user is logged in ---
if (!isset($_SESSION['branch_user']) || !isset($_SESSION['branch_id'])) {
    die("Access Denied. You must be logged in to view this page.");
}

// --- CONFIGURATION & DATABASE CONNECTION ---
include '../config/db.php';

// --- Get the username of the currently logged-in branch user ---
$loggedInUsername = 'N/A';
if ($conn) {
    $stmt_user = $conn->prepare("SELECT username FROM branch WHERE id = ?");
    if ($stmt_user) {
        $stmt_user->bind_param("i", $_SESSION['branch_id']);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($row_user = $result_user->fetch_assoc()) {
            $loggedInUsername = $row_user['username'];
        }
        $stmt_user->close();
    }
}

// A function to check if a file exists (works for local files)
function file_exists_check($path) {
    return file_exists($path);
}

// --- AJAX: FETCH CUSTOMER DETAILS FOR VIEW MODAL ---
if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'get_details') {
    header('Content-Type: application/json');
    $customerId = $_GET['id'];

    // Security check: Ensure the requested customer was created by the logged-in user
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ? AND created_by_user = ?");
    $stmt->bind_param("is", $customerId, $loggedInUsername);
    $stmt->execute();
    $customer_result = $stmt->get_result();
    
    if ($customer_result->num_rows > 0) {
        $customer_data = $customer_result->fetch_assoc();
        
        // Fetch associated documents
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
    } else {
        // Customer not found or doesn't belong to this user
        echo json_encode(['error' => 'Customer not found or access denied.']);
    }
    
    $stmt->close();
    $conn->close();
    exit();
}

// --- INITIAL PAGE LOAD: FETCH CUSTOMERS FOR THE TABLE ---
$search_query = "";
$customers = [];

// Base SQL query to fetch customers created by the logged-in user, newest first
$sql = "SELECT c.id, c.customer_uid, c.name, c.mobile_no, c.photo_path, c.created_at 
        FROM customers c 
        WHERE c.created_by_user = ?";

$params = [$loggedInUsername];
$types = "s";

// Check if a search term is provided
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_query = $_GET['search'];
    // Add search condition to the SQL query
    $sql .= " AND c.customer_uid LIKE ?";
    $params[] = "%" . $search_query . "%";
    $types .= "s";
}

// Add ordering
$sql .= " ORDER BY c.id DESC";

$stmt_customers = $conn->prepare($sql);
if ($stmt_customers) {
    $stmt_customers->bind_param($types, ...$params);
    $stmt_customers->execute();
    $result = $stmt_customers->get_result();
    
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }
    }
    $stmt_customers->close();
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #334155; }
        .container { max-width: 1400px; margin: 2rem auto; background: #ffffff; padding: 2rem; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05); }
        .table-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; gap: 1rem; }
        .table-header h1 { font-size: 1.875rem; font-weight: 700; color: #1e293b; }
        .search-form { display: flex; gap: 0.5rem; }
        .search-input { border: 1px solid #cbd5e1; border-radius: 0.375rem; padding: 0.5rem 0.75rem; }
        .search-input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2); }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { padding: 0.75rem 1rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: middle; }
        thead th { background-color: #f8fafc; font-weight: 600; color: #475569; }
        tbody tr:hover { background-color: #f1f5f9; }
        .profile-pic { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #e2e8f0; }
        .btn { cursor: pointer; border: none; padding: 0.5rem 0.75rem; border-radius: 0.375rem; font-weight: 500; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.25rem; }
        .btn-view { background-color: #e0f2fe; color: #0c4a6e; } .btn-view:hover { background-color: #bae6fd; }
        .btn-search { background-color: #1e40af; color: white; } .btn-search:hover { background-color: #1e3a8a; }
        .btn-clear { background-color: #64748b; color: white; } .btn-clear:hover { background-color: #475569; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); }
        .modal-content { background-color: #ffffff; margin: 10% auto; padding: 2rem; border: none; width: 90%; max-width: 600px; border-radius: 0.75rem; position: relative; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0; }
        .close-btn { color: #64748b; font-size: 1.5rem; font-weight: bold; cursor: pointer; } .close-btn:hover { color: #1e293b; }
        #viewModalContent .profile-pic-large { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; }
        #viewModalContent .doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 1rem; margin-top: 1.5rem; }
        #viewModalContent .doc-item { text-align: center; border: 1px solid #e2e8f0; padding: 0.75rem; border-radius: 0.5rem; background-color: #f8fafc; }
        #viewModalContent .doc-item .doc-icon { font-size: 3rem; color: #be123c; margin-bottom: 0.5rem; }
    </style>
</head>
<body>

<div class="container">
    <div class="table-header">
        <h1>My Customers</h1>
        <form action="" method="GET" class="search-form">
            <input type="text" name="search" class="search-input" placeholder="Search by Customer ID..." value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit" class="btn btn-search"><i class="fas fa-search"></i></button>
            <a href="view_customers.php" class="btn btn-clear"><i class="fas fa-times"></i></a>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead>
                <tr>
                    <th>S.No</th>
                    <th>Photo</th>
                    <th>Customer ID</th>
                    <th>Name</th>
                    <th>Mobile Number</th>
                    <th>Registered On</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($customers)): ?>
                    <?php $s_no = 1; ?>
                    <?php foreach ($customers as $customer): ?>
                        <tr>
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
                            <td><?php echo htmlspecialchars($customer['name']); ?></td>
                            <td><?php echo htmlspecialchars($customer['mobile_no']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></td>
                            <td class="text-center">
                                <button class="btn btn-view" data-id="<?php echo $customer['id']; ?>"><i class="fas fa-eye"></i> View</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-10">
                            <?php if (!empty($search_query)): ?>
                                No customers found matching your search criteria.
                            <?php else: ?>
                                You have not added any customers yet.
                            <?php endif; ?>
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
        <div id="viewModalContent" class="py-4">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    const viewModal = $('#viewModal');

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
                if (response.error) {
                    alert(response.error);
                    return;
                }
                
                const photoUrl = (response.photo_path && response.photo_path.length > 0) 
                                ? response.photo_path 
                                : 'https://placehold.co/100x100/EBF4FF/7F9CF5?text=N/A';

                let content = `
                    <div class="flex items-center gap-6">
                        <img src="${photoUrl}" alt="Profile" class="profile-pic-large">
                        <div>
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
                                        <a href="${doc.document_image_path}" target="_blank" title="View PDF">
                                            <i class="fas fa-file-pdf doc-icon"></i>
                                        </a>
                                        <h4 class="font-semibold">${doc.document_type}</h4>
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
});
</script>

</body>
</html>
