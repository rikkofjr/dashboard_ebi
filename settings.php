<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings = new admin_settingpage('local_dashboard_ebi', get_string('pluginname', 'local_dashboard_ebi'));

    // 1. Shortname Profile Field Jabatan
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/field_level_jabatan',
        get_string('setting_field_level_jabatan', 'local_dashboard_ebi'),
        get_string('setting_field_level_jabatan_desc', 'local_dashboard_ebi'),
        'level_jabatan',
        PARAM_TEXT
    ));

    // 2. Shortname Profile Field Atasan Langsung
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/field_atasan_langsung',
        get_string('setting_field_atasan_langsung', 'local_dashboard_ebi'),
        get_string('setting_field_atasan_langsung_desc', 'local_dashboard_ebi'),
        'atasan_langsung',
        PARAM_TEXT
    ));

    // 3. Tipe Identitas Atasan yang Dicocokkan
    $options = [
        'username' => get_string('key_username', 'local_dashboard_ebi'),
        'email'    => get_string('key_email', 'local_dashboard_ebi'),
        'idnumber' => get_string('key_idnumber', 'local_dashboard_ebi')
    ];
    $settings->add(new admin_setting_configselect(
        'local_dashboard_ebi/manager_key_type',
        get_string('setting_manager_key_type', 'local_dashboard_ebi'),
        get_string('setting_manager_key_type_desc', 'local_dashboard_ebi'),
        'username',
        $options
    ));

    $ADMIN->add('localplugins', $settings);
}