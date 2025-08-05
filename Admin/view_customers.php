<?php
include '../config/db.php';

// --- ACTION HANDLER (POST REQUESTS for Delete/Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'An unknown error occurred.'];

    // === DELETE CUSTOMER ACTION ===
    if ($_POST['action'] == 'deleteCustomer' && isset($_POST['id'])) {
        $customerId = intval($_POST['id']);
        $conn->begin_transaction();
        try {
            // 1. Get all file paths to delete from the filesystem
            $files_to_delete = [];
            $stmt = $conn->prepare("SELECT photo_path FROM customers WHERE id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if (!empty($row['photo_path'])) $files_to_delete[] = $row['photo_path'];
            }
            $stmt->close();

            $stmt = $conn->prepare("SELECT document_image_path FROM customer_documents WHERE customer_id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if (!empty($row['document_image_path'])) $files_to_delete[] = $row['document_image_path'];
            }
            $stmt->close();

            // 2. Delete the customer record from DB
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param("i", $customerId);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                // 3. Delete the actual files
                foreach ($files_to_delete as $file) {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
                $conn->commit();
                $response = ['success' => true, 'message' => 'Customer and all associated files deleted successfully.'];
            } else {
                throw new Exception('Customer not found or already deleted.');
            }
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = 'Error deleting customer: ' . $e->getMessage();
        }
    }
    
    echo json_encode($response);
    $conn->close();
    exit;
}

// --- AJAX REQUEST HANDLER (GET for View/Edit Modals) ---
if (isset($_GET['action']) && $_GET['action'] == 'getCustomerDetails' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $customerId = intval($_GET['id']);
    $response = ['customer' => null, 'documents' => []];

    // Fetch customer details - UPDATED to include customer_uid
    $stmt_customer = $conn->prepare("SELECT id, customer_uid, name, father_name, email, mobile_no, company_name, employee_id, photo_path FROM customers WHERE id = ?");
    $stmt_customer->bind_param("i", $customerId);
    $stmt_customer->execute();
    $result_customer = $stmt_customer->get_result();
    if ($customer = $result_customer->fetch_assoc()) {
        $response['customer'] = $customer;
    }
    $stmt_customer->close();

    // Fetch associated documents
    $stmt_docs = $conn->prepare("SELECT id, document_type, document_number, document_image_path FROM customer_documents WHERE customer_id = ? ORDER BY id DESC");
    $stmt_docs->bind_param("i", $customerId);
    $stmt_docs->execute();
    $result_docs = $stmt_docs->get_result();
    while ($doc = $result_docs->fetch_assoc()) {
        $response['documents'][] = $doc;
    }
    $stmt_docs->close();

    echo json_encode($response);
    $conn->close();
    exit;
}

// --- INITIAL PAGE LOAD ---
$customers = [];
// UPDATED to fetch customer_uid for the main table
$sql = "SELECT id, customer_uid, name, email, mobile_no, company_name, photo_path FROM customers ORDER BY id DESC";
$result = $conn->query($sql);
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
    <title>Customer Records</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .table-header { background-color: #1f2937; color: #ffffff; }
        .action-btn { transition: all 0.2s ease-in-out; }
        .action-btn:hover { transform: scale(1.1); }
        .modal-backdrop { background-color: rgba(0, 0, 0, 0.7); transition: opacity 0.3s ease-in-out; }
        .modal-content { transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out; max-height: 90vh; }
        .modal-body::-webkit-scrollbar { width: 8px; }
        .modal-body::-webkit-scrollbar-track { background: #f1f1f1; }
        .modal-body::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        .modal-body::-webkit-scrollbar-thumb:hover { background: #555; }
        .spinner { border: 4px solid rgba(0, 0, 0, 0.1); width: 36px; height: 36px; border-radius: 50%; border-left-color: #4a90e2; animation: spin 1s ease infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto">
    <div class="mb-8 text-center">
        <h1 class="text-4xl font-bold text-gray-800">Customer Records</h1>
        <p class="text-lg text-gray-500 mt-2">A complete list of all registered customers.</p>
    </div>

    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-center">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 sm:mb-0">All Customers</h2>
            <div class="relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3"><i class="fas fa-search text-gray-400"></i></span><input type="text" id="searchInput" placeholder="Search customers..." class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"></div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-600">
                <thead class="table-header">
                    <tr>
                        <th scope="col" class="px-6 py-3">Photo</th>
                        <th scope="col" class="px-6 py-3">Customer ID</th>
                        <th scope="col" class="px-6 py-3">Name</th>
                        <th scope="col" class="px-6 py-3 hidden md:table-cell">Email</th>
                        <th scope="col" class="px-6 py-3 hidden lg:table-cell">Company</th>
                        <th scope="col" class="px-6 py-3">Mobile No</th>
                        <th scope="col" class="px-6 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="customerTableBody">
                    <?php if (!empty($customers)): ?>
                        <?php foreach($customers as $customer): ?>
                            <tr id="customer-row-<?php echo $customer['id']; ?>" class="hover:bg-gray-100 border-b border-gray-200">
                                <td class="px-6 py-4">
                                    <?php if (!empty($customer['photo_path']) && file_exists($customer['photo_path'])): ?>
                                        <img class="h-12 w-12 rounded-full object-cover" src="<?php echo htmlspecialchars($customer['photo_path']); ?>" alt="Photo">
                                    <?php else: ?>
                                        <span class="h-12 w-12 rounded-full bg-gray-200 flex items-center justify-center"><i class="fas fa-user text-2xl text-gray-400"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-gray-700 font-semibold"><?php echo htmlspecialchars($customer['customer_uid'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap"><?php echo htmlspecialchars($customer['name']); ?></td>
                                <td class="px-6 py-4 hidden md:table-cell"><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4 hidden lg:table-cell"><?php echo htmlspecialchars($customer['company_name'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($customer['mobile_no']); ?></td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick='openViewModal(<?php echo $customer['id']; ?>)' class="action-btn text-blue-500 hover:text-blue-700 mr-3" title="View Details"><i class="fas fa-eye fa-lg"></i></button>
                                    <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="action-btn text-green-500 hover:text-green-700 mr-3" title="Edit"><i class="fas fa-pencil-alt fa-lg"></i></a>
                                    <button onclick='confirmDelete(<?php echo $customer['id']; ?>)' class="action-btn text-red-500 hover:text-red-700" title="Delete"><i class="fas fa-trash-alt fa-lg"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-10 text-gray-500"><i class="fas fa-users fa-3x mb-3"></i><p class="text-xl">No customers found.</p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="viewModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden modal-backdrop">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-4xl mx-auto modal-content transform scale-95 opacity-0 flex flex-col">
        <div class="flex justify-between items-center p-5 border-b flex-shrink-0">
            <h3 class="text-2xl font-semibold text-gray-800">Customer Details</h3>
            <button onclick="closeModal('viewModal')" class="text-gray-400 hover:text-gray-600 transition"><i class="fas fa-times fa-2x"></i></button>
        </div>
        <div class="p-6 overflow-y-auto modal-body">
            <div id="viewModalLoader" class="text-center py-20"><div class="spinner mx-auto"></div><p class="mt-4 text-lg text-gray-600">Loading Details...</p></div>
            <div id="viewModalDataContainer" class="hidden">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="md:col-span-1 flex flex-col items-center text-center">
                        <img id="modalPhoto" class="h-32 w-32 rounded-full object-cover border-4 border-gray-200 shadow-md" src="" alt="Customer Photo">
                        <h4 id="modalName" class="mt-4 text-xl font-bold text-gray-900"></h4>
                        <p id="modalCompany" class="text-sm text-gray-500"></p>
                    </div>
                    <div class="md:col-span-2 space-y-4 pt-4">
                        <div class="flex items-start"><i class="fas fa-barcode w-5 text-gray-400 mt-1"></i><p><span class="font-medium text-gray-600">Customer ID:</span> <span id="modalCustomerId" class="text-gray-800 font-mono"></span></p></div>
                        <div class="flex items-start"><i class="fas fa-user-tie w-5 text-gray-400 mt-1"></i><p><span class="font-medium text-gray-600">Father's Name:</span> <span id="modalFatherName" class="text-gray-800"></span></p></div>
                        <div class="flex items-start"><i class="fas fa-envelope w-5 text-gray-400 mt-1"></i><p><span class="font-medium text-gray-600">Email:</span> <span id="modalEmail" class="text-gray-800"></span></p></div>
                        <div class="flex items-start"><i class="fas fa-mobile-alt w-5 text-gray-400 mt-1"></i><p><span class="font-medium text-gray-600">Mobile:</span> <span id="modalMobile" class="text-gray-800"></span></p></div>
                        <div class="flex items-start"><i class="fas fa-id-badge w-5 text-gray-400 mt-1"></i><p><span class="font-medium text-gray-600">Employee ID:</span> <span id="modalEmpId" class="text-gray-800"></span></p></div>
                    </div>
                </div>
                <div class="border-t pt-6">
                    <h4 class="text-xl font-semibold text-gray-800 mb-4">Uploaded Documents</h4>
                    <div id="modalDocuments" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="deleteConfirmModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden modal-backdrop">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-md mx-auto modal-content transform scale-95 opacity-0">
        <div class="p-6 text-center">
            <i class="fas fa-exclamation-triangle text-5xl text-red-500 mb-4"></i>
            <h3 class="text-2xl font-bold text-gray-800">Are you sure?</h3>
            <p class="text-gray-600 mt-2">Do you really want to delete this customer? This process cannot be undone.</p>
        </div>
        <div class="flex justify-center items-center p-4 bg-gray-50 border-t">
            <button onclick="closeModal('deleteConfirmModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-6 rounded-lg mr-4">Cancel</button>
            <button id="confirmDeleteBtn" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded-lg">Delete</button>
        </div>
    </div>
</div>


<script>
    // --- Live Search ---
    document.getElementById('searchInput').addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('#customerTableBody tr').forEach(row => {
            const rowText = row.textContent.toLowerCase();
            row.style.display = rowText.includes(filter) ? '' : 'none';
        });
    });

    // --- Modal Generic Functions ---
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.style.opacity = '1';
            modal.querySelector('.modal-content').classList.remove('scale-95', 'opacity-0');
        }, 10);
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.style.opacity = '0';
        modal.querySelector('.modal-content').classList.add('scale-95', 'opacity-0');
        setTimeout(() => modal.classList.add('hidden'), 300);
    }

    // --- View Modal Logic ---
    async function openViewModal(customerId) {
        openModal('viewModal');
        const loader = document.getElementById('viewModalLoader');
        const dataContainer = document.getElementById('viewModalDataContainer');
        loader.classList.remove('hidden');
        dataContainer.classList.add('hidden');

        try {
            const response = await fetch(`?action=getCustomerDetails&id=${customerId}`);
            if (!response.ok) throw new Error('Network response was not ok');
            const data = await response.json();
            populateViewModal(data);
            loader.classList.add('hidden');
            dataContainer.classList.remove('hidden');
        } catch (error) {
            console.error('Fetch error:', error);
            loader.innerHTML = `<p class="text-red-500">Failed to load details. Please try again.</p>`;
        }
    }

    function populateViewModal(data) {
        const { customer, documents } = data;
        if (customer) {
            // UPDATED: Populate the new Customer ID field
            document.getElementById('modalCustomerId').textContent = customer.customer_uid || 'N/A';
            document.getElementById('modalName').textContent = customer.name || 'N/A';
            document.getElementById('modalFatherName').textContent = customer.father_name || 'N/A';
            document.getElementById('modalEmail').textContent = customer.email || 'N/A';
            document.getElementById('modalMobile').textContent = customer.mobile_no || 'N/A';
            document.getElementById('modalCompany').textContent = customer.company_name || 'N/A';
            document.getElementById('modalEmpId').textContent = customer.employee_id || 'N/A';
            document.getElementById('modalPhoto').src = (customer.photo_path && customer.photo_path.length > 0) ? customer.photo_path : 'https://placehold.co/128x128/e2e8f0/64748b?text=No+Photo';
        }
        const docsContainer = document.getElementById('modalDocuments');
        docsContainer.innerHTML = '';
        if (documents && documents.length > 0) {
            documents.forEach(doc => {
                const isPdf = doc.document_image_path.toLowerCase().endsWith('.pdf');
                const docImage = isPdf ? 'https://placehold.co/400x300/e2e8f0/64748b?text=PDF' : doc.document_image_path;
                docsContainer.innerHTML += `
                    <div class="border rounded-lg overflow-hidden bg-gray-50 shadow-sm">
                        <a href="${doc.document_image_path || '#'}" target="_blank" title="View full document">
                            <img src="${docImage || 'https://placehold.co/400x300/e2e8f0/64748b?text=No+Image'}" alt="${doc.document_type}" class="w-full h-40 object-cover hover:opacity-80 transition">
                        </a>
                        <div class="p-3">
                            <h5 class="font-bold text-gray-800">${doc.document_type || 'N/A'}</h5>
                            <p class="text-sm text-gray-600">${doc.document_number || 'N/A'}</p>
                        </div>
                    </div>`;
            });
        } else {
            docsContainer.innerHTML = '<p class="text-gray-500 col-span-full text-center">No documents uploaded.</p>';
        }
    }

    // --- Delete Logic ---
    function confirmDelete(customerId) {
        openModal('deleteConfirmModal');
        document.getElementById('confirmDeleteBtn').onclick = () => deleteCustomer(customerId);
    }

    async function deleteCustomer(customerId) {
        const btn = document.getElementById('confirmDeleteBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Deleting...';

        const formData = new FormData();
        formData.append('action', 'deleteCustomer');
        formData.append('id', customerId);

        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                const row = document.getElementById(`customer-row-${customerId}`);
                if (row) row.remove();
                closeModal('deleteConfirmModal');
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('An error occurred while trying to delete the customer.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Delete';
        }
    }
    
    // Close modals on escape key press
    window.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop:not(.hidden)').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });
</script>
</body>
</html>