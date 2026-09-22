<?php
/**
 * @var object                           $user_info
 * @var array                            $allowed_modules
 * @var CodeIgniter\HTTP\IncomingRequest $request
 * @var array                            $config
 */

use Config\Services;

$request = Services::request();
?>

<!doctype html>
<?php
/*
 * The declared language and the writing direction must both come from the
 * application's configured language. $request->getLocale() reflects browser
 * content negotiation, which can report a locale the page is not rendered in.
 */
$language_code = current_language_code();
?>
<html lang="<?= $language_code ?>" dir="<?= text_direction($language_code) ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc($config['company']) . ' | ' . lang('Common.powered_by') . ' OSPOS ' . esc(config('App')->application_version) ?></title>
    <link rel="shortcut icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="<?= 'resources/bootswatch/' . (empty($config['theme']) ? 'flatly' : esc($config['theme'])) . '/bootstrap.min.css' ?>">

    <?php
    $header_assets_path = APPPATH . 'Views/partial/header_assets.php';
$header_assets_built    = is_file($header_assets_path);
if ($header_assets_built) {
    include $header_assets_path;
}
?>

    <?= view('partial/header_js') ?>
    <?= view('partial/lang_lines') ?>

    <style>
        html {
            overflow: auto;
        }
    </style>
</head>

<body>
    <?php if (! $header_assets_built): ?>
        <?= view('partial/assets_not_built_notice') ?>
    <?php endif; ?>
    <div class="wrapper">
        <div class="topbar">
            <div class="container">
                <div class="navbar-left">
                    <div id="liveclock"><?= date($config['dateformat'] . ' ' . $config['timeformat']) ?></div>
                </div>

                <div class="navbar-right" style="margin: 0;">
                    <?= anchor("home/changePassword/{$user_info->person_id}", "{$user_info->first_name} {$user_info->last_name}", ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Employees.change_password')]) ?>
                    <span>&nbsp;|&nbsp;</span>
                    <?= anchor('home/logout', lang('Login.logout')) ?>
                </div>

                <div class="navbar-center" style="text-align: center;">
                    <strong><?= esc($config['company']) ?></strong>
                </div>
            </div>
        </div>

        <div class="navbar navbar-default" role="navigation">
            <div class="container">
                <div class="navbar-header">
                    <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target=".navbar-collapse">
                        <span class="sr-only">Toggle navigation</span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                        <span class="icon-bar"></span>
                    </button>

                    <a class="navbar-brand hidden-sm" href="<?= site_url() ?>">OSPOS</a>
                </div>

                <div class="navbar-collapse collapse">
                    <ul class="nav navbar-nav navbar-right">
                        <?php foreach ($allowed_modules as $module): ?>
                            <li class="<?= $module->module_id === $request->getUri()->getSegment(1) ? 'active' : '' ?>">
                                <a href="<?= base_url($module->module_id) ?>" title="<?= lang("Module.{$module->module_id}") ?>" class="menu-icon">
                                    <img src="<?= base_url("images/menubar/{$module->module_id}.svg") ?>" style="border: none;" alt="Module Icon"><br>
                                    <?= lang('Module.' . $module->module_id) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="container">
            <div class="row">
