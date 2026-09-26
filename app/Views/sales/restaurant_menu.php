<?php
/**
 * @var string $controller_name
 * @var array  $restaurant_menu_items
 */
$restaurant_categories = [];

foreach ($restaurant_menu_items as $restaurant_menu_item) {
    $category_name = trim((string) $restaurant_menu_item['category']);
    if ($category_name === '') {
        $category_name = lang('Sales.uncategorized');
    }

    $restaurant_categories[$category_name][] = $restaurant_menu_item;
}
?>

<?= form_open("{$controller_name}/add", ['id' => 'restaurant_add_item_form', 'class' => 'restaurant-menu panel panel-default']) ?>
    <?php if ($restaurant_categories === []) { ?>
        <p class="restaurant-menu-empty"><?= lang('Sales.restaurant_menu_empty') ?></p>
    <?php } else { ?>
        <nav class="restaurant-menu-tabs" role="tablist" aria-label="<?= lang('Sales.register') ?>">
            <?php $category_index = 0; ?>
            <?php foreach ($restaurant_categories as $category_name => $category_items) { ?>
                <button
                    type="button"
                    class="btn btn-default restaurant-menu-tab"
                    role="tab"
                    id="restaurant-menu-tab-<?= $category_index ?>"
                    aria-controls="restaurant-menu-category-<?= $category_index ?>"
                    aria-selected="<?= $category_index === 0 ? 'true' : 'false' ?>"
                    data-restaurant-category="<?= $category_index ?>"
                ><?= esc($category_name) ?></button>
                <?php $category_index++; ?>
            <?php } ?>
        </nav>

        <?php $category_index = 0; ?>
        <?php foreach ($restaurant_categories as $category_items) { ?>
            <section
                class="restaurant-menu-category"
                role="tabpanel"
                id="restaurant-menu-category-<?= $category_index ?>"
                aria-labelledby="restaurant-menu-tab-<?= $category_index ?>"
                <?= $category_index === 0 ? '' : 'hidden' ?>
            >
                <div class="restaurant-menu-grid">
                    <?php foreach ($category_items as $restaurant_menu_item) { ?>
                        <button
                            type="submit"
                            class="btn btn-default restaurant-menu-item"
                            name="item"
                            value="<?= (int) $restaurant_menu_item['item_id'] ?>"
                        >
                            <span class="restaurant-menu-item-name"><?= esc($restaurant_menu_item['name']) ?></span>
                            <span class="restaurant-menu-item-price" dir="ltr"><?= to_currency($restaurant_menu_item['unit_price']) ?></span>
                        </button>
                    <?php } ?>
                </div>
            </section>
            <?php $category_index++; ?>
        <?php } ?>
    <?php } ?>
<?= form_close() ?>
