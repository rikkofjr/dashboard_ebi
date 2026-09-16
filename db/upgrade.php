<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_dashboard_ebi_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026091500) {

        // Definisi tabel local_dashboard_training_ext
        $table = new xmldb_table('local_dashboard_training_ext');

        // Menambahkan fields/kolom
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('column_identifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('column_heading', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('is_visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sort_order', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Menambahkan Primary Key dan Index
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('reportid_idx', XMLDB_INDEX_NOTUNIQUE, ['reportid']);

        // Eksekusi pembuatan tabel jika belum ada
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Simpan titik versi upgrade
        upgrade_plugin_savepoint(true, 2026091500, 'local', 'dashboard_ebi');
    }
    // delete table
    if ($oldversion < 2026091600) {
        $table = new xmldb_table('local_dashboard_training_ext');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091600, 'local', 'dashboard_ebi');
    }

    return true;
}