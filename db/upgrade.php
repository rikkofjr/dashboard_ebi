<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_dashboard_ebi_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Contoh penanganan continuous development untuk versi mendatang:
    /*
    if ($oldversion < 2026080600) {
        // Logika penambahan kolom/tabel baru di masa depan ditulis di sini
        upgrade_plugin_savepoint(true, 2026080600, 'local', 'dashboard_ebi');
    }
    */

    return true;
}