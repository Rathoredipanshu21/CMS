<?php

$message = '';
$error = '';
$display_uid = ''; // Variable to hold the new unique ID for display

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    include '../config/db.php'; // Ensure this path is correct

    $conn->begin_transaction();

    try {
        // --- Insert into `customers` table (without custom UID first) ---
        $stmt_customer = $conn->prepare("INSERT INTO customers (name, father_name, email, mobile_no, company_name, employee_id, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        // Initialize variables from POST data
        $name = $_POST['name'];
        $father_name = $_POST['father_name'];
        $email = $_POST['email'];
        $mobile_no = $_POST['mobile_no'];
        $company_name = $_POST['company_name'];
        $employee_id = $_POST['employee_id'];
        $photo_path = ''; // Will be updated after file move

        // Handle Profile Photo Upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
            $target_dir = "uploads/profiles/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            
            $file_extension = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
            $target_file = $target_dir . uniqid('profile_', true) . '.' . strtolower($file_extension);
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array(strtolower($file_extension), $allowed_types)) {
                if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
                    $photo_path = $target_file;
                } else {
                    throw new Exception("Sorry, there was an error uploading your profile photo.");
                }
            } else {
                throw new Exception("Sorry, only JPG, JPEG, PNG & GIF files are allowed for the profile photo.");
            }
        }

        // Bind parameters and execute the initial insert
        $stmt_customer->bind_param("sssssss", $name, $father_name, $email, $mobile_no, $company_name, $employee_id, $photo_path);
        if (!$stmt_customer->execute()) {
            throw new Exception("Error creating customer record: " . $stmt_customer->error);
        }

        // Get the ID of the newly created customer
        $customer_id = $conn->insert_id;
        $stmt_customer->close();

        // --- Generate and Update Unique Customer ID (DBCECMSXXXX) ---
        $customer_uid = 'DBCECMS' . str_pad($customer_id, 4, '0', STR_PAD_LEFT);
        $stmt_uid = $conn->prepare("UPDATE customers SET customer_uid = ? WHERE id = ?");
        $stmt_uid->bind_param("si", $customer_uid, $customer_id);
        if (!$stmt_uid->execute()) {
            throw new Exception("Error generating unique customer ID: " . $stmt_uid->error);
        }
        $stmt_uid->close();

        // --- Handle Multiple Document Uploads ---
        if (isset($_POST['document_type']) && is_array($_POST['document_type'])) {
            $doc_files = $_FILES['document_image'];
            $doc_types = $_POST['document_type'];
            $doc_numbers = $_POST['document_number'];
            $doc_target_dir = "uploads/documents/";
            if (!is_dir($doc_target_dir)) mkdir($doc_target_dir, 0755, true);

            foreach ($doc_types as $key => $type) {
                if (!empty($type)) {
                    $number = $doc_numbers[$key];
                    if (empty($number) || !isset($doc_files['name'][$key]) || $doc_files['error'][$key] !== UPLOAD_ERR_OK) {
                        throw new Exception("For each selected document type, you must provide a document number and upload an image file.");
                    }

                    $file_name = $doc_files['name'][$key];
                    $file_tmp = $doc_files['tmp_name'][$key];
                    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                    $doc_target_file = $doc_target_dir . uniqid('doc_' . $customer_id . '_', true) . '.' . strtolower($file_ext);
                    
                    if (!in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'pdf'])) {
                         throw new Exception("Sorry, only JPG, JPEG, PNG & PDF files are allowed for documents.");
                    }

                    if (!move_uploaded_file($file_tmp, $doc_target_file)) {
                        throw new Exception("Failed to upload document file for " . htmlspecialchars($type));
                    }

                    $stmt_doc = $conn->prepare("INSERT INTO customer_documents (customer_id, document_type, document_number, document_image_path) VALUES (?, ?, ?, ?)");
                    $stmt_doc->bind_param("isss", $customer_id, $type, $number, $doc_target_file);
                    
                    if (!$stmt_doc->execute()) {
                        throw new Exception("Error saving document record: " . $stmt_doc->error);
                    }
                    $stmt_doc->close();
                }
            }
        }

        $conn->commit();
        $message = "New customer created successfully!";
        $display_uid = htmlspecialchars($customer_uid); // Set the ID for display in the form

    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }

    $conn->close();
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .form-container { background-color: white; border-radius: 12px; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); overflow: hidden; }
        .form-header { background: linear-gradient(to right, #4a90e2, #50e3c2); color: white; padding: 24px; text-align: center; }
        .form-header h1 { font-size: 2rem; font-weight: 700; letter-spacing: 1px; }
        .form-input-group { position: relative; }
        .form-input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .form-input { width: 100%; padding: 12px 12px 12px 40px; border: 1px solid #d1d5db; border-radius: 8px; transition: all 0.3s ease; }
        .form-input-file { padding: 8px 12px; }
        .form-input:focus { outline: none; border-color: #4a90e2; box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2); }
        .form-input[readonly] { background-color: #f3f4f6; cursor: not-allowed; }
        .required-star { color: #ef4444; font-weight: bold; }
        .btn { padding: 12px 24px; border-radius: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.3s ease; border: none; cursor: pointer; color: white; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); display: inline-flex; align-items: center; justify-content: center; }
        .btn-submit { background-color: #22c55e; } .btn-submit:hover { background-color: #16a34a; transform: translateY(-2px); }
        .btn-clear { background-color: #3b82f6; } .btn-clear:hover { background-color: #2563eb; transform: translateY(-2px); }
        .btn-cancel { background-color: #ef4444; } .btn-cancel:hover { background-color: #dc2626; transform: translateY(-2px); }
        .btn-add { background-color: #6366f1; padding: 10px 20px; } .btn-add:hover { background-color: #4f46e5; transform: translateY(-2px); }
        .btn-remove { background-color: #f43f5e; color: white; border-radius: 50%; width: 32px; height: 32px; font-size: 14px; } .btn-remove:hover { background-color: #e11d48; }
        .document-entry { background-color: #fafafa; border: 1px solid #e5e7eb; border-radius: 8px; }
        #photo-preview-container { width: 150px; height: 150px; border-radius: 50%; border: 4px solid #e5e7eb; overflow: hidden; margin: 1rem auto; background-color: #f9fafb; display: none; }
        #photo-preview { width: 100%; height: 100%; object-fit: cover; }
        #camera-modal { display: none; }
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
                            <input type="text" id="customer_uid" name="customer_uid" class="form-input" value="<?php echo $display_uid; ?>" readonly placeholder="Generated after submission">
                        </div>
                    </div>
                    
                    <div>
                        <label for="name" class="block text-gray-700 font-medium mb-2">Name <span class="required-star">*</span></label>
                        <div class="form-input-group"><i class="fas fa-user form-input-icon"></i><input type="text" id="name" name="name" class="form-input" placeholder="Enter full Name" required></div>
                    </div>
                    <div>
                        <label for="mobile_no" class="block text-gray-700 font-medium mb-2">Mobile No <span class="required-star">*</span></label>
                        <div class="form-input-group"><i class="fas fa-mobile-alt form-input-icon"></i><input type="tel" id="mobile_no" name="mobile_no" class="form-input" placeholder="Enter contact number" required></div>
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
                        <label for="company_name" class="block text-gray-700 font-medium mb-2">Company Name</label>
                         <div class="form-input-group"><i class="fas fa-building form-input-icon"></i><input type="text" id="company_name" name="company_name" class="form-input" placeholder="Enter Company Name"></div>
                    </div>
                     <div>
                        <label for="employee_id" class="block text-gray-700 font-medium mb-2">Employee ID</label>
                         <div class="form-input-group"><i class="fas fa-id-badge form-input-icon"></i><input type="text" id="employee_id" name="employee_id" class="form-input" placeholder="Enter Employee ID"></div>
                    </div>
                </div>

                <div class="mt-6">
                    <label class="block text-gray-700 font-medium mb-2">Upload Profile Photo (Optional)</label>
                    <div id="photo-preview-container"><img id="photo-preview" src="#" alt="Photo Preview"/></div>
                    <div class="flex items-center justify-center space-x-4 mt-2">
                        <label for="photo" class="btn btn-clear cursor-pointer">
                            <i class="fas fa-upload mr-2"></i> Upload File
                        </label>
                        <input id="photo" name="photo" type="file" class="hidden" accept="image/png, image/jpeg, image/gif">
                        <button type="button" id="start-camera-btn" class="btn btn-add">
                            <i class="fas fa-camera mr-2"></i> Use Camera
                        </button>
                    </div>
                    <p class="text-center text-xs text-gray-500 mt-2">PNG, JPG, GIF up to 10MB</p>
                </div>

                <div class="mt-10 pt-6 border-t">
                     <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800">Customer Documents (Optional)</h2>
                        <button type="button" id="add-document-btn" class="btn btn-add"><i class="fas fa-plus mr-2"></i> Add Document</button>
                    </div>
                    <div id="documents-container" class="space-y-6"></div>
                </div>

                <div class="mt-8 border-t pt-6">
                    <p class="text-sm text-gray-600">I hereby declare that the information given above is true to the best of my knowledge.</p>
                </div>
                
                <div class="mt-8 flex justify-end space-x-4">
                    <button type="submit" class="btn btn-submit"><i class="fas fa-check mr-2"></i>Submit</button>
                    <button type="reset" class="btn btn-clear"><i class="fas fa-undo mr-2"></i>Clear Form</button>
                    <button type="button" class="btn btn-cancel" onclick="window.history.back()"><i class="fas fa-times mr-2"></i>Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="camera-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-2xl text-center">
            <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Live Camera Feed</h3>
            <video id="camera-feed" class="w-full rounded-md border" autoplay playsinline></video>
            <canvas id="camera-canvas" class="hidden"></canvas>
            <div class="mt-4 flex justify-center space-x-4">
                <button type="button" id="capture-btn" class="btn btn-submit"><i class="fas fa-camera-retro mr-2"></i>Capture Photo</button>
                <button type="button" id="close-camera-btn" class="btn btn-cancel"><i class="fas fa-times mr-2"></i>Close</button>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Document Management
    const addDocumentBtn = document.getElementById('add-document-btn');
    const documentsContainer = document.getElementById('documents-container');

    addDocumentBtn.addEventListener('click', function () {
        const docEntry = document.createElement('div');
        docEntry.className = 'document-entry p-4 grid grid-cols-1 md:grid-cols-3 gap-4 items-center relative';
        docEntry.innerHTML = `
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Type</label>
                <select name="document_type[]" class="form-input w-full" required>
                    <option value="">-- Select --</option>
                    <option value="Aadhaar Card">Aadhaar Card</option><option value="Voter ID">Voter ID</option>
                    <option value="Driving License">Driving License</option><option value="PAN Card">PAN Card</option>
                    <option value="Ration Card">Ration Card</option><option value="Passport">Passport</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Document Number</label>
                <input type="text" name="document_number[]" class="form-input w-full" placeholder="Enter number" required>
            </div>
            <div class="flex items-end h-full">
                <input type="file" name="document_image[]" class="form-input form-input-file w-full" required>
                <button type="button" class="btn btn-remove ml-2 flex-shrink-0"><i class="fas fa-trash-alt"></i></button>
            </div>
        `;
        documentsContainer.appendChild(docEntry);
    });

    documentsContainer.addEventListener('click', e => {
        if (e.target.closest('.btn-remove')) {
            e.target.closest('.document-entry').remove();
        }
    });
    
    // Form Reset Logic
    document.getElementById('customerForm').addEventListener('reset', function() {
        documentsContainer.innerHTML = '';
        photoPreviewContainer.style.display = 'none';
        photoPreview.src = '#';
        // Do not clear the unique ID field on reset, as it might be useful
        // document.getElementById('customer_uid').value = ''; 
        if (window.cameraStream) {
            window.cameraStream.getTracks().forEach(track => track.stop());
        }
    });

    // --- Profile Photo & Camera Logic ---
    const photoInput = document.getElementById('photo');
    const photoPreviewContainer = document.getElementById('photo-preview-container');
    const photoPreview = document.getElementById('photo-preview');
    const startCameraBtn = document.getElementById('start-camera-btn');
    const cameraModal = document.getElementById('camera-modal');
    const cameraFeed = document.getElementById('camera-feed');
    const cameraCanvas = document.getElementById('camera-canvas');
    const captureBtn = document.getElementById('capture-btn');
    const closeCameraBtn = document.getElementById('close-camera-btn');

    // Show preview for uploaded file
    photoInput.addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                photoPreview.src = e.target.result;
                photoPreviewContainer.style.display = 'block';
            }
            reader.readAsDataURL(file);
        }
    });

    // Open camera modal
    startCameraBtn.addEventListener('click', async () => {
        cameraModal.style.display = 'flex';
        try {
            window.cameraStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            cameraFeed.srcObject = window.cameraStream;
        } catch (err) {
            console.error("Error accessing camera:", err);
            alert("Could not access the camera. Please check permissions and ensure your device has a camera.");
            cameraModal.style.display = 'none';
        }
    });

    // Close camera modal and stop stream
    const closeCamera = () => {
        cameraModal.style.display = 'none';
        if (window.cameraStream) {
            window.cameraStream.getTracks().forEach(track => track.stop());
        }
    };
    closeCameraBtn.addEventListener('click', closeCamera);

    // Capture photo from camera
    captureBtn.addEventListener('click', () => {
        cameraCanvas.width = cameraFeed.videoWidth;
        cameraCanvas.height = cameraFeed.videoHeight;
        const context = cameraCanvas.getContext('2d');
        context.drawImage(cameraFeed, 0, 0, cameraCanvas.width, cameraCanvas.height);

        // Show preview
        photoPreview.src = cameraCanvas.toDataURL('image/jpeg');
        photoPreviewContainer.style.display = 'block';

        // Convert canvas to blob and attach to file input
        cameraCanvas.toBlob(function(blob) {
            const file = new File([blob], "camera-photo.jpg", { type: "image/jpeg" });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            photoInput.files = dataTransfer.files;
        }, 'image/jpeg');

        closeCamera();
    });
});
</script>
</body>
</html>