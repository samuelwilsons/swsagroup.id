<?php
date_default_timezone_set('Asia/Jakarta');

// --- DATABASE HELPER ---
function db($file) {
    $path = 'data/' . $file . '.json';
    if(!file_exists($path)) return [];
    return json_decode(@file_get_contents($path), true) ?: [];
}

function save($file, $data) {
    file_put_contents('data/' . $file . '.json', json_encode($data, JSON_PRETTY_PRINT));
}

$banks = db('banks');
$pays = db('payments');

// --- LOGIC: PENCARIAN STATUS (MULTIPLE RESULTS) ---
$search_results = null;
$search_query = "";
if (isset($_POST['search_status'])) {
    $search_query = strtolower(trim($_POST['query']));
    $search_results = [];

    foreach ($pays as $id => $v) {
        $clean_id = str_replace(['#', 'trx-', 'TRX-'], '', strtolower($id));
        $clean_query = str_replace(['#', 'trx-', 'TRX-'], '', $search_query);

        $match_name = (isset($v['sender']) && strpos(strtolower($v['sender']), $search_query) !== false);
        $match_email = (isset($v['email']) && strtolower($v['email']) == $search_query);
        $match_id = ($clean_id == $clean_query);

        if ($match_name || $match_email || $match_id) {
            $v['id'] = $id;
            $search_results[] = $v;
        }
    }

    usort($search_results, function($a, $b) {
        $tA = strtotime(str_replace('/', '-', $a['date'] ?? '01/01/2020 00:00'));
        $tB = strtotime(str_replace('/', '-', $b['date'] ?? '01/01/2020 00:00'));
        return $tB - $tA;
    });
}

// --- LOGIC: PEMBAYARAN MANDIRI ---
if (isset($_POST['direct_pay'])) {
    $id = strtoupper(bin2hex(random_bytes(6))); 
    $amt = preg_replace('/[^0-9]/', '', $_POST['amount']);
    $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
    
    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
        $fn = "proof_direct_" . time() . "_" . $id . "." . $ext;
        if (move_uploaded_file($_FILES['proof']['tmp_name'], "uploads/" . $fn)) {
            $pays[$id] = [
                'sender' => $_POST['full_name'],
                'email' => $_POST['email'],
                'amount' => (int)$amt,
                'title' => $_POST['product_name'] ?: 'Pembayaran Mandiri',
                'notes' => $_POST['notes'],
                'method' => $_POST['method'],
                'proof' => $fn,
                'status' => 'Pending',
                'date' => date('d/m/Y H:i')
            ];
            save('payments', $pays);
            header("Location: index.php?success_id=" . $id . "#sect-pay");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SWSAGroup Pay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary: #003399; --accent: #c5a059; --bg: #fdfdfd; }
        html, body { height: 100%; margin: 0; }
        body { 
            display: flex; flex-direction: column; font-family: 'Plus Jakarta Sans', sans-serif; background-color: var(--bg); color: #1e293b; 
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23003399' fill-opacity='0.012' fill-rule='evenodd'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E"); 
            -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none;
        }
        input, textarea, select { user-select: text !important; }
        .hero-compact { padding: 30px 0 50px; background: linear-gradient(135deg, #001a4d 0%, var(--primary) 100%); color: white; border-bottom: 4px solid var(--accent); text-align: center; }
        .portal-wrapper { max-width: 940px; margin: 30px auto; padding: 0 15px; flex: 1 0 auto; width: 100%; }
        .mac-card-ui { background: #ffffff; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.05); overflow: hidden; }
        .mac-bar { background: #f8fafc; padding: 12px 20px; display: flex; align-items: center; border-bottom: 1px solid #edf2f7; }
        .mac-dots { display: flex; gap: 6px; }
        .mac-dot { width: 10px; height: 10px; border-radius: 50%; }
        .dot-1 { background: #ff5f56; } .dot-2 { background: #ffbd2e; } .dot-3 { background: #27c93f; }
        .mac-status { margin-left: auto; font-size: 10px; font-weight: 800; color: #94a3b8; letter-spacing: 1.2px; }
        .nav-scroller { background: #fff; margin: 20px 0; padding: 6px; border-radius: 14px; border: 1px solid #e2e8f0; display: flex; overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; gap: 6px; scrollbar-width: none; }
        .nav-link-item { padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; color: #64748b; text-decoration: none; transition: 0.3s; display: inline-block; flex: 0 0 auto; }
        .nav-link-item.active { background: var(--primary); color: #fff; box-shadow: 0 4px 10px rgba(0, 51, 153, 0.2); }
        .sect-title { font-weight: 800; color: var(--primary); font-size: 1.3rem; margin-bottom: 25px; display: flex; align-items: center; }
        .content-padding { padding: 40px; }
        .form-label-modern { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #64748b; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .form-label-modern i { color: var(--primary); font-size: 14px; }
        .input-modern { border: 1.5px solid #e2e8f0; padding: 12px 16px; border-radius: 12px; font-weight: 600; transition: all 0.3s; background: #f8fafc; }
        .input-modern:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 4px rgba(0, 51, 153, 0.05); outline: none; }
        .upload-zone { border: 2px dashed #cbd5e1; background: #f8fafc; border-radius: 15px; padding: 30px; text-align: center; transition: 0.3s; cursor: pointer; position: relative; }
        .upload-zone:hover { border-color: var(--primary); background: rgba(0, 51, 153, 0.02); }
        .captcha-header { background: var(--primary); color: #fff; padding: 20px; text-align: left; }
        .captcha-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; padding: 4px; background: #fff; }
        .captcha-img-box { width: 100%; aspect-ratio: 1/1; position: relative; cursor: pointer; overflow: hidden; }
        .captcha-img-box img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.2s; }
        .captcha-img-box.selected::after { content: "\f058"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; top: 5px; left: 5px; color: var(--primary); background: #fff; border-radius: 50%; font-size: 18px; }
        .table-modern { border-collapse: separate; border-spacing: 0 8px; }
        .table-modern td { padding: 12px 15px; background: #fff; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .status-pill { font-size: 10px; font-weight: 800; padding: 5px 12px; border-radius: 6px; text-transform: uppercase; }
        .pill-pending { background: #fffbeb; color: #92400e; }
        .pill-paid { background: #f0fdf4; color: #166534; }
        .pill-suspend { background: #fef2f2; color: #991b1b; }
        .footer-accent { background: #000; color: #fff; padding: 40px 0; border-top: 5px solid var(--accent); text-align: center; flex-shrink: 0; }
    </style>
</head>
<body oncontextmenu="return false;">

<header class="hero-compact">
    <div class="container">
        <h1 class="fw-800 mb-1">SWSAGroup Pay</h1>
        <p class="small opacity-75 mb-0">SWSAGroup Payment Gateway</p>
    </div>
</header>

<div class="portal-wrapper">
    <div class="mac-card-ui">
        <div class="mac-bar">
            <div class="mac-dots"><div class="mac-dot dot-1"></div><div class="mac-dot dot-2"></div><div class="mac-dot dot-3"></div></div>
            <div class="mac-status"><span id="load-ms">--</span>ms | ENCRYPTED</div>
        </div>
    </div>

    <nav class="nav-scroller">
        <a href="javascript:void(0)" class="nav-link-item active" id="tab-status" onclick="openSect('status')"><i class="fa fa-history me-2"></i>Status Transaksi</a>
        <a href="javascript:void(0)" class="nav-link-item" id="tab-pay" onclick="openSect('pay')"><i class="fa fa-wallet me-2"></i>Bayar Mandiri</a>
        <a href="javascript:void(0)" class="nav-link-item" id="tab-help" onclick="openSect('help')"><i class="fa fa-headset me-2"></i>Bantuan</a>
    </nav>

    <div class="mac-card-ui">
        <div class="content-padding">
            <!-- SECTION: STATUS -->
            <div id="sect-status">
                <h5 class="sect-title"><i class="fa fa-search me-2 text-accent"></i> Penelusuran Transaksi</h5>
                <form method="POST" class="mb-4">
                    <div class="input-group">
                        <input type="text" name="query" class="form-control shadow-none input-modern" placeholder="Cari Nama, Email, atau ID Transaksi..." value="<?= htmlspecialchars($search_query) ?>" required>
                        <button class="btn btn-primary px-4" type="submit" name="search_status" style="border-radius: 0 12px 12px 0;"><i class="fa fa-search"></i></button>
                    </div>
                </form>

                <?php if ($search_results !== null): ?>
                    <div class="table-responsive">
                        <?php if (empty($search_results)): ?>
                            <div class="alert alert-light border text-center small text-muted p-4">Transaksi tidak ditemukan.</div>
                        <?php else: ?>
                            <div class="mb-2 small fw-bold text-muted">Ditemukan <?= count($search_results) ?> transaksi:</div>
                            <table class="table table-modern align-middle">
                                <thead><tr><th>Ref ID</th><th>Deskripsi</th><th>Status</th><th>Invoice</th></tr></thead>
                                <tbody>
                                    <?php foreach ($search_results as $res): ?>
                                    <tr>
                                        <td><code class="fw-bold text-dark">#<?= $res['id'] ?></code><br><span style="font-size: 9px;"><?= $res['date'] ?></span></td>
                                        <td><span class="small fw-600"><?= $res['title'] ?></span></td>
                                        <td><span class="status-pill <?= ($res['status']=='Paid')?'pill-paid':(($res['status']=='Suspend')?'pill-suspend':'pill-pending') ?>"><?= strtoupper($res['status']) ?></span></td>
                                        <td>
                                            <?php if($res['status'] == 'Paid'): ?>
                                                <a href="pay.php?n=<?= $res['id'] ?>&dl=1" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3" style="font-size: 9px;">CETAK</a>
                                            <?php else: ?>
                                                <i class="fa fa-clock text-muted opacity-50"></i>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECTION: PAY MANDIRI -->
            <div id="sect-pay" class="d-none">
                <h5 class="sect-title"><i class="fa fa-plus-circle me-2 text-accent"></i> Pembayaran Mandiri</h5>
                <form method="POST" enctype="multipart/form-data" class="row g-4" id="mainPayForm">
                    <div class="col-md-6">
                        <label class="form-label-modern"><i class="fa fa-user"></i> Nama Lengkap</label>
                        <input type="text" name="full_name" class="form-control input-modern" placeholder="Contoh: John Doe (Sesuai nama rekening)" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-modern"><i class="fa fa-envelope"></i> Alamat Email</label>
                        <input type="email" name="email" class="form-control input-modern" placeholder="Contoh: user@gmail.com (Untuk notifikasi status)" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-modern"><i class="fa fa-university"></i> Metode Pembayaran</label>
                        <select name="method" class="form-select input-modern" id="bankSelect" required>
                            <option value="">-- Pilih Rekening Tujuan --</option>
                            <?php foreach($banks as $name => $b): if($b['active']): ?><option value="<?= $name ?>"><?= $name ?></option><?php endif; endforeach; ?>
                        </select>
                        <div id="methodDetail" class="mt-3 p-3 border rounded-4 bg-light d-none text-center"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-modern"><i class="fa fa-tag"></i> Nominal Transfer (IDR)</label>
                        <input type="text" id="amountIn" name="amount" class="form-control input-modern fw-800 text-primary" placeholder="Contoh: 150000 (Isi nominal asli yang dikirim)" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label-modern"><i class="fa fa-shopping-bag"></i> Produk / Layanan</label>
                        <input type="text" name="product_name" class="form-control input-modern" placeholder="Contoh: Perpanjang biaya web hosting, Pembayaran Domain, Pembayaran Hosting">
                    </div>
                    <div class="col-12">
                        <label class="form-label-modern"><i class="fa fa-camera"></i> Bukti Pembayaran</label>
                        <div class="upload-zone" onclick="document.getElementById('proofInput').click()">
                            <i class="fa fa-cloud-upload-alt text-primary mb-2" style="font-size: 30px;"></i>
                            <p class="m-0 fw-bold small text-dark">Klik untuk unggah Foto Bukti Transfer</p>
                            <p class="m-0 text-muted" style="font-size: 10px;">Format: JPG, JPEG, PNG (Maks 500MB)</p>
                            <input type="file" name="proof" id="proofInput" class="d-none" accept=".jpg,.jpeg,.png" required onchange="updateFileName(this)">
                            <div id="fileNameDisplay" class="mt-2 fw-bold text-primary small"></div>
                        </div>
                    </div>
                    <div class="col-12 mt-4">
                        <div id="captcha-status-area" class="p-3 border rounded-4 bg-light d-flex align-items-center justify-content-between">
                            <div><i class="fa fa-shield-halved text-muted me-2" id="captcha-icon"></i><span class="small fw-800" id="captcha-text">Verifikasi Captcha</span></div>
                            <button type="button" class="btn btn-dark btn-sm fw-bold px-3 rounded-pill" onclick="initCaptcha()" id="btn-start-captcha">Mulai Verifikasi</button>
                        </div>
                        <input type="hidden" name="captcha_verified" id="captcha_verified" value="0">
                    </div>
                    <div class="col-12 mt-4"><button type="submit" name="direct_pay" id="btnSubmitForm" class="btn btn-primary w-100 fw-800 py-3 shadow rounded-4" disabled>KIRIM KONFIRMASI PEMBAYARAN</button></div>
                </form>
            </div>

            <!-- SECTION: HELP -->
            <div id="sect-help" class="d-none">
                <h5 class="sect-title"><i class="fa fa-info-circle me-2 text-accent"></i> Informasi & Dukungan</h5>
                <div class="accordion accordion-flush mb-5" id="faqAccordion">
                    <?php 
                    $faqs = [
                        "Berapa lama proses verifikasi pembayaran?" => "Proses verifikasi umumnya memakan waktu 5-30 menit selama jam operasional aktif.",
                        "Apa yang menyebabkan status 'Suspend'?" => "Status ini muncul jika nominal transfer tidak sesuai atau bukti transfer tidak valid.",
                        "Bagaimana cara mengunduh invoice?" => "Setelah status PAID, gunakan fitur pencarian status lalu klik tombol CETAK.",
                        "Jam berapa layanan verifikasi beroperasi?" => "Tim kami aktif setiap hari pukul 08:00 - 22:00 WIB.",
                        "Metode pembayaran apa yang didukung?" => "Kami mendukung Transfer Bank Nasional dan E-Wallet via metode QRIS.",
                        "Apakah data saya aman?" => "Tentu, setiap data transaksi dilindungi dengan protokol enkripsi SSL 256-bit.",
                        "Bagaimana jika saya salah mengisi data email?" => "Mohon segera lapor ke admin WhatsApp Support dengan melampirkan ID transaksi Anda.",
                        "Kenapa riwayat transaksi saya tidak muncul?" => "Sistem hanya menampilkan data yang sesuai dengan kata kunci (Email/Nama/ID) yang dimasukkan.",
                        "Apakah ada biaya administrasi?" => "Biaya admin bervariasi tergantung pada metode pembayaran yang Anda pilih.",
                        "Berapa batas maksimal upload bukti bayar?" => "Sistem menerima file gambar dengan ukuran maksimal hingga 500MB.",
                        "Apakah pembayaran QRIS bisa otomatis?" => "Beberapa layanan QRIS kami sudah mendukung deteksi instan oleh sistem verifikasi.",
                        "Dapatkah transaksi dibatalkan?" => "Transaksi yang sudah dalam antrean verifikasi tidak dapat dibatalkan.",
                        "Kenapa Captcha tidak muncul?" => "Pastikan koneksi internet stabil dan browser Anda tidak memblokir skrip JavaScript.",
                        "Apakah bukti pembayaran harus fisik?" => "Tidak, Anda dapat melampirkan screenshot mutasi rekening atau bukti m-banking.",
                        "Ke mana saya harus melapor kendala sistem?" => "Anda dapat menghubungi tim teknis melalui tombol WhatsApp di bawah menu bantuan.",
                        "Berapa lama riwayat transaksi disimpan?" => "Riwayat disimpan hingga 30 hari terakhir sebelum dibersihkan oleh sistem."
                    ];
                    $j=0; foreach($faqs as $q => $a): $j++; ?>
                    <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed small fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $j ?>"><?= $q ?></button></h2>
                    <div id="faq<?= $j ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion"><div class="accordion-body small text-muted"><?= $a ?></div></div></div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-4 p-4 rounded-4 bg-light border border-2 text-center shadow-sm">
                    <h6 class="fw-800 mb-3 text-primary">Kontak Resmi</h6>
                    <div class="d-flex flex-column gap-3">
                        <a href="https://wa.me/6285183156235" target="_blank" class="btn btn-success btn-sm fw-bold py-3 rounded-pill shadow-sm"><i class="fab fa-whatsapp me-2"></i>WhatsApp Support</a>
                        <a href="mailto:care@swsagroup.id" class="btn btn-outline-primary btn-sm fw-bold py-3 rounded-pill">Email Support</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="footer-accent text-center"><div class="container"><p class="small mb-0 fw-bold">© 2025 SWSAGroup Pay, All right reserved</p></div></footer>

<!-- Captcha Modal Modern -->
<div class="modal fade" id="captchaModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 4px; width: 350px; margin: auto;">
            <div class="captcha-header">
                <p class="m-0 small opacity-75 fw-bold">Pilih semua gambar yang bertema:</p>
                <h4 class="m-0 fw-800" id="captcha-target-text">TEMA</h4>
            </div>
            <div class="modal-body p-0"><div class="captcha-grid" id="grid"></div></div>
            <div class="modal-footer d-flex justify-content-between p-2">
                <div class="small text-muted"><i class="fa fa-info-circle me-1"></i>Tahap <span id="captcha-step">1</span>/2</div>
                <button type="button" class="btn btn-primary btn-sm fw-bold px-4" onclick="verifyCaptchaSelection()">VERIFIKASI</button>
            </div>
        </div>
    </div>
</div>

<script>
    // --- REALTIME PING FUNCTION ---
    async function measurePing() {
        const start = performance.now();
        try {
            // Mengukur latensi nyata ke pay.swsagroup.id menggunakan fetch HEAD
            await fetch('https://pay.swsagroup.id/favicon.ico?cache=' + start, { 
                method: 'HEAD', 
                mode: 'no-cors',
                cache: 'no-store' 
            });
            const end = performance.now();
            const ping = Math.round(end - start);
            document.getElementById('load-ms').innerText = ping;
        } catch (e) {
            // Fallback jika terjadi error koneksi
            document.getElementById('load-ms').innerText = Math.floor(Math.random() * 10) + 10;
        }
    }
    // Jalankan setiap 3 detik
    measurePing();
    setInterval(measurePing, 3000);

    // --- IDLE TIMEOUT 5 MENIT ---
    let idleSecondsCounter = 0;
    document.onmousemove = document.onkeypress = document.ontouchstart = function() { idleSecondsCounter = 0; };
    setInterval(function() {
        if (!document.getElementById('sect-pay').classList.contains('d-none')) {
            idleSecondsCounter++;
            if (idleSecondsCounter >= 300) { location.reload(); }
        }
    }, 1000);

    function updateFileName(input) {
        const display = document.getElementById('fileNameDisplay');
        display.innerText = input.files.length > 0 ? "File: " + input.files[0].name : "";
    }

    const bankData = <?= json_encode($banks) ?>;
    document.getElementById('bankSelect')?.addEventListener('change', function() {
        const n = this.value; const b = document.getElementById('methodDetail');
        if(n && bankData[n]){
            const d = bankData[n]; let h = `<div class='small text-muted mb-1 text-uppercase fw-bold' style='font-size:9px'>Tujuan Transfer:</div>`;
            if(d.nmid) { 
                h += `<h5 class='fw-800 text-primary mb-1'>NMID: <span id='cN'>${d.nmid}</span></h5>`; 
                if(d.qris_img) {
                    h += `<img src='uploads/${d.qris_img}' class='img-fluid rounded-4 mt-2 mb-2 shadow-sm' style='max-height:160px'><br>`;
                    h += `<a href='uploads/${d.qris_img}' download='QRIS_SWSAGroup.png' class='btn btn-primary btn-sm fw-bold px-4 rounded-pill mt-1'><i class='fa fa-download me-2'></i>SIMPAN QRIS</a>`;
                }
            } else { 
                h += `<h5 class='fw-800 text-primary mb-1'>No. Rek: <span id='cA'>${d.acc}</span> <button type='button' class='btn btn-light btn-sm border ms-2' onclick="cT('cA')">Salin</button></h5>`; 
            }
            h += `<div class='fw-bold small text-dark mt-1'>A/N: ${d.owner}</div>`; b.innerHTML = h; b.classList.remove('d-none');
        } else { b.classList.add('d-none'); }
    });

    function cT(id) { navigator.clipboard.writeText(document.getElementById(id).innerText); Swal.fire({ icon: 'success', title: 'Salin Berhasil', timer: 1000, showConfirmButton: false }); }

    // --- CAPTCHA PRO v4 GRID ---
    let step = 1, currentTarget = "", selectedIndices = [];
    const pool = [
        { url: 'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=150', tag: 'Bus' },
        { url: 'https://images.unsplash.com/photo-1541888946425-d81bb19240f5?w=150', tag: 'Jembatan' },
        { url: 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=150', tag: 'Kopi' },
        { url: 'https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?w=150', tag: 'Gunung' },
        { url: 'https://images.unsplash.com/photo-1517841905240-472988babdf9?w=150', tag: 'Hewan' },
        { url: 'https://images.unsplash.com/photo-1481349518771-20055b2a7b24?w=150', tag: 'Buah' },
        { url: 'https://images.unsplash.com/photo-1519389950473-47ba0277781c?w=150', tag: 'Laptop' },
        { url: 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?w=150', tag: 'Mobil' },
        { url: 'https://images.unsplash.com/photo-1470770841072-f978cf4d019e?w=150', tag: 'Pemandangan' },
        { url: 'https://images.unsplash.com/photo-1513542789411-b6a5d4f31634?w=150', tag: 'Mainan' },
        { url: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=150', tag: 'Orang' },
        { url: 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=150', tag: 'Jam' },
        { url: 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?w=150', tag: 'Toko' },
        { url: 'https://images.unsplash.com/photo-1490730141103-6cac27aaab94?w=150', tag: 'Udara' },
        { url: 'https://images.unsplash.com/photo-1501785888041-af3ef285b470?w=150', tag: 'Air' }
    ];

    function initCaptcha() { step = 1; loadCaptcha(); new bootstrap.Modal(document.getElementById('captchaModal')).show(); }

    function loadCaptcha() {
        selectedIndices = []; document.getElementById('captcha-step').innerText = step;
        const grid = document.getElementById('grid'); grid.innerHTML = "";
        const uniqueTags = [...new Set(pool.map(item => item.tag))];
        currentTarget = uniqueTags[Math.floor(Math.random() * uniqueTags.length)];
        document.getElementById('captcha-target-text').innerText = currentTarget;
        let displayList = [...pool].sort(() => 0.5 - Math.random()).slice(0, 9);
        if(!displayList.some(i => i.tag === currentTarget)){
            const t = pool.find(i => i.tag === currentTarget); displayList[0] = t;
            displayList = displayList.sort(() => 0.5 - Math.random());
        }
        displayList.forEach((item, idx) => {
            const div = document.createElement('div'); div.className = 'captcha-img-box';
            div.innerHTML = `<img src="${item.url}" data-tag="${item.tag}">`;
            div.onclick = () => {
                div.classList.toggle('selected');
                const i = selectedIndices.indexOf(idx);
                if(i > -1) selectedIndices.splice(i, 1); else selectedIndices.push(idx);
            };
            grid.appendChild(div);
        });
    }

    function verifyCaptchaSelection() {
        const gridItems = document.querySelectorAll('.captcha-img-box img');
        let isCorrect = true, foundAny = false;
        gridItems.forEach((img, idx) => {
            const tag = img.getAttribute('data-tag');
            const isSelected = selectedIndices.includes(idx);
            if(tag === currentTarget) { foundAny = true; if(!isSelected) isCorrect = false; }
            else { if(isSelected) isCorrect = false; }
        });
        if(isCorrect && foundAny) {
            if(step < 2) { step++; loadCaptcha(); } 
            else {
                bootstrap.Modal.getInstance(document.getElementById('captchaModal')).hide();
                document.getElementById('captcha_verified').value = "1"; document.getElementById('btnSubmitForm').disabled = false;
                document.getElementById('captcha-status-area').className = "p-3 border rounded-4 bg-success-subtle d-flex align-items-center justify-content-between";
                document.getElementById('captcha-text').innerText = "Verifikasi Berhasil"; document.getElementById('btn-start-captcha').style.display = "none";
            }
        } else { Swal.fire({ title: 'Gagal', text: 'Identifikasi tidak akurat.', icon: 'error', timer: 1500 }); loadCaptcha(); }
    }

    function resetCaptcha() { step = 1; selectedIndices = []; }
    function openSect(t) {
        document.querySelectorAll('.nav-link-item').forEach(el => el.classList.remove('active'));
        document.getElementById('tab-' + t).classList.add('active');
        document.getElementById('sect-status').classList.add('d-none'); document.getElementById('sect-pay').classList.add('d-none'); document.getElementById('sect-help').classList.add('d-none');
        document.getElementById('sect-' + t).classList.remove('d-none');
        if(t === 'pay') idleSecondsCounter = 0;
    }
    const am = document.getElementById('amountIn');
    if(am) am.addEventListener('input', e => { 
        let v = e.target.value.replace(/\D/g, ''); 
        e.target.value = v ? 'Rp. ' + new Intl.NumberFormat('id-ID').format(v) : ''; 
    });

    document.addEventListener('keydown', function(e) {
        if (e.keyCode == 123 || (e.ctrlKey && e.shiftKey && (e.keyCode == 73 || e.keyCode == 74)) || (e.ctrlKey && (e.keyCode == 85 || e.keyCode == 83 || e.keyCode == 65))) {
            e.preventDefault(); return false;
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
