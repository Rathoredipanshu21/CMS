<?php
// C:\xampp\htdocs\Cash\Admin\ajax_update_permissions.php
include '../config/db.php';

header('Content-Type: application/json');

$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $page_file = isset($_POST['page_file']) ? $_POST['page_file'] : '';
    $has_permission = isset($_POST['has_permission']) ? (int)$_POST['has_permission'] : 0;

    if ($branch_id > 0 && !empty($page_file)) {
        if ($has_permission === 1) {
            // Add permission
            $stmt = $conn->prepare("INSERT INTO branch_page_permissions (branch_id, page_file) VALUES (?, ?) ON DUPLICATE KEY UPDATE page_file=page_file");
            $stmt->bind_param("is", $branch_id, $page_file);
            if ($stmt->execute()) {
                $response['success'] = true;
            }
            $stmt->close();
        } else {
            // Remove permission
            $stmt = $conn->prepare("DELETE FROM branch_page_permissions WHERE branch_id = ? AND page_file = ?");
            $stmt->bind_param("is", $branch_id, $page_file);
            if ($stmt->execute()) {
                $response['success'] = true;
            }
            $stmt->close();
        }
    }
}

$conn->close();
echo json_encode($response);
?>
