<?php
defined('MOODLE_INTERNAL') || die();

global $DB, $USER;

// 1. Ambil Seluruh Konfigurasi Plugin dari Settings
$field_jabatan = get_config('local_dashboard_ebi', 'field_level_jabatan');
$field_jabatan = !empty($field_jabatan) ? trim($field_jabatan) : 'level_jabatan';

$field_atasan  = get_config('local_dashboard_ebi', 'field_atasan_langsung');
$field_atasan  = !empty($field_atasan) ? trim($field_atasan) : 'atasan_langsung';

$manager_key   = get_config('local_dashboard_ebi', 'manager_key_type');
$manager_key   = !empty($manager_key) ? trim($manager_key) : 'username';

// Tentukan Nilai Identitas User Login Berdasarkan Settings (Username / Email / ID Number)
$user_key_value = isset($USER->$manager_key) ? $USER->$manager_key : $USER->username;

/**
 * Helper Fungsi Rekursif Dinamis dengan Configuration Fallback
 */
function get_semua_bawahan_data_limited($atasan_key_value, $field_atasan, $field_jabatan, $manager_key, $current_depth = 2, $max_depth = 3) {
    global $DB;
    $results = [];

    if ($current_depth > $max_depth || empty($atasan_key_value)) {
        return $results;
    }

    // Query SQL Dinamis sesuai field profile di settings.php
    $sql = "SELECT u.id, u.username, u.email, u.idnumber, u.firstname, u.lastname, 
                   COALESCE(uid_jab.data, 'unassigned') AS user_target_field, 
                   ? AS manager_key_val
              FROM {user} u
              JOIN {user_info_data} uid ON uid.userid = u.id
              JOIN {user_info_field} uif ON uif.id = uid.fieldid AND uif.shortname = ?
         LEFT JOIN {user_info_field} uif_jab ON uif_jab.shortname = ?
         LEFT JOIN {user_info_data} uid_jab ON uid_jab.userid = u.id AND uid_jab.fieldid = uif_jab.id
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

        // Ambil nilai identifier bawahan untuk pencarian rekursif level berikutnya
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

// 2. PANGGIL BAWAHAN DENGAN CONFIGURABLE PARAMETER
$team_members = get_semua_bawahan_data_limited($user_key_value, $field_atasan, $field_jabatan, $manager_key, 2, 3);

// 3. KUMPULKAN DROPDOWN FILTER JABATAN / DIREKTORAT DINAMIS
$available_jabatans = [];
foreach ($team_members as $member) {
    $jab = !empty($member->user_target_field) ? strtolower(trim($member->user_target_field)) : 'unassigned';
    $available_jabatans[$jab] = strtoupper($jab);
}
ksort($available_jabatans);

$selected_jabatan_filter = optional_param('filter_team_jabatan', 'all', PARAM_TEXT);

// Label Kategori Header Dinamis
$header_field_label = strtoupper(str_replace('_', ' ', $field_jabatan));

// 4. PROSES AGREGASI PERHITUNGAN MATRIKS BAWAHAN & STATISTIK GRAFIK
$team_report_data = [];

$total_compliance_sum = 0;
$count_team_members = 0;

$status_distribution = [
    'high'      => 0, // 100%
    'progress'  => 0, // 50% - 99%
    'low'       => 0  // < 50%
];

$category_stats = [];

foreach ($team_members as $member) {
    $member_jabatan = !empty($member->user_target_field) ? strtolower(trim($member->user_target_field)) : 'unassigned';

    // Apply Filter Jabatan / Direktorat
    if ($selected_jabatan_filter !== 'all' && $member_jabatan !== $selected_jabatan_filter) {
        continue;
    }

    $rules = $DB->get_records('local_dashboard_matrix', ['level_jabatan' => $member_jabatan]);

    $total_target_courses = 0;
    $completed_courses = 0;
    $course_details = [];

    if (!empty($rules)) {
        foreach ($rules as $rule) {
            $kat_tag    = strtolower(trim($rule->kategori_tag));
            $status_tag = strtolower(trim($rule->status_tag));

            $sql = "SELECT c.id AS courseid, c.fullname AS coursename,
                           GROUP_CONCAT(LOWER(t.name) SEPARATOR ',') AS course_tags,
                           (SELECT cc.timecompleted 
                              FROM {course_completions} cc 
                             WHERE cc.course = c.id AND cc.userid = :uid1) AS timecompleted,
                           (SELECT ROUND(gg.finalgrade, 2)
                              FROM {grade_items} gi
                              JOIN {grade_grades} gg ON gg.itemid = gi.id
                             WHERE gi.courseid = c.id AND gi.itemtype = 'course' AND gg.userid = :uid2) AS finalgrade
                      FROM {course} c
                      JOIN {tag_instance} ti ON ti.itemid = c.id AND ti.itemtype = 'course' AND ti.component = 'core'
                      JOIN {tag} t ON t.id = ti.tagid
                     WHERE c.id != 1 AND c.visible = 1
                  GROUP BY c.id, c.fullname
                    HAVING FIND_IN_SET(:jab_tag, course_tags) > 0
                       AND FIND_IN_SET(:kat_tag, course_tags) > 0
                       AND FIND_IN_SET(:stat_tag, course_tags) > 0";

            $params = [
                'uid1'     => $member->id,
                'uid2'     => $member->id,
                'jab_tag'  => $member_jabatan,
                'kat_tag'  => $kat_tag,
                'stat_tag' => $status_tag
            ];

            $courses = $DB->get_records_sql($sql, $params);
            $kat_label = ucfirst($kat_tag);

            if (!isset($category_stats[$kat_label])) {
                $category_stats[$kat_label] = ['target' => 0, 'completed' => 0];
            }

            foreach ($courses as $c) {
                $total_target_courses++;
                $category_stats[$kat_label]['target']++;

                $is_comp = !empty($c->timecompleted);
                if ($is_comp) {
                    $completed_courses++;
                    $category_stats[$kat_label]['completed']++;
                }

                $course_details[] = [
                    'fullname' => $c->coursename,
                    'kategori' => $kat_label,
                    'grade'    => !is_null($c->finalgrade) ? $c->finalgrade : '-',
                    'is_comp'  => $is_comp
                ];
            }
        }
    }

    $compliance_pct = $total_target_courses > 0 ? round(($completed_courses / $total_target_courses) * 100, 1) : 0;

    // HITUNG DATA UNTUK GRAFIK
    $count_team_members++;
    $total_compliance_sum += $compliance_pct;

    if ($compliance_pct >= 100) {
        $status_distribution['high']++;
    } else if ($compliance_pct >= 50) {
        $status_distribution['progress']++;
    } else {
        $status_distribution['low']++;
    }

    $team_report_data[] = [
        'userid'         => $member->id,
        'fullname'       => trim($member->firstname . ' ' . $member->lastname),
        'username'       => $member->username,
        'jabatan'        => strtoupper($member_jabatan),
        'depth'          => $member->level_depth,
        'manager'        => !empty($member->manager_key_val) ? $member->manager_key_val : '-',
        'total_target'   => $total_target_courses,
        'completed'      => $completed_courses,
        'compliance_pct' => $compliance_pct,
        'courses'        => $course_details
    ];
}

// NILAI AKHIR UNTUK CHART
$avg_team_compliance = $count_team_members > 0 ? round($total_compliance_sum / $count_team_members, 1) : 0;

// OLAH DATA CHART PEMENUHAN PER KATEGORI (Mandatory, Fundamental, dll)
$chart_kat_labels = [];
$chart_kat_data   = [];
foreach ($category_stats as $k_name => $k_data) {
    $chart_kat_labels[] = $k_name;
    $pct = $k_data['target'] > 0 ? round(($k_data['completed'] / $k_data['target']) * 100, 1) : 0;
    $chart_kat_data[]   = $pct;
}
?>

<div class="container-fluid p-0 mb-5">
    <!-- FILTER BAR DINAMIS SESUAI FIELD SETTINGS -->
    <div class="card border-0 shadow-sm rounded-lg p-3 mb-4 bg-light">
        <form method="get" action="" class="form-inline justify-content-between">
            <input type="hidden" name="tab" value="team_learning_path">
            
            <div class="d-flex align-items-center">
                <label class="font-weight-bold mr-2 text-dark small">
                    <i class="fa fa-filter mr-1"></i> Filter <?php echo $header_field_label; ?> Bawahan:
                </label>
                <select name="filter_team_jabatan" class="form-control form-control-sm mr-2" onchange="this.form.submit();">
                    <option value="all">-- Semua <?php echo $header_field_label; ?> Tim --</option>
                    <?php foreach ($available_jabatans as $jab_key => $jab_label): ?>
                        <option value="<?php echo htmlspecialchars($jab_key); ?>" <?php echo ($selected_jabatan_filter === $jab_key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($jab_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <span class="badge badge-primary p-2">
                    <i class="fa fa-users mr-1"></i> Total Anggota Filtered: <?php echo $count_team_members; ?> Orang
                </span>
            </div>
        </form>
    </div>

    <?php if (empty($team_report_data)): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="fa fa-info-circle mr-1"></i> Tidak ada anggota tim / bawahan yang terdeteksi atau cocok dengan filter.
        </div>
    <?php else: ?>

        <!-- SECTION GRAFIK ANALYTICS TIM -->
        <div class="row mb-4">
            <!-- Chart 1: Average Team Compliance -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Rata-rata Pemenuhan Tim</h6>
                    <small class="text-muted d-block mb-2">Pencapaian keseluruhan tim yang difilter</small>
                    
                    <div class="d-flex align-items-center justify-content-center my-auto" style="height: 150px;">
                        <canvas id="chartTeamAvg"></canvas>
                    </div>
                    
                    <div class="text-center mt-2 pt-2 border-top">
                        <span class="h4 font-weight-bold text-primary mb-0"><?php echo $avg_team_compliance; ?>%</span>
                        <small class="text-muted d-block">Overall Compliance Rate</small>
                    </div>
                </div>
            </div>

            <!-- Chart 2: Distribusi Performa Bawahan -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Distribusi Status Tim</h6>
                    <small class="text-muted d-block mb-2">Sebaran kesiapan bawahan</small>
                    
                    <div class="d-flex align-items-center justify-content-center my-auto" style="height: 180px;">
                        <canvas id="chartTeamStatus"></canvas>
                    </div>
                </div>
            </div>

            <!-- Chart 3: Compliance by Category (Mandatory, Fundamental, dll) -->
            <div class="col-md-4 mb-3">
                <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
                    <h6 class="font-weight-bold text-dark mb-1">Pemenuhan per Kategori Pelatihan (%)</h6>
                    <small class="text-muted d-block mb-2">Tingkat kelulusan per tag kategori</small>
                    
                    <div style="height: 180px;">
                        <canvas id="chartTeamCategory"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABEL MONITORING TIM BAWAHAN -->
        <div class="card border-0 shadow-sm rounded-lg">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fa fa-sitemap text-primary mr-2"></i> Laporan Pemenuhan Matriks Pelatihan Tim
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="thead-light">
                            <tr>
                                <th>Nama Karyawan</th>
                                <th><?php echo $header_field_label; ?></th>
                                <th>Atasan Langsung</th>
                                <th class="text-center">Level Hirarki</th>
                                <th class="text-center">Progress Pemenuhan</th>
                                <th class="text-center">% Compliance</th>
                                <th class="text-center" style="width: 120px;">Rincian</th>
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
                                        <small class="text-secondary font-weight-bold">@<?php echo htmlspecialchars($row['manager']); ?></small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['depth'] == 2): ?>
                                            <span class="badge badge-info p-1">Direct</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary p-1">Sub-Team</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-weight-bold">
                                        <?php echo $row['completed']; ?> / <?php echo $row['total_target']; ?> Course
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $p_class = $row['compliance_pct'] >= 100 ? 'badge-success' : ($row['compliance_pct'] >= 50 ? 'badge-warning text-dark' : 'badge-danger');
                                        ?>
                                        <span class="badge <?php echo $p_class; ?> p-2" style="font-size: 0.85rem;">
                                            <?php echo $row['compliance_pct']; ?>%
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" 
                                                class="btn btn-outline-primary btn-sm" 
                                                data-toggle="collapse" 
                                                data-target="#detail-user-<?php echo $row['userid']; ?>"
                                                data-bs-toggle="collapse" 
                                                data-bs-target="#detail-user-<?php echo $row['userid']; ?>"
                                                aria-expanded="false">
                                            <i class="fa fa-eye mr-1"></i> Detail
                                        </button>
                                    </td>
                                </tr>
                                
                                <tr>
                                    <td colspan="7" class="p-0 border-0">
                                        <div class="collapse bg-light p-3" id="detail-user-<?php echo $row['userid']; ?>">
                                            <div class="card border-0 shadow-sm p-3">
                                                <h6 class="font-weight-bold text-primary mb-2">
                                                    <i class="fa fa-list-alt mr-1"></i> Matriks Course: <?php echo htmlspecialchars($row['fullname']); ?>
                                                </h6>
                                                <?php if (empty($row['courses'])): ?>
                                                    <small class="text-muted italic">Belum ada course yang di-tag untuk <?php echo strtolower($header_field_label); ?> ini.</small>
                                                <?php else: ?>
                                                    <table class="table table-sm table-bordered bg-white mb-0" style="font-size: 0.8rem;">
                                                        <thead class="thead-light">
                                                            <tr>
                                                                <th>Nama Course</th>
                                                                <th>Kategori</th>
                                                                <th class="text-center">Nilai</th>
                                                                <th class="text-center">Status Completion</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($row['courses'] as $cr): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($cr['fullname']); ?></td>
                                                                    <td><span class="badge badge-light border"><?php echo htmlspecialchars($cr['kategori']); ?></span></td>
                                                                    <td class="text-center font-weight-bold"><?php echo $cr['grade']; ?></td>
                                                                    <td class="text-center">
                                                                        <?php if ($cr['is_comp']): ?>
                                                                            <span class="badge badge-success">Lulus (Yes)</span>
                                                                        <?php else: ?>
                                                                            <span class="badge badge-secondary">Belum Selesai</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                <?php endif; ?>
                                            </div>
                                        </div>
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

<!-- SCRIPT CHART.JS UNTUK TEAM ANALYTICS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Chart Average Team Compliance
    var ctxAvg = document.getElementById('chartTeamAvg').getContext('2d');
    new Chart(ctxAvg, {
        type: 'doughnut',
        data: {
            labels: ['Terpenuhi', 'Sisa Target'],
            datasets: [{
                data: [<?php echo $avg_team_compliance; ?>, <?php echo max(0, 100 - $avg_team_compliance); ?>],
                backgroundColor: ['#007bff', '#e9ecef'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            cutout: '75%'
        }
    });

    // 2. Chart Status Distribution
    var ctxStatus = document.getElementById('chartTeamStatus').getContext('2d');
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['100% Complete', '50-99% Progress', '< 50% Low'],
            datasets: [{
                data: [
                    <?php echo $status_distribution['high']; ?>, 
                    <?php echo $status_distribution['progress']; ?>, 
                    <?php echo $status_distribution['low']; ?>
                ],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });

    // 3. Chart Compliance by Category (Mandatory, Fundamental, dll)
    var ctxCat = document.getElementById('chartTeamCategory').getContext('2d');
    new Chart(ctxCat, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chart_kat_labels); ?>,
            datasets: [{
                label: 'Compliance (%)',
                data: <?php echo json_encode($chart_kat_data); ?>,
                backgroundColor: '#17a2b8',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100
                }
            }
        }
    });
});
</script>