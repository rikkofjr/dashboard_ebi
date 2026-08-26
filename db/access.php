<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Capability untuk mengelola panel matrix admin dashboard EBI
    'local/dashboard_ebi:manage_matrix' => [
        'riskbitmask' => RISK_CONFIG | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_PREVENT,
            'teacher' => CAP_PREVENT,
            'student' => CAP_PREVENT,
            'guest' => CAP_PREVENT,
            'user' => CAP_PREVENT,
        ],
    ],

    // Capability alternatif jika Anda ingin membedakan antara sekadar melihat dan mengelola
    'local/dashboard_ebi:view_matrix' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_PREVENT,
            'teacher' => CAP_PREVENT,
            'student' => CAP_PREVENT,
            'guest' => CAP_PREVENT,
            'user' => CAP_PREVENT,
        ],
    ],
];