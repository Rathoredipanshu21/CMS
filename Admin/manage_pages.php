<?php
session_start();
// C:\xampp\htdocs\Cash\Admin\manage_pages.php
include '../config/db.php';
include '../config/pages.php';

// This block handles the form submission when the 'Update' button is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear all existing permissions first
    $conn->query("TRUNCATE TABLE branch_page_permissions");

    // Check if any permissions were submitted
    if (!empty($_POST['permissions'])) {
        $stmt = $conn->prepare("INSERT INTO branch_page_permissions (branch_id, page_file) VALUES (?, ?)");
        // Loop through each branch's submitted permissions
        foreach ($_POST['permissions'] as $branch_id => $pages) {
            // Loop through each page selected for the branch
            foreach ($pages as $page_file) {
                $stmt->bind_param("is", $branch_id, $page_file);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
    // Redirect back to the same page with a success message
    header("Location: manage_pages.php?success=1");
    exit;
}

// Fetch all branches from the database
$branches_result = $conn->query("SELECT id, branch_name FROM branch ORDER BY branch_name");
$branches = [];
while ($row = $branches_result->fetch_assoc()) {
    $branches[] = $row;
}

// Fetch all current permissions to pre-check the boxes
$permissions_result = $conn->query("SELECT branch_id, page_file FROM branch_page_permissions");
$current_permissions = [];
while ($row = $permissions_result->fetch_assoc()) {
    $current_permissions[$row['branch_id']][] = $row['page_file'];
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Page Permissions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f1f5f9; }
        .table-container { max-height: 70vh; overflow-y: auto; }
        th { position: sticky; top: 0; z-index: 10; background-color: #f1f5f9; }
        .permission-checkbox { transform: scale(1.2); cursor: pointer; accent-color: #2563eb; }
        tbody tr:nth-child(even) { background-color: #f8fafc; }
        tbody tr:hover { background-color: #eff6ff; }
    </style>
</head>
<body class="p-4 sm:p-6 lg:p-8">
    <div class="max-w-7xl mx-auto">
        <header class="mb-8" data-aos="fade-down">
            <h1 class="text-4xl font-bold text-gray-800"><i class="fas fa-user-shield mr-4 text-blue-500"></i>Manage Page Permissions</h1>
            <p class="text-gray-500 mt-2">Assign page access to each branch and click the update button to save.</p>
        </header>
        <?php if (isset($_GET['success'])): ?>
            <div id="success-alert" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md shadow-md" role="alert" data-aos="fade-left">
                <p class="font-bold">Success!</p><p>Permissions have been updated successfully.</p>
            </div>
        <?php endif; ?>
        <form action="manage_pages.php" method="POST">
            <div class="bg-white rounded-lg shadow-xl overflow-hidden" data-aos="fade-up">
                <div class="table-container">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="text-xs text-gray-700 uppercase">
                            <tr>
                                <th scope="col" class="px-6 py-4">Page Name</th>
                                <?php foreach ($branches as $branch): ?>
                                    <th scope="col" class="px-6 py-4 text-center">
                                        <?php echo htmlspecialchars($branch['branch_name']); ?><br>
                                        <input type="checkbox" title="Select all for <?php echo htmlspecialchars($branch['branch_name']); ?>" class="permission-checkbox select-all-col mt-2" data-branch-id="<?php echo $branch['id']; ?>">
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($managed_pages as $page): ?>
                                <tr class="border-b">
                                    <th scope="row" class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap flex items-center">
                                        <input type="checkbox" title="Select for all branches" class="permission-checkbox select-all-row mr-4" data-page-file="<?php echo htmlspecialchars($page['file']); ?>">
                                        <i class="<?php echo $page['icon']; ?> text-gray-500 mr-3 w-5 text-center"></i>
                                        <?php echo htmlspecialchars($page['name']); ?>
                                    </th>
                                    <?php foreach ($branches as $branch): ?>
                                        <td class="px-6 py-4 text-center">
                                            <input type="checkbox" class="permission-checkbox permission-cb col-<?php echo $branch['id']; ?> row-<?php echo htmlspecialchars(str_replace('.', '_', $page['file'])); ?>"
                                                name="permissions[<?php echo $branch['id']; ?>][]" 
                                                value="<?php echo htmlspecialchars($page['file']); ?>"
                                                <?php if (isset($current_permissions[$branch['id']]) && in_array($page['file'], $current_permissions[$branch['id']])) { echo 'checked'; } ?>>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <footer class="mt-8 text-center" data-aos="fade-up" data-aos-delay="200">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-lg shadow-lg transition transform hover:scale-105">
                    <i class="fas fa-save mr-2"></i> Update All Permissions
                </button>
            </footer>
        </form>
    </div>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ duration: 600, once: true });

        // This script makes the success message disappear after a few seconds.
        const successAlert = document.getElementById('success-alert');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.transition = 'opacity 0.5s ease';
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.remove(), 500);
            }, 4000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Logic for "Select All" checkbox in each column header
            document.querySelectorAll('.select-all-col').forEach(headerCheckbox => {
                headerCheckbox.addEventListener('change', function() {
                    const branchId = this.dataset.branchId;
                    document.querySelectorAll(`.permission-cb.col-${branchId}`).forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            });

            // Logic for "Select All" checkbox in each row header
            document.querySelectorAll('.select-all-row').forEach(rowCheckbox => {
                rowCheckbox.addEventListener('change', function() {
                    // We need to clean the page file name to be a valid CSS class selector
                    const pageFileClass = this.dataset.pageFile.replace(/\./g, '_');
                    document.querySelectorAll(`.permission-cb.row-${pageFileClass}`).forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            });
        });
    </script>
</body>
</html>
