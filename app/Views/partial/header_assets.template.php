<?php
/**
 * @var array                            $config
 * @var CodeIgniter\HTTP\IncomingRequest $request
 */
?>
    <?php if (ENVIRONMENT === 'development' || get_cookie('debug') === 'true' || $request->getGet('debug') === 'true') : ?>
        <!-- inject:debug:css -->
        <!-- endinject -->
        <!-- inject:debug:js -->
        <!-- endinject -->
    <?php else : ?>
        <!--inject:prod:css -->
        <!-- endinject -->

        <!-- Tweaks to the UI for a particular theme should drop here  -->
        <?php if ($config['theme'] !== 'flatly' && file_exists($_SERVER['DOCUMENT_ROOT'] . '/public/css/' . esc($config['theme']) . '.css')) { ?>
            <link rel="stylesheet" href="<?= 'css/' . esc($config['theme']) . '.css' ?>">
        <?php } ?>
        <!-- inject:prod:js -->
        <!-- endinject -->
    <?php endif; ?>

