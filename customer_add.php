<?php
// --- FORM SUBMISSION LOGIC ---

$message = '';
$error = '';

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Include the database connection file
    include 'config/db.php';

    // --- Get data from form ---
    $name = $_POST['name'];
    $father_name = $_POST['father_name'];
    $email = $_POST['email'];
    $mobile_no = $_POST['mobile_no'];
    $document_type = $_POST['document_type'];
    $document_number = $_POST['document_number'];
    $company_name = $_POST['company_name'];
    $employee_id = $_POST['employee_id'];
    $photo_path = '';

    // --- Handle File Upload ---
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $target_dir = "uploads/";
        // Create a unique filename to avoid overwriting
        $file_extension = pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION);
        $target_file = $target_dir . uniqid('photo_', true) . '.' . $file_extension;
        
        // Allowed file types
        $allowed_types = array('jpg', 'jpeg', 'png', 'gif');
        if (in_array(strtolower($file_extension), $allowed_types)) {
            if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
                $photo_path = $target_file;
            } else {
                $error = "Sorry, there was an error uploading your file.";
            }
        } else {
            $error = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
        }
    }

    // --- Insert into Database if no upload error ---
    if (empty($error)) {
        // Using prepared statements to prevent SQL injection
        $stmt = $conn->prepare("INSERT INTO customers (name, father_name, email, mobile_no, document_type, document_number, company_name, employee_id, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $name, $father_name, $email, $mobile_no, $document_type, $document_number, $company_name, $employee_id, $photo_path);

        if ($stmt->execute()) {
            $message = "New customer record created successfully!";
        } else {
            $error = "Error: " . $stmt->error;
        }

        $stmt->close();
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
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Custom Styles */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f0f2f5;
        }
        .form-container {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(to right, #4a90e2, #50e3c2);
            color: white;
            padding: 24px;
            text-align: center;
        }
        .form-header h1 {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .form-input-group {
            position: relative;
        }
        .form-input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        .form-input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .form-input:focus {
            outline: none;
            border-color: #4a90e2;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.2);
        }
        .required-star {
            color: #ef4444;
            font-weight: bold;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            color: white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        .btn-submit { background-color: #22c55e; }
        .btn-submit:hover { background-color: #16a34a; transform: translateY(-2px); }
        .btn-clear { background-color: #3b82f6; }
        .btn-clear:hover { background-color: #2563eb; transform: translateY(-2px); }
        .btn-cancel { background-color: #ef4444; }
        .btn-cancel:hover { background-color: #dc2626; transform: translateY(-2px); }
    </style>
</head>
<body>

    <div class="container mx-auto p-4 md:p-8 max-w-5xl">
        
        <div class="form-container">
            <div class="form-header">
                <h1>Customer Entry Form</h1>
            </div>

            <!-- Success/Error Messages -->
            <?php if (!empty($message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 m-6 rounded-md" role="alert">
                    <p class="font-bold">Success</p>
                    <p><?php echo $message; ?></p>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 m-6 rounded-md" role="alert">
                    <p class="font-bold">Error</p>
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" method="post" enctype="multipart/form-data" class="p-6 md:p-8" id="customerForm">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Left Column -->
                    <div>
                        <div class="mb-4">
                            <label for="name" class="block text-gray-700 font-medium mb-2">Name <span class="required-star">*</span></label>
                            <div class="form-input-group">
                                <i class="fas fa-user form-input-icon"></i>
                                <input type="text" id="name" name="name" class="form-input" placeholder="Enter your Name" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="father_name" class="block text-gray-700 font-medium mb-2">Father's Name</label>
                             <div class="form-input-group">
                                <i class="fas fa-user-tie form-input-icon"></i>
                                <input type="text" id="father_name" name="father_name" class="form-input" placeholder="Enter Father's Name">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="email" class="block text-gray-700 font-medium mb-2">Email ID</label>
                             <div class="form-input-group">
                                <i class="fas fa-envelope form-input-icon"></i>
                                <input type="email" id="email" name="email" class="form-input" placeholder="Enter your Email ID">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="document_type" class="block text-gray-700 font-medium mb-2">Select Document</label>
                            <div class="form-input-group">
                                <i class="fas fa-id-card form-input-icon"></i>
                                <select id="document_type" name="document_type" class="form-input">
                                    <option value="">-- Select a Document --</option>
                                    <option value="Aadhaar Card">Aadhaar Card</option>
                                    <option value="Voter ID">Voter ID</option>
                                    <option value="Driving License">Driving License</option>
                                    <option value="PAN Card">PAN Card</option>
                                    <option value="Ration Card">Ration Card</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="document_number" class="block text-gray-700 font-medium mb-2">Document Number</label>
                             <div class="form-input-group">
                                <i class="fas fa-hashtag form-input-icon"></i>
                                <input type="text" id="document_number" name="document_number" class="form-input" placeholder="Enter selected document number">
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <div class="mb-4">
                            <label for="mobile_no" class="block text-gray-700 font-medium mb-2">Mobile No <span class="required-star">*</span></label>
                             <div class="form-input-group">
                                <i class="fas fa-mobile-alt form-input-icon"></i>
                                <input type="tel" id="mobile_no" name="mobile_no" class="form-input" placeholder="Enter contact number" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="company_name" class="block text-gray-700 font-medium mb-2">Company Name</label>
                             <div class="form-input-group">
                                <i class="fas fa-building form-input-icon"></i>
                                <input type="text" id="company_name" name="company_name" class="form-input" placeholder="Enter Company Name">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="employee_id" class="block text-gray-700 font-medium mb-2">Employee ID</label>
                             <div class="form-input-group">
                                <i class="fas fa-id-badge form-input-icon"></i>
                                <input type="text" id="employee_id" name="employee_id" class="form-input" placeholder="Enter Employee ID">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="photo" class="block text-gray-700 font-medium mb-2">Upload Photo</label>
                            <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                <div class="space-y-1 text-center">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400"></i>
                                    <div class="flex text-sm text-gray-600">
                                        <label for="photo" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                            <span>Upload a file</span>
                                            <input id="photo" name="photo" type="file" class="sr-only">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">PNG, JPG, GIF up to 10MB</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Declaration -->
                <div class="mt-8 border-t pt-6">
                    <p class="text-sm text-gray-600">
                        I hereby declare that the information given above and in the enclosed documents is true to the best of my knowledge and belief and nothing has been concealed therein. I understand that if the information given by me is proved false/not true, I will have to face the punishment as per the law.
                    </p>
                </div>
                
                <!-- Action Buttons -->
                <div class="mt-8 flex justify-end space-x-4">
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-check mr-2"></i>Submit
                    </button>
                    <button type="reset" class="btn btn-clear">
                         <i class="fas fa-undo mr-2"></i>Clear
                    </button>
                    <button type="button" class="btn btn-cancel" onclick="window.history.back()">
                         <i class="fas fa-times mr-2"></i>Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
<script>
    // Simple script to clear the form
    document.getElementById('customerForm').addEventListener('reset', function() {
        // Optional: Add any extra clearing logic here if needed
        console.log('Form cleared!');
    });
</script>
</body>
</html>
