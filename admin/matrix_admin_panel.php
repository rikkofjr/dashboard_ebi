<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/local/dashboard_ebi/admin/matrix_admin_panel.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title('Rule Mapping Learning Path');
$PAGE->set_heading('Pengaturan Matriks Tag Per Level Jabatan');
$PAGE->set_pagelayout('admin');

global $DB;

// Ambil parameter level jabatan terpilih (Master vs Detail)
$selected_jabatan = optional_param('jabatan', '', PARAM_TEXT);

// HANDLE POST ACTIONS
if (data_submitted() && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_TEXT);

    // 1. Hapus Single Rule
    if ($action === 'delete_rule') {
        $id = required_param('rule_id', PARAM_INT);
        $DB->delete_records('local_dashboard_matrix', ['id' => $id]);
        redirect(new moodle_url($url, ['jabatan' => $selected_jabatan]), 'Rule berhasil dihapus!', null, \core\output\notification::NOTIFY_SUCCESS);
    } 
    // 2. Hapus Seluruh Rule Jabatan
    else if ($action === 'delete_jabatan') {
        $jabatan_to_del = required_param('jabatan_to_del', PARAM_TEXT);
        $DB->delete_records('local_dashboard_matrix', ['level_jabatan' => $jabatan_to_del]);
        redirect($url, 'Seluruh rule untuk jabatan berhasil dihapus!', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    // 3. Tambah Level Jabatan Baru (Master)
    else if ($action === 'add_jabatan') {
        $new_jabatan = strtolower(trim(required_param('new_jabatan', PARAM_TEXT)));
        if (!empty($new_jabatan)) {
            redirect(new moodle_url($url, ['jabatan' => $new_jabatan]));
        }
    }
    // 4. Tambah / Update Kategori & Status Tag (Detail)
    else if ($action === 'save_rule') {
        $jabatan  = strtolower(trim(required_param('level_jabatan', PARAM_TEXT)));
        $kategori = strtolower(trim(required_param('kategori_tag', PARAM_TEXT)));
        $status   = strtolower(trim(required_param('status_tag', PARAM_TEXT)));

        $existing = $DB->get_record('local_dashboard_matrix', [
            'level_jabatan' => $jabatan,
            'kategori_tag'  => $kategori
        ]);

        if ($existing) {
            $existing->status_tag = $status;
            $DB->update_record('local_dashboard_matrix', $existing);
        } else {
            $rec = new stdClass();
            $rec->level_jabatan = $jabatan;
            $rec->kategori_tag  = $kategori;
            $rec->status_tag    = $status;
            $DB->insert_record('local_dashboard_matrix', $rec);
        }
        redirect(new moodle_url($url, ['jabatan' => $jabatan]), 'Rule Kategori berhasil disimpan!', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Ambil Seluruh Tag Moodle untuk Autocomplete List
$moodle_tags = $DB->get_records_menu('tag', [], 'name ASC', 'id, name');

echo $OUTPUT->header();
?>

<!-- HTML5 DataList untuk Autocomplete dari seluruh Tag Moodle -->
<datalist id="moodleTagsList">
    <?php foreach ($moodle_tags as $tag_item): ?>
        <option value="<?php echo htmlspecialchars($tag_item); ?>"></option>
    <?php endforeach; ?>
</datalist>

<div class="container-fluid mb-5">

<?php if (empty($selected_jabatan)): ?>
    <!-- ==================================================================== -->
    <!-- HALAMAN 1: MASTER LEVEL JABATAN                                      -->
    <!-- ==================================================================== -->
    
    <div class="card border-0 shadow-sm p-4 mb-4 bg-white rounded-lg">
        <h5 class="font-weight-bold text-primary mb-3">
            <i class="fa fa-plus-circle mr-2"></i> Tambah Master Level Jabatan
        </h5>
        <form method="post" action="" class="form-inline">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="add_jabatan">
            
            <label class="mr-2 font-weight-bold small">Cari / Input Tag Level Jabatan:</label>
            <input type="text" name="new_jabatan" list="moodleTagsList" class="form-control form-control-sm mr-2" style="min-width: 300px;" placeholder="Ketik/Pilih Tag (misal: store supervisor)" autocomplete="off" required>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fa fa-arrow-right mr-1"></i> Atur Kategori Pelatihan
            </button>
        </form>
    </div>

    <!-- Tabel Master Level Jabatan -->
    <div class="card border-0 shadow-sm p-4 bg-white rounded-lg">
        <h5 class="font-weight-bold text-dark mb-3">
            <i class="fa fa-sitemap mr-2"></i> Daftar Level Jabatan Terkonfigurasi
        </h5>
        
        <?php 
        $jabatan_list = $DB->get_records_sql("SELECT level_jabatan, COUNT(id) AS total_rules 
                                                FROM {local_dashboard_matrix} 
                                            GROUP BY level_jabatan 
                                            ORDER BY level_jabatan ASC");
        ?>
        
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle" style="font-size: 0.85rem;">
                <thead class="thead-light">
                    <tr>
                        <th>Level Jabatan (Tag Target)</th>
                        <th class="text-center">Jumlah Kategori Configured</th>
                        <th class="text-center" style="width: 220px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($jabatan_list)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">Belum ada Level Jabatan yang dikonfigurasi. Silakan tambah di atas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($jabatan_list as $j): ?>
                            <tr>
                                <td>
                                    <strong class="text-primary font-weight-bold" style="font-size: 0.95rem;">
                                        <i class="fa fa-briefcase mr-2"></i><?php echo htmlspecialchars(strtoupper($j->level_jabatan)); ?>
                                    </strong>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-info p-2"><?php echo $j->total_rules; ?> Kategori Rule</span>
                                </td>
                                <td class="text-center">
                                    <a href="<?php echo new moodle_url($url, ['jabatan' => $j->level_jabatan]); ?>" class="btn btn-primary btn-sm mr-1">
                                        <i class="fa fa-cog mr-1"></i> Kelola Rule Tag
                                    </a>
                                    <form method="post" action="" class="d-inline" onsubmit="return confirm('Hapus seluruh konfigurasi untuk jabatan ini?');">
                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                        <input type="hidden" name="action" value="delete_jabatan">
                                        <input type="hidden" name="jabatan_to_del" value="<?php echo htmlspecialchars($j->level_jabatan); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <!-- ==================================================================== -->
    <!-- HALAMAN 2: DETAIL RULE PER LEVEL JABATAN TERPILIH                    -->
    <!-- ==================================================================== -->
    
    <div class="mb-3">
        <a href="<?php echo $url; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left mr-1"></i> Kembali ke Master Level Jabatan
        </a>
    </div>

    <div class="card border-0 shadow-sm p-3 mb-4 bg-primary text-white rounded-lg">
        <h4 class="font-weight-bold mb-0">
            <i class="fa fa-sliders mr-2"></i> Konfigurasi Kategori Tag untuk Jabatan: 
            <u class="text-warning"><?php echo htmlspecialchars(strtoupper($selected_jabatan)); ?></u>
        </h4>
    </div>

    <!-- Form Detail Rule dengan Autocomplete Tag Kategori & Status -->
    <div class="card border-0 shadow-sm p-4 mb-4 bg-white rounded-lg">
        <h6 class="font-weight-bold text-dark mb-3">Tambah / Update Kategori Pelatihan</h6>
        <form method="post" action="" class="row align-items-end">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save_rule">
            <input type="hidden" name="level_jabatan" value="<?php echo htmlspecialchars($selected_jabatan); ?>">

            <div class="col-md-5 mb-2">
                <label class="font-weight-bold small">Tag Kategori Course:</label>
                <input type="text" name="kategori_tag" list="moodleTagsList" class="form-control form-control-sm" placeholder="Ketik/Pilih Tag (misal: mandatory, fundamental)" autocomplete="off" required>
            </div>

            <div class="col-md-4 mb-2">
                <label class="font-weight-bold small">Tag Status Access Course:</label>
                <input type="text" name="status_tag" list="moodleTagsList" class="form-control form-control-sm" placeholder="Ketik/Pilih Tag (misal: open, closed)" autocomplete="off" required>
            </div>

            <div class="col-md-3 mb-2">
                <button type="submit" class="btn btn-success btn-sm btn-block">
                    <i class="fa fa-plus mr-1"></i> Simpan Kategori Rule
                </button>
            </div>
        </form>
    </div>

    <!-- Tabel Matriks Aktif -->
    <div class="card border-0 shadow-sm p-4 bg-white rounded-lg">
        <?php 
        $current_rules = $DB->get_records('local_dashboard_matrix', ['level_jabatan' => $selected_jabatan], 'kategori_tag ASC');
        ?>
        <h6 class="font-weight-bold text-dark mb-3">Matriks Pelatihan Aktif</h6>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle" style="font-size: 0.85rem;">
                <thead class="thead-light">
                    <tr>
                        <th>Kategori Tag</th>
                        <th>Status Tag Access</th>
                        <th>Syarat Kombinasi Tag yang Wajib Terpasang di Course Moodle</th>
                        <th class="text-center" style="width: 100px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($current_rules)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">Belum ada Kategori yang dikonfigurasi untuk jabatan ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($current_rules as $r): ?>
                            <tr>
                                <td><span class="badge badge-info p-2" style="font-size: 0.85rem;"><?php echo htmlspecialchars($r->kategori_tag); ?></span></td>
                                <td>
                                    <?php if ($r->status_tag === 'closed'): ?>
                                        <span class="badge badge-danger p-1"><i class="fa fa-lock mr-1"></i> <?php echo htmlspecialchars($r->status_tag); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-success p-1"><i class="fa fa-unlock mr-1"></i> <?php echo htmlspecialchars($r->status_tag); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?php echo htmlspecialchars($selected_jabatan); ?></code> + 
                                    <code><?php echo htmlspecialchars($r->kategori_tag); ?></code> + 
                                    <code><?php echo htmlspecialchars($r->status_tag); ?></code>
                                </td>
                                <td class="text-center">
                                    <form method="post" action="" onsubmit="return confirm('Hapus rule kategori ini?');">
                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                        <input type="hidden" name="action" value="delete_rule">
                                        <input type="hidden" name="rule_id" value="<?php echo $r->id; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();