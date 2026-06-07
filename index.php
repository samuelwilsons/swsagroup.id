<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

// --- DATABASE ENGINE (JSON) ---
$paths = [
    'links' => '../data/links.json',
    'payments' => '../data/payments.json',
    'banks' => '../data/banks.json',
    'settings' => '../data/settings.json',
    'logs' => '../data/logs.json'
];

if (!file_exists('../data')) mkdir('../data', 0777, true);
if (!file_exists('../uploads')) mkdir('../uploads', 0777, true);
foreach ($paths as $f) { if (!file_exists($f)) file_put_contents($f, json_encode([])); }

function db($k) { 
    global $paths; 
    $content = @file_get_contents($paths[$k]);
    $data = json_decode($content, true);
    return is_array($data) ? $data : []; 
}
function save($k, $d) { global $paths; file_put_contents($paths[$k], json_encode($d, JSON_PRETTY_PRINT)); }

// --- CONFIG & AUTH ---
$config = db('settings');
if(empty($config)) { 
    $config = ["username" => "admin", "password" => "MasterP4ssw0rd", "name" => "SWS Administrator", "pic" => "https://ui-avatars.com/api/?name=Admin", "lang" => "id"]; 
    save('settings', $config); 
}

if (isset($_POST['login'])) {
    if ($_POST['user'] === $config['username'] && $_POST['pass'] === $config['password']) {
        $_SESSION['sws_auth'] = true;
        $logs = db('logs');
        $logs[] = ['ip' => $_SERVER['REMOTE_ADDR'], 'date' => date('d M Y'), 'time' => date('H:i:s')];
        save('logs', array_slice($logs, -15));
        header("Location: index.php"); exit;
    } else { $error = "Kredensial Login Tidak Valid."; }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }
$auth = $_SESSION['sws_auth'] ?? false;

if ($auth) {
    $page = $_GET['page'] ?? 'dashboard';

    // --- ACTIONS ---
    // Save Link (Create & Edit)
    if (isset($_POST['save_link'])) {
        $links = db('links');
        $id = $_POST['link_id'] ?: strtoupper(bin2hex(random_bytes(6)));
        $amt = preg_replace('/[^0-9]/', '', $_POST['amount']);
        $links[$id] = [
            'id' => $id, 
            'title' => $_POST['title'], 
            'customer' => $_POST['customer'], 
            'product' => $_POST['product'],
            'amount' => (int)$amt, 
            'notes' => $_POST['notes'], 
            'methods' => $_POST['methods'] ?? [],
            'exp_type' => $_POST['exp_type'], 
            'exp_date' => $_POST['exp_date'], 
            'created_at' => (isset($_POST['link_id']) && isset($links[$id])) ? $links[$id]['created_at'] : date('Y-m-d H:i:s')
        ];
        save('links', $links);
        header("Location: index.php?page=links&msg=success"); exit;
    }

    // Delete Link
    if (isset($_GET['del_link'])) {
        $links = db('links'); 
        unset($links[$_GET['del_link']]);
        save('links', $links); 
        header("Location: index.php?page=links"); exit;
    }

    // Delete Payment Data (NEW)
    if (isset($_GET['del_pay'])) {
        $p = db('payments'); 
        unset($p[$_GET['del_pay']]);
        save('payments', $p); 
        header("Location: index.php?page=payments"); exit;
    }

    // Update Payment Status
    if (isset($_POST['upd_pay_status'])) {
        $p = db('payments');
        if(isset($p[$_POST['pid']])) { $p[$_POST['pid']]['status'] = $_POST['status']; save('payments', $p); }
        header("Location: index.php?page=payments"); exit;
    }

    // Save Profile Settings
    if (isset($_POST['save_settings'])) {
        $config['username'] = $_POST['new_user'];
        if(!empty($_POST['new_pass'])) $config['password'] = $_POST['new_pass'];
        save('settings', $config); header("Location: index.php?page=settings&msg=1"); exit;
    }

    // Update Bank Data
    if (isset($_POST['update_bank'])) {
        $banks = db('banks');
        $bn = $_POST['bank_name'];
        $banks[$bn] = ['active' => isset($_POST['active']), 'owner' => $_POST['owner'], 'acc' => $_POST['acc'] ?? '', 'nmid' => $_POST['nmid'] ?? '', 'qris_img' => $_POST['old_qris'] ?? ''];
        if(!empty($_FILES['qris_img']['name'])){
            $fn = "qris_".time().".png";
            move_uploaded_file($_FILES['qris_img']['tmp_name'], "../uploads/".$fn);
            $banks[$bn]['qris_img'] = $fn;
        }
        save('banks', $banks); header("Location: index.php?page=banks"); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SWSAGroup Pay | Login Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>
    <style>
        :root { --blue: #003399; --gold: #c5a059; --bg: #f8fafc; --sidebar-width: 280px; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: #334155; overflow-x: hidden; }
        
        /* Sidebar Styles */
        #sidebar { 
            width: var(--sidebar-width); height: 100vh; position: fixed; 
            background: #fff; border-right: 1px solid #e2e8f0; z-index: 1050; 
            transition: all 0.3s; 
        }
        #main { margin-left: var(--sidebar-width); padding: 40px; transition: all 0.3s; }
        
        /* Mobile Warning Overlay */
        #mobile-warning {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: #ffffff;
            z-index: 99999;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 20px;
        }
        #mobile-warning i { font-size: 60px; color: var(--blue); margin-bottom: 20px; }
        #mobile-warning h4 { font-weight: 700; color: #1e293b; }
        #mobile-warning p { color: #64748b; max-width: 300px; }

        @media (max-width: 991px) {
            #mobile-warning { display: flex; }
            body { overflow: hidden; } /* Disable scroll on mobile */
            #sidebar, #main, .sidebar-overlay { display: none !important; }
        }

        .nav-link { color: #64748b; padding: 12px 25px; border-left: 4px solid transparent; font-weight: 500; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { background: #f1f5f9; color: var(--blue); border-left-color: var(--blue); }
        .card-pro { border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); background: #fff; }
        .card-header-sws { background: #fff; border-bottom: 2px solid var(--blue); color: var(--blue); font-weight: 700; }
        
        .batik-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            opacity: 0.02; pointer-events: none; 
            background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3z' fill='%23003399'/%3E%3C/svg%3E"); 
        }
        
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 15px; }
        .bg-success-light { background: #dcfce7; color: #166534; }
        .bg-warning-light { background: #fef9c3; color: #854d0e; }
        .bg-danger-light { background: #fee2e2; color: #991b1b; }
        .bg-primary-light { background: #e0f2fe; color: #0369a1; }
    </style>
</head>
<body>
    <!-- Mobile Access Restriction -->
    <div id="mobile-warning">
        <i class="fas fa-desktop"></i>
        <h4>Akses Terbatas</h4>
        <p>Demi menjaga keamanan sistem <b>SWSAGroup Pay</b>, dashboard administrator hanya dapat diakses melalui perangkat <b>Desktop/Laptop</b>.</p>
        <div class="mt-3">
            <small class="text-muted fw-bold">SWSAGroup Security Protocol</small>
        </div>
    </div>

    <div class="batik-overlay"></div>
    <div class="sidebar-overlay" id="overlay" onclick="toggleSidebar()"></div>

<?php if (!$auth): ?>
    <div class="d-flex align-items-center justify-content-center vh-100 p-3">
        <div class="card card-pro p-4 shadow-lg" style="width: 400px; border-top: 6px solid var(--gold);">
            <div class="text-center mb-4">
                <h4 class="fw-bold text-primary mb-0">SWSAGroup Pay</h4>
                <small class="text-muted fw-bold">PAYMENT GATEWAY</small>
            </div>
            <?php if(isset($error)) echo "<div class='alert alert-danger small'>$error</div>"; ?>
            <form method="POST">
                <div class="mb-3"><label class="small fw-bold">USER ID</label><input type="text" name="user" class="form-control" required></div>
                <div class="mb-4"><label class="small fw-bold">PASSWORD</label><input type="password" name="pass" class="form-control" required></div>
                <button type="submit" name="login" class="btn btn-primary w-100 fw-bold py-2">LOGIN</button>
            </form>
        </div>
    </div>
<?php else: ?>

    <nav id="sidebar">
        <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
            <div>
                <h5 class="fw-bold text-primary m-0">SWSAGroup</h5>
                <small class="text-muted fw-bold">Admin Panel</small>
            </div>
            <button class="btn d-lg-none" onclick="toggleSidebar()"><i class="fa fa-times"></i></button>
        </div>
        <div class="nav flex-column mt-3">
            <a href="?page=dashboard" class="nav-link <?= $page=='dashboard'?'active':'' ?>"><i class="fa fa-chart-line me-3"></i>Dashboard</a>
            <a href="?page=links" class="nav-link <?= $page=='links'?'active':'' ?>"><i class="fa fa-link me-3"></i>Link Payment</a>
            <a href="?page=payments" class="nav-link <?= $page=='payments'?'active':'' ?>"><i class="fa fa-exchange-alt me-3"></i>Manage Payment</a>
            <a href="?page=banks" class="nav-link <?= $page=='banks'?'active':'' ?>"><i class="fa fa-university me-3"></i>Manage Bank</a>
            <a href="?page=settings" class="nav-link <?= $page=='settings'?'active':'' ?>"><i class="fa fa-cog me-3"></i>Settings</a>
            <a href="?logout=1" class="nav-link text-danger mt-5"><i class="fa fa-power-off me-3"></i>Logout</a>
        </div>
    </nav>

    <div id="main">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center">
                <h4 class="fw-bold m-0 text-dark"><?= strtoupper($page) ?></h4>
            </div>
            <div class="small text-muted d-none d-md-block">Server Time: <?= date('H:i') ?> WIB</div>
        </div>

        <?php if($page == 'dashboard'): ?>
            <div class="row g-4 mb-4">
                <?php 
                    $links_all = db('links'); 
                    $p_all = db('payments'); 
                    $stat = ['Paid'=>0,'Pending'=>0,'Suspend'=>0]; 
                    foreach($p_all as $v){ if(isset($v['status'])) $stat[$v['status']]++; } 
                ?>
                <div class="col-md-3">
                    <div class="card card-pro p-3 border-0">
                        <div class="stat-icon bg-success-light"><i class="fa fa-check-circle"></i></div>
                        <small class="text-muted fw-bold">SUCCESS</small>
                        <h3 class="fw-bold m-0"><?= $stat['Paid'] ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-pro p-3 border-0">
                        <div class="stat-icon bg-warning-light"><i class="fa fa-clock"></i></div>
                        <small class="text-muted fw-bold">PENDING</small>
                        <h3 class="fw-bold m-0"><?= $stat['Pending'] ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-pro p-3 border-0">
                        <div class="stat-icon bg-danger-light"><i class="fa fa-ban"></i></div>
                        <small class="text-muted fw-bold">SUSPEND</small>
                        <h3 class="fw-bold m-0"><?= $stat['Suspend'] ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card card-pro p-3 border-0">
                        <div class="stat-icon bg-primary-light"><i class="fa fa-link"></i></div>
                        <small class="text-muted fw-bold">TOTAL LINKS</small>
                        <h3 class="fw-bold m-0"><?= count($links_all) ?></h3>
                    </div>
                </div>
            </div>

            <div class="card card-pro">
                <div class="card-header card-header-sws px-4 py-3">NOTIFIKASI LOGIN TERBARU</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr><th class="px-4">IP ADDRESS</th><th>TANGGAL</th><th>JAM</th><th>STATUS</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach(array_reverse(db('logs')) as $log): ?>
                                <tr>
                                    <td class="px-4"><code><?= $log['ip'] ?></code></td>
                                    <td><?= $log['date'] ?></td>
                                    <td><?= $log['time'] ?></td>
                                    <td><span class="badge bg-success-light text-success">Authorized</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif($page == 'links'): ?>
            <div class="d-flex flex-column flex-md-row justify-content-between mb-3 gap-2">
                <input type="text" id="linkFilter" class="form-control w-100 w-md-50" placeholder="Cari Judul / Customer...">
                <button class="btn btn-primary px-4 fw-bold shadow-sm" onclick="showModal()">+ BUAT LINK BARU</button>
            </div>
            <div class="card card-pro overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr><th class="px-4">NOMOR</th><th>JUDUL / CUSTOMER</th><th>NOMINAL</th><th>AKSI</th></tr>
                        </thead>
                        <tbody id="linkBody">
                            <?php foreach(array_reverse(db('links'), true) as $id => $v): ?>
                            <tr>
                                <td class="px-4"><code><?= $id ?></code></td>
                                <td><strong><?= $v['title'] ?></strong><br><small class="text-muted"><?= $v['customer'] ?></small></td>
                                <td class="fw-bold text-primary">Rp <?= number_format($v['amount'], 0, ',', '.') ?></td>
                                <td>
                                    <div class="btn-group">
                                        <button onclick='editLink("<?= $id ?>", <?= json_encode($v) ?>)' class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-edit"></i></button>
                                        <button onclick="copy('<?= $id ?>')" class="btn btn-sm btn-outline-secondary" title="Salin Link"><i class="fa fa-copy"></i></button>
                                        <a href="?page=links&del_link=<?= $id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus link ini?')"><i class="fa fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif($page == 'payments'): ?>
            <div class="d-flex flex-column flex-md-row justify-content-between mb-3 gap-2">
                <input type="text" id="payFilter" class="form-control w-100 w-md-50" placeholder="Cari Nama, Nomor Bayar...">
                <button onclick="exportExcel()" class="btn btn-success fw-bold"><i class="fa fa-file-excel me-2"></i>Export Excel</button>
            </div>
            <div class="card card-pro overflow-hidden">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="payTable">
                        <thead class="bg-light">
                            <tr><th class="px-4">NO. BAYAR</th><th>CUSTOMER</th><th>STATUS</th><th>AKSI</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_reverse(db('payments'), true) as $pid => $v): ?>
                            <tr>
                                <td class="px-4"><code><?= $pid ?></code></td>
                                <td><strong><?= $v['sender'] ?></strong><br><small><?= $v['email'] ?></small></td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="pid" value="<?= $pid ?>">
                                        <select name="status" class="form-select form-select-sm" style="width:130px" onchange="this.form.submit()">
                                            <option value="Pending" <?= ($v['status']??'')=='Pending'?'selected':'' ?>>Pending</option>
                                            <option value="Paid" <?= ($v['status']??'')=='Paid'?'selected':'' ?>>Success</option>
                                            <option value="Error" <?= ($v['status']??'')=='Error'?'selected':'' ?>>Error</option>
                                            <option value="Suspend" <?= ($v['status']??'')=='Suspend'?'selected':'' ?>>Suspend</option>
                                        </select>
                                        <input type="hidden" name="upd_pay_status">
                                    </form>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <button onclick="viewBukti('<?= $v['proof'] ?>')" class="btn btn-sm btn-info text-white"><i class="fa fa-image me-1"></i> CEK</button>
                                        <a href="?page=payments&del_pay=<?= $pid ?>" class="btn btn-sm btn-danger" onclick="return confirm('Hapus data pembayaran ini?')"><i class="fa fa-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif($page == 'banks'): ?>
            <div class="row g-4">
                <?php 
                    $bank_list = ['BCA Qris','BCA Bank Transfer','OVO','Go-Pay Transfer','Go-Pay Qris','Shopee-Pay','DANA','Blu by BCA Digital']; 
                    $db_banks = db('banks'); 
                ?>
                <?php foreach($bank_list as $b): $curr = $db_banks[$b] ?? ['active'=>false,'owner'=>'','acc'=>'','nmid'=>'','qris_img'=>'']; ?>
                <div class="col-md-4">
                    <form class="card card-pro h-100 shadow-sm" method="POST" enctype="multipart/form-data">
                        <div class="card-header card-header-sws d-flex justify-content-between align-items-center">
                            <span class="small"><?= strtoupper($b) ?></span>
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="active" <?= $curr['active']?'checked':'' ?>></div>
                        </div>
                        <div class="card-body">
                            <input type="hidden" name="bank_name" value="<?= $b ?>">
                            <div class="mb-2"><label class="small fw-bold">NAMA PEMILIK</label><input type="text" name="owner" class="form-control form-control-sm" value="<?= $curr['owner'] ?>"></div>
                            <?php if(strpos($b,'Qris')!==false): ?>
                                <div class="mb-2"><label class="small fw-bold">NMID</label><input type="text" name="nmid" class="form-control form-control-sm" value="<?= $curr['nmid'] ?>"></div>
                                <div class="mb-2"><label class="small fw-bold">UPLOAD QRIS</label><input type="file" name="qris_img" class="form-control form-control-sm"></div>
                                <input type="hidden" name="old_qris" value="<?= $curr['qris_img'] ?>">
                            <?php else: ?>
                                <div class="mb-2"><label class="small fw-bold">NO. REKENING</label><input type="text" name="acc" class="form-control form-control-sm" value="<?= $curr['acc'] ?>"></div>
                            <?php endif; ?>
                            <button type="submit" name="update_bank" class="btn btn-primary btn-sm w-100 mt-2 fw-bold">SIMPAN</button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>

        <?php elseif($page == 'settings'): ?>
            <div class="card card-pro p-4" style="max-width: 500px;">
                <form method="POST">
                    <div class="mb-3"><label class="small fw-bold">USER ID</label><input type="text" name="new_user" class="form-control" value="<?= $config['username'] ?>"></div>
                    <div class="mb-3"><label class="small fw-bold">PASSWORD BARU</label><input type="password" name="new_pass" class="form-control" placeholder="Kosongkan jika tidak diganti"></div>
                    <button type="submit" name="save_settings" class="btn btn-primary w-100 fw-bold py-2">UPDATE ACCOUNT</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- MODAL LINK -->
    <div class="modal fade" id="modalLink" tabindex="-1">
        <div class="modal-dialog modal-lg"><form class="modal-content border-0 shadow" method="POST">
            <div class="modal-header border-0"><h5 class="fw-bold" id="mTitle">Generate New Link</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4"><div class="row g-3">
                <input type="hidden" name="link_id" id="mId">
                <div class="col-md-6"><label class="small fw-bold">Judul Pembayaran</label><input type="text" name="title" id="mTi" class="form-control" required placeholder="Cth: Invoice Hosting"></div>
                <div class="col-md-6"><label class="small fw-bold">Nama Customer</label><input type="text" name="customer" id="mCu" class="form-control" required placeholder="Cth: Budi Santoso"></div>
                <div class="col-md-6"><label class="small fw-bold">Nama Produk (Opsional)</label><input type="text" name="product" id="mPr" class="form-control"></div>
                <div class="col-md-6"><label class="small fw-bold">Nominal (Rp)</label><input type="text" id="amountIn" name="amount" class="form-control fw-bold" required></div>
                <div class="col-md-6"><label class="small fw-bold">Expired</label><select name="exp_type" id="mEx" class="form-select" onchange="toggleDate(this.value)"><option value="Unlimited">Unlimited</option><option value="Specific">Specific Date</option></select></div>
                <div id="dateBox" class="col-md-6 d-none"><label class="small fw-bold">Atur Tanggal</label><input type="datetime-local" name="exp_date" id="mEd" class="form-control"></div>
                <div class="col-12"><label class="small fw-bold d-block mb-2">Pilihan Metode</label>
                    <?php 
                    $bank_list_opt = ['BCA Qris','BCA Bank Transfer','OVO','Go-Pay Transfer','Go-Pay Qris','Shopee-Pay','DANA','Blu by BCA Digital'];
                    foreach($bank_list_opt as $b_opt): 
                    ?>
                        <div class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" name="methods[]" value="<?= $b_opt ?>" checked> <label class="small"><?= $b_opt ?></label></div>
                    <?php endforeach; ?>
                </div>
                <div class="col-12"><label class="small fw-bold">Catatan (Opsional)</label><textarea name="notes" id="mNo" class="form-control"></textarea></div>
            </div></div>
            <div class="modal-footer border-0"><button type="submit" name="save_link" class="btn btn-primary w-100 py-3 fw-bold">GENERATE PAYMENT LINK</button></div>
        </form></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
        }
        function toggleDate(v){ document.getElementById('dateBox').classList.toggle('d-none', v==='Unlimited'); }
        function showModal(){ 
            document.getElementById('mId').value=""; 
            document.getElementById('mTitle').innerText="Generate New Link"; 
            document.getElementById('mTi').value=""; document.getElementById('mCu').value=""; 
            document.getElementById('mPr').value=""; 
            document.getElementById('amountIn').value=""; 
            document.getElementById('mNo').value=""; 
            new bootstrap.Modal('#modalLink').show(); 
        }
        function editLink(id, d){ 
            document.getElementById('mId').value=id; 
            document.getElementById('mTitle').innerText="Edit Link: " + id; 
            document.getElementById('mTi').value=d.title; 
            document.getElementById('mCu').value=d.customer; 
            document.getElementById('mPr').value=d.product || ""; 
            document.getElementById('amountIn').value= 'Rp. ' + new Intl.NumberFormat('id-ID').format(d.amount); 
            document.getElementById('mEx').value=d.exp_type; 
            toggleDate(d.exp_type);
            document.getElementById('mEd').value=d.exp_date || ""; 
            document.getElementById('mNo').value=d.notes; 
            new bootstrap.Modal('#modalLink').show(); 
        }
        function copy(id){ 
            const u = window.location.origin+"/pay.php?n="+id; 
            navigator.clipboard.writeText(u); 
            Swal.fire({ icon: 'success', title: 'Salin Berhasil', text: u, timer: 1500, showConfirmButton: false });
        }
        function viewBukti(src){ 
            Swal.fire({ 
                imageUrl: '../uploads/'+src, 
                imageWidth: 400, 
                showConfirmButton: false, 
                showCloseButton: true
            }); 
        }
        function exportExcel(){ let wb = XLSX.utils.table_to_book(document.getElementById("payTable")); XLSX.writeFile(wb, "SWSAGroup_Payments.xlsx"); }
        
        const amIn = document.getElementById('amountIn'); 
        if(amIn) amIn.addEventListener('input', e => { 
            let v = e.target.value.replace(/\D/g, ''); 
            e.target.value = v ? 'Rp. ' + new Intl.NumberFormat('id-ID').format(v) : ''; 
        });

        document.getElementById('linkFilter')?.addEventListener('keyup', function(){ 
            let v = this.value.toLowerCase(); 
            document.querySelectorAll('#linkBody tr').forEach(r => r.style.display = r.innerText.toLowerCase().includes(v) ? '' : 'none'); 
        });
        document.getElementById('payFilter')?.addEventListener('keyup', function(){ 
            let v = this.value.toLowerCase(); 
            document.querySelectorAll('#payTable tbody tr').forEach(r => r.style.display = r.innerText.toLowerCase().includes(v) ? '' : 'none'); 
        });
    </script>
<?php endif; ?>
</body>
</html>
