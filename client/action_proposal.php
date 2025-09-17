<?php
session_start();
header('Content-Type: application/json');

require_once '../connect.php';

// ตรวจสอบว่าผู้ใช้ล็อกอินในฐานะ client หรือไม่
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit();
}

$client_id = $_SESSION['user_id'];
$application_id = filter_input(INPUT_POST, 'application_id', FILTER_VALIDATE_INT);
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$designer_id = filter_input(INPUT_POST, 'designer_id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';

if (!$application_id || !$request_id || !$designer_id || !in_array($action, ['accept', 'reject'])) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit();
}

$conn->begin_transaction();

try {
    // ตรวจสอบว่าเป็นเจ้าของงานและงานยังรอการพิจารณาอยู่
    $sql_verify = "SELECT status FROM client_job_requests WHERE request_id = ? AND client_id = ?";
    $stmt_verify = $conn->prepare($sql_verify);
    $stmt_verify->bind_param("ii", $request_id, $client_id);
    $stmt_verify->execute();
    $result = $stmt_verify->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('ไม่พบคำของาน หรือคุณไม่มีสิทธิ์จัดการ');
    }
    $job_info = $result->fetch_assoc();
    if ($job_info['status'] !== 'proposed') {
        throw new Exception('การตัดสินใจนี้ได้ถูกดำเนินการไปแล้ว');
    }

    if ($action === 'accept') {
        // --- ส่วนของการ "ตอบตกลง" ---

        // 1. อัปเดตสถานะใบสมัครที่ถูกเลือกเป็น 'accepted'
        $sql_accept = "UPDATE job_applications SET status = 'accepted' WHERE application_id = ?";
        $stmt_accept = $conn->prepare($sql_accept);
        $stmt_accept->bind_param("i", $application_id);
        $stmt_accept->execute();
        $stmt_accept->close();

        // 2. [แก้ไข] อัปเดตสถานะของงาน (client_job_requests) เป็น 'awaiting_deposit_verification'
        $new_status = 'awaiting_deposit_verification';
        $sql_update_req = "UPDATE client_job_requests SET status = ?, designer_id = ? WHERE request_id = ?";
        $stmt_update_req = $conn->prepare($sql_update_req);
        $stmt_update_req->bind_param("sii", $new_status, $designer_id, $request_id);
        $stmt_update_req->execute();
        $stmt_update_req->close();
        
        // 3. สร้างสัญญา (contract) ในสถานะ 'pending'
        $sql_get_price = "SELECT offered_price FROM job_applications WHERE application_id = ?";
        $stmt_price = $conn->prepare($sql_get_price);
        $stmt_price->bind_param("i", $application_id);
        $stmt_price->execute();
        $price_result = $stmt_price->get_result()->fetch_assoc();
        $agreed_price = $price_result['offered_price'];
        $stmt_price->close();

        $start_date = date('Y-m-d');
        $sql_insert_contract = "INSERT INTO contracts (request_id, designer_id, client_id, agreed_price, start_date, contract_status, payment_status) VALUES (?, ?, ?, ?, ?, 'pending', 'pending')";
        $stmt_insert_contract = $conn->prepare($sql_insert_contract);
        $stmt_insert_contract->bind_param("iiids", $request_id, $designer_id, $client_id, $agreed_price, $start_date);
        $stmt_insert_contract->execute();
        $stmt_insert_contract->close();
        
        // 4. ปฏิเสธใบสมัครอื่นๆ ทั้งหมดสำหรับงานนี้โดยอัตโนมัติ
        $sql_reject_others = "UPDATE job_applications SET status = 'rejected' WHERE request_id = ? AND application_id != ?";
        $stmt_reject_others = $conn->prepare($sql_reject_others);
        $stmt_reject_others->bind_param("ii", $request_id, $application_id);
        $stmt_reject_others->execute();
        $stmt_reject_others->close();
        
        $message = "ตอบตกลงข้อเสนอเรียบร้อยแล้ว! ระบบกำลังนำคุณไปสู่หน้าชำระเงินมัดจำ";
        // [แก้ไข] เพิ่ม redirectUrl เพื่อให้ JavaScript รู้ว่าต้องไปหน้าไหนต่อ
        $response = ['status' => 'success', 'message' => $message, 'redirectUrl' => 'payment.php?request_id=' . $request_id];

    } else { // 'reject'
        // --- ส่วนของการ "ปฏิเสธ" ---
        $sql_reject = "UPDATE job_applications SET status = 'rejected' WHERE application_id = ?";
        $stmt_reject = $conn->prepare($sql_reject);
        $stmt_reject->bind_param("i", $application_id);
        $stmt_reject->execute();
        $stmt_reject->close();
        
        $message = "ปฏิเสธข้อเสนอเรียบร้อยแล้ว";
        $response = ['status' => 'success', 'message' => $message];
    }

    $conn->commit();
    echo json_encode($response);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();