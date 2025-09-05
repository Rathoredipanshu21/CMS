<?php
// Include the database configuration file
include '../config/db.php';

// --- HANDLE DELETE REQUEST ---
if (isset($_GET['delete_id'])) {
    $id_to_delete = $_GET['delete_id'];
    
    // Use a prepared statement to prevent SQL injection
    $stmt = $conn->prepare("DELETE FROM branch WHERE id = ?");
    $stmt->bind_param("i", $id_to_delete);
    
    if ($stmt->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=deleted");
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=error");
    }
    $stmt->close();
    exit();
}

// --- HANDLE UPDATE REQUEST ---
if (isset($_POST['update'])) {
    // Sanitize and retrieve form data
    $id = $_POST['id'];
    $branch_name = $_POST['branch_name'];
    $username = $_POST['username'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];

    // Use a prepared statement to prevent SQL injection
    $stmt = $conn->prepare("UPDATE branch SET branch_name = ?, username = ?, email = ?, phone = ?, address = ? WHERE id = ?");
    $stmt->bind_param("sssssi", $branch_name, $username, $email, $phone, $address, $id);

    if ($stmt->execute()) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=updated");
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
    exit();
}


// --- HANDLE FORM SUBMISSION FOR CREATING A NEW BRANCH ---
if (isset($_POST['create'])) {
    // Sanitize and retrieve form data
    $branch_name = $_POST['branch_name'];
    $username = $_POST['username'];
    $password = $_POST['password']; // WARNING: Storing passwords in plaintext is highly insecure. Consider using password_hash().
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];

    // --- USE PREPARED STATEMENTS TO PREVENT SQL INJECTION ---
    $stmt = $conn->prepare("INSERT INTO branch (branch_name, username, password, email, phone, address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $branch_name, $username, $password, $email, $phone, $address);

    if ($stmt->execute()) {
        // Create a new branch-specific table
        $tableName = "branch_" . strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $username)); // Sanitize username for table name
        $createTableQuery = "CREATE TABLE IF NOT EXISTS `$tableName` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        
        // Execute the table creation query
        $conn->query($createTableQuery);

        // Redirect to the same page to prevent form resubmission and show the new branch
        header("Location: " . $_SERVER['PHP_SELF'] . "?status=success");
        exit();
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
}

// --- FETCH ALL BRANCHES FOR DISPLAY ---
$sql = "SELECT id, branch_name, username, email, phone, address, created_at FROM branch ORDER BY id DESC";
$result = $conn->query($sql);
$total_branches = $result->num_rows;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Employees</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- AOS Animation Library -->
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.1/dist/aos.css">
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-gray-100 min-h-screen p-8">

    <div class="container mx-auto max-w-7xl">
        <!-- Header Section -->
        <div class="flex justify-between items-center mb-8" data-aos="fade-down">
            <div>
                <h1 class="text-3xl font-bold text-gray-800">Manage Employees</h1>
                <p class="text-gray-500">Total Employees: <span class="font-semibold text-blue-600"><?php echo $total_branches; ?></span></p>
            </div>
            <button id="addBranchBtn" class="flex items-center gap-2 bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg shadow-md hover:bg-blue-700 transition duration-300">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
                Add Branch
            </button>
        </div>

        <!-- Branches Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden" data-aos="fade-up" data-aos-delay="200">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3">S.No</th>
                            <th scope="col" class="px-6 py-3">Employee Name</th>
                            <th scope="col" class="px-6 py-3">Username</th>
                            <th scope="col" class="px-6 py-3">Email</th>
                            <th scope="col" class="px-6 py-3">Phone</th>
                            <th scope="col" class="px-6 py-3 min-w-[200px]">Address</th>
                            <th scope="col" class="px-6 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php $s_no = 1; ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium text-gray-900"><?php echo $s_no++; ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['branch_name']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['phone']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($row['address']); ?></td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex justify-center items-center gap-4">
                                            <button class="editBtn font-medium text-blue-600 hover:text-blue-800"
                                                data-id="<?php echo $row['id']; ?>"
                                                data-branch_name="<?php echo htmlspecialchars($row['branch_name']); ?>"
                                                data-username="<?php echo htmlspecialchars($row['username']); ?>"
                                                data-email="<?php echo htmlspecialchars($row['email']); ?>"
                                                data-phone="<?php echo htmlspecialchars($row['phone']); ?>"
                                                data-address="<?php echo htmlspecialchars($row['address']); ?>">
                                                <i data-lucide="pencil" class="w-5 h-5"></i>
                                            </button>
                                            <a href="?delete_id=<?php echo $row['id']; ?>" class="font-medium text-red-600 hover:text-red-800" onclick="return confirm('Are you sure you want to delete this branch?');">
                                                <i data-lucide="trash-2" class="w-5 h-5"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr class="bg-white border-b">
                                <td colspan="7" class="px-6 py-4 text-center text-gray-500">No branches found. Add one to get started!</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Branch Modal -->
    <div id="addBranchModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md mx-4" data-aos="zoom-in-up" data-aos-duration="400">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Create a New Branch</h2>
                <button class="closeModalBtn text-gray-500 hover:text-gray-800">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <form method="POST">
                <input type="text" name="branch_name" placeholder="Branch Name" required class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="text" name="username" placeholder="Username" required class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="text" name="password" placeholder="Password" required class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="email" name="email" placeholder="Email" class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="text" name="phone" placeholder="Phone" class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <textarea name="address" placeholder="Address" class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3"></textarea>
                <button type="submit" name="create" class="w-full bg-blue-600 text-white p-3 rounded-lg font-semibold hover:bg-blue-700 transition duration-300">Create Branch</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Branch Modal -->
    <div id="editBranchModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white p-8 rounded-2xl shadow-xl w-full max-w-md mx-4" data-aos="zoom-in-up" data-aos-duration="400">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Edit Branch Details</h2>
                <button class="closeModalBtn text-gray-500 hover:text-gray-800">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="id" id="edit_id">
                <input type="text" name="branch_name" id="edit_branch_name" placeholder="Branch Name" required class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="text" name="username" id="edit_username" placeholder="Username" required class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="email" name="email" id="edit_email" placeholder="Email" class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <input type="text" name="phone" id="edit_phone" placeholder="Phone" class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                <textarea name="address" id="edit_address" placeholder="Address" class="w-full mb-3 p-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3"></textarea>
                <button type="submit" name="update" class="w-full bg-green-600 text-white p-3 rounded-lg font-semibold hover:bg-green-700 transition duration-300">Update Branch</button>
            </form>
        </div>
    </div>


    <!-- JavaScript Libraries -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true
        });

        // Initialize Lucide Icons
        lucide.createIcons();

        // --- Modal Handling ---
        const addBranchBtn = document.getElementById('addBranchBtn');
        const addBranchModal = document.getElementById('addBranchModal');
        const editBranchModal = document.getElementById('editBranchModal');
        const closeModalBtns = document.querySelectorAll('.closeModalBtn');

        // Open Add Modal
        addBranchBtn.addEventListener('click', () => {
            addBranchModal.classList.remove('hidden');
        });

        // Open Edit Modal
        const editBtns = document.querySelectorAll('.editBtn');
        editBtns.forEach(button => {
            button.addEventListener('click', () => {
                const data = button.dataset;
                document.getElementById('edit_id').value = data.id;
                document.getElementById('edit_branch_name').value = data.branch_name;
                document.getElementById('edit_username').value = data.username;
                document.getElementById('edit_email').value = data.email;
                document.getElementById('edit_phone').value = data.phone;
                document.getElementById('edit_address').value = data.address;
                editBranchModal.classList.remove('hidden');
            });
        });

        // Close any modal
        const closeAllModals = () => {
            addBranchModal.classList.add('hidden');
            editBranchModal.classList.add('hidden');
        };

        closeModalBtns.forEach(button => {
            button.addEventListener('click', closeAllModals);
        });

        // Close modal if user clicks outside the content
        [addBranchModal, editBranchModal].forEach(modal => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeAllModals();
                }
            });
        });


        // --- Display Status Messages ---
        const urlParams = new URLSearchParams(window.location.search);
        const status = urlParams.get('status');
        let message = '';

        if (status) {
            switch(status) {
                case 'success':
                    message = 'Branch Created Successfully!';
                    break;
                case 'updated':
                    message = 'Branch Updated Successfully!';
                    break;
                case 'deleted':
                    message = 'Branch Deleted Successfully!';
                    break;
                case 'error':
                    message = 'An error occurred. Please try again.';
                    break;
            }
            alert(message);
            // Clean the URL to prevent the message from showing on refresh
            window.history.replaceState(null, null, window.location.pathname);
        }
    </script>
</body>
</html>
<?php
// Close the database connection
$conn->close();
?>
