<?php
session_start();

// 1. Check if the user is logged in. If not, redirect to the login page.
if (!isset($_SESSION['customer_id'])) {
    header("Location: login.php");
    exit();
}

include '../config/db.php'; // Make sure this path is correct

// 2. Fetch all data for the logged-in customer
$customerId = $_SESSION['customer_id'];
$customer = null;
$documents = [];

// Fetch customer details from 'customers' table
$stmt_customer = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt_customer->bind_param("i", $customerId);
$stmt_customer->execute();
$result_customer = $stmt_customer->get_result();

if ($result_customer->num_rows == 1) {
    $customer = $result_customer->fetch_assoc();
} else {
    // If customer not found (e.g., deleted), destroy session and redirect to login
    session_destroy();
    header("Location: login.php");
    exit();
}
$stmt_customer->close();

// Fetch all associated documents from 'customer_documents' table
$stmt_docs = $conn->prepare("SELECT document_type, document_number, document_image_path FROM customer_documents WHERE customer_id = ? ORDER BY id ASC");
$stmt_docs->bind_param("i", $customerId);
$stmt_docs->execute();
$result_docs = $stmt_docs->get_result();
while ($doc = $result_docs->fetch_assoc()) {
    $documents[] = $doc;
}
$stmt_docs->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f7fafc;
        }
        .profile-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .info-item {
            display: flex;
            align-items: flex-start;
            padding: 0.75rem 0;
            border-bottom: 1px solid #edf2f7;
        }
        .info-item:last-child {
            border-bottom: none;
        }
        .info-icon {
            color: #4a90e2;
            width: 20px;
            margin-right: 1rem;
            margin-top: 4px;
        }
        .document-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body class="text-gray-800">

    <!-- Header & Navbar -->
    <header class="bg-white shadow-md sticky top-0 z-40">
        <nav class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex-shrink-0">
                    <a href="#" class="text-2xl font-bold text-blue-600">MyDashboard</a>
                </div>
                <!-- Desktop Menu -->
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-4">
                        <a href="#" class="bg-blue-600 text-white px-3 py-2 rounded-md text-sm font-medium">Profile</a>
                        <a href="edit_profile.php" class="text-gray-600 hover:bg-gray-200 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">Edit Profile</a>
                        <a href="logout.php" class="text-gray-600 hover:bg-gray-200 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium">Logout</a>
                    </div>
                </div>
                <!-- Mobile Menu Button -->
                <div class="-mr-2 flex md:hidden">
                    <button id="mobile-menu-button" type="button" class="bg-gray-200 inline-flex items-center justify-center p-2 rounded-md text-gray-600 hover:text-gray-900 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-200 focus:ring-white">
                        <span class="sr-only">Open main menu</span>
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </nav>
        <!-- Mobile Menu, show/hide based on menu state. -->
        <div class="md:hidden hidden" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
                <a href="#" class="bg-blue-600 text-white block px-3 py-2 rounded-md text-base font-medium">Profile</a>
                <a href="edit_profile.php" class="text-gray-600 hover:bg-gray-200 hover:text-gray-900 block px-3 py-2 rounded-md text-base font-medium">Edit Profile</a>
                <a href="logout.php" class="text-gray-600 hover:bg-gray-200 hover:text-gray-900 block px-3 py-2 rounded-md text-base font-medium">Logout</a>
            </div>
        </div>
    </header>

    <main class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <!-- Profile Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Left Column: Profile Card -->
            <div class="lg:col-span-1" data-aos="fade-right">
                <div class="profile-card p-6 text-center">
                    <?php
                        // **FIX**: Correctly determine the profile photo path
                        $profile_photo_url = 'https://placehold.co/128x128/e2e8f0/64748b?text=No+Photo';
                        if (!empty($customer['photo_path'])) {
                            // The script is in /Customers, uploads are in parent dir, so go up one level ../
                            $photo_server_path = '../' . $customer['photo_path'];
                            if (file_exists($photo_server_path)) {
                                $profile_photo_url = htmlspecialchars($photo_server_path);
                            }
                        }
                    ?>
                    <img class="w-32 h-32 rounded-full mx-auto object-cover border-4 border-blue-200 shadow-lg" 
                         src="<?php echo $profile_photo_url; ?>" 
                         alt="Profile Photo">
                    <h1 class="mt-4 text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($customer['name']); ?></h1>
                    <p class="text-gray-500 font-mono"><?php echo htmlspecialchars($customer['customer_uid']); ?></p>
                </div>
            </div>

            <!-- Right Column: Detailed Information -->
            <div class="lg:col-span-2" data-aos="fade-left" data-aos-delay="100">
                <div class="profile-card p-6">
                    <h2 class="text-xl font-bold border-b pb-3 mb-4">Personal Information</h2>
                    <div class="space-y-2">
                        <div class="info-item">
                            <i class="fas fa-user-tie info-icon"></i>
                            <div>
                                <span class="font-semibold text-gray-500">Father's Name</span>
                                <p class="text-gray-800"><?php echo htmlspecialchars($customer['father_name'] ?: 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-envelope info-icon"></i>
                            <div>
                                <span class="font-semibold text-gray-500">Email ID</span>
                                <p class="text-gray-800"><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-mobile-alt info-icon"></i>
                            <div>
                                <span class="font-semibold text-gray-500">Mobile Number</span>
                                <p class="text-gray-800"><?php echo htmlspecialchars($customer['mobile_no']); ?></p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-building info-icon"></i>
                            <div>
                                <span class="font-semibold text-gray-500">Company Name</span>
                                <p class="text-gray-800"><?php echo htmlspecialchars($customer['company_name'] ?: 'N/A'); ?></p>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-id-badge info-icon"></i>
                            <div>
                                <span class="font-semibold text-gray-500">Employee ID</span>
                                <p class="text-gray-800"><?php echo htmlspecialchars($customer['employee_id'] ?: 'N/A'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documents Section -->
        <div class="mt-12" data-aos="fade-up">
            <h2 class="text-2xl font-bold mb-6">My Documents</h2>
            <?php if (!empty($documents)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                    <?php foreach ($documents as $doc): ?>
                        <?php
                            // **FIX**: Correctly determine the document image and link paths
                            $doc_link_path = '#';
                            $doc_thumb_path = 'https://placehold.co/400x300/e2e8f0/64748b?text=No+Image';
                            if (!empty($doc['document_image_path'])) {
                                $doc_server_path = '../' . $doc['document_image_path'];
                                if (file_exists($doc_server_path)) {
                                    $doc_link_path = htmlspecialchars($doc_server_path);
                                    // Use a placeholder for PDF thumbnails, otherwise use the image path
                                    if (str_ends_with(strtolower($doc_server_path), '.pdf')) {
                                        $doc_thumb_path = 'https://placehold.co/400x300/e2e8f0/64748b?text=PDF';
                                    } else {
                                        $doc_thumb_path = $doc_link_path;
                                    }
                                }
                            }
                        ?>
                        <div class="document-card bg-white rounded-lg shadow-md overflow-hidden">
                            <a href="<?php echo $doc_link_path; ?>" target="_blank">
                                <img class="h-48 w-full object-cover" 
                                     src="<?php echo $doc_thumb_path; ?>" 
                                     alt="<?php echo htmlspecialchars($doc['document_type']); ?>">
                            </a>
                            <div class="p-4">
                                <h3 class="font-bold text-lg"><?php echo htmlspecialchars($doc['document_type']); ?></h3>
                                <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($doc['document_number']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12 bg-white rounded-lg shadow-md">
                    <i class="fas fa-file-excel fa-3x text-gray-400"></i>
                    <p class="mt-4 text-gray-500">You have not uploaded any documents yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ once: true, duration: 700 });

        // Mobile menu toggle
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
    </script>
</body>
</html>
