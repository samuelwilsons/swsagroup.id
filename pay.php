<?php
/**
 * SWSAGroup Pay - Bank-Level Security Implementation
 * Keamanan: CSRF Protection, XSS Filtering, Bot Honeypot, Secure File Upload, Security Headers.
 */

// 1. Security Headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: upgrade-insecure-requests");

session_start();
date_default_timezone_set('Asia/Jakarta');

// 2. Sanitasi Input
$n = isset($_GET['n']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['n']) : '';

// 3. CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function db($file) {
    $path = 'data/' . $file . '.json';
    if(!file_exists($path)) return [];
    $data = json_decode(@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

$links = db('links');
$banks = db('banks');
$pays = db('payments');

// --- 404 LOGIC ---
if (!isset($links[$n]) && !isset($pays[$n])) { 
    http_response_code(404);
    if (file_exists('404.php')) { include '404.php'; } else { echo "404 - Link tidak ditemukan"; }
    exit; 
}

$data = $links[$n] ?? $pays[$n];
$status = $pays[$n]['status'] ?? 'None';

// --- LOGIC: SUBMIT PROOF ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sender_name'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Alert: Invalid Request Token.");
    }
    if (!empty($_POST['user_hp_field'])) {
        die("Bot activity detected.");
    }

    if(isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK){
        $file_tmp = $_FILES['proof']['tmp_name'];
        $file_name = $_FILES['proof']['name'];
        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);
        
        $allowed_ext = ['png', 'jpg', 'jpeg'];
        $allowed_mimes = ['image/jpeg', 'image/png'];

        if(in_array($ext, $allowed_ext) && in_array($mime, $allowed_mimes)){
            if ($_FILES['proof']['size'] <= 500 * 1024 * 1024) {
                $fn = "proof_" . bin2hex(random_bytes(8)) . "_" . time() . "." . $ext;
                
                if(move_uploaded_file($file_tmp, "uploads/".$fn)){
                    $pays[$n] = [
                        'sender' => htmlspecialchars(strip_tags($_POST['sender_name'])), 
                        'email' => filter_var($_POST['email'], FILTER_SANITIZE_EMAIL),
                        'phone' => htmlspecialchars(strip_tags($_POST['phone_number'] ?? '-')), 
                        'amount' => $data['amount'], 
                        'title' => htmlspecialchars($data['title']), 
                        'proof' => $fn, 
                        'status' => 'Pending', 
                        'date' => date('d/m/Y H:i')
                    ];
                    file_put_contents('data/payments.json', json_encode($pays, JSON_PRETTY_PRINT));
                    $_SESSION['last_upload'] = time();
                    header("Location: pay.php?n=$n"); 
                    exit;
                }
            }
        }
    }
}

$expire_time = strtotime($data['created_at'] ?? date('Y-m-d H:i:s')) + (5 * 3600);
$remaining = (isset($data['exp_type']) && $data['exp_type'] == 'Specific') ? strtotime($data['exp_date']) - time() : $expire_time - time();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SWSAGroup Pay | #<?= htmlspecialchars($n) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* CSS ASLI DIPERTAHANKAN 100% */
        body { 
            background: #f8fafc; font-family: 'Plus Jakarta Sans', sans-serif;
            background-image: url("data:image/svg+xml,%3Csvg width='80' height='80' viewBox='0 0 80 80' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 0l40 40L0 80V0zm80 0L40 40l40 40V0z' fill='%23003399' fill-opacity='0.015'/%3E%3C/svg%3E");
            -webkit-user-select: none; user-select: none;
        }
        input, textarea, select { user-select: text !important; }
        .pay-card { max-width: 500px; margin: 30px auto; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; overflow: hidden; background: #fff; position: relative; }
        
        /* Fix Mobile Sempit */
        @media (max-width: 576px) {
            .pay-card { margin: 15px auto; width: 95%; }
            .amount-box h1 { font-size: 1.8rem !important; }
        }

        .mac-bar { background: #f8fafc; padding: 12px 15px; display: flex; align-items: center; border-bottom: 1px solid #f1f5f9; position: relative; }
        .dots { display: flex; gap: 6px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; }
        .dot-red { background: #ff5f56; } .dot-yellow { background: #ffbd2e; } .dot-green { background: #27c93f; }
        .bar-info { position: absolute; left: 50%; transform: translateX(-50%); font-size: 10px; font-weight: 800; color: #94a3b8; letter-spacing: 1px; white-space: nowrap; }
        .lamp { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .lamp-green { background: #22c55e; box-shadow: 0 0 8px #22c55e; }
        .lamp-yellow { background: #facc15; box-shadow: 0 0 8px #facc15; }
        .lamp-red { background: #ef4444; box-shadow: 0 0 8px #ef4444; }
        
        .greeting-modern { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-radius: 16px; padding: 18px; border: 1px solid #bfdbfe; display: flex; align-items: center; gap: 15px; margin-bottom: 25px; }
        .greeting-avatar { width: 45px; height: 45px; background: #fff; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #3b82f6; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.1); }
        
        .timer-box { background: #fffbeb; color: #b45309; padding: 12px; border-radius: 12px; font-weight: 800; text-align: center; margin-bottom: 25px; border: 1px solid #fef3c7; font-size: 1.1rem; }
        .success-box { background: #f0fdf4; color: #166534; padding: 15px; border-radius: 12px; font-weight: 800; text-align: center; margin-bottom: 20px; border: 1px solid #dcfce7; letter-spacing: 1px; }
        .amount-box { background: #0f172a; color: #fff; padding: 30px; border-radius: 18px; text-align: center; margin-bottom: 25px; position: relative; overflow: hidden; }
        .amount-box::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 70%); pointer-events: none; }

        .modern-accordion .accordion-item { border: 1px solid #e2e8f0 !important; border-radius: 14px !important; margin-bottom: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.3s; }
        .modern-accordion .accordion-button { padding: 16px; font-weight: 700; font-size: 0.9rem; color: #334155; background: #fff; }
        .modern-accordion .accordion-button:not(.collapsed) { color: #3b82f6; background: #f8fafc; box-shadow: none; }
        .bank-details { background: #f8fafc; padding: 20px; border-top: 1px solid #f1f5f9; text-align: center; }
        .acc-number { font-size: 1.25rem; font-weight: 800; color: #0f172a; letter-spacing: 1px; margin: 10px 0; }
        
        .modern-form-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; }
        .form-label-custom { font-size: 0.8rem; font-weight: 700; color: #64748b; margin-bottom: 8px; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
        .modern-input { border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; font-size: 0.95rem; font-weight: 600; transition: 0.3s; background: #fcfdfe; }
        .btn-pay { background: #3b82f6; border: none; border-radius: 12px; padding: 14px; font-weight: 800; letter-spacing: 0.5px; transition: 0.3s; box-shadow: 0 10px 20px rgba(59, 130, 246, 0.2); }
        .user-hp-field { display: none !important; }

        /* --- STYLING INVOICE (PROFESSIONAL LOOK) --- */
        #invoice-template { 
            display: none; width: 100%; padding: 40px; background: white; color: #333;
            font-family: 'Arial', sans-serif;
        }
        .inv-header { display: flex; justify-content: space-between; border-bottom: 3px solid #3b82f6; padding-bottom: 20px; margin-bottom: 30px; }
        .inv-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .inv-table th { background: #f8fafc; padding: 12px; border-bottom: 1px solid #dee2e6; text-align: left; color: #64748b; font-size: 12px; }
        .inv-table td { padding: 15px 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .status-badge-print { padding: 5px 15px; border-radius: 5px; font-weight: bold; text-transform: uppercase; font-size: 12px; }
        .paid { background: #dcfce7; color: #166534; }
        .pending { background: #fef3c7; color: #92400e; }
    </style>
</head>
<body oncontextmenu="return false;">

<div class="container px-2">
    <!-- TAMPILAN WEB (SAMA SEPERTI SEBELUMNYA) -->
    <div class="pay-card">
        <div class="mac-bar">
            <div class="dots"><div class="dot dot-red"></div><div class="dot dot-yellow"></div><div class="dot dot-green"></div></div>
            <div class="bar-info"><span id="signalLamp" class="lamp lamp-green"></span> <span id="signalText">ONLINE</span> | <span id="ms">--</span> MS</div>
        </div>

        <div class="card-body p-4">
            <div class="text-center mb-4">
                <h4 class="fw-bold m-0 text-primary" style="letter-spacing: -0.5px;">SWSAGroup Pay</h4>
                <small class="text-muted fw-semibold" style="font-size: 11px;"><?= ($status == 'None') ? 'PAYMENT GATEWAY' : 'OFFICIAL PAYMENT RECEIPT' ?></small>
            </div>

            <?php if ($status == 'None'): ?>
                <!-- VIEW: CHECKOUT -->
                <div class="text-center mb-4">
                    <h5 class="fw-extrabold mb-1" style="color: #0f172a;"><?= htmlspecialchars($data['title']) ?></h5>
                    <span class="badge bg-light text-muted border px-3 py-2 rounded-pill fw-bold" style="font-size: 10px;">ORDER ID #<?= htmlspecialchars($n) ?></span>
                </div>

                <?php if(!empty($data['customer'])): ?>
                    <div class="greeting-modern">
                        <div class="greeting-avatar"><i class="fa-solid fa-user-check"></i></div>
                        <div>
                            <div class="fw-bold text-dark" style="font-size: 0.95rem;">Halo, Kak <?= htmlspecialchars($data['customer']) ?>!</div>
                            <div class="text-muted" style="font-size: 0.8rem; line-height: 1.2;">Mohon selesaikan pembayaran pesanan Anda.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="timer-box" id="timer">--:--:--</div>
                
                <div class="amount-box">
                    <small class="text-uppercase fw-bold opacity-50" style="font-size: 10px; letter-spacing: 2px;">Total Tagihan</small>
                    <h1 class="fw-bolder mb-0 mt-1" style="font-size: 2.2rem;">Rp <?= number_format((int)$data['amount'], 0, ',', '.') ?></h1>
                </div>

                <h6 class="fw-bold mb-3 text-dark d-flex align-items-center" style="font-size: 0.9rem;">
                    <i class="fa-solid fa-credit-card me-2 text-primary"></i> Metode Pembayaran
                </h6>
                
                <div class="accordion modern-accordion mb-4" id="bankList">
                    <?php 
                    $methods = $data['methods'] ?? [];
                    if(empty($methods)) {
                        foreach($banks as $name => $b) { if($b['active']) $methods[] = $name; }
                    }
                    $i=0; foreach($methods as $m): $b=$banks[$m]??null; if(!$b || !$b['active']) continue; $i++; ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#b<?= $i ?>">
                                <i class="fa-solid fa-building-columns me-2 opacity-50"></i> <?= htmlspecialchars($m) ?>
                            </button>
                        </h2>
                        <div id="b<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#bankList">
                            <div class="bank-details">
                                <?php if(isset($b['nmid']) && !empty($b['nmid'])): ?>
                                    <div class="mb-3">
                                        <img src="uploads/<?= htmlspecialchars($b['qris_img']) ?>" class="img-fluid rounded-4" style="max-height: 220px;">
                                    </div>
                                <?php else: ?>
                                    <div class="acc-number"><?= htmlspecialchars($b['acc']) ?></div>
                                <?php endif; ?>
                                <div class="fw-bold text-dark mb-3"><?= htmlspecialchars($b['owner']) ?></div>
                                <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($b['acc']??$b['nmid']) ?>'); Swal.fire({icon:'success',title:'Berhasil',text:'Nomor disalin'})" class="btn btn-sm btn-light border rounded-pill px-3 fw-bold">SALIN NOMOR</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="modern-form-card">
                    <h6 class="fw-bold mb-4 text-dark" style="font-size: 0.9rem;">Konfirmasi Pembayaran</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="text" name="user_hp_field" class="user-hp-field">
                        
                        <div class="mb-3">
                            <label class="form-label-custom">Nama Pengirim</label>
                            <input type="text" name="sender_name" class="form-control modern-input" placeholder="John Doe" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label-custom">Email</label>
                                <input type="email" name="email" class="form-control modern-input" placeholder="mail@mail.com" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label-custom">Nomor WA</label>
                                <input type="text" name="phone_number" class="form-control modern-input" placeholder="0812..." required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label-custom">Bukti Transfer</label>
                            <input type="file" name="proof" class="form-control modern-input" accept="image/*" required>
                        </div>
                        <button type="submit" class="btn btn-pay btn-primary w-100 text-white">KIRIM KONFIRMASI</button>
                    </form>
                </div>

            <?php else: ?>
                <!-- VIEW: RECEIPT WEB -->
                <?php if($status == 'Paid'): ?>
                    <div class="success-box"><i class="fa-solid fa-circle-check me-2"></i> PEMBAYARAN BERHASIL</div>
                <?php else: ?>
                    <div class="badge bg-warning p-3 mb-4 w-100 rounded-4 fw-bold">STATUS: <?= strtoupper(htmlspecialchars($status)) ?></div>
                <?php endif; ?>
                
                <div class="amount-box">
                    <small class="text-uppercase fw-bold opacity-50">Jumlah Dibayarkan</small>
                    <h1 class="fw-bolder m-0 mt-1">Rp <?= number_format((int)$data['amount'], 0, ',', '.') ?></h1>
                </div>

                <div class="text-start mb-4 bg-light p-3 rounded-4 border border-dashed">
                    <table class="table table-borderless table-sm m-0 small">
                        <tr><td class="text-muted fw-bold">ID Transaksi</td><td class="text-end fw-extrabold">#<?= htmlspecialchars($n) ?></td></tr>
                        <tr><td class="text-muted fw-bold">Pengirim</td><td class="text-end"><?= htmlspecialchars($pays[$n]['sender']) ?></td></tr>
                        <tr><td class="text-muted fw-bold">Waktu</td><td class="text-end"><?= htmlspecialchars($pays[$n]['date']) ?></td></tr>
                    </table>
                </div>

                <div class="d-grid gap-2">
                    <button onclick="downloadPDF()" class="btn btn-dark fw-bold py-3 rounded-4 shadow-sm"><i class="fa-solid fa-file-pdf me-2"></i> UNDUH PDF</button>
                    <button onclick="location.reload()" class="btn btn-outline-primary fw-bold py-3 rounded-4">REFRESH STATUS</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- --- INVOICE TEMPLATE UNTUK PDF (DIBUAT SEPERTI STRUK ASLI) --- -->
<div id="invoice-template">
    <div class="inv-header">
        <div>
            <h2 style="color:#3b82f6; margin:0;">SWSAGroup Pay</h2>
            <p style="font-size:12px; color:#64748b; margin:0;">Official Payment Invoice</p>
        </div>
        <div style="text-align:right">
            <h4 style="margin:0;">INVOICE</h4>
            <p style="font-size:12px; color:#64748b; margin:0;">#<?= htmlspecialchars($n) ?></p>
        </div>
    </div>

    <div style="display:flex; justify-content:space-between; margin-bottom:30px;">
        <div style="font-size:13px;">
            <strong style="color:#64748b; text-transform:uppercase; font-size:10px;">Diterbitkan Untuk:</strong><br>
            <strong><?= htmlspecialchars($pays[$n]['sender'] ?? 'Pelanggan') ?></strong><br>
            <?= htmlspecialchars($pays[$n]['email'] ?? '-') ?><br>
            <?= htmlspecialchars($pays[$n]['phone'] ?? '-') ?>
        </div>
        <div style="text-align:right; font-size:13px;">
            <strong style="color:#64748b; text-transform:uppercase; font-size:10px;">Tanggal Transaksi:</strong><br>
            <?= htmlspecialchars($pays[$n]['date'] ?? date('d/m/Y H:i')) ?>
        </div>
    </div>

    <table class="inv-table">
        <thead>
            <tr>
                <th>DESKRIPSI PEMBAYARAN</th>
                <th style="text-align:right;">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($data['title']) ?></strong><br>
                    <span style="font-size:11px; color:#64748b;">Metode: Transfer Bank / QRIS Digital</span>
                </td>
                <td style="text-align:right;"><strong>Rp <?= number_format((int)$data['amount'], 0, ',', '.') ?></strong></td>
            </tr>
            <tr>
                <td style="text-align:right; border:0; padding-top:30px;">SUBTOTAL</td>
                <td style="text-align:right; border:0; padding-top:30px;">Rp <?= number_format((int)$data['amount'], 0, ',', '.') ?></td>
            </tr>
            <tr>
                <td style="text-align:right; border:0; font-size:18px;"><strong>TOTAL AKHIR</strong></td>
                <td style="text-align:right; border:0; font-size:18px; color:#3b82f6;"><strong>Rp <?= number_format((int)$data['amount'], 0, ',', '.') ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top:40px; border-top:1px solid #eee; padding-top:20px; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <span class="status-badge-print <?= ($status == 'Paid') ? 'paid' : 'pending' ?>">
                STATUS: <?= strtoupper($status) ?>
            </span>
        </div>
        <div style="font-size:10px; color:#94a3b8; text-align:right;">
            Invoice ini sah dan diproses secara digital.<br>&copy; <?= date('Y') ?> SWSAGroup Networks.
        </div>
    </div>
</div>

<script>
    // Logic Signal & Timer Sama Persis
    setInterval(() => { 
        const msEl = document.getElementById('ms');
        if(msEl) msEl.innerText = Math.floor(Math.random() * 25) + 10; 
    }, 800);

    <?php if($status == 'None'): ?>
    let left = <?= (int)$remaining ?>;
    const timerInterval = setInterval(() => {
        const timerEl = document.getElementById('timer');
        if(!timerEl) return;
        if(left <= 0) { timerEl.innerText = "EXPIRED"; clearInterval(timerInterval); return; }
        let h = Math.floor(left / 3600), m = Math.floor((left % 3600) / 60), s = left % 60;
        timerEl.innerText = `${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}:${s.toString().padStart(2,'0')}`;
        left--;
    }, 1000);
    <?php endif; ?>

    // FUNGSI PRINT INVOICE BARU
    function downloadPDF() {
        const element = document.getElementById('invoice-template');
        element.style.display = 'block'; // Tampilkan untuk proses render
        
        const opt = {
            margin:       [10, 10],
            filename:     'Invoice_SWSAGROUP_<?= $n ?>.pdf',
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(element).save().then(() => {
            element.style.display = 'none'; // Sembunyikan kembali setelah jadi PDF
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.keyCode == 123 || (e.ctrlKey && e.shiftKey && (e.keyCode == 73 || e.keyCode == 74)) || (e.ctrlKey && e.keyCode == 85)) {
            e.preventDefault();
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

<!--Start of Tawk.to Script-->
<script type="text/javascript">
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
s1.async=true;
s1.src='https://embed.tawk.to/681098c5cba56419020bdf55/1jpf96ah0';
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
<!--End of Tawk.to Script-->

</html>
