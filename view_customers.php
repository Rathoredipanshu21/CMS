<?php
// Include the database configuration file
include 'config/db.php';

// SQL query to fetch all customers, ordering by the most recently created
$sql = "SELECT id, name, father_name, email, mobile_no, document_type, document_number, company_name, employee_id, photo_path, created_at FROM customers ORDER BY id DESC";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Records</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        .table-header {
            background-color: #1f2937; /* Dark Gray */
            color: #ffffff;
        }
        .table-row-even {
            background-color: #ffffff;
        }
        .table-row-odd {
            background-color: #f9fafb; /* Light Gray */
        }
        .action-btn {
            transition: all 0.2s ease-in-out;
        }
        .action-btn:hover {
            transform: scale(1.1);
        }
        /* Modal styles */
        .modal-backdrop {
            background-color: rgba(0, 0, 0, 0.6);
            transition: opacity 0.3s ease-in-out;
        }
        .modal-content {
            transition: transform 0.3s ease-in-out;
        }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">

<div class="max-w-7xl mx-auto">
    <!-- Header Section -->
    <div class="mb-8 text-center">
        <h1 class="text-4xl font-bold text-gray-800">Customer Records</h1>
        <p class="text-lg text-gray-500 mt-2">A complete list of all registered customers.</p>
    </div>

    <!-- Main Content: Table Card -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden">
        <!-- Card Header with Search -->
        <div class="p-6 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-center">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 sm:mb-0">All Customers</h2>
            <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3">
                    <i class="fas fa-search text-gray-400"></i>
                </span>
                <input type="text" id="searchInput" placeholder="Search customers..." class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <!-- Table Container -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-600">
                <thead class="table-header">
                    <tr>
                        <th scope="col" class="px-6 py-3">Photo</th>
                        <th scope="col" class="px-6 py-3">Name</th>
                        <th scope="col" class="px-6 py-3 hidden md:table-cell">Email</th>
                        <th scope="col" class="px-6 py-3 hidden lg:table-cell">Company</th>
                        <th scope="col" class="px-6 py-3">Mobile No</th>
                        <th scope="col" class="px-6 py-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="customerTableBody">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr class="table-row-odd hover:bg-gray-200 border-b border-gray-200">
                                <td class="px-6 py-4">
                                    <?php if (!empty($row['photo_path']) && file_exists($row['photo_path'])): ?>
                                        <img class="h-12 w-12 rounded-full object-cover" src="<?php echo htmlspecialchars($row['photo_path']); ?>" alt="Customer Photo">
                                    <?php else: ?>
                                        <span class="h-12 w-12 rounded-full bg-gray-200 flex items-center justify-center">
                                            <i class="fas fa-user text-2xl text-gray-400"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </td>
                                <td class="px-6 py-4 hidden md:table-cell">
                                    <?php echo htmlspecialchars($row['email']); ?>
                                </td>
                                <td class="px-6 py-4 hidden lg:table-cell">
                                    <?php echo htmlspecialchars($row['company_name']); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php echo htmlspecialchars($row['mobile_no']); ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick='openModal(<?php echo json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)' class="action-btn text-blue-500 hover:text-blue-700 mr-3" title="View Details">
                                        <i class="fas fa-eye fa-lg"></i>
                                    </button>
                                    <a href="#" class="action-btn text-green-500 hover:text-green-700 mr-3" title="Edit">
                                        <i class="fas fa-pencil-alt fa-lg"></i>
                                    </a>
                                    <a href="#" class="action-btn text-red-500 hover:text-red-700" title="Delete">
                                        <i class="fas fa-trash-alt fa-lg"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-10 text-gray-500">
                                <i class="fas fa-exclamation-circle fa-3x mb-3"></i>
                                <p class="text-xl">No customers found.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php $conn->close(); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div id="customerModal" class="fixed inset-0 z-50 flex items-center justify-center hidden modal-backdrop">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4 modal-content transform scale-95">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-5 border-b">
            <h3 class="text-2xl font-semibold text-gray-800">Customer Details</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times fa-2x"></i>
            </button>
        </div>
        <!-- Modal Body -->
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Photo Column -->
                <div class="md:col-span-1 flex flex-col items-center">
                    <img id="modalPhoto" class="h-32 w-32 rounded-full object-cover border-4 border-gray-200" src="" alt="Customer Photo">
                    <h4 id="modalName" class="mt-4 text-xl font-bold text-gray-900 text-center"></h4>
                    <p id="modalCompany" class="text-sm text-gray-500"></p>
                </div>
                <!-- Details Column -->
                <div class="md:col-span-2 space-y-4">
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <p class="text-gray-500 col-span-1">Father's Name</p>
                        <p id="modalFatherName" class="text-gray-800 font-medium col-span-2"></p>
                    </div>
                    <hr>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <p class="text-gray-500 col-span-1">Email ID</p>
                        <p id="modalEmail" class="text-gray-800 font-medium col-span-2"></p>
                    </div>
                    <hr>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <p class="text-gray-500 col-span-1">Mobile No.</p>
                        <p id="modalMobile" class="text-gray-800 font-medium col-span-2"></p>
                    </div>
                    <hr>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <p class="text-gray-500 col-span-1">Document</p>
                        <p id="modalDocType" class="text-gray-800 font-medium col-span-2"></p>
                    </div>
                    <hr>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <p class="text-gray-500 col-span-1">Document No.</p>
                        <p id="modalDocNumber" class="text-gray-800 font-medium col-span-2"></p>
                    </div>
                    <hr>
                     <div class="grid grid-cols-3 gap-4 text-sm">
                        <p class="text-gray-500 col-span-1">Employee ID</p>
                        <p id="modalEmpId" class="text-gray-800 font-medium col-span-2"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
    // --- Live Search Functionality ---
    const searchInput = document.getElementById('searchInput');
    const tableBody = document.getElementById('customerTableBody');
    const tableRows = tableBody.getElementsByTagName('tr');

    searchInput.addEventListener('keyup', function() {
        const filter = searchInput.value.toLowerCase();
        for (let i = 0; i < tableRows.length; i++) {
            let row = tableRows[i];
            let cells = row.getElementsByTagName('td');
            let text = '';
            // Concatenate text from all cells in the row
            for (let j = 0; j < cells.length; j++) {
                text += cells[j].textContent || cells[j].innerText;
            }
            if (text.toLowerCase().indexOf(filter) > -1) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        }
    });

    // --- Modal Functionality ---
    const modal = document.getElementById('customerModal');
    const modalBackdrop = modal.querySelector('.modal-backdrop');
    const modalContent = modal.querySelector('.modal-content');

    function openModal(customerData) {
        // Populate modal with customer data
        document.getElementById('modalName').textContent = customerData.name || 'N/A';
        document.getElementById('modalFatherName').textContent = customerData.father_name || 'N/A';
        document.getElementById('modalEmail').textContent = customerData.email || 'N/A';
        document.getElementById('modalMobile').textContent = customerData.mobile_no || 'N/A';
        document.getElementById('modalDocType').textContent = customerData.document_type || 'N/A';
        document.getElementById('modalDocNumber').textContent = customerData.document_number || 'N/A';
        document.getElementById('modalCompany').textContent = customerData.company_name || 'N/A';
        document.getElementById('modalEmpId').textContent = customerData.employee_id || 'N/A';
        
        const photoElement = document.getElementById('modalPhoto');
        if (customerData.photo_path && customerData.photo_path !== '') {
            photoElement.src = customerData.photo_path;
        } else {
            // Use a placeholder if no photo is available
            photoElement.src = 'https://placehold.co/128x128/e2e8f0/64748b?text=No+Photo';
        }

        // Show the modal with animation
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            modalContent.classList.remove('scale-95');
        }, 10);
    }

    function closeModal() {
        // Hide the modal with animation
        modal.classList.add('opacity-0');
        modalContent.classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300); // Match transition duration
    }

    // Close modal if backdrop is clicked
    modal.addEventListener('click', function(event) {
        if (event.target === modal) {
            closeModal();
        }
    });
</script>

</body>
</html>