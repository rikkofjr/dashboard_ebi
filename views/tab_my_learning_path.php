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

// 1. Ambil seluruh course Daily Practice user (Order by Enrol Date DESC)
$sql_dp_courses = "SELECT c.id AS courseid, 
                          c.fullname AS coursename, 
                          ue.timecreated AS enrol_time,
                          FROM_UNIXTIME(c.startdate, '%Y') AS course_year
                     FROM {course} c
                     JOIN {course_categories} cc ON cc.id = c.category
                     JOIN {enrol} e ON e.courseid = c.id
                     JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
                    WHERE cc.idnumber = 'daily_practice' AND c.visible = 1
                 ORDER BY ue.timecreated DESC";

$all_user_dp_courses = $DB->get_records_sql($sql_dp_courses, ['userid' => $USER->id]);

// Limit 3 untuk Kolom Kanan
$top3_dp_courses = array_slice($all_user_dp_courses, 0, 3, true);

// Pre-define Data Kolom Kiri (Tahun Berjalan)
$current_year = date('Y');
$colA_completed = 0;
$colA_total_quizzes = 0;
$colA_final_grade = '-';

if (!empty($all_user_dp_courses)) {
    // Cari course tahun berjalan
    $current_course = null;
    foreach ($all_user_dp_courses as $c) {
        if ($c->course_year == $current_year) {
            $current_course = $c;
            break;
        }
    }
    if (!$current_course) {
        $current_course = reset($all_user_dp_courses); // Fallback ke course terbaru
    }

    if ($current_course) {
        // Total Kuis Terbit
        $colA_total_quizzes = $DB->count_records_sql("SELECT COUNT(cm.id) 
                                                        FROM {course_modules} cm 
                                                        JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz' 
                                                       WHERE cm.course = ?", [$current_course->courseid]);

        // Total Kuis Complete
        $colA_completed = $DB->count_records_sql("SELECT COUNT(DISTINCT qa.quiz) 
                                                     FROM {quiz_attempts} qa 
                                                     JOIN {quiz} q ON q.id = qa.quiz 
                                                    WHERE q.course = ? AND qa.userid = ? AND qa.state = 'finished'", [$current_course->courseid, $USER->id]);

        // Grade Akhir dari Gradebook
        $sql_grade = "SELECT gg.finalgrade 
                        FROM {grade_items} gi 
                        JOIN {grade_grades} gg ON gg.itemid = gi.id 
                       WHERE gi.courseid = ? AND gi.itemtype = 'course' AND gg.userid = ?";
        $grade_val = $DB->get_field_sql($sql_grade, [$current_course->courseid, $USER->id]);
        if (!is_null($grade_val)) {
            $colA_final_grade = number_format($grade_val, 2);
        }
    }
}

$colA_missed = max(0, $colA_total_quizzes - $colA_completed);
$colA_pct = $colA_total_quizzes > 0 ? round(($colA_completed / $colA_total_quizzes) * 100, 1) : 0;

// Query Hitung Ringkasan Semua Course yang Di-enrol User (Aktif)
$sql_overall_enrol = "
    SELECT 
        COUNT(c.id) AS total_enrolled,
        COUNT(cc.timecompleted) AS total_completed
    FROM {course} c
    JOIN {enrol} e ON e.courseid = c.id
    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
    LEFT JOIN {course_completions} cc ON cc.course = c.id AND cc.userid = :userid_comp
   WHERE c.id != :sitecourseid 
     AND c.visible = 1 
     AND ue.status = 0";

$overall_stats = $DB->get_record_sql($sql_overall_enrol, [
    'userid'      => $USER->id,
    'userid_comp' => $USER->id,
    'sitecourseid' => 1
]);

$overall_total = $overall_stats->total_enrolled ?? 0;
$overall_completed = $overall_stats->total_completed ?? 0;
$overall_inprogress = max(0, $overall_total - $overall_completed);

?>

<!-- ==================================================================== -->
<!-- WIDGET DAILY PRACTICE: 2-COLUMN OVERVIEW (GRID 8 - 4) -->
<!-- ==================================================================== -->

<!-- UI COMPONENT: DAILY PRACTICE OVERVIEW (GRID 8 - 4) -->
<?php if (!empty($all_user_dp_courses)): ?>
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="font-weight-bold text-dark mb-0">
            Ringkasan Daily Practice
        </h6>
    </div>
    <div class="card-body pt-0">
        <div class="row">
            
            <!-- KOLOM KIRI (LEBIH BESAR - GRID 8) -->
            <div class="col-md-8 border-right mb-3 mb-md-0">
                <div class="p-2 rounded bg-light mb-3">
                    <small class="text-muted d-block font-weight-bold text-uppercase" style="font-size:0.75rem;">
                        <i class="fa fa-calendar mr-1"></i> Performa Tahun Berjalan (<?php echo $current_year; ?>)
                    </small>
                </div>

                <div class="row align-items-center">
                    <!-- Sub-kolom A1: Total Latihan -->
                    <div class="col-6 border-right">
                        <small class="text-muted d-block mb-1">Progres Penyelesaian Latihan</small>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="h5 font-weight-bold text-dark mb-0"><?php echo $colA_completed; ?> <small class="text-muted h6">/ <?php echo $colA_total_quizzes; ?></small></span>
                            <span class="badge badge-success"><?php echo $colA_pct; ?>%</span>
                        </div>
                        <div class="progress rounded-pill mb-2" style="height: 6px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $colA_pct; ?>%;"></div>
                        </div>
                        <div class="d-flex justify-content-between small text-muted" style="font-size:0.75rem;">
                            <span class="text-success"><i class="fa fa-check mr-1"></i> Complete: <strong><?php echo $colA_completed; ?></strong></span>
                            <span class="text-danger"><i class="fa fa-times mr-1"></i> Missed: <strong><?php echo $colA_missed; ?></strong></span>
                        </div>
                    </div>

                    <!-- Sub-kolom A2: Nilai Akhir (Grade) Bersisian -->
                    <div class="col-6 text-center d-flex flex-column justify-content-center align-items-center">
                        <small class="text-muted d-block">Nilai Akhir Daily Practice Saat Ini</small>
                        <span class="font-weight-bold text-primary my-2" style="font-size: 2.2rem; line-height: 1.2;">
                            <?php echo $colA_final_grade; ?>
                        </span>
                        <span class="badge badge-light border text-muted px-2 py-1" style="font-size: 0.7rem;">
                            <i class="fa fa-star text-warning mr-1"></i> Skor Gradebook
                        </span>
                    </div>
                </div>
            </div>

            <!-- KOLOM KANAN (GRID 4 - LIMIT 3 COURSE) -->
            <div class="col-md-4">
                <div class="p-2 rounded bg-light mb-3">
                    <small class="text-muted d-block font-weight-bold text-uppercase" style="font-size:0.75rem;">
                        <i class="fa fa-book mr-1"></i> History Daily Practice
                    </small>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($top3_dp_courses as $c): ?>
                        <?php
                        // Hitung completion % per course
                        $c_total = $DB->count_records_sql("SELECT COUNT(cm.id) FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz' WHERE cm.course = ?", [$c->courseid]);
                        $c_done  = $DB->count_records_sql("SELECT COUNT(DISTINCT qa.quiz) FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz WHERE q.course = ? AND qa.userid = ? AND qa.state = 'finished'", [$c->courseid, $USER->id]);
                        $c_pct   = $c_total > 0 ? round(($c_done / $c_total) * 100, 1) : 0;
                        ?>
                        <div class="list-group-item px-0 py-1 border-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="<?php echo new moodle_url('/course/view.php', ['id' => $c->courseid]); ?>" class="font-weight-bold text-dark text-truncate small" target="_blank" title="<?php echo htmlspecialchars($c->coursename); ?>">
                                    <i class="fa fa-bookmark text-primary mr-1"></i> <?php echo htmlspecialchars($c->coursename); ?>
                                </a>
                                <small class="font-weight-bold text-muted" style="font-size:0.75rem;"><?php echo $c_pct; ?>%</small>
                            </div>
                            <div class="progress rounded-pill mt-1" style="height: 4px;">
                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $c_pct; ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================================================================== -->
<!-- WIDGET: RINGKASAN SELURUH COURSE YANG DI-ENROL                        -->
<!-- ==================================================================== -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
    <div class="card-body p-3">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            
            <!-- Deskripsi & Info Ringkas -->
            <div class="mb-3 mb-md-0">
                <h6 class="font-weight-bold text-dark mb-1">
                    <i class="fa fa-graduation-cap text-primary mr-2"></i> Ringkasan Seluruh Course
                </h6>
                <small class="text-muted">
                    Ringkasan course yang terdaftar atas nama Anda dalam sistem.<br/>
                    <a href="/my/courses.php">Lihat semua course yang anda ikuti > </a>
                </small>
            </div>

            <!-- Badges Metrics (Grid Ringkas) -->
            <div class="d-flex align-items-center flex-wrap gap-2">
                <div class="px-3 py-2 rounded bg-light border mr-2 mb-1 mb-md-0">
                    <small class="text-muted d-block font-weight-bold" style="font-size:0.7rem; line-height:1;">TOTAL ENROLLED</small>
                    <span class="h6 font-weight-bold text-dark mb-0"><?php echo $overall_total; ?> Course</span>
                </div>

                <div class="px-3 py-2 rounded bg-light-success border border-success mr-2 mb-1 mb-md-0">
                    <small class="text-success d-block font-weight-bold" style="font-size:0.7rem; line-height:1;">SELESAI</small>
                    <span class="h6 font-weight-bold text-success mb-0"><?php echo $overall_completed; ?> Course</span>
                </div>

                <div class="px-3 py-2 rounded bg-light-warning border border-warning mb-1 mb-md-0">
                    <small class="text-warning d-block font-weight-bold" style="font-size:0.7rem; line-height:1;">SEDANG BERJALAN</small>
                    <span class="h6 font-weight-bold text-warning mb-0"><?php echo $overall_inprogress; ?> Course</span>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- WIDGET: RINGKASAN GRAFIK & KPI ANALYTICS                          -->
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
                <small class="text-muted d-block">Course Terpenuhi (<?php echo $total_completed; ?> / <?php echo $total_courses; ?>)</small>
            </div>
        </div>
    </div>

    <!-- Card Komposisi Kategori Pelatihan -->
    <div class="col-md-8 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 h-100 bg-white">
            <h6 class="font-weight-bold text-dark mb-1">Komposisi Course Pelatihan per Kategori</h6>
            <small class="text-muted d-block mb-3">Jumlah course yang teralokasi berdasarkan kategori tag</small>
            
            <div style="height: 200px;">
                <canvas id="chartCategories"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ==================================================================== -->
<!-- WIDGET: TABEL RINGKAS PELATIHAN                                    -->
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
                label: 'Jumlah Course',
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