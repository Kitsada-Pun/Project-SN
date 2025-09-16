<?php
session_start();
date_default_timezone_set('Asia/Bangkok');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'client') {
    header("Location: ../login.php");
    exit();
}

require_once '../connect.php';

$client_id = $_SESSION['user_id'];
$loggedInUserName = $_SESSION['username'] ?? '';
if (empty($loggedInUserName)) {
    $sql_user = "SELECT first_name, last_name FROM users WHERE user_id = ?";
    $stmt_user = $conn->prepare($sql_user);
    if ($stmt_user) {
        $stmt_user->bind_param("i", $current_user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($user_info = $result_user->fetch_assoc()) {
            $loggedInUserName = $user_info['first_name'] . ' ' . $user_info['last_name'];
        }
        $stmt_user->close();
    }
}
// ดึงข้อมูลคำขอจ้างงานทั้งหมดของ Client คนนี้
$requests = [];
$sql = "
    SELECT 
        cjr.request_id,
        cjr.title,
        cjr.description,
        cjr.budget,
        cjr.posted_date,
        cjr.status,
        u.user_id AS designer_id,
        CONCAT(u.first_name, ' ', u.last_name) AS designer_name
    FROM client_job_requests cjr
    LEFT JOIN users u ON cjr.designer_id = u.user_id
    WHERE cjr.client_id = ?
    ORDER BY cjr.posted_date DESC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    die("เกิดข้อผิดพลาดในการดึงข้อมูล");
}

// --- [เพิ่มเข้ามา] นับจำนวนงานในแต่ละสถานะ ---
$counts = [
    'open' => 0,
    'proposed' => 0,
    'awaiting_confirmation' => 0, // <-- เพิ่มบรรทัดนี้
    'assigned' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
foreach ($requests as $request) {
    if (isset($counts[$request['status']])) {
        $counts[$request['status']]++;
    }
}
$conn->close();

// --- [ปรับแก้] Function สำหรับแปลง status ---
function getStatusInfoClient($status)
{
    switch ($status) {
        case 'open':
            return ['text' => 'เปิดรับข้อเสนอ', 'color' => 'bg-gray-200 text-gray-800', 'tab' => 'open'];
        case 'proposed':
            return ['text' => 'รอการพิจารณา', 'color' => 'bg-yellow-100 text-yellow-800', 'tab' => 'proposed'];
            // --- เพิ่ม Case ใหม่ตรงนี้ ---
        case 'awaiting_confirmation':
            return ['text' => 'รอชำระเงินมัดจำ', 'color' => 'bg-orange-100 text-orange-800', 'tab' => 'awaiting'];
            // --------------------------
        case 'assigned':
            return ['text' => 'กำลังดำเนินการ', 'color' => 'bg-blue-100 text-blue-800', 'tab' => 'assigned'];
        case 'completed':
            return ['text' => 'เสร็จสมบูรณ์', 'color' => 'bg-green-100 text-green-800', 'tab' => 'completed'];
        case 'cancelled':
            return ['text' => 'ยกเลิก', 'color' => 'bg-red-100 text-red-800', 'tab' => 'cancelled'];
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
    <title>คำขอจ้างงานของฉัน - PixelLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Kanit', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f0f4f8 0%, #e8edf3 100%);
            color: #2c3e50;
            overflow-x: hidden;
        }

        .navbar {
            background-color: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .btn-primary {
            background: linear-gradient(45deg, #0a5f97 0%, #0d96d2 100%);
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(13, 150, 210, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(45deg, #0d96d2 0%, #0a5f97 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13, 150, 210, 0.5);
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(108, 117, 125, 0.4);
        }

        .btn-danger {
            background-color: #ef4444;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.5);
        }

        .text-gradient {
            background: linear-gradient(45deg, #0a5f97, #0d96d2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .pixellink-logo,
        .pixellink-logo-footer {
            font-weight: 700;
            font-size: 2.25rem;
            background: linear-gradient(45deg, #0a5f97, #0d96d2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .pixellink-logo b,
        .pixellink-logo-footer b {
            color: #0d96d2;
        }

        .card-item {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .card-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .card-image {
            width: 100%;
            aspect-ratio: 16/9;
            object-fit: cover;
            border-top-left-radius: 1rem;
            border-top-right-radius: 1rem;
        }

        .hero-section {
            background-image: url('dist/img/cover.png');
            background-size: cover;
            background-position: center;
            position: relative;
            z-index: 1;
            padding: 8rem 0;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: -1;
        }
    </style>
</head>

<body class="bg-slate-50 flex flex-col min-h-screen">

    <?php include '../includes/nav.php'; ?>

    <main class="container mx-auto px-4 py-8 flex-grow">
        <div class="mb-8">
            <h1 class="text-4xl font-bold text-slate-800">คำขอจ้างงานของฉัน</h1>
            <p class="text-slate-500 mt-1">จัดการและติดตามสถานะคำขอจ้างงานทั้งหมดของคุณ</p>
        </div>

        <div x-data="{ tab: 'all' }">
            <div class="mb-6 p-1.5 bg-slate-200/60 rounded-xl flex flex-wrap items-center gap-2">
                <button @click="tab = 'all'" :class="tab === 'all' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all">
                    <i class="fa-solid fa-list-ul mr-1.5"></i> ทั้งหมด
                </button>
                <button @click="tab = 'proposed'" :class="tab === 'proposed' ? 'bg-white text-yellow-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg transition-all">
                    <i class="fa-solid fa-file-alt mr-1.5"></i> รอพิจารณา
                    <?php if ($counts['proposed'] > 0): ?>
                        <span class="ml-2 inline-flex items-center justify-center h-5 w-5 rounded-full bg-red-500 text-xs font-bold text-white"><?= $counts['proposed'] ?></span>
                    <?php endif; ?>
                </button>
                <button @click="tab = 'awaiting'" :class="tab === 'awaiting' ? 'bg-white text-orange-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="relative inline-flex items-center px-4 py-2 text-sm font-semibold rounded-lg transition-all">
                    <i class="fa-solid fa-money-bill-wave mr-1.5"></i> รอชำระเงิน
                    <?php if ($counts['awaiting_confirmation'] > 0): ?>
                        <span class="ml-2 inline-flex items-center justify-center h-5 w-5 rounded-full bg-orange-500 text-xs font-bold text-white"><?= $counts['awaiting_confirmation'] ?></span>
                    <?php endif; ?>
                </button>
                <button @click="tab = 'assigned'" :class="tab === 'assigned' ? 'bg-white text-blue-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all">
                    <i class="fa-solid fa-person-digging mr-1.5"></i> กำลังดำเนินการ
                </button>
                <button @click="tab = 'completed'" :class="tab === 'completed' ? 'bg-white text-green-600 shadow-sm' : 'text-slate-600 hover:bg-slate-300/60'" class="px-4 py-2 text-sm font-semibold rounded-lg transition-all">
                    <i class="fa-solid fa-circle-check mr-1.5"></i> เสร็จสมบูรณ์
                </button>
            </div>

            <div class="space-y-5">
                <?php if (empty($requests)): ?>
                    <div class="text-center bg-white rounded-lg shadow-sm p-12">
                        <div class="mx-auto w-20 h-20 bg-slate-100 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-file-circle-xmark fa-2x text-slate-400"></i>
                        </div>
                        <h3 class="mt-4 text-xl font-semibold text-slate-700">คุณยังไม่ได้สร้างคำขอจ้างงาน</h3>
                        <p class="mt-1 text-slate-500">เริ่มจ้างนักออกแบบได้โดยการสร้างคำขอจ้างงานใหม่</p>
                        <a href="../job_listings.php" class="mt-4 inline-block px-6 py-2 bg-blue-500 text-white rounded-lg font-semibold hover:bg-blue-600">ไปที่หน้าจ้างงาน</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $statusInfo = getStatusInfoClient($request['status']);
                        $data_status = $statusInfo['tab'];
                        ?>
                        <div x-show="tab === 'all' || tab === '<?= $data_status ?>'" class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm hover:shadow-lg transition-shadow duration-300">
                            <div class="flex flex-col sm:flex-row gap-6">
                                <div class="flex-1">
                                    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                                        <h2 class="text-xl font-bold text-slate-800">
                                            <?= htmlspecialchars($request['title']) ?>
                                        </h2>
                                        <span class="text-xs font-semibold px-3 py-1 rounded-full <?= $statusInfo['color'] ?>">
                                            <?= htmlspecialchars($statusInfo['text']) ?>
                                        </span>
                                    </div>
                                    <p class="text-slate-500 text-sm mb-4">
                                        <?= htmlspecialchars($request['description']) ?>
                                    </p>
                                    <p class="text-sm text-slate-600">
                                        <i class="fa-solid fa-calendar-day w-5 text-slate-400 mr-1"></i>
                                        ประกาศเมื่อ: <?= date('d M Y, H:i', strtotime($request['posted_date'])) ?>
                                    </p>
                                </div>
                                <div class="flex-shrink-0 sm:text-right sm:border-l sm:pl-6 border-slate-200/80 w-full sm:w-auto">
                                    <div class="text-2xl font-bold text-green-600 mb-4">
                                        ฿<?= !empty($request['budget']) ? htmlspecialchars($request['budget']) : 'N/A' ?>
                                    </div>
                                    <div class="flex flex-col sm:items-end gap-2">

                                        <?php if ($request['status'] === 'proposed'): ?>
                                            <a href="review_proposal.php?request_id=<?= $request['request_id'] ?>" class="w-full sm:w-auto text-center px-4 py-2 bg-yellow-500 text-white rounded-lg text-sm font-semibold hover:bg-yellow-600">
                                                <i class="fa-solid fa-file-alt mr-1"></i> พิจารณาข้อเสนอ
                                            </a>

                                        <?php elseif ($request['status'] === 'awaiting_confirmation'): ?>
                                            <a href="payment.php?request_id=<?= $request['request_id'] ?>" class="w-full sm:w-auto text-center px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-semibold hover:bg-green-600">
                                                <i class="fa-solid fa-credit-card mr-1"></i> ชำระเงินมัดจำ
                                            </a>
                                        <?php elseif ($request['status'] === 'assigned'): ?>
                                            <a href="../messages.php?to_user=<?= $request['designer_id'] ?>" class="w-full sm:w-auto text-center px-4 py-2 bg-green-500 text-white rounded-lg text-sm font-semibold hover:bg-green-600">
                                                <i class="fa-solid fa-comments mr-1"></i> พูดคุยกับนักออกแบบ
                                            </a>
                                        <?php else: ?>
                                            <a href="../job_detail.php?request_id=<?= $request['request_id'] ?>" class="w-full sm:w-auto text-center px-4 py-2 bg-slate-600 text-white rounded-lg text-sm font-semibold hover:bg-slate-700">
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

</body>

</html>