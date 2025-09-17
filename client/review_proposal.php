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
    die("ไม่พบคำของาน");
}

// 1. ดึงข้อมูลหลักของ Job Request
$job_request = null;
$sql_job = "SELECT title, description, budget, status FROM client_job_requests WHERE request_id = ? AND client_id = ?";
$stmt_job = $conn->prepare($sql_job);
$stmt_job->bind_param("ii", $request_id, $client_id);
$stmt_job->execute();
$result_job = $stmt_job->get_result();
if ($result_job->num_rows > 0) {
    $job_request = $result_job->fetch_assoc();
} else {
    die("ไม่พบคำของาน หรือคุณไม่มีสิทธิ์เข้าถึง");
}
$stmt_job->close();

// 2. ดึงใบเสนอราคาทั้งหมด (proposals) ที่เกี่ยวข้องกับงานนี้
$proposals = [];
$sql_proposals = "
    SELECT 
        ja.application_id,
        ja.designer_id,
        ja.proposal_text,
        ja.offered_price,
        ja.application_date,
        ja.status AS proposal_status,
        u.first_name,
        u.last_name,
        p.profile_picture_url,
        p.skills
    FROM job_applications ja
    JOIN users u ON ja.designer_id = u.user_id
    LEFT JOIN profiles p ON u.user_id = p.user_id
    WHERE ja.request_id = ?
    ORDER BY ja.application_date DESC
";

$stmt_proposals = $conn->prepare($sql_proposals);
$stmt_proposals->bind_param("i", $request_id);
$stmt_proposals->execute();
$result_proposals = $stmt_proposals->get_result();
$proposals = $result_proposals->fetch_all(MYSQLI_ASSOC);
$stmt_proposals->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>พิจารณาข้อเสนอ - <?= htmlspecialchars($job_request['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Kanit', sans-serif; }
        body { background-color: #f0f4f8; }
        .proposal-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>

<body class="bg-gray-50">

    <?php include '../includes/nav.php'; ?>

    <main class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <h1 class="text-3xl font-bold text-slate-800"><?= htmlspecialchars($job_request['title']) ?></h1>
            <p class="text-slate-500 mt-2"><?= htmlspecialchars($job_request['description']) ?></p>
            <div class="mt-4 pt-4 border-t border-gray-200">
                <span class="text-lg font-semibold text-green-600">งบประมาณ: ฿<?= htmlspecialchars(number_format((float)$job_request['budget'], 2)) ?></span>
            </div>
        </div>

        <h2 class="text-2xl font-bold text-slate-700 mb-6">ข้อเสนอจากนักออกแบบ (<?= count($proposals) ?> รายการ)</h2>

        <?php if ($job_request['status'] !== 'proposed') : ?>
             <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 rounded-lg shadow" role="alert">
                <p class="font-bold">สถานะ: ดำเนินการแล้ว</p>
                <p>คุณได้ตัดสินใจสำหรับงานนี้เรียบร้อยแล้ว</p>
            </div>
        <?php elseif (empty($proposals)) : ?>
            <div class="text-center bg-white rounded-lg shadow-sm p-12">
                <i class="fa-solid fa-hourglass-end fa-3x text-slate-300"></i>
                <h3 class="mt-4 text-xl font-semibold text-slate-700">ยังไม่มีข้อเสนอเข้ามา</h3>
                <p class="mt-1 text-slate-500">เมื่อมีนักออกแบบยื่นข้อเสนอสำหรับงานนี้ จะแสดงผลที่นี่</p>
            </div>
        <?php else : ?>
            <div class="space-y-6">
                <?php foreach ($proposals as $proposal) : ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 transition-all duration-300 proposal-card">
                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="md:w-1/4 flex flex-col items-center text-center">
                                <img src="../<?= htmlspecialchars($proposal['profile_picture_url'] ?? 'dist/img/avatar.png') ?>" class="w-24 h-24 rounded-full object-cover border-4 border-gray-200">
                                <h4 class="text-lg font-bold mt-3 text-slate-800">
                                    <a href="../view_profile.php?user_id=<?= $proposal['designer_id'] ?>" class="hover:text-blue-600">
                                        <?= htmlspecialchars($proposal['first_name'] . ' ' . $proposal['last_name']) ?>
                                    </a>
                                </h4>
                                <p class="text-sm text-slate-500 mt-1 line-clamp-2"><?= htmlspecialchars($proposal['skills'] ?? 'ยังไม่ระบุทักษะ') ?></p>
                            </div>
                            <div class="flex-1 md:border-l md:pl-6 border-gray-200">
                                <div class="flex justify-between items-start mb-3">
                                    <div>
                                        <p class="text-2xl font-bold text-green-600">฿<?= htmlspecialchars(number_format((float)$proposal['offered_price'], 2)) ?></p>
                                        <p class="text-sm text-slate-500">ยื่นข้อเสนอเมื่อ: <?= date('d M Y, H:i', strtotime($proposal['application_date'])) ?></p>
                                    </div>
                                    <?php if ($proposal['proposal_status'] === 'accepted'): ?>
                                        <span class="bg-green-100 text-green-800 text-xs font-semibold px-3 py-1 rounded-full">ตอบตกลงแล้ว</span>
                                    <?php elseif ($proposal['proposal_status'] === 'rejected'): ?>
                                        <span class="bg-red-100 text-red-800 text-xs font-semibold px-3 py-1 rounded-full">ปฏิเสธแล้ว</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-slate-600 bg-gray-50 p-4 rounded-lg"><?= !empty($proposal['proposal_text']) ? htmlspecialchars($proposal['proposal_text']) : '<i>ไม่มีข้อความเพิ่มเติมจากนักออกแบบ</i>' ?></p>

                                <div class="mt-5 pt-5 border-t border-gray-200 flex justify-end gap-3">
                                    <button 
                                        class="action-btn px-5 py-2 text-sm font-semibold text-white bg-red-500 rounded-lg hover:bg-red-600 transition-colors"
                                        data-action="reject"
                                        data-application-id="<?= $proposal['application_id'] ?>"
                                        data-request-id="<?= $request_id ?>"
                                        data-designer-id="<?= $proposal['designer_id'] ?>">
                                        <i class="fa fa-times mr-1"></i> ปฏิเสธ
                                    </button>
                                    <button 
                                        class="action-btn px-5 py-2 text-sm font-semibold text-white bg-green-500 rounded-lg hover:bg-green-600 transition-colors"
                                        data-action="accept"
                                        data-application-id="<?= $proposal['application_id'] ?>"
                                        data-request-id="<?= $request_id ?>"
                                        data-designer-id="<?= $proposal['designer_id'] ?>">
                                        <i class="fa fa-check mr-1"></i> ตอบตกลงข้อเสนอนี้
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php include '../includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            // ป้องกันการคลิกปุ่มเมื่อสถานะไม่ใช่ 'proposed'
            const isActionable = <?= json_encode($job_request['status'] === 'proposed') ?>;
            if (!isActionable) {
                $('.action-btn').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');
            }
            
            $('.action-btn').on('click', function(e) {
                e.preventDefault();

                if (!isActionable) return;

                const button = $(this);
                const action = button.data('action');
                const application_id = button.data('application-id');
                const request_id = button.data('request-id');
                const designer_id = button.data('designer-id');
                
                let confirmText = action === 'accept' 
                    ? 'เมื่อตอบตกลงแล้ว จะต้องชำระเงินมัดจำเพื่อเริ่มงาน ยืนยันหรือไม่?' 
                    : 'คุณแน่ใจหรือไม่ว่าต้องการปฏิเสธข้อเสนอนี้?';
                let confirmButtonText = action === 'accept' ? 'ใช่, ตอบตกลง' : 'ใช่, ปฏิเสธ';

                Swal.fire({
                    title: 'ยืนยันการตัดสินใจ',
                    text: confirmText,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: (action === 'accept' ? '#28a745' : '#d33'),
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: confirmButtonText,
                    cancelButtonText: 'ยกเลิก'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'กำลังดำเนินการ...',
                            allowOutsideClick: false,
                            didOpen: () => { Swal.showLoading() }
                        });

                        $.ajax({
                            url: 'action_proposal.php', // ชี้ไปที่ไฟล์ action ที่เราสร้าง
                            method: 'POST',
                            data: {
                                application_id: application_id,
                                request_id: request_id,
                                designer_id: designer_id,
                                action: action
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.status === 'success') {
                                    Swal.fire('สำเร็จ!', response.message, 'success').then(() => {
                                        window.location.href = 'my_requests.php';
                                    });
                                } else {
                                    Swal.fire('ผิดพลาด!', response.message, 'error');
                                }
                            },
                            error: function() {
                                Swal.fire('ผิดพลาด!', 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้', 'error');
                            }
                        });
                    }
                });
            });
        });
    </script>
</body>

</html>