<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings = new admin_settingpage('local_dashboard_ebi', get_string('pluginname', 'local_dashboard_ebi'));

    // 1. Shortname Profile Field Direktorat
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/field_direktorat',
        'Profile Field Shortname Direktorat',
        'Shortname dari custom profile field yang menyimpan data Direktorat karyawan.',
        'direktorat',
        PARAM_TEXT
    ));

    // 2. Shortname Profile Field Jabatan
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/field_level_jabatan',
        get_string('setting_field_level_jabatan', 'local_dashboard_ebi'),
        get_string('setting_field_level_jabatan_desc', 'local_dashboard_ebi'),
        'level_jabatan',
        PARAM_TEXT
    ));

    // 3. Shortname Profile Field Atasan Langsung
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/field_atasan_langsung',
        get_string('setting_field_atasan_langsung', 'local_dashboard_ebi'),
        get_string('setting_field_atasan_langsung_desc', 'local_dashboard_ebi'),
        'atasan_langsung',
        PARAM_TEXT
    ));

    // 4. Tipe Identitas Atasan yang Dicocokkan
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

    // =========================================================================
    // SECTION 2: TRAINING EXTERNAL SETTINGS
    // =========================================================================
    $settings->add(new admin_setting_heading(
        'local_dashboard_ebi/header_training_ext',
        'Pengaturan Filter Training External',
        'Konfigurasi condition custom field untuk penyaringan data pelatihan eksternal.'
    ));

    // 5. Course Custom Field: Creator Course Shortname
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/ext_creator_course_shortname',
        'Course Custom Field Shortname (Creator Course)',
        'Shortname custom field pada level course.',
        'creator_course',
        PARAM_TEXT
    ));

    // 6. Course Custom Field: Creator Course Value
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/ext_creator_course_value',
        'Course Custom Field Value',
        'Nilai teks pembanding untuk Creator Course.',
        'EXTERNAL',
        PARAM_TEXT
    ));

    // 7. Group Custom Field: Is Peserta Shortname
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/ext_is_peserta_shortname',
        'Group Custom Field Shortname (Peserta Training External)',
        'Shortname custom field pada level group.',
        'is_peserta',
        PARAM_TEXT
    ));

    // 8. Group Custom Field: Is Peserta Value
    $settings->add(new admin_setting_configtext(
        'local_dashboard_ebi/ext_is_peserta_value',
        'Group Custom Field Value',
        'Nilai intvalue pembanding untuk penanda Yes/No (contoh: 1).',
        '1',
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}