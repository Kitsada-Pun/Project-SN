<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    header("Location: ../login.php");
    exit();
}

require_once '../connect.php';

$client_id = $_SESSION['user_id'];
$request_id = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

if ($request_id === 0) {
    die("ไม่พบรหัสคำของาน");
}

// --- START: MODIFIED CODE ---
// ดึงข้อมูลที่จำเป็นสำหรับหน้าชำระเงิน รวมถึงข้อมูลบัญชีของ Designer
$payment_info = null;
$sql = "
    SELECT 
        cjr.title,
        cjr.status,
        ja.offered_price,
        u.first_name AS designer_first_name,
        u.last_name AS designer_last_name,
        p.bank_account_name,
        p.bank_account_number,
        p.bank_name
    FROM client_job_requests cjr
    JOIN job_applications ja ON cjr.request_id = ja.request_id AND ja.status = 'accepted'
    JOIN users u ON ja.designer_id = u.user_id
    LEFT JOIN profiles p ON u.user_id = p.user_id
    WHERE cjr.request_id = ? AND cjr.client_id = ?
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ii", $request_id, $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $payment_info = $result->fetch_assoc();
    } else {
        die("ไม่พบข้อมูลงาน หรือคุณไม่มีสิทธิ์ในการเข้าถึง");
    }
    $stmt->close();
} else {
    die("เกิดข้อผิดพลาดในการดึงข้อมูล");
}

// ตรวจสอบสถานะของงาน ต้องเป็น 'awaiting_deposit_verification' เท่านั้น
if ($payment_info['status'] !== 'awaiting_deposit_verification') {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'งานนี้ไม่อยู่ในสถานะรอการชำระเงิน'];
    header('Location: my_requests.php');
    exit();
}
// --- END: MODIFIED CODE ---

$deposit_amount = (float)$payment_info['offered_price'] * 0.20;
$conn->close();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ชำระเงินมัดจำ - <?= htmlspecialchars($payment_info['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Kanit', sans-serif; }
        .payment-card { background: white; border-radius: 1.5rem; box-shadow: 0 20px 50px -10px rgba(0, 0, 0, 0.1); }
        .file-upload-wrapper {
            position: relative; width: 100%; height: 150px;
            border: 2px dashed #cbd5e1; border-radius: 0.75rem;
            display: flex; align-items: center; justify-content: center;
            flex-direction: column; cursor: pointer; transition: all 0.3s ease;
        }
        .file-upload-wrapper:hover { border-color: #3b82f6; background-color: #f9fafb; }
        #file-upload-input { opacity: 0; position: absolute; width: 100%; height: 100%; cursor: pointer; }
    </style>
</head>
<body class="bg-slate-50 flex flex-col min-h-screen">
    <?php include '../includes/nav.php'; ?>
    <main class="container mx-auto px-4 py-8 flex-grow">
        <div class="max-w-2xl mx-auto">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-slate-800">ชำระเงินมัดจำ (20%)</h1>
                <p class="text-slate-500 mt-1">สำหรับงาน: "<?= htmlspecialchars($payment_info['title']) ?>"</p>
            </div>
            <div class="payment-card p-8">
                <div class="mb-6 pb-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">สรุปรายการ</h2>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-slate-500">นักออกแบบ:</span>
                        <span class="font-semibold text-slate-700"><?= htmlspecialchars($payment_info['designer_first_name'] . ' ' . $payment_info['designer_last_name']) ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-slate-500">ราคาที่ตกลง:</span>
                        <span class="font-semibold text-slate-700">฿<?= number_format((float)$payment_info['offered_price'], 2) ?></span>
                    </div>
                    <div class="flex justify-between items-center text-xl mt-4">
                        <span class="font-bold text-slate-800">ยอดมัดจำที่ต้องชำระ:</span>
                        <span class="font-bold text-green-600">฿<?= number_format($deposit_amount, 2) ?></span>
                    </div>
                </div>

                <div class="mb-8 pb-6 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">ข้อมูลการชำระเงินของนักออกแบบ</h2>
                     <?php if (!empty($payment_info['bank_name']) && !empty($payment_info['bank_account_number'])) : ?>
                    <div class="space-y-2 p-4 bg-gray-50 rounded-lg">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">ธนาคาร:</span>
                            <span class="font-semibold text-slate-700"><?= htmlspecialchars($payment_info['bank_name']) ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">เลขที่บัญชี:</span>
                            <span class="font-semibold text-slate-700"><?= htmlspecialchars($payment_info['bank_account_number']) ?></span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-500">ชื่อบัญชี:</span>
                            <span class="font-semibold text-slate-700"><?= htmlspecialchars($payment_info['bank_account_name']) ?></span>
                        </div>
                    </div>
                    <?php else : ?>
                        <p class="text-center text-red-500 p-4 bg-red-50 rounded-lg">นักออกแบบยังไม่ได้เพิ่มข้อมูลการชำระเงิน กรุณาติดต่อนักออกแบบผ่านทางแชท</p>
                    <?php endif; ?>
                </div>
                <form action="submit_payment.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="request_id" value="<?= $request_id ?>">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">แนบสลิปการโอนเงิน</label>
                        <div class="file-upload-wrapper" id="file-upload-container">
                            <input type="file" name="payment_slip" id="file-upload-input" accept="image/png, image/jpeg, image/jpg" required>
                            <div id="file-upload-text">
                                <div class="text-center text-slate-500">
                                    <i class="fa-solid fa-cloud-arrow-up fa-3x"></i>
                                    <p class="mt-2">คลิกเพื่อเลือกไฟล์</p>
                                    <p class="text-xs">(รองรับ: JPG, PNG)</p>
                                </div>
                            </div>
                            <div id="file-preview-container" class="hidden w-full h-full">
                                <img id="file-preview" src="#" alt="Preview" class="max-w-full max-h-full object-contain rounded-lg"/>
                            </div>
                        </div>
                        <p id="file-name" class="text-sm text-slate-600 mt-2 text-center"></p>
                    </div>

                    <div class="mt-8">
                        <button type="submit" class="w-full text-center px-6 py-3 bg-blue-600 text-white rounded-lg font-semibold text-lg hover:bg-blue-700 transition-colors disabled:bg-gray-400" <?= (empty($payment_info['bank_name'])) ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-paper-plane mr-2"></i>ส่งหลักฐานการชำระเงิน
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>

    <script>
    document.getElementById('file-upload-input').addEventListener('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                document.getElementById('file-preview').setAttribute('src', e.target.result);
                document.getElementById('file-upload-text').classList.add('hidden');
                document.getElementById('file-preview-container').classList.remove('hidden');
            }
            
            reader.readAsDataURL(file);
            document.getElementById('file-name').textContent = 'ไฟล์ที่เลือก: ' + file.name;
        }
    });

    document.getElementById('file-upload-container').addEventListener('click', function() {
        document.getElementById('file-upload-input').click();
    });
    </script>
</body>
</html>