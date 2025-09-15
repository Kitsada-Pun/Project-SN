<?php
session_start();
header('Content-Type: application/json');

// Include your database connection file
require_once '../connect.php'; 

// Check if user is a logged-in designer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'designer') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

$designer_id = $_SESSION['user_id'];
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (!$request_id || !in_array($action, ['accept', 'reject'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']);
    exit();
}

// Start transaction for data consistency
$conn->begin_transaction();

try {
    // Verify that this designer is the one assigned to the request and it's still 'open'
    $sql_verify = "SELECT client_id, title, budget FROM client_job_requests WHERE request_id = ? AND designer_id = ? AND status = 'open'";
    $stmt_verify = $conn->prepare($sql_verify);
    $stmt_verify->bind_param("ii", $request_id, $designer_id);
    $stmt_verify->execute();
    $result = $stmt_verify->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Offer not found or already actioned.');
    }
    $job_info = $result->fetch_assoc();
    $client_id = $job_info['client_id'];
    $job_title = $job_info['title'];
    $agreed_price = $job_info['budget'];
    $stmt_verify->close();

    if ($action === 'accept') {
        // 1. Update job request status to 'assigned'
        $sql_update_req = "UPDATE client_job_requests SET status = 'assigned' WHERE request_id = ?";
        $stmt_update_req = $conn->prepare($sql_update_req);
        $stmt_update_req->bind_param("i", $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();

        // 2. Create a new contract
        $start_date = date('Y-m-d');
        $sql_insert_contract = "INSERT INTO contracts (request_id, designer_id, client_id, agreed_price, start_date, contract_status, payment_status) VALUES (?, ?, ?, ?, ?, 'active', 'pending')";
        $stmt_insert_contract = $conn->prepare($sql_insert_contract);
        $stmt_insert_contract->bind_param("iiids", $request_id, $designer_id, $client_id, $agreed_price, $start_date);
        $stmt_insert_contract->execute();
        $stmt_insert_contract->close();
        
        $message = "คุณได้ยอมรับข้อเสนองาน '{$job_title}' เรียบร้อยแล้ว";

    } else { // 'reject'
        // Update job request status to 'cancelled' and remove designer assignment
        $sql_update_req = "UPDATE client_job_requests SET status = 'open', designer_id = NULL WHERE request_id = ?";
        $stmt_update_req = $conn->prepare($sql_update_req);
        $stmt_update_req->bind_param("i", $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();

        $message = "คุณได้ปฏิเสธข้อเสนองาน '{$job_title}'";
    }

    // Commit the transaction
    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => $message]);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>