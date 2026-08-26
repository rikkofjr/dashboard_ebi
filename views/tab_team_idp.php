<?php
defined('MOODLE_INTERNAL') || die();

global $DB, $USER, $CFG;

// 1. INTEGRASI FILE LIB DARI LOCAL_MYIDPEBI & SETTINGS DASHBOARD
$myidp_lib = $CFG->dirroot . '/local/myidpebi/lib.php';
if (file_exists($myidp_lib)) {
    require_once($myidp_lib);
}

// Ambil Konfigurasi Profile Field & Identitas dari Settings Dashboard
$field_jabatan = get_config('local_dashboard_ebi', 'field_level_jabatan');
$field_jabatan = !empty($field_jabatan) ? trim($field_jabatan) : 'level_jabatan';

$field_atasan  = get_config('local_dashboard_ebi', 'field_atasan_langsung');
$field_atasan  = !empty($field_atasan) ? trim($field_atasan) : 'atasan_langsung';

$manager_key   = get_config('local_dashboard_ebi', 'manager_key_type');
$manager_key   = !empty($manager_key) ? trim($manager_key) : 'username';

$user_key_value = isset($USER->$manager_key) ? $USER->$manager_key : $USER->username;

// 2. DAPATKAN SEMUA BAWAHAN (MENGGUNAKAN FUNGSI REKURSIF HIRARKI)
if (!function_exists('get_semua_bawahan_data_limited')) {
    function get_semua_bawahan_data_limited($atasan_key_value, $field_atasan, $field_jabatan, $manager_key, $current_depth = 2, $max_depth = 3) {
        global $DB;
        $results = [];

        if ($current_depth > $max_depth || empty($atasan_key_value)) {
            return $results;
        }

        // Query disesuaikan dengan LEFT JOIN ke user atasan untuk mengambil firstname & lastname atasan
        $sql = "SELECT u.id, u.username, u.email, u.idnumber, u.firstname, u.lastname, 
                       COALESCE(uid_jab.data, 'unassigned') AS user_target_field, 
                       u_mgr.firstname AS manager_firstname,
                       u_mgr.lastname AS manager_lastname,
                       ? AS manager_key_val
                  FROM {user} u
                  JOIN {user_info_data} uid ON uid.userid = u.id
                  JOIN {user_info_field} uif ON uif.id = uid.fieldid AND uif.shortname = ?
             LEFT JOIN {user_info_field} uif_jab ON uif_jab.shortname = ?
             LEFT JOIN {user_info_data} uid_jab ON uid_jab.userid = u.id AND uid_jab.fieldid = uif_jab.id
             LEFT JOIN {user} u_mgr ON LOWER(TRIM(u_mgr.{$manager_key})) = LOWER(TRIM(uid.data)) AND u_mgr.deleted = 0
                 WHERE LOWER(TRIM(uid.data)) = ? 
                   AND u.deleted = 0 
                   AND u.suspended = 0";

        $bawahan = $DB->get_records_sql($sql, [
            $atasan_key_value, 
            $field_atasan, 
            $field_jabatan, 
            strtolower(trim($atasan_key_value))
        ]);

        foreach ($bawahan as $b) {
            $b->level_depth = $current_depth;
            $results[$b->id] = $b;

            if ($current_depth < $max_depth) {
                $next_key_val = isset($b->$manager_key) ? $b->$manager_key : $b->username;
                $sub_bawahan = get_semua_bawahan_data_limited($next_key_val, $field_atasan, $field_jabatan, $manager_key, $current_depth + 1, $max_depth);
                
                foreach ($sub_bawahan as $sub_id => $sub_user) {
                    if (!isset($results[$sub_id])) {
                        $results[$sub_id] = $sub_user;
                    }
                }
            }
        }

        return $results;
    }
}

$team_members = get_semua_bawahan_data_limited($user_key_value, $field_atasan, $field_jabatan, $manager_key, 2, 3);
$team_user_ids = array_keys($team_members);

// 3. DAFTAR TAHUN IDP UNTUK DROPDOWN FILTER TIM
$user_idp_years = [];
if (!empty($team_user_ids)) {
    list($in_sql_users, $in_params_users) = $DB->get_in_or_equal($team_user_ids);
    $sql_years = "SELECT DISTINCT FROM_UNIXTIME(mulai_date, '%Y') AS idp_year 
                    FROM {local_myidpebi} 
                   WHERE userid $in_sql_users 
                ORDER BY idp_year DESC";
    $user_idp_years = $DB->get_fieldset_sql($sql_years, $in_params_users);
}

$current_year_default = date('Y');
$selected_year = optional_param('filter_team_idp_year', in_array($current_year_default, $user_idp_years) ? $current_year_default : 'all', PARAM_TEXT);

// 4. OLAH DATA AGREGASI & INDIKATOR TIM
$team_report_data = [];
$total_team_jp_rencana = 0;
$total_team_jp_realisasi = 0;

$status_counts = [0 => 0, 1 => 0, 2 => 0]; // 0: Pending, 1: Process, 2: Verified
$cdp_distribution = [];

// Variabel Segmentasi Kesiapan Tim
$segmentation_counts = [
    'completed' => 0, // 100%+
    'ontrack'   => 0, // 50-99%
    'lagging'   => 0, // 1-49%
    'noact'     => 0  // 0% / Belum ada JP
];

$sum_team_self = 0;
$sum_team_atasan = 0;
$count_team_self = 0;
$count_team_atasan = 0;

if (!empty($team_members)) {
    foreach ($team_members as $member) {
        $member_fullname = trim($member->firstname . ' ' . $member->lastname);
        $member_jabatan  = !empty($member->user_target_field) ? strtoupper(trim($member->user_target_field)) : 'UNASSIGNED';
        
        // Ambil nama Firstname & Lastname Atasan (dengan Fallback ke manager_key_val jika nama di master user kosong)
        $manager_fullname = trim($member->manager_firstname . ' ' . $member->manager_lastname);
        if (empty($manager_fullname)) {
            $manager_fullname = !empty($member->manager_key_val) ? $member->manager_key_val : '-';
        }

        // Query IDP per Anggota
        $where_clause = "userid = ?";
        $params = [$member->id];

        if ($selected_year !== 'all') {
            $where_clause .= " AND FROM_UNIXTIME(mulai_date, '%Y') = ?";
            $params[] = $selected_year;
        }

        $idp_records = $DB->get_records_select('local_myidpebi', $where_clause, $params, 'id DESC');

        $user_jp_rencana = 0;
        $user_jp_realisasi = 0;
        $user_latest_status = null;

        if (!empty($idp_records)) {
            $idp_ids = array_keys($idp_records);
            
            // Status IDP Terbaru
            $first_idp = reset($idp_records);
            $user_latest_status = $first_idp->status;
            
            if (isset($status_counts[$user_latest_status])) {
                $status_counts[$user_latest_status]++;
            }

            // Hitung Skor
            foreach ($idp_records as $idp_item) {
                if ($idp_item->skor_efektivitas > 0) {
                    $sum_team_self += $idp_item->skor_efektivitas;
                    $count_team_self++;
                }
                if ($idp_item->skor_atasan > 0) {
                    $sum_team_atasan += $idp_item->skor_atasan;
                    $count_team_atasan++;
                }
            }

            // Tarik Aktivitas untuk JP
            list($in_act_sql, $in_act_params) = $DB->get_in_or_equal($idp_ids);
            $sql_act = "SELECT a.*, m.tipe_aktivitas_cdp 
                          FROM {local_myidpebi_act} a
                     LEFT JOIN {local_myidpebi_learning_activity} m ON m.id = CAST(a.learning_activity AS SIGNED)
                         WHERE a.idp_id $in_act_sql AND a.deleted = 0";
            
            $acts = $DB->get_records_sql($sql_act, $in_act_params);

            foreach ($acts as $act) {
                $jp_rec = (int)$act->jumlah_jp_perencanaan;
                $jp_rel = (int)$act->jumlah_jp_realisasi;

                $user_jp_rencana   += $jp_rec;
                $user_jp_realisasi += $jp_rel;

                $cdp_type = !empty($act->tipe_aktivitas_cdp) ? $act->tipe_aktivitas_cdp : 'Lainnya';
                if (!isset($cdp_distribution[$cdp_type])) {
                    $cdp_distribution[$cdp_type] = 0;
                }
                $cdp_distribution[$cdp_type] += $jp_rel;
            }
        }

        $total_team_jp_rencana   += $user_jp_rencana;
        $total_team_jp_realisasi += $user_jp_realisasi;

        $user_compliance_pct = $user_jp_rencana > 0 ? round(($user_jp_realisasi / $user_jp_rencana) * 100, 1) : 0;

        // Hitung Distribusi Segmentasi Kesiapan Tim
        if ($user_jp_rencana == 0 || $user_jp_realisasi == 0) {
            $segmentation_counts['noact']++;
        } else if ($user_compliance_pct >= 100) {
            $segmentation_counts['completed']++;
        } else if ($user_compliance_pct >= 50) {
            $segmentation_counts['ontrack']++;
        } else {
            $segmentation_counts['lagging']++;
        }

        $team_report_data[] = [
            'userid'         => $member->id,
            'fullname'       => $member_fullname,
            'username'       => $member->username,
            'jabatan'        => $member_jabatan,
            'manager_name'   => $manager_fullname,
            'status'         => $user_latest_status,
            'jp_rencana'     => $user_jp_rencana,
            'jp_realisasi'   => $user_jp_realisasi,
            'compliance_pct' => $user_compliance_pct
        ];
    }
}

// Statistik Keseluruhan Tim
$team_jp_pct = $total_team_jp_rencana > 0 ? round(($total_team_jp_realisasi / $total_team_jp_rencana) * 100, 1) : 0;
$avg_team_self   = $count_team_self > 0 ? round($sum_team_self / $count_team_self, 1) : 0;
$avg_team_atasan = $count_team_atasan > 0 ? round($sum_team_atasan / $count_team_atasan, 1) : 0;

// URL Menuju Panel Eksekusi Manage.php
$manage_idp_url = new moodle_url('/local/myidpebi/manage.php');
?>

<div class="container-fluid p-0 mb-5">
    
    <!-- BAR TOP HEADER: FILTER & BUTTON REDIRECT KE MANAGE.PHP -->
    <div class="card border-0 shadow-sm rounded-lg p-3 mb-4 bg-light">
        <form method="get" action="" class="form-inline justify-content-between">
            <input type="hidden" name="tab" value="team_idp">
            
            <div class="d-flex align-items-center">
                <label class="font-weight-bold mr-2 text-dark small">
                    <i class="fa fa-calendar mr-1"></i> Periode IDP Tim:
                </label>
                <select name="filter_team_idp_year" class="form-control form-control-sm mr-2" onchange="this.form.submit();">
                    <option value="all" <?php echo ($selected_year === 'all') ? 'selected' : ''; ?>>-- Akumulasi Semua Tahun --</option>
                    <?php foreach ($user_idp_years as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo ($selected_year == $yr) ? 'selected' : ''; ?>>
                            Tahun <?php echo $yr; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- TOMBOL DIRECT LINK KE LOCAL/MYIDPEBI/MANAGE.PHP -->
            <div>
                <a href="<?php echo $manage_idp_url; ?>" class="btn btn-primary btn-sm font-weight-bold shadow-sm" target="_blank">
                    <i class="fa fa-tasks mr-1"></i> Kelola & Approval IDP Tim <i class="fa fa-external-link ml-1"></i>
                </a>
            </div>
        </form>
    </div>

    <?php if (empty($team_report_data)): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-lg p-4">
            <i class="fa fa-info-circle mr-1"></i> Tidak ada anggota tim / bawahan yang terdeteksi.
        </div>
    <?php else: ?>

        <!-- ROW 1: TEAM METRIC WIDGET CARDS -->
        <div class="row mb-4">
            <!-- Widget 1: Progress Realisasi JP Tim -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Total Jam Pelajaran Tim (JP)</h6>
                    <small class="text-muted d-block mb-3">Realisasi vs Target Perencanaan Tim</small>
                    
                    <div class="d-flex justify-content-between align-items-end mb-1">
                        <span class="h3 font-weight-bold text-primary mb-0">
                            <?php echo $total_team_jp_realisasi; ?> <small class="text-muted h6">/ <?php echo $total_team_jp_rencana; ?> JP</small>
                        </span>
                        <span class="font-weight-bold text-success"><?php echo $team_jp_pct; ?>%</span>
                    </div>
                    
                    <div class="progress rounded-pill" style="height: 10px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo min(100, $team_jp_pct); ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Widget 2: Status Funnel IDP Tim -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Status Pengajuan IDP Tim</h6>
                    <small class="text-muted d-block mb-2">Jumlah Status IDP Bawahan</small>

                    <div class="d-flex justify-content-around align-items-center my-auto flex-wrap">
                        <?php 
                        // Melakukan perulangan dinamis sesuai status yang tercatat
                        foreach ($status_counts as $status_code => $count): 
                            // Memanggil metadata status resmi dari lib.php
                            $status_info = local_myidpebi_get_status_info($status_code);
                            
                            // Ekstraksi warna teks berdasarkan badge class bawaan (e.g., badge-warning -> text-warning)
                            $text_color_class = str_replace('badge-', 'text-', $status_info->class);
                        ?>
                            <div class="text-center px-1 my-1">
                                <span class="h4 font-weight-bold <?php echo $text_color_class; ?> mb-0">
                                    <?php echo $count; ?>
                                </span>
                                <small class="text-muted d-block" style="font-size:0.7rem;">
                                    <?php echo htmlspecialchars($status_info->text); ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Widget 3: Rata-rata Efektivitas Tim -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Rata-rata Skor Efektivitas Tim</h6>
                    <small class="text-muted d-block mb-2">Penilaian Mandiri vs Penilaian Atasan</small>

                    <div class="d-flex justify-content-around align-items-center mt-2">
                        <div class="text-center">
                            <span class="h4 font-weight-bold text-info mb-0"><?php echo number_format($avg_team_self, 1); ?>%</span>
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Self Assessment</small>
                        </div>
                        <div class="text-center">
                            <span class="h4 font-weight-bold text-warning mb-0"><?php echo number_format($avg_team_atasan, 1); ?>%</span>
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Penilaian Atasan</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 2: VISUAL ANALYTICS CHART TIM -->
        <div class="row mb-4">
            <!-- Chart 1: Segmentasi Kesiapan Tim -->
            <div class="col-md-7 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">JP Realisasi</h6>
                    <small class="text-muted d-block mb-3">Pencapaian JP bawahan</small>

                    <div style="height: 220px;">
                        <canvas id="chartTeamSegment"></canvas>
                    </div>
                </div>
            </div>

            <!-- Chart 2: Sebaran Tipe Aktivitas CDP Tim -->
            <div class="col-md-5 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Komposisi Metode CDP Tim</h6>
                    <small class="text-muted d-block mb-2">Sebaran Tipe Pembelajaran (JP)</small>

                    <div style="height: 220px;" class="d-flex align-items-center justify-content-center">
                        <?php if (empty($cdp_distribution)): ?>
                            <small class="text-muted italic">Belum ada realisasi JP.</small>
                        <?php else: ?>
                            <canvas id="chartTeamCdpType"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 3: TABEL OVERVIEW MONITORING TIM BAWAHAN -->
        <div class="card border-0 shadow-sm rounded-lg">
            <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fa fa-users text-primary mr-2"></i> Ringkasan Progress IDP Anggota Tim
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="thead-light">
                            <tr>
                                <th>Nama Karyawan</th>
                                <th>Level Jabatan</th>
                                <th>Nama Atasan Langsung</th>
                                <th class="text-center">Progress JP</th>
                                <th class="text-center">Persentase</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_report_data as $row): ?>
                                <tr>
                                    <td>
                                        <strong class="text-dark d-block"><?php echo htmlspecialchars($row['fullname']); ?></strong>
                                        <small class="text-muted">@<?php echo htmlspecialchars($row['username']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-light border p-1"><?php echo htmlspecialchars($row['jabatan']); ?></span>
                                    </td>
                                    <td>
                                        <strong class="text-dark d-block"><?php echo htmlspecialchars($row['manager_name']); ?></strong>
                                    </td>
                                    <td class="text-center font-weight-bold">
                                        <?php echo $row['jp_realisasi']; ?> / <?php echo $row['jp_rencana']; ?> JP
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $p_class = $row['compliance_pct'] >= 100 ? 'badge-success' : ($row['compliance_pct'] >= 50 ? 'badge-warning text-dark' : 'badge-danger');
                                        ?>
                                        <span class="badge <?php echo $p_class; ?> p-2" style="font-size: 0.85rem;">
                                            <?php echo $row['compliance_pct']; ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- SCRIPT CHART.JS UNTUK DASHBOARD TIM -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Chart Bar Segmentasi Kesiapan Tim
    var ctxSegment = document.getElementById('chartTeamSegment').getContext('2d');
    new Chart(ctxSegment, {
        type: 'bar',
        data: {
            labels: ['Tuntas (100%+)', 'On Track (50-99%)', 'Lagging (< 50%)', 'Belum Ada JP (0%)'],
            datasets: [{
                label: 'Jumlah Karyawan',
                data: [
                    <?php echo $segmentation_counts['completed']; ?>,
                    <?php echo $segmentation_counts['ontrack']; ?>,
                    <?php echo $segmentation_counts['lagging']; ?>,
                    <?php echo $segmentation_counts['noact']; ?>
                ],
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'],
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // 2. Chart Doughnut Sebaran Metode CDP Tim
    <?php if (!empty($cdp_distribution)): ?>
    var ctxCdp = document.getElementById('chartTeamCdpType').getContext('2d');
    new Chart(ctxCdp, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_keys($cdp_distribution)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_values($cdp_distribution)); ?>,
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#007bff', '#6c757d'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
    <?php endif; ?>
});
</script>