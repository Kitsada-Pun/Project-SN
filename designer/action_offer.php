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

if (!$request_id || !in_array($action, ['accept', 'reject', 'confirm_payment'])) { // <-- เพิ่ม 'confirm_payment'
    echo json_encode(['status' => 'error', 'message' => 'Invalid data provided.']);
    exit();
}

// Start transaction for data consistency
$conn->begin_transaction();

try {
    // Verify that this designer is the one assigned to the request and it's still 'open'
    // ทำให้รองรับได้ทั้งตอนปฏิเสธงาน (open) และตอนยืนยันการจ่ายเงิน (awaiting_confirmation)
    $sql_verify = "SELECT client_id, title, budget, status FROM client_job_requests WHERE request_id = ? AND designer_id = ?";
    $stmt_verify = $conn->prepare($sql_verify);
    $stmt_verify->bind_param("ii", $request_id, $designer_id);
    $stmt_verify->execute();
    $result = $stmt_verify->get_result();


    if ($result->num_rows === 0) {
        throw new Exception('ไม่พบข้อเสนอนี้ หรือคุณไม่มีสิทธิ์จัดการ');
    }
    $job_info = $result->fetch_assoc();
    $client_id = $job_info['client_id'];
    $job_title = $job_info['title'];
    $agreed_price = $job_info['budget'];
    $current_status = $job_info['status']; // <-- ดึงสถานะปัจจุบันมาเก็บไว้
    $stmt_verify->close();

    if ($action === 'accept') {
        // 1. Update job request status to 'assigned'
        $sql_update_req = "UPDATE client_job_requests SET status = 'assigned' WHERE request_id = ?";
        $stmt_update_req = $conn->prepare($sql_update_req);
        $stmt_update_req->bind_param("i", $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();
    } elseif ($action === 'confirm_payment') { // <-- ACTION ใหม่
        if ($current_status !== 'awaiting_confirmation') {
            throw new Exception('ไม่สามารถยืนยันงานที่ไม่ได้อยู่ในสถานะรอการชำระเงินได้');
        }
        // อัปเดตสถานะเป็น 'assigned' (กำลังดำเนินการ)
        $sql_update_req = "UPDATE client_job_requests SET status = 'assigned' WHERE request_id = ?";
        $stmt_update_req = $conn->prepare($sql_update_req);
        $stmt_update_req->bind_param("i", $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();

        // (อาจจะสร้าง Contract ตรงนี้แทนก็ได้ ถ้าต้องการ)

        $message = "คุณได้ยืนยันการชำระเงินและเริ่มงาน '{$job_title}' เรียบร้อยแล้ว";
    } else { // 'reject'
        if ($current_status !== 'open') {
            throw new Exception('ไม่สามารถปฏิเสธงานที่ตอบรับไปแล้วได้');
        }

        // [แก้ไข] เปลี่ยน status เป็น 'rejected'
        $sql_update_req = "UPDATE client_job_requests SET status = 'rejected' WHERE request_id = ? AND designer_id = ?";
        $stmt_update_req = $conn->prepare($sql_update_req);
        $stmt_update_req->bind_param("ii", $request_id, $designer_id);
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
