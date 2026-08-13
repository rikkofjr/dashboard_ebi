<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();

$url = new moodle_url('/local/dashboard_ebi/index.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title('Employee Dashboard');
$PAGE->set_heading('Dashboard');
$PAGE->set_pagelayout('standard');

global $DB, $USER;

// Ambil Tab Aktif (Default: my_learning_path)
$active_tab = optional_param('tab', 'my_learning_path', PARAM_TEXT);

echo $OUTPUT->header();
?>

<div class="container-fluid p-0 mb-5">
    <!-- Header Title -->
    <div class="card border-0 shadow-sm rounded-lg bg-primary text-white p-3 mb-4">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="font-weight-bold mb-0">Dashboard</h4>
                <small class="text-white-50">Portal Pembelajaran & Pengembangan Individu / Tim</small>
            </div>
            <div>
                <span class="badge badge-light p-2 font-weight-bold text-primary">
                    <i class="fa fa-user mr-1"></i> <?php echo fullname($USER); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- TABBED NAVIGATION BAR -->
    <ul class="nav nav-tabs nav-fill font-weight-bold border-bottom-0 mb-4" id="dashboardTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link border-0 shadow-sm rounded-top mr-1 <?php echo ($active_tab === 'my_learning_path') ? 'active bg-white text-primary' : 'bg-light text-muted'; ?>" 
               href="<?php echo new moodle_url($url, ['tab' => 'my_learning_path']); ?>">
                <i class="fa fa-book mr-2"></i> My Learning Path
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link border-0 shadow-sm rounded-top mr-1 <?php echo ($active_tab === 'team_learning_path') ? 'active bg-white text-primary' : 'bg-light text-muted'; ?>" 
               href="<?php echo new moodle_url($url, ['tab' => 'team_learning_path']); ?>">
                <i class="fa fa-users mr-2"></i> Team Learning Path
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link border-0 shadow-sm rounded-top mr-1 <?php echo ($active_tab === 'my_idp') ? 'active bg-white text-primary' : 'bg-light text-muted'; ?>" 
               href="<?php echo new moodle_url($url, ['tab' => 'my_idp']); ?>">
                <i class="fa fa-id-card mr-2"></i> My IDP
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link border-0 shadow-sm rounded-top <?php echo ($active_tab === 'team_idp') ? 'active bg-white text-primary' : 'bg-light text-muted'; ?>" 
               href="<?php echo new moodle_url($url, ['tab' => 'team_idp']); ?>">
                <i class="fa fa-sitemap mr-2"></i> Team IDP
            </a>
        </li>
    </ul>

    <!-- LOAD COMPONENT VIEW BERDASARKAN TAB -->
    <div class="tab-content">
        <?php
        switch ($active_tab) {
            case 'team_learning_path':
                include(__DIR__ . '/views/tab_team_learning_path.php');
                break;
            case 'my_idp':
                include(__DIR__ . '/views/tab_my_idp.php');
                break;
            case 'team_idp':
                include(__DIR__ . '/views/tab_team_idp.php');
                break;
            case 'my_learning_path':
            default:
                include(__DIR__ . '/views/tab_my_learning_path.php');
                break;
        }
        ?>
    </div>
</div>

<?php
echo $OUTPUT->footer();