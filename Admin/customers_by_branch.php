<?php
// Include the database configuration file.
// This file should establish a connection to the database in a variable named $conn.
include '../config/db.php';

// --- Data Fetching ---

// The SQL query to fetch customers along with their branch name.
// We use a LEFT JOIN to ensure all customers are included, even if their `created_by_branch` is NULL.
// We assume your branches table is named 'branches' with 'id' and 'name' columns.
// If your branch table has different column names, please adjust the query.
$sql = "
    SELECT 
        c.id, 
        c.customer_uid, 
        c.name, 
        c.father_name, 
        c.mobile_no, 
        c.email, 
        c.company_id, 
        c.employee_id, 
        c.photo_path, 
        c.created_by_branch, 
        c.created_by_user, 
        c.created_at,
        b.name AS branch_name 
    FROM 
        customers c
    LEFT JOIN 
        branches b ON c.created_by_branch = b.id
    ORDER BY 
        b.name ASC, c.created_at DESC
";

$result = $conn->query($sql);

// --- Data Processing ---

// Create an array to hold customers, grouped by their branch.
$customersByBranch = [];

if ($result && $result->num_rows > 0) {
    // Loop through each row from the database result.
    while ($row = $result->fetch_assoc()) {
        // Determine the group key. If branch_name is NULL or empty, group under 'Admin'.
        $branchKey = !empty($row['branch_name']) ? $row['branch_name'] : 'Customers Added by Admin';
        
        // Add the customer row to the corresponding branch group.
        $customersByBranch[$branchKey][] = $row;
    }
} else {
    // Handle the case where no customers are found.
    $message = "No customers found in the database.";
}

// Close the database connection as it's no longer needed.
$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers by Branch</title>
    <!-- Using Tailwind CSS for modern styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Custom styles for better presentation */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc; /* A light gray background */
        }
        .table-container {
            overflow-x: auto; /* Allows horizontal scrolling on small screens */
        }
        th, td {
            white-space: nowrap; /* Prevents table cell content from wrapping */
        }
    </style>
</head>
<body class="text-gray-800">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <header class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Customer Admissions Report</h1>
            <p class="text-md text-gray-600 mt-1">Customers grouped by the branch where they were registered.</p>
        </header>

        <?php if (!empty($customersByBranch)): ?>
            <!-- Loop through each branch group -->
            <?php foreach ($customersByBranch as $branchName => $customers): ?>
                <div class="mb-10 bg-white rounded-xl shadow-md overflow-hidden">
                    <div class="bg-gray-100 p-4 border-b border-gray-200">
                        <h2 class="text-xl font-semibold text-gray-700"><?php echo htmlspecialchars($branchName); ?> (<?php echo count($customers); ?> Customers)</h2>
                    </div>
                    
                    <div class="table-container p-4">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Photo</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Father's Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer UID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User ID</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <!-- Loop through customers in the current branch group -->
                                <?php foreach ($customers as $customer): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4">
                                            <img 
                                                class="h-10 w-10 rounded-full object-cover" 
                                                src="<?php echo !empty($customer['photo_path']) ? htmlspecialchars($customer['photo_path']) : 'https://placehold.co/40x40/E2E8F0/4A5568?text=N/A'; ?>" 
                                                alt="Customer Photo"
                                                onerror="this.onerror=null;this.src='https://placehold.co/40x40/E2E8F0/4A5568?text=Error';"
                                            >
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($customer['father_name']); ?></td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($customer['mobile_no']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($customer['email']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 font-mono"><?php echo htmlspecialchars($customer['customer_uid']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo date("d M, Y", strtotime($customer['created_at'])); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($customer['created_by_user']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Display a message if no customers were found -->
            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 rounded-md shadow-sm" role="alert">
                <p class="font-bold">No Customers Found</p>
                <p><?php echo $message; ?></p>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
