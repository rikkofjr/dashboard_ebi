<?php
defined('MOODLE_INTERNAL') || die();

global $DB, $USER;

// Ambil Konfigurasi Profile Field Jabatan dari Settings
$field_jabatan = get_config('local_dashboard_ebi', 'field_level_jabatan');
$field_jabatan = !empty($field_jabatan) ? $field_jabatan : 'level_jabatan';

// 1. Ambil Profile Field level_jabatan Karyawan
$user_jabatan_sql = "SELECT uid.data 
                       FROM {user_info_data} uid
                       JOIN {user_info_field} uif ON uif.id = uid.fieldid
                      WHERE uid.userid = :userid 
                        AND uif.shortname = :field_jabatan";

$user_jabatan_rec = $DB->get_field_sql($user_jabatan_sql, [
    'userid'        => $USER->id,
    'field_jabatan' => $field_jabatan
]);
$user_jabatan = !empty($user_jabatan_rec) ? strtolower(trim($user_jabatan_rec)) : 'unassigned';

// 2. Ambil Aturan Matriks Tag khusus Jabatan User
$rules = $DB->get_records('local_dashboard_matrix', ['level_jabatan' => $user_jabatan]);

$grouped_courses = [];
$total_completed = 0;
$total_pending = 0;
$category_counts = [];

if (!empty($rules)) {
    foreach ($rules as $rule) {
        $kat_tag    = strtolower(trim($rule->kategori_tag));
        $status_tag = strtolower(trim($rule->status_tag));

        $sql = "SELECT 
                    c.id AS courseid,
                    c.fullname AS coursename,
                    GROUP_CONCAT(LOWER(t.name) SEPARATOR ',') AS course_tags,
                    
                    (SELECT cc.timecompleted 
                       FROM {course_completions} cc 
                      WHERE cc.course = c.id AND cc.userid = :userid1) AS timecompleted,

                    (SELECT ROUND(gg.finalgrade, 2)
                       FROM {grade_items} gi
                       JOIN {grade_grades} gg ON gg.itemid = gi.id
                      WHERE gi.courseid = c.id AND gi.itemtype = 'course' AND gg.userid = :userid2) AS finalgrade

                FROM {course} c
                JOIN {tag_instance} ti ON ti.itemid = c.id AND ti.itemtype = 'course' AND ti.component = 'core'
                JOIN {tag} t ON t.id = ti.tagid
                
               WHERE c.id != :sitecourseid AND c.visible = 1
               GROUP BY c.id, c.fullname
              HAVING FIND_IN_SET(:jabatan_tag, course_tags) > 0
                 AND FIND_IN_SET(:kat_tag, course_tags) > 0
                 AND FIND_IN_SET(:status_tag, course_tags) > 0
               ORDER BY c.fullname ASC";

        $params = [
            'sitecourseid' => 1,
            'userid1'      => $USER->id,
            'userid2'      => $USER->id,
            'jabatan_tag'  => $user_jabatan,
            'kat_tag'      => $kat_tag,
            'status_tag'   => $status_tag
        ];

        $courses = $DB->get_records_sql($sql, $params);
        $kat_title = ucfirst($kat_tag);

        foreach ($courses as $row) {
            $is_completed = !empty($row->timecompleted);
            $grade = !is_null($row->finalgrade) ? $row->finalgrade : '-';

            // Hitung untuk Chart Analytics
            if ($is_completed) {
                $total_completed++;
            } else {
                $total_pending++;
            }

            if (!isset($category_counts[$kat_title])) {
                $category_counts[$kat_title] = 0;
            }
            $category_counts[$kat_title]++;

            $grouped_courses[$kat_title][] = [
                'id'           => $row->courseid,
                'fullname'     => $row->coursename,
                'is_completed' => $is_completed,
                'grade'        => $grade
            ];
        }
    }
}

$total_courses = $total_completed + $total_pending;
$compliance_pct = $total_courses > 0 ? round(($total_completed / $total_courses) * 100, 1) : 0;
?>

<!-- ==================================================================== -->
<!-- SECTION 1: RINGKASAN GRAFIK & KPI ANALYTICS                          -->
<!-- ==================================================================== -->
<div class="row mb-4">
    <!-- Card KPI Pemenuhan Matriks -->
    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
            <h6 class="font-weight-bold text-dark mb-1">Rasio Pemenuhan Pelatihan</h6>
            <small class="text-muted d-block mb-3">Jabatan: <strong class="text-uppercase text-primary"><?php echo htmlspecialchars($user_jabatan); ?></strong></small>
            
            <div class="d-flex align-items-center justify-content-center my-auto" style="height: 160px;">
                <canvas id="chartCompliance"></canvas>
            </div>
            
            <div class="text-center mt-3 pt-2 border-top">
                <span class="h4 font-weight-bold text-success mb-0"><?php echo $compliance_pct; ?>%</span>
                <small class="text-muted d-block">Modul Terpenuhi (<?php echo $total_completed; ?> / <?php echo $total_courses; ?>)</small>
            </div>
        </div>
    </div>

    <!-- Card Komposisi Kategori Pelatihan -->
    <div class="col-md-8 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
            <h6 class="font-weight-bold text-dark mb-1">Komposisi Modul Pelatihan per Kategori</h6>
            <small class="text-muted d-block mb-3">Jumlah modul yang teralokasi berdasarkan kategori tag</small>
            
            <div style="height: 200px;">
                <canvas id="chartCategories"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- SECTION 2: TABEL RINGKAS PELATIHAN                                    -->
<!-- ==================================================================== -->
<?php if (empty($grouped_courses)): ?>
    <div class="alert alert-warning border-0 shadow-sm">
        Belum ada pelatihan yang dikonfigurasi untuk level jabatan Anda (<strong><?php echo htmlspecialchars($user_jabatan); ?></strong>).
    </div>
<?php else: ?>
    <?php foreach ($grouped_courses as $kat_name => $course_list): ?>
        <div class="card border-0 shadow-sm rounded-lg mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fa fa-folder text-primary mr-2"></i> Kategori: <?php echo htmlspecialchars($kat_name); ?>
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 50%;">Nama Course</th>
                                <th class="text-center" style="width: 25%;">Nilai</th>
                                <th class="text-center" style="width: 25%;">Completion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($course_list as $c): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo new moodle_url('/course/view.php', ['id' => $c['id']]); ?>" class="font-weight-bold text-primary" target="_blank">
                                            <i class="fa fa-book mr-1"></i> <?php echo htmlspecialchars($c['fullname']); ?>
                                        </a>
                                    </td>
                                    <td class="text-center font-weight-bold text-dark">
                                        <?php echo $c['grade']; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($c['is_completed']): ?>
                                            <span class="badge badge-success p-2"><i class="fa fa-check mr-1"></i> Yes</span>
                                        <?php else: ?>
                                            <span class="text-muted font-weight-bold">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- SCRIPT CHART.JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Doughnut Chart Pemenuhan
    var ctxComp = document.getElementById('chartCompliance').getContext('2d');
    new Chart(ctxComp, {
        type: 'doughnut',
        data: {
            labels: ['Lulus (Yes)', 'Belum Selesai'],
            datasets: [{
                data: [<?php echo $total_completed; ?>, <?php echo $total_pending; ?>],
                backgroundColor: ['#28a745', '#e9ecef'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            cutout: '70%'
        }
    });

    // 2. Bar Chart Komposisi Kategori
    var catLabels = <?php echo json_encode(array_keys($category_counts)); ?>;
    var catValues = <?php echo json_encode(array_values($category_counts)); ?>;
    
    var ctxCat = document.getElementById('chartCategories').getContext('2d');
    new Chart(ctxCat, {
        type: 'bar',
        data: {
            labels: catLabels,
            datasets: [{
                label: 'Jumlah Modul',
                data: catValues,
                backgroundColor: '#007bff',
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
            }
        }
    });
});
</script>