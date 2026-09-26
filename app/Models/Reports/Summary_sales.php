<?php

namespace App\Models\Reports;

class Summary_sales extends Summary_report
{
    /**
     * @return list<array>
     */
    protected function _get_data_columns(): array
    {
        return [
            ['sale_date' => lang('Reports.date'), 'sortable' => false],
            ['sales'     => lang('Reports.sales'), 'sorter' => 'number_sorter'],
            ['quantity'  => lang('Reports.quantity'), 'sorter' => 'number_sorter'],
            ['subtotal'  => lang('Reports.subtotal'), 'sorter' => 'number_sorter'],
            ['tax'       => lang('Reports.tax'), 'sorter' => 'number_sorter'],
            ['total'     => lang('Reports.total'), 'sorter' => 'number_sorter'],
            ['lbp_total' => lang('Reports.lbp_total'), 'sorter' => 'number_sorter'],
            ['cost'      => lang('Reports.cost'), 'sorter' => 'number_sorter'],
            ['profit'    => lang('Reports.profit'), 'sorter' => 'number_sorter'],
        ];
    }

    protected function _select(array $inputs, object &$builder): void    // TODO: hungarian notation
    {
        parent::_select($inputs, $builder);    // TODO: hungarian notation

        $builder->select('
                DATE(sales.sale_time) AS sale_date,
                SUM(sales_items.quantity_purchased) AS quantity_purchased,
                COUNT(DISTINCT sales.sale_id) AS sales
        ');
    }

    protected function _group_order(object &$builder): void    // TODO: hungarian notation
    {
        $builder->groupBy('sale_date');
        $builder->orderBy('sale_date');
    }

    /**
     * Returns one row per day and adds the saved Lebanese pound total of that day's sales as `lbp_total`.
     *
     * `lbp_total` is null when any sale of the day has no saved pound total, such as sales from before it was saved.
     */
    public function getData(array $inputs): array
    {
        $rows       = parent::getData($inputs);
        $lbp_by_day = [];

        foreach ($this->get_lbp_totals($inputs, true) as $lbp_row) {
            $lbp_by_day[$lbp_row['sale_date']] = $lbp_row;
        }

        foreach ($rows as &$row) {
            $row['lbp_total'] = complete_lbp_total($lbp_by_day[$row['sale_date']] ?? null);
        }
        unset($row);

        return $rows;
    }

    /**
     * Returns the report totals and adds the saved Lebanese pound total of all matching sales as `lbp_total`.
     *
     * `lbp_total` is 0 when no sale matches, and null when any matching sale has no saved pound total.
     */
    public function getSummaryData(array $inputs): array
    {
        $summary              = parent::getSummaryData($inputs);
        $lbp_rows             = $this->get_lbp_totals($inputs, false);
        $summary['lbp_total'] = complete_lbp_total($lbp_rows[0] ?? null);

        return $summary;
    }

    /**
     * Sums the saved pound totals of the sales matching the report filters, counting each sale once.
     *
     * The report joins one row per sale item, so the matching sale ids are collected first and summed afterwards.
     *
     * @param array $inputs Report filters: start_date, end_date, sale_type and location_id.
     * @param bool  $by_day Whether to return one row per sale date instead of a single total row.
     *
     * @return list<array<string, int|string|null>> Rows with lbp_total, sale_count, missing_count and, by day, sale_date.
     */
    public function get_lbp_totals(array $inputs, bool $by_day): array
    {
        $builder = $this->db->table('sales_items AS sales_items');
        $builder->select('sales.sale_id, DATE(sales.sale_time) AS sale_date, sales.lbp_total');
        $builder->distinct();
        $builder->join('sales AS sales', 'sales_items.sale_id = sales.sale_id', 'inner');
        $this->_where($inputs, $builder);

        $matching_sales = $builder->getCompiledSelect();
        $select         = 'SUM(matching_sales.lbp_total) AS lbp_total, COUNT(*) AS sale_count, SUM(matching_sales.lbp_total IS NULL) AS missing_count';

        if ($by_day) {
            return $this->db->query(
                'SELECT matching_sales.sale_date, ' . $select . ' FROM (' . $matching_sales . ') AS matching_sales GROUP BY matching_sales.sale_date',
            )->getResultArray();
        }

        return $this->db->query('SELECT ' . $select . ' FROM (' . $matching_sales . ') AS matching_sales')->getResultArray();
    }
}
