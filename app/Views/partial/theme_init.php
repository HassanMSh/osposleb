<script>
    /* Apply the saved colour theme before the stylesheets load. */
    (function () {
        try {
            var theme = window.localStorage.getItem('ospos-theme');

            if (['dark', 'tech', 'earthy'].indexOf(theme) !== -1) {
                document.documentElement.setAttribute('data-ospos-theme', theme);
            }
        } catch (error) {
            /* Keep the default theme when browser storage is blocked. */
        }
    }());
</script>
