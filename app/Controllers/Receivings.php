<?php

namespace App\Controllers;

use App\Libraries\Barcode_lib;
use App\Libraries\Receiving_lib;
use App\Libraries\Token_lib;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_kit;
use App\Models\Receiving;
use App\Models\Stock_location;
use Config\OSPOS;
use ReflectionException;

class Receivings extends Secure_Controller
{
    private Receiving_lib $receiving_lib;
    private Token_lib $token_lib;
    private Barcode_lib $barcode_lib;
    private Inventory $inventory;
    private Item $item;
    private Item_kit $item_kit;
    private Receiving $receiving;
    private Stock_location $stock_location;
    private array $config;

    public function __construct()
    {
        parent::__construct('receivings');

        $this->receiving_lib = new Receiving_lib();
        $this->token_lib     = new Token_lib();
        $this->barcode_lib   = new Barcode_lib();

        $this->inventory      = model(Inventory::class);
        $this->item_kit       = model(Item_kit::class);
        $this->item           = model(Item::class);
        $this->receiving      = model(Receiving::class);
        $this->stock_location = model(Stock_location::class);
        $this->config         = config(OSPOS::class)->settings;
    }

    /**
     * Shows the receiving screen or recovers it when saved locations lost their grants.
     */
    public function getIndex(): void
    {
        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        $this->_reload();
    }

    /**
     * Returns search suggestions for an item. Used in app/Views/sales/register.php
     *
     * @noinspection PhpUnused
     */
    public function getItemSearch(): void
    {
        $search      = $this->request->getGet('term');
        $suggestions = $this->item->get_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true);
        $suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

        echo json_encode($suggestions);
    }

    /**
     * Gets search suggestions for a stock item. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function getStockItemSearch(): void
    {
        $search      = $this->request->getGet('term');
        $suggestions = $this->item->get_stock_search_suggestions($search, ['search_custom' => false, 'is_deleted' => false], true);
        $suggestions = array_merge($suggestions, $this->item_kit->get_search_suggestions($search));

        echo json_encode($suggestions);
    }

    /**
     * Changes receiving mode or stock locations after checking and recovering the current receiving state.
     *
     * @noinspection PhpUnused
     */
    public function postChangeMode(): void
    {
        $stock_destination   = $this->request->getPost('stock_destination', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $stock_source        = $this->request->getPost('stock_source', FILTER_SANITIZE_NUMBER_INT);
        $requested_locations = [];

        if ($stock_source) {
            $requested_locations[] = $stock_source;
        }
        if ($stock_destination) {
            $requested_locations[] = $stock_destination;
        }

        if (! $this->hasReceivingLocationAccess($requested_locations)) {
            return;
        }

        if ((! $stock_source || $stock_source == $this->receiving_lib->get_stock_source())
            && (! $stock_destination || $stock_destination == $this->receiving_lib->get_stock_destination())
        ) {
            $this->receiving_lib->clear_reference();
            $mode = $this->request->getPost('mode', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $this->receiving_lib->set_mode($mode);
        } elseif ($this->stock_location->is_allowed_location($stock_source, 'receivings')) {
            $this->receiving_lib->set_stock_source($stock_source);
            $this->receiving_lib->set_stock_destination($stock_destination);
        }

        $this->_reload();    // TODO: Hungarian notation
    }

    /**
     * Stores the receiving comment as typed, trimmed but not HTML-encoded.
     *
     * @noinspection PhpUnused
     */
    public function postSetComment(): void
    {
        $this->receiving_lib->set_comment($this->postedText('comment'));
    }

    /**
     * Sets the print after sale flag for the receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postSetPrintAfterSale(): void
    {
        $this->receiving_lib->set_print_after_sale($this->request->getPost('recv_print_after_sale') != null);
    }

    /**
     * Stores the receiving reference as typed, trimmed but not HTML-encoded.
     *
     * @noinspection PhpUnused
     */
    public function postSetReference(): void
    {
        $this->receiving_lib->set_reference($this->postedText('recv_reference'));
    }

    /**
     * Checks current grants and saved receipt locations before adding items or loading a return.
     *
     * @noinspection PhpUnused
     */
    public function postAdd(): void
    {
        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        $data = [];

        $mode                                     = $this->receiving_lib->get_mode();
        $item_id_or_number_or_item_kit_or_receipt = $this->request->getPost('item', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $this->token_lib->parse_barcode($quantity, $price, $item_id_or_number_or_item_kit_or_receipt);
        $quantity      = ($mode == 'receive' || $mode == 'requisition') ? $quantity : -$quantity;
        $item_location = $this->receiving_lib->get_stock_source();
        $discount      = $this->config['default_receivings_discount'];
        $discount_type = $this->config['default_receivings_discount_type'];

        if ($mode == 'return' && $this->receiving->is_valid_receipt($item_id_or_number_or_item_kit_or_receipt)) {
            $receipt_pieces = explode(' ', $item_id_or_number_or_item_kit_or_receipt);
            $receiving_id   = count($receipt_pieces) === 2 && in_array($receipt_pieces[0], ['RECV', 'KIT'], true)
                ? $receipt_pieces[1]
                : $this->receiving->get_receiving_by_reference($item_id_or_number_or_item_kit_or_receipt)->getRowArray()['receiving_id'];

            if ($this->hasSavedReceivingLocationAccess([$receiving_id])) {
                $this->receiving_lib->return_entire_receiving($item_id_or_number_or_item_kit_or_receipt);
            } else {
                $data['error'] = lang('Receivings.unable_to_add_item');
            }
        } elseif ($this->item_kit->is_valid_item_kit($item_id_or_number_or_item_kit_or_receipt)) {
            $this->receiving_lib->add_item_kit($item_id_or_number_or_item_kit_or_receipt, $item_location, $discount, $discount_type);
        } elseif (! $this->receiving_lib->add_item($item_id_or_number_or_item_kit_or_receipt, $quantity, $item_location, $discount, $discount_type)) {
            $data['error'] = lang('Receivings.unable_to_add_item');
        }

        $this->_reload($data);    // TODO: Hungarian notation
    }

    /**
     * Checks location access, validates receiving line fields, and converts LL prices and fixed discounts to dollars.
     * Refuses negative prices, quantities that save as zero, and missing or non-string fields.
     * Stores line text as typed so the views can escape it once when shown.
     *
     * @noinspection PhpUnused
     *
     * @param mixed $item_id
     */
    public function postEditItem($item_id): void
    {
        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        $data                   = [];
        $price_input            = $this->request->getPost('price');
        $quantity_input         = $this->request->getPost('quantity');
        $discount_input         = $this->request->getPost('discount');
        $raw_receiving_quantity = $this->request->getPost('receiving_quantity');
        $description            = $this->request->getPost('description');
        $serialnumber           = $this->request->getPost('serialnumber');

        if (! is_string($price_input)
            || ! is_string($quantity_input)
            || ! is_string($discount_input)
            || ! is_string($raw_receiving_quantity)
            || ! is_string($description)
            || ! is_string($serialnumber)
        ) {
            $data['error'] = lang('Receivings.error_editing_item');
            $this->_reload($data);

            return;
        }

        $validation_rule = [
            'price'    => 'trim|required|decimal_locale',
            'quantity' => 'trim|required|decimal_locale',
            'discount' => 'trim|permit_empty|decimal_locale',
        ];

        if (! $this->validate($validation_rule)) {
            $data['error'] = lang('Receivings.error_editing_item');
            $this->_reload($data);

            return;
        }

        $price_lbp = parse_decimals($price_input);
        if ($price_lbp < 0) {
            $data['error'] = lang('Receivings.price_non_negative');
            $this->_reload($data);

            return;
        }

        $quantity = parse_quantity($quantity_input);
        if (is_quantity_zero($quantity)) {
            $data['error'] = lang('Receivings.quantity_zero');
            $this->_reload($data);

            return;
        }

        $lbp_rate = $this->config['lbp_exchange_rate'] ?? null;
        if (! is_numeric($lbp_rate) || (float) $lbp_rate <= 0) {
            $data['error'] = lang('Common.lbp_rate_missing');
            $this->_reload($data);

            return;
        }

        $description  = trim($description);
        $serialnumber = trim($serialnumber);
        if (trim($discount_input) === '') {
            $discount_input = '0';
        }

        $cart                 = $this->receiving_lib->get_cart();
        $line_item            = $cart[$item_id] ?? [];
        $posted_discount_type = $this->request->getPost('discount_type', FILTER_SANITIZE_NUMBER_INT);
        $discount_type        = $posted_discount_type === null ? ($line_item['discount_type'] ?? FIXED) : (int) $posted_discount_type;
        $currency_decimals    = (int) ($this->config['currency_decimals'] ?? 2);
        if ((int) $discount_type === FIXED) {
            $discount_lbp = parse_decimals($discount_input);
            $discount     = lbp_to_dollar_string(
                (string) $discount_lbp,
                $lbp_rate,
                $line_item['discount'] ?? null,
                true,
                $currency_decimals,
            );
        } else {
            $discount = parse_quantity(filter_var($discount_input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION));
        }

        $price = lbp_to_dollar_string(
            (string) $price_lbp,
            $lbp_rate,
            $line_item['price'] ?? null,
            true,
            $currency_decimals,
        );

        $receiving_quantity = filter_var($raw_receiving_quantity, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $this->receiving_lib->edit_item($item_id, $description, $serialnumber, $quantity, $discount, $discount_type, (float) $price, $receiving_quantity);

        $this->_reload($data);    // TODO: Hungarian notation
    }

    /**
     * Loads editable receiving details without a supplier picker.
     *
     * @noinspection PhpUnused
     *
     * @param mixed $receiving_id
     */
    public function getEdit($receiving_id): void
    {
        $data = [];

        $data['employees'] = [];

        foreach ($this->employee->get_all()->getResult() as $employee) {
            $data['employees'][$employee->person_id] = $employee->first_name . ' ' . $employee->last_name;
        }

        $receiving_info         = $this->receiving->get_info($receiving_id)->getRowArray();
        $data['receiving_info'] = $receiving_info;

        echo view('receivings/form', $data);
    }

    /**
     * Deletes a cart line only after checking the current receiving locations. Used in the receiving view.
     *
     * @noinspection PhpUnused
     *
     * @param mixed $item_number
     */
    public function getDeleteItem($item_number): void
    {
        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        $this->receiving_lib->delete_item($item_number);

        $this->_reload();    // TODO: Hungarian notation
    }

    /**
     * Deletes receivings only when the employee can access their current and saved stock locations.
     *
     * @throws ReflectionException
     */
    public function postDelete(int $receiving_id = -1, bool $update_inventory = true): void
    {
        $receiving_ids = $receiving_id == -1 ? $this->request->getPost('ids', FILTER_SANITIZE_NUMBER_INT) : [$receiving_id];    // TODO: Replace -1 with constant

        if (! is_array($receiving_ids)) {
            $receiving_ids = $receiving_ids === null ? [] : [$receiving_ids];
        }

        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        if (! $this->hasSavedReceivingLocationAccess($receiving_ids)) {
            echo json_encode(['success' => false, 'message' => lang('Receivings.location_not_allowed')]);

            return;
        }

        $employee_id = $this->employee->get_logged_in_employee_info()->person_id;

        if ($this->receiving->delete_list($receiving_ids, $employee_id, $update_inventory)) {    // TODO: Likely need to surround this block of code in a try-catch to catch the ReflectionException
            echo json_encode([
                'success' => true,
                'message' => lang('Receivings.successfully_deleted') . ' ' . count($receiving_ids) . ' ' . lang('Receivings.one_or_multiple'),
                'ids'     => $receiving_ids,
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => lang('Receivings.cannot_be_deleted')]);
        }
    }

    /**
     * Recovers stale location grants and refuses missing rates, negative prices, or zero quantities before saving.
     * Successful receivings keep their rounded LL totals.
     *
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postComplete(): void
    {
        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        $lbp_rate = $this->config['lbp_exchange_rate'] ?? null;
        if (! is_numeric($lbp_rate) || (float) $lbp_rate <= 0) {
            $this->_reload(['error' => lang('Common.lbp_rate_missing')]);

            return;
        }

        $cart = $this->receiving_lib->get_cart();

        foreach ($cart as $item) {
            if ((float) ($item['price'] ?? 0) < 0) {
                $this->_reload(['error' => lang('Receivings.price_non_negative')]);

                return;
            }

            if (is_quantity_zero($item['quantity'] ?? 0)) {
                $this->_reload(['error' => lang('Receivings.quantity_zero')]);

                return;
            }
        }

        $data = [];

        $data['cart']                 = $cart;
        $data['total']                = $this->receiving_lib->get_total();
        $data['transaction_time']     = to_datetime(time());
        $data['mode']                 = $this->receiving_lib->get_mode();
        $data['comment']              = $this->receiving_lib->get_comment();
        $data['reference']            = $this->receiving_lib->get_reference();
        $data['payment_type']         = $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $data['show_stock_locations'] = $this->stock_location->show_locations('receivings');
        $data['stock_location']       = $this->receiving_lib->get_stock_source();
        if ($this->request->getPost('amount_tendered') != null) {
            $data['amount_tendered'] = parse_decimals($this->request->getPost('amount_tendered'));
            $data['amount_change']   = to_currency($data['amount_tendered'] - $data['total']);
        }

        $employee_id      = $this->employee->get_logged_in_employee_info()->person_id;
        $employee_info    = $this->employee->get_info($employee_id);
        $data['employee'] = $employee_info->first_name . ' ' . $employee_info->last_name;

        $data['lbp_rate']   = lbp_rate_to_save($lbp_rate);
        $data['lbp_totals'] = get_receiving_lbp_totals($data['cart'], $data['lbp_rate'] ?? 0);
        $data['lbp_total']  = $data['lbp_rate'] === null ? null : $data['lbp_totals']['total'];

        // SAVE receiving to database
        $data['receiving_id'] = 'RECV ' . $this->receiving->save_value($data['cart'], null, $employee_id, $data['comment'], $data['reference'], $data['payment_type'], $data['stock_location'], $data['lbp_total'], $data['lbp_rate']);

        if ($data['receiving_id'] == 'RECV -1') {
            $data['error_message'] = lang('Receivings.transaction_failed');
        } else {
            $data['barcode'] = $this->barcode_lib->generate_receipt_barcode($data['receiving_id']);
        }

        $data['print_after_sale'] = $this->receiving_lib->is_print_after_sale();

        echo view('receivings/receipt', $data);

        $this->receiving_lib->clear_all();
    }

    /**
     * Moves requisition cart lines after checking grants for the source, destination, and cart locations.
     *
     * @throws ReflectionException
     * @noinspection PhpUnused
     */
    public function postRequisitionComplete(): void
    {
        if (! $this->hasReceivingLocationAccess()) {
            return;
        }

        if ($this->receiving_lib->get_stock_source() != $this->receiving_lib->get_stock_destination()) {
            foreach ($this->receiving_lib->get_cart() as $item) {
                $this->receiving_lib->delete_item($item['line']);
                $this->receiving_lib->add_item($item['item_id'], $item['quantity'], $this->receiving_lib->get_stock_destination(), $item['discount_type']);
                $this->receiving_lib->add_item($item['item_id'], -$item['quantity'], $this->receiving_lib->get_stock_source(), $item['discount_type']);
            }

            $this->postComplete();
        } else {
            $data['error'] = lang('Receivings.error_requisition');

            $this->_reload($data);    // TODO: Hungarian notation
        }
    }

    /**
     * Reprints a saved receiving with its stored reference, pound total, and exchange rate.
     *
     * @noinspection PhpUnused
     *
     * @param mixed $receiving_id
     */
    public function getReceipt($receiving_id): void
    {
        $receiving_info = $this->receiving->get_info($receiving_id)->getRowArray();
        $this->receiving_lib->copy_entire_receiving($receiving_id);
        $data['cart']                 = $this->receiving_lib->get_cart();
        $data['total']                = $this->receiving_lib->get_total();
        $data['lbp_rate']             = lbp_rate_to_save($receiving_info['lbp_exchange_rate'] ?? null);
        $data['lbp_total']            = $receiving_info['lbp_total'] ?? null;
        $data['lbp_totals']           = get_receiving_lbp_totals($data['cart'], $data['lbp_rate'] ?? 0);
        $data['mode']                 = $this->receiving_lib->get_mode();
        $data['transaction_time']     = to_datetime(strtotime($receiving_info['receiving_time']));
        $data['show_stock_locations'] = $this->stock_location->show_locations('receivings');
        $data['payment_type']         = $receiving_info['payment_type'];
        $data['reference']            = $receiving_info['reference'] ?? '';
        $data['receiving_id']         = 'RECV ' . $receiving_id;
        $data['barcode']              = $this->barcode_lib->generate_receipt_barcode($data['receiving_id']);
        $employee_info                = $this->employee->get_info($receiving_info['employee_id']);
        $data['employee']             = $employee_info->first_name . ' ' . $employee_info->last_name;

        $data['print_after_sale'] = false;

        echo view('receivings/receipt', $data);

        $this->receiving_lib->clear_all();
    }

    /**
     * Reloads the receiving screen with the cart's dollar and pound totals.
     */
    private function _reload(array $data = []): void    // TODO: Hungarian notation
    {
        $data['cart']                 = $this->receiving_lib->get_cart();
        $data['modes']                = ['receive' => lang('Receivings.receiving'), 'return' => lang('Receivings.return')];
        $data['mode']                 = $this->receiving_lib->get_mode();
        $data['stock_locations']      = $this->stock_location->get_allowed_locations('receivings');
        $data['show_stock_locations'] = count($data['stock_locations']) > 1;
        if ($data['show_stock_locations']) {
            $data['modes']['requisition'] = lang('Receivings.requisition');
            $data['stock_source']         = $this->receiving_lib->get_stock_source();
            $data['stock_destination']    = $this->receiving_lib->get_stock_destination();
        }

        $data['total']                = $this->receiving_lib->get_total();
        $data['lbp_rate']             = lbp_rate_to_save($this->config['lbp_exchange_rate'] ?? null);
        $data['lbp_totals']           = get_receiving_lbp_totals($data['cart'], $data['lbp_rate'] ?? 0);
        $data['lbp_total']            = $data['lbp_rate'] === null ? null : $data['lbp_totals']['total'];
        $data['items_module_allowed'] = $this->employee->has_grant('items', $this->employee->get_logged_in_employee_info()->person_id);
        $data['comment']              = $this->receiving_lib->get_comment();
        $data['reference']            = $this->receiving_lib->get_reference();
        $data['payment_options']      = $this->receiving->get_payment_options();

        $data['print_after_sale'] = $this->receiving_lib->is_print_after_sale();

        echo view('receivings/receiving', $data);
    }

    /**
     * Checks current grants and clears the receiving if its saved locations are no longer allowed.
     *
     * @param array<int, int|string> $additional_locations
     */
    private function hasReceivingLocationAccess(array $additional_locations = []): bool
    {
        $allowed_locations = $this->stock_location->get_allowed_locations('receivings');
        if ($allowed_locations === []) {
            $this->showReceivingNoAccess();

            return false;
        }

        $session_locations = [
            $this->receiving_lib->get_stock_source(),
            $this->receiving_lib->get_stock_destination(),
        ];

        foreach ($this->receiving_lib->get_cart() as $item) {
            if (! isset($item['item_location'])) {
                $this->resetReceivingForCurrentLocations($allowed_locations);

                return false;
            }

            $session_locations[] = $item['item_location'];
        }

        foreach ($session_locations as $location_id) {
            if (! is_numeric($location_id) || (int) $location_id < 1 || ! $this->stock_location->is_allowed_location((int) $location_id, 'receivings')) {
                $this->resetReceivingForCurrentLocations($allowed_locations);

                return false;
            }
        }

        foreach ($additional_locations as $location_id) {
            if (! is_numeric($location_id) || (int) $location_id < 1 || ! $this->stock_location->is_allowed_location((int) $location_id, 'receivings')) {
                $this->_reload(['error' => lang('Receivings.location_not_allowed')]);

                return false;
            }
        }

        return true;
    }

    /**
     * Clears a stale receiving and starts it at the employee's first granted location.
     *
     * @param array<int|string, string> $allowed_locations
     */
    private function resetReceivingForCurrentLocations(array $allowed_locations): void
    {
        $default_location_id = $this->stock_location->get_default_location_id('receivings');
        $location_id         = isset($allowed_locations[$default_location_id])
            ? $default_location_id
            : (int) array_key_first($allowed_locations);

        $this->receiving_lib->clear_all();
        $this->receiving_lib->set_stock_source($location_id);
        $this->receiving_lib->set_stock_destination((string) $location_id);
        $this->_reload(['error' => lang('Receivings.locations_changed')]);
    }

    /**
     * Checks current location grants for all stock locations in saved receivings.
     *
     * @param array<int, int|string> $receiving_ids
     */
    private function hasSavedReceivingLocationAccess(array $receiving_ids): bool
    {
        if (empty($this->stock_location->get_allowed_locations('receivings'))) {
            return false;
        }

        foreach ($receiving_ids as $receiving_id) {
            if (! is_numeric($receiving_id) || (int) $receiving_id < 1) {
                return false;
            }

            foreach ($this->receiving->get_receiving_items((int) $receiving_id)->getResultArray() as $item) {
                if (! isset($item['item_location'])
                    || ! is_numeric($item['item_location'])
                    || ! $this->stock_location->is_allowed_location((int) $item['item_location'], 'receivings')
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Shows the same no-access page used when the receiving screen has no location grants.
     */
    private function showReceivingNoAccess(): void
    {
        echo view('no_access', [
            'module_name'   => lang('Module.receivings'),
            'permission_id' => 'receivings_<location>',
        ]);
    }

    /**
     * Saves editable receiving details and stores comment and reference text as typed.
     *
     * @throws ReflectionException
     */
    public function postSave(int $receiving_id = -1): void    // TODO: Replace -1 with a constant
    {
        $newdate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);    // TODO: newdate does not follow naming conventions

        $date_formatter = date_create_from_format($this->config['dateformat'] . ' ' . $this->config['timeformat'], $newdate);
        $receiving_time = $date_formatter->format('Y-m-d H:i:s');

        $receiving_data = [
            'receiving_time' => $receiving_time,
            'employee_id'    => $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT),
            'comment'        => $this->postedText('comment'),
            'reference'      => $this->postedText('reference') !== '' ? $this->postedText('reference') : null,
        ];

        $this->inventory->update('RECV ' . $receiving_id, ['trans_date' => $receiving_time]);
        if ($this->receiving->update($receiving_id, $receiving_data)) {
            echo json_encode([
                'success' => true,
                'message' => lang('Receivings.successfully_updated'),
                'id'      => $receiving_id,
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => lang('Receivings.unsuccessfully_updated'),
                'id'      => $receiving_id,
            ]);
        }
    }

    /**
     * Cancel an in-process receiving. Used in app/Views/receivings/receiving.php
     *
     * @noinspection PhpUnused
     */
    public function postCancelReceiving(): void
    {
        $this->receiving_lib->clear_all();

        $this->_reload();    // TODO: Hungarian Notation
    }

    /**
     * Returns a posted receiving text field trimmed and unencoded, or an empty string for invalid input.
     */
    private function postedText(string $field): string
    {
        $value = $this->request->getPost($field);

        return is_string($value) ? trim($value) : '';
    }
}
