<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

if (file_exists($CFG->dirroot . '/local/myidpebi/lib.php')) {
    require_once($CFG->dirroot . '/local/myidpebi/lib.php');
}

// 1. Proteksi Akses & Konteks Halaman
$context = context_system::instance();
require_login();
require_capability('local/dashboard_ebi:manage_matrix', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/dashboard_ebi/admin/idp_analytics.php'));
$PAGE->set_title('Dashboard Analitik IDP - Admin & HR');
$PAGE->set_heading('Dashboard Analitik IDP Perusahaan');
$PAGE->set_pagelayout('report');

// 2. Ambil Setting Dynamic Profile Field Direktorat
$dir_field_shortname = get_config('local_dashboard_ebi', 'field_direktorat');
if (empty($dir_field_shortname)) {
    $dir_field_shortname = 'direktorat';
}

// 3. Tangkap Input Filter Global
$selected_year = optional_param('year', date('Y'), PARAM_INT);
$selected_dir  = optional_param('dir', '', PARAM_TEXT);

global $DB, $OUTPUT;

// 4. Opsi Dropdown Filter Direktorat
$sql_dirs = "SELECT DISTINCT d.data, d.data AS dirname 
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE f.shortname = :dirfield 
                AND d.data IS NOT NULL 
                AND d.data != ''
           ORDER BY d.data ASC";
$direktorats = $DB->get_records_sql_menu($sql_dirs, ['dirfield' => $dir_field_shortname]);

// 5. Siapkan Param & Where Clause SQL
$where_clause = "WHERE u.deleted = 0 AND u.suspended = 0";
$params = ['dirfield' => $dir_field_shortname];

if (!empty($selected_year)) {
    $where_clause .= " AND FROM_UNIXTIME(i.timecreated, '%Y') = :year";
    $params['year'] = (string)$selected_year;
}

// Subquery JOIN untuk filter berdasarkan Direktorat
$dir_join = " LEFT JOIN {user_info_data} ud ON ud.userid = u.id 
              LEFT JOIN {user_info_field} uf ON uf.id = ud.fieldid AND uf.shortname = :dirfield ";

if (!empty($selected_dir)) {
    $where_clause .= " AND ud.data = :dir";
    $params['dir'] = $selected_dir;
}

// --------------------------------------------------------------------
// A. DATA HERO METRICS (KPI CARDS)
// --------------------------------------------------------------------

$sql_total_users = "SELECT COUNT(DISTINCT u.id) 
                      FROM {user} u 
                      {$dir_join}
                     WHERE u.deleted = 0 AND u.suspended = 0";

$params_total_users = ['dirfield' => $dir_field_shortname];

if (!empty($selected_dir)) {
    $sql_total_users .= " AND ud.data = :dir";
    $params_total_users['dir'] = $selected_dir;
}

$total_active_users = $DB->count_records_sql($sql_total_users, $params_total_users);

// Query Agregat Header IDP
$sql_kpi_header = "SELECT 
                    COUNT(DISTINCT CASE WHEN i.status = 2 THEN i.userid END) AS verified_users,
                    COUNT(CASE WHEN i.status = 0 THEN i.id END) AS pending_idp,
                    AVG(CASE WHEN i.status = 2 THEN ((i.skor_efektivitas + i.skor_atasan) / 2) END) AS avg_score
                   FROM {user} u
              {$dir_join}
              LEFT JOIN {local_myidpebi} i ON i.userid = u.id
                   {$where_clause}";
$kpi_header = $DB->get_record_sql($sql_kpi_header, $params);

// Query Agregat Activity IDP
$sql_kpi_act = "SELECT 
                    SUM(act.jumlah_jp_perencanaan) AS total_target_jp,
                    SUM(act.jumlah_jp_realisasi) AS total_realisasi_jp,
                    COUNT(CASE WHEN act.evidence_fileid IS NOT NULL AND act.evidence_fileid > 0 THEN act.id END) AS uploaded_evidence
                FROM {user} u
                {$dir_join}
                JOIN {local_myidpebi} i ON i.userid = u.id
                JOIN {local_myidpebi_act} act ON act.idp_id = i.id
                {$where_clause} AND act.deleted = 0";
$kpi_act = $DB->get_record_sql($sql_kpi_act, $params);

$compliance_rate    = ($total_active_users > 0) ? round(($kpi_header->verified_users / $total_active_users) * 100, 1) : 0;
$total_target_jp    = $kpi_act->total_target_jp ?? 0;
$total_realisasi_jp = $kpi_act->total_realisasi_jp ?? 0;
$avg_score          = !empty($kpi_header->avg_score) ? number_format($kpi_header->avg_score, 1) : '-';
$action_queue       = $kpi_header->pending_idp ?? 0;

// --------------------------------------------------------------------
// B. DATA CHARTS
// --------------------------------------------------------------------

$sql_funnel = "SELECT i.status, COUNT(i.id) AS total 
                 FROM {user} u 
                 {$dir_join}
                 JOIN {local_myidpebi} i ON i.userid = u.id 
                {$where_clause} 
             GROUP BY i.status";
$status_counts = $DB->get_records_sql_menu($sql_funnel, $params);

$status_draft      = $status_counts[0] ?? 0;
$status_inprogress = $status_counts[1] ?? 0;
$status_verified   = $status_counts[2] ?? 0;

// Query Komposisi berdasarkan Tipe Aktivitas CDP (Hitung Jumlah IDP)
$sql_702010 = "SELECT la.tipe_aktivitas_cdp, COUNT(DISTINCT act.idp_id) AS total_idp
                 FROM {user} u
                 {$dir_join}
                 JOIN {local_myidpebi} i ON i.userid = u.id
                 JOIN {local_myidpebi_act} act ON act.idp_id = i.id
                 JOIN {local_myidpebi_learning_activity} la ON la.learning_activity = act.learning_activity
                {$where_clause} AND act.deleted = 0
             GROUP BY la.tipe_aktivitas_cdp";
$category_counts = $DB->get_records_sql_menu($sql_702010, $params);

$chart_labels = !empty($category_counts) ? array_keys($category_counts) : ['Tidak ada data'];
$chart_values = !empty($category_counts) ? array_values($category_counts) : [0];

// --------------------------------------------------------------------
// C. ORGANIZATIONAL ANALYTICS (PER DIREKTORAT)
// --------------------------------------------------------------------

$sql_org = "SELECT 
                ud.data AS direktorat,
                COUNT(DISTINCT u.id) AS total_emp,
                COUNT(DISTINCT CASE WHEN i.status = 2 THEN u.id END) AS verified_emp,
                SUM(CASE WHEN act.deleted = 0 THEN act.jumlah_jp_realisasi ELSE 0 END) AS dir_realisasi_jp,
                SUM(CASE WHEN act.deleted = 0 THEN act.jumlah_jp_perencanaan ELSE 0 END) AS dir_target_jp
            FROM {user} u
            JOIN {user_info_data} ud ON ud.userid = u.id
            JOIN {user_info_field} uf ON uf.id = ud.fieldid AND uf.shortname = :dirfield
       LEFT JOIN {local_myidpebi} i ON i.userid = u.id
       LEFT JOIN {local_myidpebi_act} act ON act.idp_id = i.id
           WHERE u.deleted = 0 AND u.suspended = 0 AND ud.data IS NOT NULL AND ud.data != ''
        GROUP BY ud.data
        ORDER BY ud.data ASC";
$dir_analytics = $DB->get_records_sql($sql_org, ['dirfield' => $dir_field_shortname]);

// --------------------------------------------------------------------
// D. ACTIONABLE BOTTLENECKS TABLE
// --------------------------------------------------------------------

$sql_bottlenecks = "SELECT 
                        i.atasan_id,
                        u_mgr.firstname, 
                        u_mgr.lastname,
                        COALESCE(ud_mgr.data, '-') AS mgr_dir,
                        COUNT(i.id) AS pending_count,
                        MAX(DATEDIFF(NOW(), FROM_UNIXTIME(i.timecreated))) AS max_delay_days
                    FROM {local_myidpebi} i
                    JOIN {user} u ON u.id = i.userid
                    JOIN {user} u_mgr ON u_mgr.id = i.atasan_id
               LEFT JOIN {user_info_data} ud_mgr ON ud_mgr.userid = u_mgr.id 
                     AND ud_mgr.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = :dirfield LIMIT 1)
                   WHERE i.status = 0 AND u.deleted = 0 AND u.suspended = 0
                GROUP BY i.atasan_id, u_mgr.firstname, u_mgr.lastname, ud_mgr.data
                ORDER BY pending_count DESC, max_delay_days DESC
                   LIMIT 10";
$bottlenecks = $DB->get_records_sql($sql_bottlenecks, ['dirfield' => $dir_field_shortname]);

// Output Header Moodle
echo $OUTPUT->header();
?>

<!-- CDN Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container-fluid py-2">

    <!-- GLOBAL FILTER HEADER -->
    <div class="card border-0 shadow-sm mb-4 bg-white">
        <div class="card-body p-3">
            <form method="get" class="form-inline d-flex flex-wrap align-items-center justify-content-between">
                <div class="d-flex align-items-center mb-2 mb-md-0">
                    <h5 class="mb-0 font-weight-bold text-dark"><i class="fa fa-tachometer text-primary mr-2"></i> IDP Analytics Control Center</h5>
                </div>
                <div class="d-flex align-items-center flex-wrap">
                    <label class="mr-2 font-weight-bold small text-muted">Tahun:</label>
                    <select name="year" class="custom-select custom-select-sm mr-3 mb-2 mb-md-0">
                        <?php 
                        for ($y = date('Y'); $y >= date('Y') - 3; $y--) {
                            $selected = ($y == $selected_year) ? 'selected' : '';
                            echo "<option value='{$y}' {$selected}>{$y}</option>";
                        }
                        ?>
                    </select>

                    <label class="mr-2 font-weight-bold small text-muted">Direktorat:</label>
                    <select name="dir" class="custom-select custom-select-sm mr-3 mb-2 mb-md-0">
                        <option value="">-- Semua Direktorat --</option>
                        <?php 
                        foreach ($direktorats as $key => $val) {
                            $selected = ($key == $selected_dir) ? 'selected' : '';
                            echo "<option value='" . s($key) . "' {$selected}>" . s($val) . "</option>";
                        }
                        ?>
                    </select>

                    <button type="submit" class="btn btn-sm btn-primary px-3 mb-2 mb-md-0">
                        <i class="fa fa-filter mr-1"></i> Terapkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SECTION A: HERO METRICS (KPI CARDS) -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm rounded-lg h-100 border-left-lg border-primary">
                <div class="card-body">
                    <small class="text-muted font-weight-bold text-uppercase d-block mb-1">Overall Compliance Rate</small>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 font-weight-bold text-dark mb-0"><?php echo $compliance_rate; ?>%</span>
                        <div class="icon-circle bg-light-primary text-primary p-3 rounded-circle">
                            <i class="fa fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2"><?php echo $kpi_header->verified_users ?? 0; ?> dari <?php echo $total_active_users; ?> Karyawan Verified</small>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm rounded-lg h-100 border-left-lg border-success">
                <div class="card-body">
                    <small class="text-muted font-weight-bold text-uppercase d-block mb-1">Realisasi vs Target JP</small>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 font-weight-bold text-dark mb-0"><?php echo number_format($total_realisasi_jp); ?> <small class="h6 text-muted">/ <?php echo number_format($total_target_jp); ?> JP</small></span>
                        <div class="icon-circle bg-light-success text-success p-3 rounded-circle">
                            <i class="fa fa-clock-o fa-2x"></i>
                        </div>
                    </div>
                    <?php $jp_pct = $total_target_jp > 0 ? round(($total_realisasi_jp / $total_target_jp) * 100, 1) : 0; ?>
                    <small class="text-success font-weight-bold d-block mt-2"><?php echo $jp_pct; ?>% Ketercapaian JP</small>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm rounded-lg h-100 border-left-lg border-info">
                <div class="card-body">
                    <small class="text-muted font-weight-bold text-uppercase d-block mb-1">Rata-Rata Efektivitas</small>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 font-weight-bold text-dark mb-0"><?php echo $avg_score; ?></span>
                        <div class="icon-circle bg-light-info text-info p-3 rounded-circle">
                            <i class="fa fa-star fa-2x"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">Gabungan Self & Manager Eval</small>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-0 shadow-sm rounded-lg h-100 border-left-lg border-warning">
                <div class="card-body">
                    <small class="text-muted font-weight-bold text-uppercase d-block mb-1">Action Needed Queue</small>
                    <div class="d-flex align-items-center justify-content-between">
                        <span class="h2 font-weight-bold text-warning mb-0"><?php echo number_format($action_queue); ?></span>
                        <div class="icon-circle bg-light-warning text-warning p-3 rounded-circle">
                            <i class="fa fa-exclamation-triangle fa-2x"></i>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">Pending Approval Atasan</small>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION B: CHARTS SECTION -->
    <div class="row mb-4">
        <div class="col-md-5 mb-3">
            <div class="card border-0 shadow-sm rounded-lg h-100 bg-white">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="font-weight-bold text-dark mb-0">Funnel Status IDP</h6>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center" style="position: relative; height:260px;">
                    <canvas id="chartFunnel"></canvas>
                </div>
            </div>
        </div>

        <div class="col-md-7 mb-3">
            <div class="card border-0 shadow-sm rounded-lg h-100 bg-white">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="font-weight-bold text-dark mb-0">Komposisi Tipe Aktivitas CDP (Jumlah IDP)</h6>
                </div>
                <div class="card-body d-flex align-items-center justify-content-center" style="position: relative; height:260px;">
                    <canvas id="chart702010"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION C: ORGANIZATIONAL ANALYTICS -->
    <div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
        <div class="card-header bg-white border-0 py-3">
            <h6 class="font-weight-bold text-dark mb-0"><i class="fa fa-sitemap text-primary mr-2"></i> Performa IDP per Direktorat</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 25%;">Direktorat</th>
                            <th style="width: 15%;" class="text-center">Total Karyawan</th>
                            <th style="width: 30%;">Compliance Rate (Verified)</th>
                            <th style="width: 30%;">Realisasi JP vs Target</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dir_analytics)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Data tidak ditemukan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($dir_analytics as $dir): ?>
                                <?php 
                                $d_comp   = $dir->total_emp > 0 ? round(($dir->verified_emp / $dir->total_emp) * 100, 1) : 0;
                                $d_real   = $dir->dir_realisasi_jp ?? 0;
                                $d_target = $dir->dir_target_jp ?? 0;
                                $d_jp_pct = $d_target > 0 ? min(100, round(($d_real / $d_target) * 100, 1)) : 0;
                                ?>
                                <tr>
                                    <td class="font-weight-bold text-dark"><?php echo s($dir->direktorat); ?></td>
                                    <td class="text-center"><?php echo $dir->total_emp; ?> Karyawan</td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="mr-2 font-weight-bold small" style="width: 45px;"><?php echo $d_comp; ?>%</span>
                                            <div class="progress flex-grow-1 rounded-pill" style="height: 8px;">
                                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $d_comp; ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="mr-2 font-weight-bold small text-muted" style="width: 85px;"><?php echo number_format($d_real); ?>/<?php echo number_format($d_target); ?></span>
                                            <div class="progress flex-grow-1 rounded-pill" style="height: 8px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $d_jp_pct; ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SECTION D: ACTIONABLE BOTTLENECK TABLE -->
    <div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="font-weight-bold text-dark mb-0"><i class="fa fa-hourglass-half text-warning mr-2"></i> Top Pending Approval Atasan (Bottleneck)</h6>
            <span class="badge badge-warning">Butuh Follow-Up HR</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Nama Atasan</th>
                            <th>Direktorat</th>
                            <th class="text-center">Jumlah IDP Pending</th>
                            <th class="text-center">Tertunda Tertua (Hari)</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bottlenecks)): ?>
                            <tr><td colspan="5" class="text-center text-success py-3"><i class="fa fa-check-circle mr-1"></i> Tidak ada penumpukan antrean approval atasan.</td></tr>
                        <?php else: ?>
                            <?php foreach ($bottlenecks as $b): ?>
                                <tr>
                                    <td class="font-weight-bold text-dark">
                                        <?php echo s($b->firstname . ' ' . $b->lastname); ?>
                                    </td>
                                    <td><?php echo s($b->mgr_dir ?? '-'); ?></td>
                                    <td class="text-center">
                                        <span class="badge badge-danger px-2 py-1"><?php echo $b->pending_count; ?> IDP</span>
                                    </td>
                                    <td class="text-center font-weight-bold text-danger">
                                        <?php echo $b->max_delay_days; ?> Hari
                                    </td>
                                    <td class="text-center">
                                        <a href="mailto:<?php echo $DB->get_field('user', 'email', ['id' => $b->atasan_id]); ?>?subject=Pengingat Approval IDP Tim" class="btn btn-xs btn-outline-primary rounded-pill">
                                            <i class="fa fa-envelope mr-1"></i> Kirim Reminder
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- INITIATE CHARTS -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctxFunnel = document.getElementById('chartFunnel').getContext('2d');
    new Chart(ctxFunnel, {
        type: 'doughnut',
        data: {
            labels: ['Draft/Pending (0)', 'In-Progress (1)', 'Verified (2)'],
            datasets: [{
                data: [<?php echo $status_draft; ?>, <?php echo $status_inprogress; ?>, <?php echo $status_verified; ?>],
                backgroundColor: ['#ffc107', '#17a2b8', '#28a745'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            cutout: '65%'
        }
    });

    var ctx702010 = document.getElementById('chart702010').getContext('2d');
    new Chart(ctx702010, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Jumlah IDP',
                data: <?php echo json_encode($chart_values); ?>,
                backgroundColor: ['#007bff', '#20c997', '#ffc107', '#6c757d', '#e83e8c'],
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { 
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
});
</script>

<?php
echo $OUTPUT->footer();
?>