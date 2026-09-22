<?php if (! is_file(APPPATH . 'Views/partial/header_assets.php')): ?>
    <div role="alert" style="position: relative; z-index: 10000; margin: 12px; padding: 12px; border: 2px solid #9f6000; background: #fff4cc; color: #402d00; font: bold 16px/1.4 sans-serif;">
        Front-end assets are not built. Run <code>docker compose -f docker-compose.dev.yml up</code> or <code>npm run build</code> from the project root.
    </div>
<?php endif; ?>
