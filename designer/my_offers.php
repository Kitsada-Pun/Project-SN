<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'designer') {
    header("Location: ../login.php");
    exit();
}

require_once '../connect.php';

$designer_id = $_SESSION['user_id'];
$loggedInUserName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Designer';

if (empty($_SESSION['full_name'])) {
    $user_id = $_SESSION['user_id'];
    $sql_user = "SELECT first_name, last_name FROM users WHERE user_id = ?";
    $stmt_user = $conn->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($result_user->num_rows === 1) {
            $user_info = $result_user->fetch_assoc();
            $loggedInUserName = $user_info['first_name'] . ' ' . $user_info['last_name'];
            $_SESSION['full_name'] = $loggedInUserName;
        }
        $stmt_user->close();
    }
}

$offers = [];

// --- [ปรับแก้ SQL Query] ---
// ดึงข้อมูลจาก client_job_requests ที่ส่งถึงนักออกแบบคนนี้โดยตรง
$sql = "
    SELECT 
        cjr.request_id,
        cjr.title,
        cjr.description,
        cjr.budget AS price,
        cjr.posted_date AS offer_date,
        cjr.status,
        u.user_id AS client_id,
        CONCAT(u.first_name, ' ', u.last_name) AS client_name,
        p.profile_picture_url AS client_avatar
    FROM client_job_requests cjr
    JOIN users u ON cjr.client_id = u.user_id
    LEFT JOIN profiles p ON u.user_id = p.user_id
    WHERE cjr.designer_id = ?
    ORDER BY cjr.posted_date DESC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $designer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $offers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    error_log("SQL Error (my_offers): " . $conn->error);
    die("เกิดข้อผิดพลาดในการดึงข้อมูล");
}
// นับจำนวนข้อเสนอที่รอการตอบรับจากข้อมูลที่มีอยู่แล้ว
$pending_offers_count = 0;
$submitted_offers_count = 0;
foreach ($offers as $offer) {
    if ($offer['status'] === 'open') {
        $pending_offers_count++;
    } elseif ($offer['status'] === 'proposed') {
        $submitted_offers_count++;
    }
}
$conn->close();

// --- [ปรับแก้ Function] ---
function getStatusInfo($status)
{
    switch ($status) {
        case 'open':
            return ['text' => 'รอการตอบรับ', 'color' => 'bg-yellow-100 text-yellow-800', 'tab' => 'pending'];
        case 'proposed':
            return ['text' => 'รอผู้ว่าจ้างพิจารณา', 'color' => 'bg-blue-100 text-blue-800', 'tab' => 'submitted'];
        case 'assigned':
            return ['text' => 'กำลังดำเนินการ', 'color' => 'bg-blue-100 text-blue-800', 'tab' => 'active'];
        case 'completed':
            return ['text' => 'เสร็จสมบูรณ์', 'color' => 'bg-green-100 text-green-800', 'tab' => 'completed'];
        case 'cancelled':
            return ['text' => 'ยกเลิก', 'color' => 'bg-gray-100 text-gray-800', 'tab' => 'cancelled'];
        default:
            return ['text' => 'ไม่ระบุ', 'color' => 'bg-gray-100 text-gray-800', 'tab' => 'all'];
    }
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>งานของฉัน - PixelLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Kanit', sans-serif;
        }

        .line-clamp-2 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
    </style>
</head>

<body class="bg-slate-50 flex flex-col min-h-screen">

    <?php include '../includes/nav.php'; ?>

    <main class="container mx-auto px-4 py-8 flex-grow">
        <div class="mb-8">
            <h1 class="text-4xl font-bold text-slate-800">งานของฉัน</h1>
            <p class="text-slate-500 mt-1">ติดตามและจัดการงานที่ผู้ว่าจ้างยื่นข้อเสนอให้คุณที่นี่</p>
        </div>

        <div x-data="{ tab: 'all' }">
            <div class="mb-6 p-1.5 bg-slate-200/60 rounded-xl flex flex-wrap items-center gap-2">
                <button @click="tab = 'all'" :class="tab === 'all' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all duration-200">
                    <i class="fa-solid fa-list-ul mr-1.5"></i> ทั้งหมด
                </button>
                <button @click="tab = 'pending'" :class="tab === 'pending' ? 'bg-white text-yellow-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg transition-all duration-200">
                    <i class="fa-solid fa-inbox mr-1.5"></i>
                    <span>ข้อเสนองาน</span>
                    <?php if ($pending_offers_count > 0): ?>
                        <span class="ml-2 inline-flex items-center justify-center h-5 w-5 rounded-full bg-red-500 text-xs font-bold text-white">
                            <?= $pending_offers_count ?>
                        </span>
                    <?php endif; ?>
                </button>

                <button @click="tab = 'submitted'" :class="tab === 'submitted' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg transition-all duration-200">
                    <i class="fa-solid fa-paper-plane mr-1.5"></i>
                    <span>ยื่นใบเสนอราคา</span>
                    <?php if ($submitted_offers_count > 0): ?>
                        <span class="ml-2 inline-flex items-center justify-center h-5 w-5 rounded-full bg-blue-500 text-xs font-bold text-white">
                            <?= $submitted_offers_count ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button @click="tab = 'active'" :class="tab === 'active' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all duration-200">
                    <i class="fa-solid fa-person-digging mr-1.5"></i> กำลังดำเนินการ
                </button>
                <button @click="tab = 'completed'" :class="tab === 'completed' ? 'bg-white text-green-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all duration-200">
                    <i class="fa-solid fa-circle-check mr-1.5"></i> เสร็จสมบูรณ์
                </button>
                <button @click="tab = 'cancelled'" :class="tab === 'cancelled' ? 'bg-white text-red-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all duration-200">
                    <i class="fa-solid fa-circle-xmark mr-1.5"></i> ยกเลิก
                </button>
            </div>

            <div class="space-y-5">
                <?php if (empty($offers)): ?>
                    <div class="text-center bg-white rounded-lg shadow-sm p-12">
                        <div class="mx-auto w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-paper-plane fa-2x text-slate-400"></i>
                        </div>
                        <h3 class="mt-4 text-xl font-semibold text-slate-700">ยังไม่มีข้อเสนองานเข้ามา</h3>
                        <p class="mt-1 text-slate-500">เมื่อมีผู้ว่าจ้างสนใจคุณ ข้อเสนอจะแสดงที่นี่</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($offers as $offer): ?>
                        <?php
                        $statusInfo = getStatusInfo($offer['status']);
                        $data_status = $statusInfo['tab'];
                        ?>
                        <div x-show="tab === 'all' || tab === '<?= $data_status ?>'"
                            class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
                            <div class="flex flex-col sm:flex-row gap-6">
                                <div class="flex-1">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                                        <h2 class="text-xl font-bold text-slate-800 leading-tight">
                                            <a href="../job_detail.php?request_id=<?= $offer['request_id'] ?>" class="hover:text-blue-600"><?= htmlspecialchars($offer['title']) ?></a>
                                        </h2>
                                        <span class="text-xs font-semibold px-3 py-1 rounded-full <?= $statusInfo['color'] ?>">
                                            <?= htmlspecialchars($statusInfo['text']) ?>
                                        </span>
                                    </div>
                                    <p class="text-slate-500 text-sm mb-4 line-clamp-2">
                                        <?= htmlspecialchars($offer['description']) ?>
                                    </p>
                                    <div class="text-sm space-y-2 text-slate-600">
                                        <p><i class="fa-solid fa-user-tie w-5 text-slate-400 mr-1"></i> ผู้ว่าจ้าง: <a href="../view_profile.php?user_id=<?= $offer['client_id'] ?>" class="font-semibold text-blue-600 hover:underline"><?= htmlspecialchars($offer['client_name']) ?></a></p>
                                        <p><i class="fa-solid fa-calendar-day w-5 text-slate-400 mr-1"></i> ยื่นข้อเสนอเมื่อ: <?= date('d M Y, H:i', strtotime($offer['offer_date'])) ?></p>
                                    </div>
                                </div>
                                <div class="flex-shrink-0 sm:text-right sm:border-l sm:pl-6 border-slate-200/80 w-full sm:w-auto">
                                    <div class="text-2xl font-bold text-green-600 mb-4">
                                        ฿<?= !empty($offer['price']) ? number_format((float)$offer['price'], 2) : 'N/A' ?>
                                    </div>
                                    <div class="flex flex-col sm:items-end gap-2">

                                        <?php if ($offer['status'] === 'open'): ?>

                                            <button
                                                @click="isModalOpen = true; modalData = <?= htmlspecialchars(json_encode($offer), ENT_QUOTES, 'UTF-8') ?>"
                                                class="w-full sm:w-auto text-center px-4 py-2 bg-blue-500 text-white rounded-lg text-sm font-semibold hover:bg-blue-600 transition-colors">
                                                <i class="fa-solid fa-file-invoice-dollar mr-1"></i> ยื่นใบเสนอราคา
                                            </button>
                                            <button
                                                data-request-id="<?= $offer['request_id'] ?>"
                                                data-action="reject"
                                                class="offer-action-btn w-full sm:w-auto text-center px-4 py-2 bg-red-500 text-white rounded-lg text-sm font-semibold hover:bg-red-600 transition-colors">
                                                <i class="fa-solid fa-times mr-1"></i> ปฏิเสธ
                                            </button>

                                        <?php elseif ($offer['status'] === 'proposed'): ?>

                                            <button disabled class="w-full sm:w-auto text-center px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-semibold cursor-not-allowed">
                                                <i class="fa-solid fa-hourglass-half mr-1"></i> รอการอนุมัติ
                                            </button>

                                        <?php elseif ($offer['status'] === 'assigned'): ?>

                                            <a href="../messages.php?to_user=<?= $offer['client_id'] ?>" class="w-full sm:w-auto text-center px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-semibold hover:bg-green-600 transition-colors">
                                                <i class="fa-solid fa-comments mr-1"></i> พูดคุยกับผู้ว่าจ้าง
                                            </a>

                                        <?php else: ?>

                                            <a href="../job_detail.php?request_id=<?= $offer['request_id'] ?>" class="w-full sm:w-auto text-center px-4 py-2 bg-slate-600 text-white rounded-lg text-sm font-semibold hover:bg-slate-700 transition-colors">
                                                <i class="fa-solid fa-search mr-1"></i> ดูรายละเอียด
                                            </a>

                                        <?php endif; ?>

                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include '../includes/footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            $('.offer-action-btn').on('click', function() {
                const button = $(this);
                const requestId = button.data('request-id');
                const action = button.data('action');

                let confirmButtonColor = action === 'accept' ? '#3085d6' : '#d33';
                let title = action === 'accept' ? 'ยืนยันการรับงาน?' : 'ยืนยันการปฏิเสธ?';
                let text = action === 'accept' ?
                    "คุณต้องการยอมรับข้อเสนอนี้ใช่หรือไม่" :
                    "คุณแน่ใจหรือไม่ที่จะปฏิเสธข้อเสนอนี้";

                Swal.fire({
                    title: title,
                    text: text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: confirmButtonColor,
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'ยืนยัน',
                    cancelButtonText: 'ยกเลิก'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            url: 'action_offer.php',
                            method: 'POST',
                            data: {
                                request_id: requestId,
                                action: action
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.status === 'success') {
                                    Swal.fire(
                                        'สำเร็จ!',
                                        response.message,
                                        'success'
                                    ).then(() => {
                                        // Reload the page to show the updated status
                                        location.reload();
                                    });
                                } else {
                                    Swal.fire(
                                        'เกิดข้อผิดพลาด!',
                                        response.message,
                                        'error'
                                    );
                                }
                            },
                            error: function() {
                                Swal.fire(
                                    'เกิดข้อผิดพลาด!',
                                    'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้',
                                    'error'
                                );
                            }
                        });
                    }
                });
            });
        });
    </script>

</body>

</html>