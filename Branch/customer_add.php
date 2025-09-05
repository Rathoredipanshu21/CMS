<?php
session_start(); // Start the session to access logged-in user data

// --- Security Check: Ensure user is logged in ---
if (!isset($_SESSION['branch_user']) || !isset($_SESSION['branch_id'])) {
    // If not logged in, stop execution and show an error.
    die("Access Denied. You must be logged in to perform this action.");
}

$message = '';
$error = '';
$display_uid = '';
$companies = [];

// --- Database Connection ---
include '../config/db.php';

// --- Fetch Logged-in User's Branch Name AND Username from DB ---
$branch_name_from_db = 'N/A';
$username_from_db = 'N/A'; // Variable to hold the username from the database

if ($conn) {
    // Prepare a single query to get both branch_name and username
    $stmt_branch = $conn->prepare("SELECT branch_name, username FROM branch WHERE id = ?");
    if ($stmt_branch) {
        $stmt_branch->bind_param("i", $_SESSION['branch_id']);
        $stmt_branch->execute();
        $result_branch = $stmt_branch->get_result();
        if ($row_branch = $result_branch->fetch_assoc()) {
            $branch_name_from_db = $row_branch['branch_name'];
            $username_from_db = $row_branch['username']; // Store the username
        }
        $stmt_branch->close();
    }
}

// --- Fetch Companies for Dropdown ---
if ($conn && !$conn->connect_error) {
    $company_result = $conn->query("SELECT id, company_name FROM company_commissions ORDER BY company_name ASC");
    if ($company_result && $company_result->num_rows > 0) {
        while($row = $company_result->fetch_assoc()) {
            $companies[] = $row;
        }
    }
} else {
    $error = "Database connection failed: " . ($conn ? $conn->connect_error : "Connection object is null");
}

// --- Form Submission Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!$conn || $conn->connect_error) {
        $error = "Database connection has been lost.";
    } else {
        // --- Use the variables fetched from the database ---
        $created_by_branch = $branch_name_from_db;
        $created_by_user = $username_from_db;

        $conn->begin_transaction();

        try {
            // --- Define File Size and Type Limits ---
            define('MAX_IMAGE_SIZE', 400 * 1024); // 400 KB
            define('MAX_PDF_SIZE', 400 * 1024);   // 400 KB
            $allowed_image_types = ['jpg', 'jpeg', 'png'];
            $allowed_doc_types = ['pdf'];

            // --- Generate New Unique Customer ID ---
            $result = $conn->query("SELECT customer_uid FROM customers ORDER BY id DESC LIMIT 1");
            $last_uid = 'DBCE-CMS-000';
            if ($result && $result->num_rows > 0) {
                $last_uid = $result->fetch_assoc()['customer_uid'];
            }
            $numeric_part = (int) substr($last_uid, -3);
            $new_numeric_part = $numeric_part + 1;
            $customer_uid = 'DBCE-CMS-' . str_pad($new_numeric_part, 3, '0', STR_PAD_LEFT);

            // --- Validate Mobile Number ---
            $mobile_no = $_POST['mobile_no'];
            if (!preg_match('/^[0-9]{10}$/', $mobile_no)) {
                throw new Exception("Mobile number must be exactly 10 digits.");
            }

            // --- Insert into `customers` table ---
            // ## FIX 1: The SQL statement now uses `company_id` instead of `company_name`
            $stmt_customer = $conn->prepare(
                "INSERT INTO customers (customer_uid, name, father_name, email, mobile_no, company_id, employee_id, photo_path, created_by_branch, created_by_user)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if ($stmt_customer === false) {
                throw new Exception("Failed to prepare the customer insert statement. DB Error: " . $conn->error);
            }

            $name = $_POST['name'];
            $father_name = $_POST['father_name'];
            $email = $_POST['email'];
            // ## FIX 2: Get `company_id` from the form. Handle the optional (NULL) case.
            $company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : NULL;
            $employee_id = $_POST['employee_id'];
            $photo_path = '';

            // --- Handle Profile Photo Upload with Validation ---
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                if ($_FILES['photo']['size'] > MAX_IMAGE_SIZE) {
                    throw new Exception("Profile photo size cannot exceed 400KB.");
                }
                $target_dir = "../Admin/uploads/profiles/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                $file_extension = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
                if (!in_array($file_extension, $allowed_image_types)) {
                    throw new Exception("Invalid file type for profile photo. Only JPG, JPEG, & PNG are allowed.");
                }
                $target_file = $target_dir . uniqid('profile_', true) . '.' . $file_extension;
                if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
                    $photo_path = $target_file;
                } else {
                    throw new Exception("Sorry, there was an error uploading your profile photo.");
                }
            }

            // ## FIX 3: Updated bind_param with 'i' for integer and using the correct $company_id variable
            $stmt_customer->bind_param("sssssissss", $customer_uid, $name, $father_name, $email, $mobile_no, $company_id, $employee_id, $photo_path, $created_by_branch, $created_by_user);
            if (!$stmt_customer->execute()) {
                throw new Exception("Error creating customer record: " . $stmt_customer->error);
            }
            $customer_id = $conn->insert_id;
            $stmt_customer->close();

            // --- Handle Multiple Document Uploads with Validation ---
            if (isset($_POST['document_type']) && is_array($_POST['document_type'])) {
                $doc_files = $_FILES['document_image'];
                $doc_types = $_POST['document_type'];
                $doc_numbers = $_POST['document_number'];
                $doc_target_dir = "../Admin/uploads/documents/";
                if (!is_dir($doc_target_dir)) mkdir($doc_target_dir, 0755, true);

                foreach ($doc_types as $key => $type) {
                    if (!empty($type)) {
                        $number = $doc_numbers[$key];
                        if (empty($number) || !isset($doc_files['name'][$key]) || $doc_files['error'][$key] !== UPLOAD_ERR_OK) {
                            throw new Exception("For each document, you must provide a type, number, and upload a file.");
                        }
                        if ($doc_files['size'][$key] > MAX_PDF_SIZE) {
                            throw new Exception("Document '" . htmlspecialchars($type) . "' size cannot exceed 400KB.");
                        }
                        $file_ext = strtolower(pathinfo($doc_files['name'][$key], PATHINFO_EXTENSION));
                        if (!in_array($file_ext, $allowed_doc_types)) {
                             throw new Exception("Invalid file type for document '" . htmlspecialchars($type) . "'. Only PDF files are allowed.");
                        }
                        if ($type === 'Aadhaar Card' && !preg_match('/^\d{12}$/', $number)) throw new Exception("Invalid Aadhaar number format.");
                        if ($type === 'PAN Card' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/', $number)) throw new Exception("Invalid PAN Card format.");

                        $doc_target_file = $doc_target_dir . uniqid('doc_' . $customer_id . '_', true) . '.' . $file_ext;
                        if (!move_uploaded_file($doc_files['tmp_name'][$key], $doc_target_file)) {
                            throw new Exception("Failed to upload document file for " . htmlspecialchars($type));
                        }
                        $stmt_doc = $conn->prepare("INSERT INTO customer_documents (customer_id, document_type, document_number, document_image_path) VALUES (?, ?, ?, ?)");
                        if ($stmt_doc === false) {
                            throw new Exception("Failed to prepare document insert statement. DB Error: " . $conn->error);
                        }
                        $stmt_doc->bind_param("isss", $customer_id, $type, $number, $doc_target_file);
                        if (!$stmt_doc->execute()) {
                            throw new Exception("Error saving document record: " . $stmt_doc->error);
                        }
                        $stmt_doc->close();
                    }
                }
            }

            $conn->commit();
            $message = "New customer created successfully! The new Customer ID is: " . htmlspecialchars($customer_uid);
            $display_uid = htmlspecialchars($customer_uid);

        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }

        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Entry Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-container { background-color: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); overflow: hidden; }
        .form-header { background: linear-gradient(to right, #4a90e2, #50e3c2); color: white; padding: 24px; text-align: center; }
        .form-header h1 { font-size: 2rem; font-weight: 700; letter-spacing: 1px; }
        .form-input-group { position: relative; }
        .form-input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .form-input, .form-select { width: 100%; padding: 12px 12px 12px 40px; border: 1px solid #d1d5db; border-radius: 8px; transition: all 0.3s ease; appearance: none; background-color: white;}
        .form-select { padding-right: 40px; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e"); background-position: right 0.75rem center; background-repeat: no-repeat; background-size: 1.5em 1.5em; }
        .form-input-file { padding: 8px 12px; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #4a90e2; box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); }
        .form-input[readonly] { background-color: #f3f4f6; cursor: not-allowed; }
        .btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.3s ease; border: none; cursor: pointer; color: white; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); display: inline-flex; align-items: center; justify-content: center; }
        .btn-submit { background-color: #22c55e; } .btn-submit:hover { background-color: #16a34a; transform: translateY(-2px); }
        .btn-add { background-color: #6366f1; padding: 10px 20px; } .btn-add:hover { background-color: #4f46e5; transform: translateY(-2px); }
        .btn-remove { background-color: #f43f5e; color: white; border-radius: 50%; width: 32px; height: 32px; font-size: 14px; } .btn-remove:hover { background-color: #e11d48; }
        #photo-preview-container { width: 150px; height: 150px; border-radius: 50%; border: 4px solid #e5e7eb; overflow: hidden; margin: 1rem auto; background-color: #f9fafb; display: none; }
        #photo-preview { width: 100%; height: 100%; object-fit: cover; }
    </style>
</head>
<body>

    <div class="container mx-auto p-4 md:p-8 max-w-5xl">
        <div class="form-container">
            <div class="form-header"><h1>Customer Entry Form</h1></div>

            <?php if (!empty($message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 m-6 rounded-md" role="alert">
                    <p class="font-bold">Success</p><p><?php echo $message; ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 m-6 rounded-md" role="alert">
                    <p class="font-bold">Error</p><p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post" enctype="multipart/form-data" class="p-6 md:p-8" id="customerForm">
                <h2 class="text-xl font-bold text-gray-800 border-b pb-2 mb-6">Personal Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                    <div>
                        <label for="customer_uid" class="block text-gray-700 font-medium mb-2">Customer Unique ID</label>
                        <div class="form-input-group">
                            <i class="fas fa-barcode form-input-icon"></i>
                            <input type="text" id="customer_uid" name="customer_uid_display" class="form-input" value="<?php echo $display_uid; ?>" readonly placeholder="Generated after submission">
                        </div>
                    </div>

                    <div>
                        <label for="name" class="block text-gray-700 font-medium mb-2">Name <span class="text-red-500 font-bold">*</span></label>
                        <div class="form-input-group"><i class="fas fa-user form-input-icon"></i><input type="text" id="name" name="name" class="form-input" placeholder="Enter full Name" required></div>
                    </div>
                    <div>
                        <label for="mobile_no" class="block text-gray-700 font-medium mb-2">Mobile No <span class="text-red-500 font-bold">*</span></label>
                        <div class="form-input-group">
                            <i class="fas fa-mobile-alt form-input-icon"></i>
                            <input type="tel" id="mobile_no" name="mobile_no" class="form-input" placeholder="Enter 10-digit number" required pattern="[0-9]{10}" maxlength="10" title="Mobile number must be exactly 10 digits.">
                        </div>
                    </div>
                    <div>
                        <label for="father_name" class="block text-gray-700 font-medium mb-2">Father's Name</label>
                         <div class="form-input-group"><i class="fas fa-user-tie form-input-icon"></i><input type="text" id="father_name" name="father_name" class="form-input" placeholder="Enter Father's Name"></div>
                    </div>
                    <div>
                        <label for="email" class="block text-gray-700 font-medium mb-2">Email ID</label>
                         <div class="form-input-group"><i class="fas fa-envelope form-input-icon"></i><input type="email" id="email" name="email" class="form-input" placeholder="Enter your Email ID"></div>
                    </div>

                    <div>
                        <label for="company_id" class="block text-gray-700 font-medium mb-2">Company Name</label>
                        <div class="form-input-group">
                            <i class="fas fa-building form-input-icon"></i>
                            <select id="company_id" name="company_id" class="form-select">
                                <option value="">Select a Company (Optional)</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo htmlspecialchars($company['id']); ?>">
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="employee_id" class="block text-gray-700 font-medium mb-2">Employee ID</label>
                         <div class="form-input-group"><i class="fas fa-id-badge form-input-icon"></i><input type="text" id="employee_id" name="employee_id" class="form-input" placeholder="Enter Employee ID"></div>
                    </div>
                </div>

                <div class="mt-6">
                    <label class="block text-gray-700 font-medium mb-2">Upload Profile Photo (Optional)</label>
                    <div id="photo-preview-container"><img id="photo-preview" src="#" alt="Photo Preview"/></div>
                    <div class="flex items-center justify-center mt-2">
                        <label for="photo" class="btn bg-blue-500 hover:bg-blue-600 cursor-pointer">
                            <i class="fas fa-upload mr-2"></i> Upload File
                        </label>
                        <input id="photo" name="photo" type="file" class="hidden" accept="image/png, image/jpeg, image/jpg">
                    </div>
                    <p class="text-center text-xs text-gray-500 mt-2">PNG, JPG, JPEG up to 400KB</p>
                </div>

                <div class="mt-10 pt-6 border-t">
                     <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800">Customer Documents (Optional)</h2>
                        <button type="button" id="add-document-btn" class="btn btn-add"><i class="fas fa-plus mr-2"></i> Add Document</button>
                    </div>
                    <div id="documents-container" class="space-y-6"></div>
                </div>

                <div class="mt-8 flex justify-end space-x-4 border-t pt-6">
                    <button type="submit" class="btn btn-submit"><i class="fas fa-check mr-2"></i>Submit</button>
                    <button type="button" class="btn bg-red-500 hover:bg-red-600" onclick="window.history.back()"><i class="fas fa-times mr-2"></i>Cancel</button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const addDocumentBtn = document.getElementById('add-document-btn');
    const documentsContainer = document.getElementById('documents-container');
    const photoInput = document.getElementById('photo');
    const photoPreviewContainer = document.getElementById('photo-preview-container');
    const photoPreview = document.getElementById('photo-preview');

    addDocumentBtn.addEventListener('click', function () {
        const docEntry = document.createElement('div');
        docEntry.className = 'document-entry p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-center relative bg-gray-50 border rounded-lg';
        docEntry.innerHTML = `
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Type</label>
                <select name="document_type[]" class="form-input w-full document-type-select" required>
                    <option value="">-- Select --</option>
                    <option value="Aadhaar Card">Aadhaar Card</option>
                    <option value="PAN Card">PAN Card</option>
                    <option value="Voter ID">Voter ID</option>
                    <option value="Driving License">Driving License</option>
                    <option value="Ration Card">Ration Card</option>
                    <option value="Passport">Passport</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Number</label>
                <input type="text" name="document_number[]" class="form-input w-full document-number-input" placeholder="Select type first" required disabled>
            </div>
            <div class="flex items-end h-full">
                <input type="file" name="document_image[]" class="form-input form-input-file w-full" required accept="application/pdf">
                <button type="button" class="btn btn-remove ml-2 flex-shrink-0"><i class="fas fa-trash-alt"></i></button>
            </div>
            <p class="md:col-span-3 text-center text-xs text-gray-500 mt-1">PDF file only, up to 400KB.</p>
        `;
        documentsContainer.appendChild(docEntry);
    });

    documentsContainer.addEventListener('change', function(e) {
        if (e.target.classList.contains('document-type-select')) {
            const selectedType = e.target.value;
            const docEntry = e.target.closest('.document-entry');
            const numberInput = docEntry.querySelector('.document-number-input');

            numberInput.disabled = !selectedType;
            numberInput.value = '';
            numberInput.removeAttribute('pattern');
            numberInput.removeAttribute('maxlength');
            numberInput.title = '';
            numberInput.placeholder = selectedType ? 'Enter number' : 'Select type first';

            if (selectedType) {
                switch (selectedType) {
                    case 'Aadhaar Card':
                        numberInput.pattern = "\\d{12}";
                        numberInput.maxLength = 12;
                        numberInput.title = "Must be 12 digits.";
                        numberInput.placeholder = "Enter 12-digit number";
                        break;
                    case 'PAN Card':
                        numberInput.pattern = "[A-Z]{5}[0-9]{4}[A-Z]{1}";
                        numberInput.maxLength = 10;
                        numberInput.title = "Format: ABCDE1234F";
                        numberInput.placeholder = "Enter 10-character PAN";
                        break;
                }
            }
        }
    });

    documentsContainer.addEventListener('click', e => {
        if (e.target.closest('.btn-remove')) {
            e.target.closest('.document-entry').remove();
        }
    });

    photoInput.addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            if (file.size > 400 * 1024) {
                alert('Error: Profile photo cannot exceed 400KB.');
                event.target.value = '';
                return;
            }
            if (!['image/jpeg', 'image/png', 'image/jpg'].includes(file.type)) {
                 alert('Error: Please select a JPG or PNG image.');
                 event.target.value = '';
                 return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                photoPreviewContainer.style.display = 'block';
            }
            reader.readAsDataURL(file);
        }
    });
});
</script>
</body>
</html>