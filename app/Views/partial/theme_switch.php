<span class="ospos-theme-switch">
    <span class="glyphicon glyphicon-adjust" aria-hidden="true"></span>
    <label class="sr-only" for="ospos-theme-select"><?= esc(lang('Common.theme')) ?></label>
    <select id="ospos-theme-select" aria-label="<?= esc(lang('Common.theme')) ?>" title="<?= esc(lang('Common.theme')) ?>">
        <option value=""><?= esc(lang('Common.theme_default')) ?></option>
        <option value="dark"><?= esc(lang('Common.theme_dark')) ?></option>
        <option value="tech"><?= esc(lang('Common.theme_tech')) ?></option>
        <option value="earthy"><?= esc(lang('Common.theme_earthy')) ?></option>
    </select>
</span>
<script>
    /* Keep the theme picker in sync with the page and browser storage. */
    (function () {
        var themeSelect = document.getElementById('ospos-theme-select');

        if (!themeSelect) {
            return;
        }

        var allowedThemes = ['dark', 'tech', 'earthy'];
        var currentTheme = document.documentElement.getAttribute('data-ospos-theme');

        themeSelect.value = allowedThemes.indexOf(currentTheme) === -1 ? '' : currentTheme;
        themeSelect.addEventListener('change', function () {
            var selectedTheme = themeSelect.value;

            if (selectedTheme !== '' && allowedThemes.indexOf(selectedTheme) === -1) {
                return;
            }

            if (selectedTheme === '') {
                document.documentElement.removeAttribute('data-ospos-theme');
            } else {
                document.documentElement.setAttribute('data-ospos-theme', selectedTheme);
            }

            try {
                if (selectedTheme === '') {
                    window.localStorage.removeItem('ospos-theme');
                } else {
                    window.localStorage.setItem('ospos-theme', selectedTheme);
                }
            } catch (error) {
                /* Keep the selected theme for this page when storage is blocked. */
            }
        });
    }());
</script>
