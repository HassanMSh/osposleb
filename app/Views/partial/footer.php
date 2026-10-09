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
            <div class="footer-support">
                <?= esc(lang('Common.managed_by')) ?>
                <a href="https://hassanshamseddine.qzz.io/" target="_blank" rel="noopener noreferrer"><bdi dir="ltr">Shamseddine Tech</bdi></a> ·
                <?= esc(lang('Common.support_contact')) ?>
                <bdi dir="ltr">+96171881267</bdi>
            </div>
        </div>
    </div>
</body>

</html>
