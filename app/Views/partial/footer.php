<?php

use Config\OSPOS;

?>

        </div>
    </div>

    <div id="footer">
        <div class="jumbotron push-spaces">
            <strong>
                <?= esc(lang('Common.copyrights', [date('Y')])) ?> ·
                <a href="https://opensourcepos.org" target="_blank"><?= esc(lang('Common.website')) ?></a> ·
                <?= esc(config('App')->application_version) ?> -
                <a target="_blank" href="https://github.com/opensourcepos/opensourcepos/commit/<?= esc(config(OSPOS::class)->commit_sha1) ?>">
                    <?= esc(substr(config(OSPOS::class)->commit_sha1, 0, 6)); ?>
                </a>
            </strong>.
            <?= view('partial/support_line') ?>
        </div>
    </div>
</body>

</html>
