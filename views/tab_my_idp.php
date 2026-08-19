<?php
defined('MOODLE_INTERNAL') || die();

global $DB, $USER, $CFG;

// 1. INTEGRASI FILE LIB DARI PLUGIN LOCAL_MYIDPEBI
$myidp_lib = $CFG->dirroot . '/local/myidpebi/lib.php';
if (file_exists($myidp_lib)) {
    require_once($myidp_lib); //[cite: 10]
}

// 2. DAPATKAN DAFTAR TAHUN IDP UNTUK DROPDOWN FILTER
$sql_years = "SELECT DISTINCT FROM_UNIXTIME(mulai_date, '%Y') AS idp_year 
                FROM {local_myidpebi} 
               WHERE userid = ? 
            ORDER BY idp_year DESC";
$user_idp_years = $DB->get_fieldset_sql($sql_years, [$USER->id]); //[cite: 9]

// Catch parameter filter tahun dari URL (default: tahun aktif saat ini atau 'all')
$current_year_default = date('Y');
$selected_year = optional_param('filter_idp_year', in_array($current_year_default, $user_idp_years) ? $current_year_default : 'all', PARAM_TEXT);

// 3. KUERI DATA IDP BERDASARKAN FILTER TAHUN TERPILIH
$where_clause = "userid = ?";
$params = [$USER->id];

if ($selected_year !== 'all') {
    $where_clause .= " AND FROM_UNIXTIME(mulai_date, '%Y') = ?";
    $params[] = $selected_year; //[cite: 9]
}

$idp_records = $DB->get_records_select('local_myidpebi', $where_clause, $params, 'id DESC'); //[cite: 9]

// 4. PROSES AGREGASI AKUMULASI DARI RECORD TERPILIH
$total_jp_rencana = 0;
$total_jp_realisasi = 0;
$sum_skor_self = 0;
$sum_skor_atasan = 0;
$count_skor_self = 0;
$count_skor_atasan = 0;

$idp_ids = [];
foreach ($idp_records as $idp_item) {
    $idp_ids[] = $idp_item->id;
    if ($idp_item->skor_efektivitas > 0) {
        $sum_skor_self += $idp_item->skor_efektivitas;
        $count_skor_self++; //[cite: 9]
    }
    if ($idp_item->skor_atasan > 0) {
        $sum_skor_atasan += $idp_item->skor_atasan;
        $count_skor_atasan++; //[cite: 9]
    }
}

// 5. AMBIL SEMUA AKTIVITAS TERKAIT IDP TERPILIH (MDL_LOCAL_MYIDPEBI_ACT)
$activities = [];
$cdp_distribution = [];

if (!empty($idp_ids)) {
    list($in_sql, $in_params) = $DB->get_in_or_equal($idp_ids);
    
    $sql_act = "SELECT a.*, 
                       m.tipe_aktivitas_cdp, 
                       m.learning_activity AS nama_jenis_kegiatan
                  FROM {local_myidpebi_act} a
             LEFT JOIN {local_myidpebi_learning_activity} m ON m.id = CAST(a.learning_activity AS SIGNED)
                 WHERE a.idp_id $in_sql AND a.deleted = 0
              ORDER BY a.id DESC";
              
    $activities = $DB->get_records_sql($sql_act, $in_params);

    foreach ($activities as $act) {
        $jp_rencana   = (int)$act->jumlah_jp_perencanaan;
        $jp_realisasi = (int)$act->jumlah_jp_realisasi;

        $total_jp_rencana   += $jp_rencana;
        $total_jp_realisasi += $jp_realisasi; //[cite: 9]

        // Agregasi Chart Tipe Aktivitas CDP
        $cdp_type = !empty($act->tipe_aktivitas_cdp) ? $act->tipe_aktivitas_cdp : 'Lainnya';
        if (!isset($cdp_distribution[$cdp_type])) {
            $cdp_distribution[$cdp_type] = 0;
        }
        $cdp_distribution[$cdp_type] += $jp_realisasi; //[cite: 7, 9]
    }
}

// Hitung Statistik Agregasi
$jp_progress_pct = $total_jp_rencana > 0 ? round(($total_jp_realisasi / $total_jp_rencana) * 100, 1) : 0;
$avg_skor_self   = $count_skor_self > 0 ? round($sum_skor_self / $count_skor_self, 1) : 0;
$avg_skor_atasan = $count_skor_atasan > 0 ? round($sum_skor_atasan / $count_skor_atasan, 1) : 0;

// Data untuk Chart.js
$chart_cdp_labels = array_keys($cdp_distribution);
$chart_cdp_values = array_values($cdp_distribution);
?>

<div class="container-fluid p-0 mb-5">
    
    <!-- BAR FILTER TAHUN IDP -->
    <div class="card border-0 shadow-sm rounded-lg p-3 mb-4 bg-light">
        <form method="get" action="" class="form-inline justify-content-between">
            <input type="hidden" name="tab" value="my_idp">
            
            <div class="d-flex align-items-center">
                <label class="font-weight-bold mr-2 text-dark small">
                    <i class="fa fa-calendar mr-1"></i> Periode IDP:
                </label>
                <select name="filter_idp_year" class="form-control form-control-sm mr-2" onchange="this.form.submit();">
                    <option value="all" <?php echo ($selected_year === 'all') ? 'selected' : ''; ?>>-- Akumulasi Semua Tahun --</option>
                    <?php foreach ($user_idp_years as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo ($selected_year == $yr) ? 'selected' : ''; ?>>
                            Tahun <?php echo $yr; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <span class="badge badge-info p-2">
                    <i class="fa fa-folder-open mr-1"></i> Data Terpilih: 
                    <?php echo ($selected_year === 'all') ? 'Akumulasi Historis' : 'IDP ' . $selected_year; ?>
                </span>
            </div>
        </form>
    </div>

    <?php if (empty($idp_records)): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-lg p-4">
            <i class="fa fa-info-circle mr-1"></i> Belum ada program IDP yang tercatat untuk periode terpilih.
        </div>
    <?php else: ?>

        <!-- ROW 1: METRIC WIDGET CARDS -->
        <div class="row mb-4">
            <!-- Widget 1: Progress JP -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Pencapaian Jam Pelajaran (JP)</h6>
                    <small class="text-muted d-block mb-3">
                        <?php echo ($selected_year === 'all') ? 'Total Akumulasi Realisasi JP' : 'Realisasi JP Tahun ' . $selected_year; ?>
                    </small>
                    
                    <div class="d-flex justify-content-between align-items-end mb-1">
                        <span class="h3 font-weight-bold text-primary mb-0">
                            <?php echo $total_jp_realisasi; ?> <small class="text-muted h6">/ <?php echo $total_jp_rencana; ?> JP</small>
                        </span>
                        <span class="font-weight-bold text-success"><?php echo $jp_progress_pct; ?>%</span>
                    </div>
                    
                    <div class="progress rounded-pill" style="height: 10px;">
                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo min(100, $jp_progress_pct); ?>%;"></div>
                    </div>
                </div>
            </div>

            <!-- Widget 2: Total Aktivitas -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Rincian Aktivitas IDP</h6>
                    <small class="text-muted d-block mb-3">Status Pengajuan Aktivitas</small>

                    <div class="d-flex justify-content-around align-items-center my-auto">
                        <div class="text-center">
                            <span class="h3 font-weight-bold text-dark mb-0"><?php echo count($activities); ?></span>
                            <small class="text-muted d-block">Total Kegiatan</small>
                        </div>
                        <div class="border-right" style="height: 30px;"></div>
                        <div class="text-center">
                            <?php $completed_acts = array_filter($activities, function($a) { return $a->jumlah_jp_realisasi > 0; }); ?>
                            <span class="h3 font-weight-bold text-success mb-0"><?php echo count($completed_acts); ?></span>
                            <small class="text-muted d-block">Terlaksana</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Widget 3: Skor Efektivitas IDP -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Evaluasi Efektivitas</h6>
                    <small class="text-muted d-block mb-2">Rata-rata Skor Penilaian</small>

                    <div class="d-flex justify-content-around align-items-center mt-2">
                        <div class="text-center">
                            <span class="h4 font-weight-bold text-info mb-0"><?php echo number_format($avg_skor_self, 1); ?>%</span>
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Self Assessment</small>
                        </div>
                        <div class="text-center">
                            <span class="h4 font-weight-bold text-warning mb-0"><?php echo number_format($avg_skor_atasan, 1); ?>%</span>
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Penilaian Atasan</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 2: CHART METODE PENGEMBANGAN (CDP) -->
        <div class="row mb-4">
            <div class="col-md-12 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Tipe Aktivitas CDP</h6>
                    <small class="text-muted d-block mb-3">Komposisi metode pembelajaran yang sudah direalisasikan (JP)</small>

                    <div style="height: 220px;" class="d-flex align-items-center justify-content-center">
                        <?php if (empty($chart_cdp_values)): ?>
                            <small class="text-muted italic py-4">Belum ada realisasi JP untuk periode terpilih.</small>
                        <?php else: ?>
                            <canvas id="chartCdpType"></canvas>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 3: TABEL RINCIAN AKTIVITAS PENGEMBANGAN -->
        <div class="card border-0 shadow-sm rounded-lg">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fa fa-list text-primary mr-2"></i> Daftar Rencana & Realisasi Aktivitas IDP
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="thead-light">
                            <tr>
                                <th>Rincian Aktivitas</th>
                                <th>Tipe CDP</th>
                                <th class="text-center">JP Rencana</th>
                                <th class="text-center">JP Realisasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activities)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">Belum ada rincian aktivitas yang ditambahkan.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td>
                                            <strong class="text-dark d-block"><?php echo htmlspecialchars($act->nama_activity); ?></strong>
                                        </td>
                                        <td>
                                            <?php 
                                            if (!empty($act->tipe_aktivitas_cdp) && !empty($act->nama_jenis_kegiatan)) {
                                                $cdp_text = $act->tipe_aktivitas_cdp . ' - ' . $act->nama_jenis_kegiatan;
                                            } else {
                                                $cdp_text = '-';
                                            }
                                            ?>
                                            <span class="badge badge-light border text-dark"><?php echo htmlspecialchars($cdp_text); ?></span>
                                        </td>
                                        <td class="text-center font-weight-bold"><?php echo $act->jumlah_jp_perencanaan; ?> JP</td>
                                        <td class="text-center font-weight-bold text-primary"><?php echo $act->jumlah_jp_realisasi; ?> JP</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- SCRIPT CHART.JS TIPE AKTIVITAS CDP -->
<?php if (!empty($chart_cdp_values)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ctxCdp = document.getElementById('chartCdpType').getContext('2d');
    new Chart(ctxCdp, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_cdp_labels); ?>,
            datasets: [{
                label: 'Realisasi JP',
                data: <?php echo json_encode($chart_cdp_values); ?>,
                backgroundColor: '#28a745',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
});
</script>
<?php endif; ?>