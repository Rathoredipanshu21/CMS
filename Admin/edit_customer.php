<?php

include '../config/db.php';

$message = '';
$error = '';
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// --- FORM SUBMISSION (UPDATE) LOGIC ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && $customer_id > 0) {
    $conn->begin_transaction();
    try {
        // --- 1. UPDATE MAIN CUSTOMER DETAILS ---
        $name = $_POST['name'];
        $father_name = $_POST['father_name'];
        $email = $_POST['email'];
        $mobile_no = $_POST['mobile_no'];
        $company_name = $_POST['company_name'];
        $employee_id = $_POST['employee_id'];
        
        $stmt = $conn->prepare("UPDATE customers SET name=?, father_name=?, email=?, mobile_no=?, company_name=?, employee_id=? WHERE id=?");
        $stmt->bind_param("ssssssi", $name, $father_name, $email, $mobile_no, $company_name, $employee_id, $customer_id);
        $stmt->execute();
        $stmt->close();

        // --- 2. HANDLE PROFILE PHOTO UPDATE ---
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            // First, delete the old photo if it exists
            $stmt = $conn->prepare("SELECT photo_path FROM customers WHERE id = ?");
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            if ($result && !empty($result['photo_path']) && file_exists($result['photo_path'])) {
                unlink($result['photo_path']);
            }
            $stmt->close();

            // Then, upload the new one
            $target_dir = "uploads/profiles/";
            $file_extension = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
            $new_photo_path = $target_dir . uniqid('profile_', true) . '.' . $file_extension;
            if (move_uploaded_file($_FILES["photo"]["tmp_name"], $new_photo_path)) {
                $stmt = $conn->prepare("UPDATE customers SET photo_path = ? WHERE id = ?");
                $stmt->bind_param("si", $new_photo_path, $customer_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Failed to upload new profile photo.");
            }
        }

        // --- 3. HANDLE DOCUMENTS (UPDATE, ADD, DELETE) ---
        $doc_ids = isset($_POST['document_id']) ? $_POST['document_id'] : [];
        $doc_types = isset($_POST['document_type']) ? $_POST['document_type'] : [];
        $doc_numbers = isset($_POST['document_number']) ? $_POST['document_number'] : [];
        $doc_files = isset($_FILES['document_image']) ? $_FILES['document_image'] : [];

        // Get a list of all current documents from DB to compare against
        $current_docs_in_db = [];
        $result = $conn->query("SELECT id FROM customer_documents WHERE customer_id = $customer_id");
        while($row = $result->fetch_assoc()) {
            $current_docs_in_db[] = $row['id'];
        }

        // Documents to keep (these are the IDs submitted with the form)
        $docs_to_keep = [];

        foreach ($doc_types as $key => $type) {
            if (empty($type)) continue; // Skip empty rows added by user

            $doc_id = isset($doc_ids[$key]) ? intval($doc_ids[$key]) : 0;
            $doc_number = $doc_numbers[$key];

            if ($doc_id > 0) { // This is an EXISTING document
                $docs_to_keep[] = $doc_id;
                // Update text fields
                $stmt = $conn->prepare("UPDATE customer_documents SET document_type=?, document_number=? WHERE id=?");
                $stmt->bind_param("ssi", $type, $doc_number, $doc_id);
                $stmt->execute();
                $stmt->close();

                // Check if a new file was uploaded for this existing document
                if (isset($doc_files['name'][$key]) && $doc_files['error'][$key] == 0) {
                    // Delete old file
                    $stmt_old_file = $conn->prepare("SELECT document_image_path FROM customer_documents WHERE id = ?");
                    $stmt_old_file->bind_param("i", $doc_id);
                    $stmt_old_file->execute();
                    $res_old_file = $stmt_old_file->get_result()->fetch_assoc();
                    if ($res_old_file && !empty($res_old_file['document_image_path']) && file_exists($res_old_file['document_image_path'])) {
                        unlink($res_old_file['document_image_path']);
                    }
                    $stmt_old_file->close();
                    
                    // Upload new file
                    $doc_target_dir = "uploads/documents/";
                    $file_ext = pathinfo($doc_files['name'][$key], PATHINFO_EXTENSION);
                    $new_doc_path = $doc_target_dir . uniqid('doc_' . $customer_id . '_', true) . '.' . $file_ext;
                    if (move_uploaded_file($doc_files['tmp_name'][$key], $new_doc_path)) {
                        $stmt_update_path = $conn->prepare("UPDATE customer_documents SET document_image_path=? WHERE id=?");
                        $stmt_update_path->bind_param("si", $new_doc_path, $doc_id);
                        $stmt_update_path->execute();
                        $stmt_update_path->close();
                    }
                }
            } else { // This is a NEW document
                if (isset($doc_files['name'][$key]) && $doc_files['error'][$key] == 0) {
                    $doc_target_dir = "uploads/documents/";
                    $file_ext = pathinfo($doc_files['name'][$key], PATHINFO_EXTENSION);
                    $new_doc_path = $doc_target_dir . uniqid('doc_' . $customer_id . '_', true) . '.' . $file_ext;
                    if (move_uploaded_file($doc_files['tmp_name'][$key], $new_doc_path)) {
                        $stmt = $conn->prepare("INSERT INTO customer_documents (customer_id, document_type, document_number, document_image_path) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("isss", $customer_id, $type, $doc_number, $new_doc_path);
                        $stmt->execute();
                        $stmt->close();
                    }
                } else {
                    throw new Exception("A new document row was added but no file was uploaded.");
                }
            }
        }

        // --- 4. DELETE REMOVED DOCUMENTS ---
        $docs_to_delete = array_diff($current_docs_in_db, $docs_to_keep);
        if (!empty($docs_to_delete)) {
            foreach ($docs_to_delete as $delete_id) {
                // Get file path to delete
                $stmt = $conn->prepare("SELECT document_image_path FROM customer_documents WHERE id = ?");
                $stmt->bind_param("i", $delete_id);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                if ($res && !empty($res['document_image_path']) && file_exists($res['document_image_path'])) {
                    unlink($res['document_image_path']);
                }
                $stmt->close();

                // Delete DB record
                $stmt_del = $conn->prepare("DELETE FROM customer_documents WHERE id = ?");
                $stmt_del->bind_param("i", $delete_id);
                $stmt_del->execute();
                $stmt_del->close();
            }
        }

        $conn->commit();
        $message = "Customer details updated successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Update failed: " . $e->getMessage();
    }
}

// --- INITIAL PAGE LOAD (FETCH DATA) ---
if ($customer_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        die("Customer not found.");
    }

    $documents = [];
    $stmt = $conn->prepare("SELECT * FROM customer_documents WHERE customer_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result_docs = $stmt->get_result();
    while ($doc = $result_docs->fetch_assoc()) {
        $documents[] = $doc;
    }
    $stmt->close();
} else {
    die("No customer ID provided.");
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-container { background-color: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); }
        .form-header { background: linear-gradient(to right, #4f46e5, #6366f1); color: white; padding: 24px; }
        .form-input { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; }
        .btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; text-transform: uppercase; transition: all 0.3s ease; }
        .btn-submit { background-color: #16a34a; color: white; }
        .btn-submit:hover { background-color: #15803d; }
        .btn-cancel { background-color: #6b7280; color: white; }
        .btn-cancel:hover { background-color: #4b5563; }
        .btn-remove { background-color: #ef4444; color: white; border-radius: 50%; width: 32px; height: 32px; }
        .document-entry { background-color: #fafafa; border: 1px solid #e5e7eb; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="container mx-auto p-4 md:p-8 max-w-5xl">
        <div class="form-container">
            <div class="form-header">
                <h1 class="text-2xl font-bold">Edit Customer: <?php echo htmlspecialchars($customer['name']); ?></h1>
            </div>

            <?php if ($message): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 m-6 rounded-md" role="alert">
                <p><?php echo $message; ?></p>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 m-6 rounded-md" role="alert">
                <p><?php echo $error; ?></p>
            </div>
            <?php endif; ?>

            <form action="edit_customer.php?id=<?php echo $customer_id; ?>" method="post" enctype="multipart/form-data" class="p-6 md:p-8">
                <h2 class="text-xl font-bold text-gray-800 border-b pb-2 mb-6">Personal Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="name" class="block font-medium mb-1">Name</label>
                        <input type="text" id="name" name="name" class="form-input" value="<?php echo htmlspecialchars($customer['name']); ?>" required>
                    </div>
                    <div>
                        <label for="father_name" class="block font-medium mb-1">Father's Name</label>
                        <input type="text" id="father_name" name="father_name" class="form-input" value="<?php echo htmlspecialchars($customer['father_name']); ?>">
                    </div>
                    <div>
                        <label for="email" class="block font-medium mb-1">Email</label>
                        <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($customer['email']); ?>">
                    </div>
                    <div>
                        <label for="mobile_no" class="block font-medium mb-1">Mobile No</label>
                        <input type="tel" id="mobile_no" name="mobile_no" class="form-input" value="<?php echo htmlspecialchars($customer['mobile_no']); ?>" required>
                    </div>
                    <div>
                        <label for="company_name" class="block font-medium mb-1">Company Name</label>
                        <input type="text" id="company_name" name="company_name" class="form-input" value="<?php echo htmlspecialchars($customer['company_name']); ?>">
                    </div>
                    <div>
                        <label for="employee_id" class="block font-medium mb-1">Employee ID</label>
                        <input type="text" id="employee_id" name="employee_id" class="form-input" value="<?php echo htmlspecialchars($customer['employee_id']); ?>">
                    </div>
                </div>

                <div class="mt-6">
                    <label class="block font-medium mb-1">Profile Photo</label>
                    <div class="flex items-center gap-4">
                        <?php if (!empty($customer['photo_path']) && file_exists($customer['photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($customer['photo_path']); ?>" class="h-20 w-20 rounded-full object-cover">
                        <?php endif; ?>
                        <input type="file" name="photo" class="form-input">
                    </div>
                    <p class="text-sm text-gray-500 mt-1">Upload a new photo to replace the existing one.</p>
                </div>

                <div class="mt-10 pt-6 border-t">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800">Customer Documents</h2>
                        <button type="button" id="add-document-btn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-plus mr-2"></i> Add Document
                        </button>
                    </div>
                    <div id="documents-container" class="space-y-6">
                        <?php foreach($documents as $doc): ?>
                        <div class="document-entry p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end relative">
                            <input type="hidden" name="document_id[]" value="<?php echo $doc['id']; ?>">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Document Type</label>
                                <select name="document_type[]" class="form-input w-full" required>
                                    <option value="">-- Select --</option>
                                    <option value="Aadhaar Card" <?php echo $doc['document_type'] == 'Aadhaar Card' ? 'selected' : ''; ?>>Aadhaar Card</option>
                                    <option value="Voter ID" <?php echo $doc['document_type'] == 'Voter ID' ? 'selected' : ''; ?>>Voter ID</option>
                                    <option value="Driving License" <?php echo $doc['document_type'] == 'Driving License' ? 'selected' : ''; ?>>Driving License</option>
                                    <option value="PAN Card" <?php echo $doc['document_type'] == 'PAN Card' ? 'selected' : ''; ?>>PAN Card</option>
                                    <option value="Ration Card" <?php echo $doc['document_type'] == 'Ration Card' ? 'selected' : ''; ?>>Ration Card</option>
                                    <option value="Passport" <?php echo $doc['document_type'] == 'Passport' ? 'selected' : ''; ?>>Passport</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Document Number</label>
                                <input type="text" name="document_number[]" class="form-input w-full" value="<?php echo htmlspecialchars($doc['document_number']); ?>" required>
                            </div>
                            <div class="flex items-end h-full">
                                <div class="w-full">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload New Image</label>
                                    <input type="file" name="document_image[]" class="form-input w-full">
                                    <?php if (!empty($doc['document_image_path']) && file_exists($doc['document_image_path'])): ?>
                                        <a href="<?php echo htmlspecialchars($doc['document_image_path']); ?>" target="_blank" class="text-sm text-blue-600 hover:underline">View current image</a>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn-remove ml-2 flex-shrink-0"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mt-8 flex justify-end space-x-4">
                    <a href="view_customers.php" class="btn btn-cancel">Cancel</a>
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-save mr-2"></i>Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const addDocumentBtn = document.getElementById('add-document-btn');
    const documentsContainer = document.getElementById('documents-container');

    addDocumentBtn.addEventListener('click', function () {
        const documentEntry = document.createElement('div');
        documentEntry.className = 'document-entry p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-end relative';
        documentEntry.innerHTML = `
            <input type="hidden" name="document_id[]" value="0"> <!-- New document has ID 0 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Type</label>
                <select name="document_type[]" class="form-input w-full" required>
                    <option value="">-- Select --</option>
                    <option value="Aadhaar Card">Aadhaar Card</option>
                    <option value="Voter ID">Voter ID</option>
                    <option value="Driving License">Driving License</option>
                    <option value="PAN Card">PAN Card</option>
                    <option value="Ration Card">Ration Card</option>
                    <option value="Passport">Passport</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Number</label>
                <input type="text" name="document_number[]" class="form-input w-full" placeholder="Enter number" required>
            </div>
            <div class="flex items-end h-full">
                 <div class="w-full">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upload Image</label>
                    <input type="file" name="document_image[]" class="form-input w-full" required>
                </div>
                <button type="button" class="btn-remove ml-2 flex-shrink-0"><i class="fas fa-trash-alt"></i></button>
            </div>
        `;
        documentsContainer.appendChild(documentEntry);
    });

    documentsContainer.addEventListener('click', function (e) {
        if (e.target.closest('.btn-remove')) {
            e.target.closest('.document-entry').remove();
        }
    });
});
</script>
</body>
</html>
