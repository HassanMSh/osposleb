<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Keeps graphical report labels readable and safe inside the page script.
 *
 * @internal
 */
final class ReportGraphLabelsTest extends CIUnitTestCase
{
    /**
     * Lists every shared graph view with the script key that holds its data series.
     *
     * @return array<string, array{string, string}>
     */
    public static function graphViews(): array
    {
        return [
            'pie'  => ['reports/graphs/pie', 'series'],
            'bar'  => ['reports/graphs/bar', 'data'],
            'hbar' => ['reports/graphs/hbar', 'data'],
            'line' => ['reports/graphs/line', 'data'],
        ];
    }

    /**
     * Checks that labels and series come back unchanged when the browser reads them, and that a label cannot end the script tag.
     */
    #[DataProvider('graphViews')]
    public function testLabelsAndSeriesAreEncodedOnce(string $view, string $seriesKey): void
    {
        $labels = ['Issue 232 edited category', 'مشروبات غازية', 'Tom & Jerry\'s "best"', '</script><b>x</b>'];
        $series = [
            ['meta' => 'Issue 232 edited category 50%', 'value' => '-12.50'],
            ['meta' => 'مشروبات غازية 25%', 'value' => '6.25'],
            ['meta' => 'Tom & Jerry\'s "best" 15%', 'value' => '3.75'],
            ['meta' => '</script><b>x</b> 10%', 'value' => '2.50'],
        ];

        $html = view($view, [
            'labels_1'      => $labels,
            'series_data_1' => $series,
            'show_currency' => false,
            'yaxis_title'   => 'Revenue',
            'xaxis_title'   => 'Date',
            'config'        => ['currency_symbol' => '$'],
        ]);

        $this->assertStringNotContainsString('\x20', $html);
        $this->assertStringNotContainsString('</script><b>', $html);
        $this->assertSame(1, substr_count($html, '</script>'));

        $this->assertMatchesRegularExpression('/labels: (.+),\n/', $html);
        preg_match('/labels: (.+),\n/', $html, $labelMatch);
        $this->assertSame($labels, json_decode($labelMatch[1], true));

        $this->assertMatchesRegularExpression('/' . $seriesKey . ': (\[.+?\])\s+\}/', $html);
        preg_match('/' . $seriesKey . ': (\[.+?\])\s+\}/', $html, $seriesMatch);
        $this->assertSame($series, json_decode($seriesMatch[1], true));
    }

    /**
     * Checks that the line chart passes point text through the chart library's escaping before tooltips show it.
     */
    public function testLineChartEscapesPointMeta(): void
    {
        $source = file_get_contents(APPPATH . 'Views/reports/graphs/line.php');

        $this->assertIsString($source);
        $this->assertStringContainsString("'ct:meta': Chartist.serialize(data.meta)", $source);
    }
}
