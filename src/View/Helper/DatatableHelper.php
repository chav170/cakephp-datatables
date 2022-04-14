<?php
declare(strict_types=1);

namespace CakeDC\Datatables\View\Helper;

use Cake\Utility\Inflector;
use Cake\View\Helper;
use Cake\View\View;
use Datatables\Exception\MissConfiguredException;
use InvalidArgumentException;

/**
 * Datatable helper
 *
 * @property \CakeDC\Datatables\View\Helper\HtmlHelper $Html
 * @property \Cake\View\Helper\UrlHelper $Url
 */
class DatatableHelper extends Helper
{
    /**
     * Default Datatable js library configuration.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'processing' => true,
        'serverSide' => true,
        // override to provide translations, @see https://datatables.net/examples/basic_init/language.html
        'language' => [],
        'lengthMenu' => [],
        // @todo: enable column based search inputs
        'columnSearch' => true,
    ];

    /**
     * Json template with placeholders for configuration options.
     *
     * @var string
     */
    private $datatableConfigurationTemplate = <<<DATATABLE_CONFIGURATION
    // API callback
    %s

    // Datatables configuration
    $(() => {     
        //@todo use configuration for multicolumn filters
        $('#%s thead tr')
            .clone(true)
            .addClass('filters')
            .appendTo('#%s thead');
            
        $('#%s').DataTable({
            orderCellsTop: true,
            fixedHeader: true,
            ajax: getData(),
            processing: %s,
            serverSide: %s,
            pagingType: "simple",
            columns: [
                %s
            ],
            language: %s,
            lengthMenu: %s,
            //@todo use configuration instead  
            initComplete: function () {
                var api = this.api();
     
                // For each column
                api
                    .columns()
                    .eq(0)
                    .each(function (colIdx) {
                        // Set the header cell to contain the input element
                        var cell = $('.filters th').eq(
                            $(api.column(colIdx).header()).index()
                        );
                        var title = $(cell).text();
                        $(cell).html('<input type="text" placeholder="' + title + '" />');
     
                        // On every keypress in this input
                        $(
                            'input',
                            $('.filters th').eq($(api.column(colIdx).header()).index())
                        )
                            .off('keyup change')
                            .on('keyup change', function (e) {
                                e.stopPropagation();
     
                                // Get the search value
                                $(this).attr('title', $(this).val());
                                var regexr = '({search})'; //$(this).parents('th').find('select').val();
     
                                var cursorPosition = this.selectionStart;
                                // Search the column for that value
                                api
                                    .column(colIdx)
                                    .search(
                                        this.value != ''
                                            ? regexr.replace('{search}', '(((' + this.value + ')))')
                                            : '',
                                        this.value != '',
                                        this.value == ''
                                    )
                                    .draw();
     
                                $(this)
                                    .focus()[0]
                                    .setSelectionRange(cursorPosition, cursorPosition);
                            });
                    });
            },
        });
    });
DATATABLE_CONFIGURATION;

    /**
     * Other helpers used by DatatableHelper
     *
     * @var array
     */
    protected $helpers = ['Url', 'Html'];
    private $htmlTemplates = [
        'link' => '<a href="%s">%s</a>',
    ];

    /**
     * @var string[]
     */
    private $dataKeys;

    /**
     * @var string
     */
    private $getDataTemplate;

    /**
     * @var string
     */
    private $configColumns;

    public function __construct(View $view, array $config = [])
    {
        if (!isset($config['lengthMenu'])) {
            $config['lengthMenu'] = [20, 50, 100];
        }
        parent::__construct($view, $config);
    }

    /**
     * Build the get data callback
     *
     * @param string|array $url
     */
    public function setGetDataUrl($url = null)
    {
        $url = (array)$url;
        $url = array_merge($url, ['fullBase' => true, '_ext' => 'json']);
        $url = $this->Url->build($url);
        $this->getDataTemplate = <<<GET_DATA
let getData = async () => {
        let res = await fetch('{$url}')
    }
GET_DATA;
    }

    /**
     * @param \Cake\Collection\Collection $dataKeys
     */
    public function setFields(iterable $dataKeys)
    {
        if (empty($dataKeys)) {
            throw new InvalidArgumentException(__('Couldn\'t get first item'));
        }
        $this->dataKeys = $dataKeys;
    }

    /**
     * Get Datatable initialization script with options configured.
     *
     * @param string $tagId
     * @return string
     */
    public function getDatatableScript(string $tagId): string
    {
        if (empty($this->getDataTemplate)) {
            $this->setGetDataUrl();
        }
        $this->processColumnRenderCallbacks();
        $this->validateConfigurationOptions();

        return sprintf(
            $this->datatableConfigurationTemplate,
            $this->getDataTemplate,
            $tagId,
            $tagId,
            $tagId,
            $this->getConfig('processing') ? 'true' : 'false',
            $this->getConfig('serverSide') ? 'true' : 'false',
            $this->configColumns,
            json_encode($this->getConfig('language')),
            json_encode($this->getConfig('lengthMenu')),
        );
    }

    /**
     * Validate configuration options for the datatable.
     *
     * @throws \Datatables\Exception\MissConfiguredException
     */
    protected function validateConfigurationOptions()
    {
        if (empty($this->dataKeys)) {
            throw new MissConfiguredException(__('There are not columns specified for your datatable.'));
        }

        if (empty($this->configColumns)) {
            throw new MissConfiguredException(__('Column renders are not specified for your datatable.'));
        }
    }

    /**
     * Loop columns and create callbacks or simple json objects accordingly.
     */
    protected function processColumnRenderCallbacks()
    {
        $configColumns = array_map(function ($key) {
            $output = '{';
            if (is_string($key)) {
                $output .= "data:'{$key}'";
            } else {
                $output .= "data:'{$key['name']}',";

                if (isset($key['links'])) {
                    $output .= "\nrender: function(data, type, obj) {";
                    $links = [];
                    foreach ((array)$key['links'] as $link) {
                        $links[] = $this->processActionLink($link);
                    }
                    $output .= 'return ' . implode("\n + ", $links);
                    $output .= '}';
                } else {
                    $output .= "render:{$key['render']}";
                }
            }
            $output .= '}';

            return $output;
        }, (array)$this->dataKeys);
        $this->configColumns = implode(", \n", $configColumns);
    }

    /**
     * Format link with specified options from links array.
     *
     * @param array $link
     * @return string
     */
    protected function processActionLink(array $link): string
    {
        $urlExtraValue = '';
        if (is_array($link['url'])) {
            $urlExtraValue = $link['url']['extra'] ?? '';
            unset($link['url']['extra']);
        }

        return "'" .
            sprintf(
                $this->htmlTemplates['link'],
                $this->Url->build($link['url']) . $urlExtraValue,
                $link['label'] ?: "' + {$link['value']} + '"
            )
            . "'";
    }

    /**
     * Get formatted table headers
     *
     * @param iterable|null $tableHeaders
     * @param bool $format
     * @param bool $translate
     * @param array $headersAttrs
     * @return string
     */
    public function getTableHeaders(
        ?iterable $tableHeaders = null,
        bool $format = false,
        bool $translate = false,
        array $headersAttrs = []
    ): string {
        $tableHeaders = $tableHeaders ?? $this->dataKeys;

        foreach ($tableHeaders as &$tableHeader) {
            if ($format) {
                $tableHeader = str_replace('.', '_', $tableHeader);
                $tableHeader = Inflector::humanize($tableHeader);
            }
            if ($translate) {
                $tableHeader = __($tableHeader);
            }
        }

        return $this->Html->tableHeaders($tableHeaders, $headersAttrs);
    }
}
