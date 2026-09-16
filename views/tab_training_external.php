<?php
defined('MOODLE_INTERNAL') || die();

global $DB, $USER;

// -------------------------------------------------------------------------
// 1. AMBIL KONFIGURASI DARI settings.php
// -------------------------------------------------------------------------
$cf_creator_shortname = get_config('local_dashboard_ebi', 'ext_creator_course_shortname') ?: 'creator_course';
$cf_creator_val       = (int)(get_config('local_dashboard_ebi', 'ext_creator_course_value') ?? 2);

$cf_peserta_shortname = get_config('local_dashboard_ebi', 'ext_is_peserta_shortname') ?: 'is_peserta';
$cf_peserta_val       = (int)(get_config('local_dashboard_ebi', 'ext_is_peserta_value') ?? 1);

// -------------------------------------------------------------------------
// 2. QUERY SQL NATIVE (FIXED SCHEMA & AGGREGATION)
// -------------------------------------------------------------------------
$sql_my_ext = "SELECT 
                    c.id AS courseid,
                    c.fullname AS coursename,
                    MAX(ue.timeend) AS timeend,
                    CASE 
                        WHEN MIN(ue.status) = 0 THEN 
                            CASE 
                                WHEN (MAX(ue.timestart) > :now1) OR (MAX(ue.timeend) > 0 AND MAX(ue.timeend) < :now2) OR (MIN(e.status) = 1) THEN 2 
                                ELSE 0 
                            END 
                        ELSE MIN(ue.status) 
                    END AS enrol_status
                 FROM {course} c
                 JOIN {enrol} e ON e.courseid = c.id
                 JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
                 JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
            
            -- Join Group & Group Members (Wajib inner join atau pastikan relasinya mengikat)
            JOIN {groups_members} gm ON gm.userid = u.id
            JOIN {groups} g ON g.id = gm.groupid AND g.courseid = c.id

            -- Custom Field Group (is_peserta)
            JOIN {customfield_field} cf_g_field ON cf_g_field.shortname = :cf_peserta_shortname
            JOIN {customfield_data} cf_group ON cf_group.fieldid = cf_g_field.id 
                 AND (cf_group.instanceid = g.id OR cf_group.instanceid = gm.id)

            -- Custom Field Course (creator_course)
            JOIN {customfield_field} cf_c_field ON cf_c_field.shortname = :cf_creator_shortname
            JOIN {customfield_data} cf_course ON cf_course.fieldid = cf_c_field.id AND cf_course.instanceid = c.id

                WHERE c.id != 1
                  AND u.suspended = 0
                  -- KEDUA KONDISI WAJIB TERPENUHI (AND)
                  AND (cf_course.intvalue = :cf_creator_val OR LOWER(cf_course.charvalue) = 'external')
                  AND (cf_group.intvalue = :cf_peserta_val OR cf_group.charvalue = '1')
             GROUP BY c.id, c.fullname
             ORDER BY c.fullname ASC";

$my_ext_courses = $DB->get_records_sql($sql_my_ext, [
    'userid'               => $USER->id,
    'now1'                 => time(),
    'now2'                 => time(),
    'cf_creator_shortname' => $cf_creator_shortname,
    'cf_creator_val'       => $cf_creator_val,
    'cf_peserta_shortname' => $cf_peserta_shortname,
    'cf_peserta_val'       => $cf_peserta_val,
]);
?>

<div class="container-fluid p-0 mb-5">
    <div class="card border-0 shadow-sm rounded-lg mb-4">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
            <h6 class="font-weight-bold text-dark mb-0">
                <i class="fa fa-graduation-cap text-primary mr-2"></i> Pelatihan Eksternal Saya
            </h6>
            <span class="badge badge-light border"><?php echo count($my_ext_courses); ?> Pelatihan</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="thead-light">
                        <tr>
                            <th>Nama Pelatihan</th>
                            <th class="text-center">Status</th>
                            <th>Tanggal Berakhir</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($my_ext_courses)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">
                                    Belum ada data pelatihan eksternal untuk akun Anda.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($my_ext_courses as $row): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo new moodle_url('/course/view.php', ['id' => $row->courseid]); ?>" class="font-weight-bold text-primary" target="_blank">
                                            <?php echo htmlspecialchars($row->coursename); ?>
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row->enrol_status == 0): ?>
                                            <span class="badge badge-success px-2 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary px-2 py-1">Not current</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted">
                                        <?php echo !empty($row->timeend) ? date('l, d F Y, g:i A', $row->timeend) : '-'; ?>
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