<?php

namespace App\Console\Commands;

use DOMDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ParserCommand extends Command
{
    const META_ENTITY = 'entity';
    const META_PAGE = 'page';
    const META_TYPE = 'type';
    const META_ID = 'id';

    /**
     * Ключ BBOX для ширины
     */
    const BBOX_WIDTH = 2;

    /**
     * Ключ BBOX для высоты
     */
    const BBOX_HEIGHT = 3;

    /**
     * Ключ BBOX для позиции сверху
     */
    const BBOX_POSITION_TOP = 1;

    /**
     * Ключ BBOX для позиции с левой стороны
     */
    const BBOX_POSITION_LEFT = 0;

    /**
     * Ключ BBOX для позиции с права
     */
    const BBOX_POSITION_RIGHT = 2;

    /**
     * Ключ BBOX для позиции снизу
     */
    const BBOX_POSITION_BOTTOM = 3;

    /**
     * Ключ четной страницы
     */
    const PAGE_EVEN = 'even';

    /**
     * Ключ нечетной страницы
     */
    const PAGE_ODD = 'odd';

    //ENUM('book', 'section', 'subsection', 'chapter', 'paragraph', 'sentence', 'speech', 'table', 'tablecol', 'defaultimp', 'html')

    const CONTENT_TYPE_HTML = 'html';
    const CONTENT_TYPE_SECTION = 'section';
    const CONTENT_TYPE_SUBSECTION = 'subsection';
    const CONTENT_TYPE_CHAPTER = 'chapter';
    const CONTENT_TYPE_PARAGRAPH = 'paragraph';
    const CONTENT_TYPE_TEXT = 'sentence';
    const CONTENT_TYPE_IMAGE = 'image';

    /**
     * Класс незавершенного текста
     */
    const CLASS_TEXT_INCOMPLETION = 'text-incompletion';

    /**
     * Класс завершения текста
     */
    const CLASS_TEXT_COMPLETION = 'text-completion';

    /**
     * Иерархия типов
     */
    const HIERARCHY_TYPES = [
        1 => self::CONTENT_TYPE_SECTION,
        2 => self::CONTENT_TYPE_SUBSECTION,
        3 => self::CONTENT_TYPE_CHAPTER,
        4 => self::CONTENT_TYPE_PARAGRAPH,
        5 => self::CONTENT_TYPE_TEXT,
    ];

    /**
     * Реврайт названий если отсутствует текст,
     * в целом не так нужно, но на всякий
     */
    const HIERARCHY_TYPE_NAMES = [
        self::CONTENT_TYPE_SECTION => 'Раздел',
        self::CONTENT_TYPE_SUBSECTION => 'Подраздел',
        self::CONTENT_TYPE_CHAPTER => 'Глава',
        self::CONTENT_TYPE_PARAGRAPH => 'Параграф',
        self::CONTENT_TYPE_TEXT => 'Текст',
    ];

    /**
     * Типы, которые мы будем записывать в БД оглавлений
     */
    const HIEARCHY_APPROVAL_TYPES = [
        'SectionHeader', 'Text', 'ListItem', 'TableCell', 'Image', 'Picture', 'Caption',
    ];

    /**
     * Разрешенные HTML теги в строках
     */
    const APPROVAL_HTML_TAGS = ['b', 'i', 'strong', 'u', 's'];

    /**
     * Путь к папке книги
     */
    static $folderPath = '';

    /**
     * Путь к файлу для разбора
     */
    static $filePath = '';

    /**
     * JSON контент файла
     */
    static $fileContent;

    /**
     * Удаленные элементы номеров страниц,
     * на всякий случай для проверки что дропнули
     */
    static $deletedPageNumbersElements = [];

    /**
     * Кооридинаты блока для
     * вхождения номеров страниц
     */
    static $pageNumbersRange = [
        self::PAGE_ODD => [],
        self::PAGE_EVEN => [],
    ];

    /**
     * Кластеризация
     */
    static $clusters = [];

    /**
     * Массив оглавления книги
     */
    static $hierarchy = [];

    /**
     * Незавершенные параграфы
     * для которых будем искать продолжение
     *
     * Сохраняем тут страницы и внутри их последние элементы
     */
    static $unfinished = [];


    /**
     * Контент книги
     */
    static $struktures = [];

    static $strukturesId = 0;
    static $strukturesPage = 0;
    static $strukturesPageReal = '';
    static $strukturesPageWidth = '';
    static $strukturesPageHeight = '';

    static $structureReadableElementId = 0;

    static $strukturesParentId = '';
    static $strukturesParagraph = '';
    static $strukturesSequence = 0;

    static $clustersSequence = 0;

    static $book_content_max_width = 0;
    static $book_content_max_height = 0;

    static $ocr_book_bbox = [
        self::PAGE_ODD => [
            self::BBOX_POSITION_LEFT => 0,
            self::BBOX_POSITION_TOP => 0,
            self::BBOX_POSITION_RIGHT => 0,
            self::BBOX_POSITION_BOTTOM  => 0,
        ],
        self::PAGE_EVEN => [
            self::BBOX_POSITION_LEFT => 0,
            self::BBOX_POSITION_TOP => 0,
            self::BBOX_POSITION_RIGHT => 0,
            self::BBOX_POSITION_BOTTOM  => 0,
        ],
    ];

    static $dom;

    /**
     * Стрктура шаблона элемента
     */
    static $template_element = [
        'id' => '',
        'element_type' => '',
        'page' => null,
        'parent_id' => null,
        'paragraph' => null,
        'sequence' => null,
        'text' => null,
        'synthesized_text' => null,
        'image_path' => null,
    ];


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:teamlead-parser-command {path : Path for parse}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make parse';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        self::$folderPath = $this->argument('path');
        self::$filePath = public_path(self::$folderPath . '/' . explode('/', self::$folderPath)[1] . '.json');
        self::$fileContent = json_decode(File::get(self::$filePath), true);

        $this->info(self::$filePath);

        $this->info('Step: initDom()');
        $this->initDom();

        $this->info('Step: isPageNumbers()');
        $this->isPageNumbers();

        $this->info('Step: cleanPageNumbers()');
        $this->cleanPageNumbers();

        $this->info('Step: visionContentMaxWidthAndHeight()');
        $this->visionContentMaxWidthAndHeight();

        $this->info('Step: clusters()');
        $this->clusters(self::$fileContent['children']);

        $this->info('Step: beforeClassify()');
        $this->beforeClassify();

        $this->info('Step: classify()');
        $this->classify();

        $this->info('Step: hierarchy()');
        $this->hierarchy();

        $this->info('Step: saveOutData() -> clasters.txt');
        $this->saveOutData('clasters.txt', self::$clusters);

        $this->info('Step: hierarchy() -> hierarchy.txt');
        $this->saveOutData('hierarchy.txt', self::$hierarchy);

        //$this->info('clusters()');
        //$this->info(print_r(self::$clusters, true));
        exit();

        //exit();

        $this->info('Step: parser()');
        $this->parser();
        //$this->info(print_r(self::$struktures, true));

        $this->info('Step: saveOutData() -> structures.txt');
        $this->saveOutData('structures.txt', self::$struktures);

        $this->info('Step: saveOutHtml()');
        $fileHtmlName = explode('/', self::$folderPath)[1] . '.html';
        $this->saveOutHtml($fileHtmlName, self::$struktures);
    }

    /**
     * Для удобства
     */
    public function initDom ()
    {
        self::$dom = new DOMDocument('1.0', 'UTF-8');
    }

    /**
     * Выполняем кластеризацию
     */
    public function clusters ($children, $inner = false)
    {
        // Сортируем внутренние элементы по позиции с верху
        if ($inner) $this->bboxesSortByPositions($children, [ self::BBOX_POSITION_TOP ]);

        //foreach ($children as $index => $child)
        foreach ($children as $child)
        {
            // Заглушка для проверки определенных страниц
            //if (!collect([5, 6, 7, 8, 9, 10])->contains($this->getMetaData($child['id'])[self::META_PAGE])) continue;
            if (!collect([7])->contains($this->getMetaData($child['id'])[self::META_PAGE])) continue;

            if ($child['block_type'] == 'Page')
            {
                $block = $this->clustersPrepareBlock($child);
                self::$clusters['children'][] = $block;
            }
            else
            {
                $block = $this->clustersPrepareBlock($child);
                $this->clustersPush($block);
            }

            if (!empty($child['children']))
            {
                $this->clusters($child['children'], true);
            }
        }
    }

    /**
     * Ищем куда мы можем впихнуть элемент
     */
    public function clustersPush ($element)
    {
        $this->info('clustersPush(): ' . $element['id']);

        // Так как мы добавляем последовательно на страницы, то берем кластер последней
        $clusters = &self::$clusters['children'][array_key_last(self::$clusters['children'])];
        $searched = self::$clusters['children'][array_key_last(self::$clusters['children'])];

        $process = false;

        // Тут цель дойти до последнего кластера, в который мы по правилам можем впихнуть элемент
        do
        {
            $process = false;

            foreach ($searched['children'] as $index => $search)
            {
                if ($this->isBboxIntersectsByHeight($search['bbox'], $element['bbox'], 2))
                {
                    if ($clusters['block_type'] == 'Row')
                    {
                        $clusters['bbox'] = $this->mergeBboxes($clusters['bbox'], $element['bbox']);
                    }
                    if (collect(['Row', 'ListGroup', 'Table'])->contains($search['block_type']))
                    {
                        $clusters = &$clusters['children'][$index];
                        $this->info('Current clusters is: ' . $clusters['id']);
                    }

                    $this->info('New search in:');
                    $this->info(print_r($search, true));

                    $searched = $search;

                    if (!$search['children'])
                    {
                        /*
                        if (collect(['Row', 'ListGroup', 'Table'])->contains($search['block_type']))
                        {
                            $this->info('Current clusters is: ' . $clusters['id']);
                            $clusters = &$clusters['children'][$index];
                        }
                            */

                        $process = false;
                    }
                    else
                    {
                        $process = true;
                    }

                    break 1;
                }
            }
        }
        while ($process);


        // Страницу мы всегда оборачиваем в Row & Col
        if ($clusters['block_type'] == 'Page')
        {
            $children = $element;

            $col = [
                'id' => '/page/' . $this->getMetaData($element['id'])[self::META_PAGE] . '/Div/' . ++self::$clustersSequence,
                'block_type' => 'Col',
                'bbox' => $element['bbox'],
            ];

            $children = $this->clustersPrepareBlock($col, $children);

            $row = [
                'id' => '/page/' . $this->getMetaData($element['id'])[self::META_PAGE] . '/Div/' . ++self::$clustersSequence,
                'block_type' => 'Row',
                'bbox' => $element['bbox'],
            ];

            $children = $this->clustersPrepareBlock($row, $children);
            $clusters['children'][] = $children;
        }
        //elseif ($clusters['block_type'] == 'Row') // Тут должна содержаться основная логика разбора Row & Col
        else
        {
            // До этого мы узнали, что блоки пересеклись с Row по высоте, однако это не значит что они пересекаются с текущими Col
            foreach ($clusters['children'] as $line)
            {
                if ($this->isBboxIntersectsByHeight($line['bbox'], $element['bbox'], 2))
                {
                    $children = $element;

                    if (collect(['Row'])->contains($children['block_type']))
                    {
                        $line = [
                            'id' => '/page/' . $this->getMetaData($element['id'])[self::META_PAGE] . '/Div/' . ++self::$clustersSequence,
                            'block_type' => 'Col',
                            'bbox' => $element['bbox'],
                        ];

                        $children = $this->clustersPrepareBlock($line, $children);
                    }

                    $clusters['children'][] = $children;

                    $this->bboxesSortByPositions($clusters['children'], [ self::BBOX_POSITION_LEFT ]);

                    break; // Выходим из цикла
                }
                else
                {
                    $this->info('Not intersects by height: ' . $element['id']);
                }
            }
        }
    }

    /**
     * Подготавливаем блок для кластеризации
     */
    public function clustersPrepareBlock ($element, $children = [])
    {
        // Чистим данные
        $element = $this->cleanChildrenData($element);

        $block = [
            'id' => $element['id'],
            'block_page' => $this->getMetaData($element['id'])[self::META_PAGE],
            'block_type' => $element['block_type'],
            'block_classes' => [],
            'block_styles' => [],
            'block_hierarchy' => [],
            'section_hierarchy' => isset($element['section_hierarchy']) ? $element['section_hierarchy'] : [],
            'bbox' => $element['bbox'],
            'html' => $element['block_type'] == 'Page' ? '' : (isset($element['html']) ? $element['html'] : ''),
            'images' => isset($element['images']) && count($element['images']) ? $element['images'] : [],
            'children' => empty($children) ? [] : [ $children ],
        ];

        return $block;
    }


    /**
     * Перед разбором классов и стилей
     * надо раскидать правильные размеры по ширине
     */
    public function beforeClassify (&$clusters = [])
    {
        if (empty($clusters))
        {
            $clusters = &self::$clusters;
        }

        foreach ($clusters['children'] as $index => &$cluster)
        {
            if ($cluster['block_type'] == 'Row')
            {
                if ($clusters['block_type'] == 'Page')
                {
                    $merge = self::$ocr_book_bbox[$this->checkPageParity($this->getMetaData($cluster['id'])[self::META_PAGE])];
                }
                else
                {
                    $merge = $clusters['bbox'];
                }

                $cluster['bbox'][self::BBOX_POSITION_LEFT] = $merge[self::BBOX_POSITION_LEFT];
                $cluster['bbox'][self::BBOX_POSITION_RIGHT] = $merge[self::BBOX_POSITION_RIGHT];
            }

            if (!empty($cluster['children']))
            {
                $this->beforeClassify($cluster);
            }
        }
    }


    /**
     * Накидываем стили
     */
    public function classify (&$clusters = [])
    {
        if (empty($clusters))
        {
            $clusters = &self::$clusters;
        }

        foreach ($clusters['children'] as $index => &$cluster)
        {
            if ($cluster['block_type'] == 'Col')
            {
                $this->classifyClassesAndStyles($clusters, $cluster);
            }

            if (!empty($cluster['children']))
            {
                $this->classify($cluster);
            }
        }
    }

    /**
     * Расчет классов и стилей базовых
     */
    public function classifyClassesAndStyles (&$parent, &$child)
    {
        if (count($parent['children']) > 1)
        {
            $this->classifyClassesAndStylesMultiple($parent, $child);
        }
        else
        {
            $this->classifyClassesAndStylesSingle($parent, $child);
        }
    }


    /**
     * Разбираем стили для одного дочернего элемента
     */
    public function classifyClassesAndStylesSingle (&$parent, &$child)
    {
        if ($this->getMetaData($parent['id'])[self::META_TYPE] == 'Div')
        {
            $parent['block_classes'][] = strtolower($parent['block_type']);
        }

        if ($this->visionChildIsFullWidth($parent['bbox'], $child['bbox']))
        {
            if ($parent['block_type'] == 'Row') $parent['block_classes'][] = 'justify-content-start';
            if ($child['block_type'] == 'Col')  $child['block_classes'][] = 'col-md-12';
        }
        else
        {
            if ($this->visionChildIsLeftX($parent['bbox'], $child['bbox']))
            {
                if ($parent['block_type'] == 'Row') $parent['block_classes'][] = 'justify-content-start';
                if ($child['block_type'] == 'Col')  $child['block_classes'][] = $this->visionChildWidthClasses($parent['bbox'], $child['bbox']);
            }
            elseif ($this->visionChildIsRightX($parent['bbox'], $child['bbox']))
            {
                if ($parent['block_type'] == 'Row') $parent['block_classes'][] = 'justify-content-end';
                if ($child['block_type'] == 'Col')  $child['block_classes'][] = $this->visionChildWidthClasses($parent['bbox'], $child['bbox']);
            }
            elseif ($this->visionChildIsCenterX($parent['bbox'], $child['bbox']))
            {
                if ($parent['block_type'] == 'Row') $parent['block_classes'][] = 'justify-content-center';
                if ($child['block_type'] == 'Col')  $child['block_classes'][] = $this->visionChildWidthClasses($parent['bbox'], $child['bbox']);
            }
        }
    }

    /**
     * Разбираем стили, когда дочерних элементов несколько
     */
    public function classifyClassesAndStylesMultiple (&$parent, &$child)
    {
        if ($this->visionChildsIsFullWidth($parent))
        {
            if (!$parent['block_classes'] && $parent['block_type'] == 'Row')
            {
                $parent['block_classes'][] = strtolower($parent['block_type']);
                $parent['block_classes'][] = 'justify-content-start';
            }
            if ($child['block_type'] == 'Col')
            {
                $child['block_classes'][] = $this->visionChildWidthClasses($parent['bbox'], $child['bbox']);
            }
        }
        else
        {
            if (!$parent['block_classes'] && $parent['block_type'] == 'Row')
            {
                $parent['block_classes'][] = strtolower($parent['block_type']);
                $parent['block_classes'][] = 'justify-content-around';
            }
            if ($child['block_type'] == 'Col')
            {
                $child['block_classes'][] = $this->visionChildWidthClasses($parent['bbox'], $child['bbox']);
            }
        }
    }


    /**
     * Разбираем иерархию книги
     * Нам не надо создавать тут что-то, а надо просто сделать структуру для сохранения в БД!!!!!!
     */
    public function hierarchy (&$clusters = [])
    {
        if (empty($clusters)) $clusters = &self::$clusters;

        if (isset($clusters['section_hierarchy']))
        {
            if ($clusters['block_type'] == 'SectionHeader')
            {
                $level = array_search($clusters['id'], $clusters['section_hierarchy']);
                $text = strlen(strip_tags($clusters['html'])) ? strip_tags($clusters['html']) : self::HIERARCHY_TYPE_NAMES[array_search($level, self::HIERARCHY_TYPES)];

                self::$hierarchy[$clusters['id']] = [
                    'id' => '',
                    'json_id' => $clusters['id'],
                    'page' => $this->getMetaData($clusters['id'])[self::META_PAGE],
                    'element_level' => $level,
                    'element_type' => self::HIERARCHY_TYPES[$level],
                    'text' => $text,
                    'children' => [],
                ];
            }

            // Добавлено для того случая, если нам нет к чему привязать детей
            if (!self::$hierarchy)
            {
                $captionPlugId = '/page/' . $this->getMetaData($clusters['id'])[self::META_PAGE] . '/SectionHeader/0';

                self::$hierarchy[$captionPlugId] = [
                    'id' => '',
                    'json_id' => $captionPlugId,
                    'page' => $this->getMetaData($clusters['id'])[self::META_PAGE],
                    'element_type' => self::CONTENT_TYPE_PARAGRAPH,
                    'text' => 'Вступление',
                    'children' => [],
                ];
            }

            if (in_array($clusters['block_type'], self::HIEARCHY_APPROVAL_TYPES))
            {
                if (!$clusters['section_hierarchy'])
                {
                    $hierarchy_last_key = array_key_last(self::$hierarchy);
                    $level = array_search(self::CONTENT_TYPE_TEXT, self::HIERARCHY_TYPES);
                    $text = strlen(strip_tags($clusters['html'])) ? strip_tags($clusters['html']) : self::HIERARCHY_TYPE_NAMES[self::CONTENT_TYPE_TEXT];

                    self::$hierarchy[$hierarchy_last_key]['children'][] = [
                        'id' => '',
                        'json_id' => $clusters['id'],
                        'page' => $this->getMetaData($clusters['id'])[self::META_PAGE],
                        'element_level' => $level,
                        'element_type' => self::CONTENT_TYPE_TEXT,
                        'text' => $text,
                    ];
                }
                else
                {
                    foreach ($clusters['section_hierarchy'] as $hierarchy_id)
                    {
                        if (in_array($clusters['id'], $clusters['section_hierarchy']))
                        {
                            $level = array_search($clusters['id'], $clusters['section_hierarchy']);
                        }
                        else
                        {
                            $level = array_search(self::CONTENT_TYPE_TEXT, self::HIERARCHY_TYPES);
                        }

                        $text = strlen(strip_tags($clusters['html'])) ? strip_tags($clusters['html']) : self::HIERARCHY_TYPE_NAMES[self::HIERARCHY_TYPES[$level]];

                        self::$hierarchy[$hierarchy_id]['children'][] = [
                            'id' => '',
                            'json_id' => $clusters['id'],
                            'page' => $this->getMetaData($clusters['id'])[self::META_PAGE],
                            'element_level' => $level,
                            'element_type' => self::HIERARCHY_TYPES[$level],
                            'text' => $text,
                        ];
                    }
                }
            }
        }

        foreach ($clusters['children'] as $index => &$cluster)
        {
            $this->hierarchy($cluster);
        }
    }

    /**
     * Добавить новый элемент в заголовки
     */
    public function hierarchyPushToCaptions ($level, $id)
    {
        $captions = &self::$hierarchyCaptions;

        foreach ($captions as $caption_level => &$caption_data)
        {
            if ($caption_level == $level)
            {
                $caption_data = $id;
            }
            elseif ($caption_level > $level)
            {
                $caption_data = [];
            }
        }
    }

    /**
     * Добавить данные в массив для теста
     * сетки в выводе HTML
     */
    public function hierarchyPushToHtml ()
    {

    }



    /**
     * Выполняем основной парсинг
     */
    public function parser ()
    {
        foreach (self::$clusters as $page_index => $page_data)
        {
            $this->parsePage($page_index, $page_data);
        }
    }

    /**
     * Парсинг постранично
     */
    public function parsePage ($index, $data)
    {
        self::$strukturesPage += 1;
        /*
        self::$strukturesPageReal = $data['number'];
        self::$strukturesPageWidth = $data['width'];
        self::$strukturesPageHeight = $data['height'];
        */

        self::$strukturesSequence = 0;
        //self::$structureReadableElementId = 0;

        foreach ($data['children'] as $children_index => $children_data)
        {
            $this->parseElements($data['bbox'], $children_data, [], []);
        }
    }

    /**
     * Перебираем элементы линии
     * Для парсинга нам нужен BBOX родителя и элементы содержащиеся внутри
     */
    public function parseElements ($bbox, $elements, $classes_parent = [], $classes_child = [])
    {
        if (count($elements) == 1)
        {
            $element = $elements[0];
            $this->calcClassesSingleChild($bbox, $element, $classes_parent, $classes_child, $element['html']);

            //$this->structureAppend(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_parent) . '">');
            $this->structurePrepareElement($bbox, $element, $classes_parent, $classes_child);
            //$this->structureAppend(self::CONTENT_TYPE_HTML, '</div>');
        }
        else
        {
            $rows = $this->buildRowColIntersects($elements);


            $this->info('parseElements()');
            //$this->info(print_r($rows, true));

            foreach ($rows as $row)
            {

            }
        }
    }

    public function parseElementsInRow ()
    {

    }




    public function parseElementsIntersects ($elements)
    {
        $this->info('parseElementsIntersects ($elements)');

        // Проведем сортировку с верху и с лева
        usort($elements, function ($a, $b)
        {
            if ($a->bbox[self::BBOX_POSITION_TOP] === $b->bbox[self::BBOX_POSITION_TOP])
            {
                return $a->bbox[self::BBOX_POSITION_LEFT] <=> $b->bbox[self::BBOX_POSITION_LEFT];
            }
            return $a->bbox[self::BBOX_POSITION_TOP] <=> $b->bbox[self::BBOX_POSITION_TOP];
        });
    }


    //$this->structureAppend(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_parent) . '">');

    // Переделка функции ниже parseElement
    public function structurePrepareElement ($bbox, $element, $classes_parent = [], $classes_child = [])
    {
        // Выкинем сразу пустые
        if (collect(['SectionHeader', 'Text', 'ListGroup', 'ListItem'])->contains($element['block_type']))
        {
            if (!strlen(trim($element['html']))) return false;
        }
        elseif(collect(['Picture'])->contains($element['block_type']))
        {
            if (empty($element['images'])) return  false;
        }

        if (!empty($classes_parent))
        {
            $this->structureAppend(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_parent) . '">');
        }
        if (!empty($classes_child))
        {
            $this->structureAppend(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_child) . '">');
        }

        /*
        $this->info('structurePrepareElement()');
        $this->info(print_r($bbox, true));
        $this->info(print_r($element, true));
        $this->info(print_r($classes_parent, true));
        $this->info(print_r($classes_child, true));
        exit();
        */


        if ($element['block_type'] == 'SectionHeader')
        {
            // Не обрабатываем элементы которые пустые
            //if (!strlen(trim($element['html']))) return false;

            [$element_tag, $element_text] = $this->parseElementsHtml($element['html']);

            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_child) . '">');
            $this->structureAppend(self::CONTENT_TYPE_HTML, '<' . $element_tag . '>');
            $this->structureAppend(self::CONTENT_TYPE_SECTION, '<span>' . $element_text . '</span>');
            $this->structureAppend(self::CONTENT_TYPE_HTML, '</' . $element_tag . '>');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '</div>');
        }

        elseif ($element['block_type'] == 'Text')
        {
            // Не обрабатываем элементы которые пустые
            if (!strlen(trim($element['html']))) return false;

            [$element_tag, $element_text] = $this->parseElementsHtml($element['html']);

            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="col-12">');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_child) . '">');
            $this->structureAppend(self::CONTENT_TYPE_HTML, '<' . $element_tag . '>');
            $this->structureAppend(self::CONTENT_TYPE_TEXT, '<span>' . $element_text . '</span>');
            $this->structureAppend(self::CONTENT_TYPE_HTML, '</' . $element_tag . '>');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '</div>');
        }

        elseif ($element['block_type'] == 'ListGroup')
        {
            // Не обрабатываем элементы которые пустые
            if (!strlen(trim($element['html']))) return false;
            // Не обрабатываем где нет дочерних элементов
            if (!count($element['children'])) return false;


            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="col-12">');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<ul class="col-12">');
            $this->structureAppend(self::CONTENT_TYPE_HTML, '<ul>');

            foreach ($element['children'] as $child_index => $child_data)
            {
                $this->parseElements($element['bbox'], $child_data, $classes_parent, $classes_child);
            }

            $this->structureAppend(self::CONTENT_TYPE_HTML, '</ul>');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '</div>');


        }

        elseif ($element['block_type'] == 'ListItem')
        {
            $this->calcParentAndChildrenClasses($bbox, $element['bbox'], $classes_parent, $classes_child);
            [$element_tag, $element_text] = $this->parseElementsHtml($element['html']);

            $this->structureAppend(self::CONTENT_TYPE_HTML, '<' . $element_tag . '>');
            $this->structureAppend(self::CONTENT_TYPE_TEXT, '<span>' . $element_text . '</span>');
            $this->structureAppend(self::CONTENT_TYPE_HTML, '</' . $element_tag . '>');

            /*
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '<li class="' . $col_class_add . '">');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '</li>');
            */

        }

        elseif ($element['block_type'] == 'Picture')
        {
            if (empty($element['images'])) return  false;

            foreach ($element['images'] as $image_key => $image_path)
            {
                $this->structureAppend(self::CONTENT_TYPE_IMAGE, '<img src="../images/' . $image_path . '">');
            }
        }

        if (!empty($classes_child))
        {
            $this->structureAppend(self::CONTENT_TYPE_HTML, '</div>');
        }
        if (!empty($classes_parent))
        {
            $this->structureAppend(self::CONTENT_TYPE_HTML, '</div>');
        }
    }


    /**
     * Перебираем отдельный элемент
     */
    //public function parseElement ($bbox, $element, $col_class_add = '')
    public function parseElement ($bbox, $element, $classes_parent = [], $classes_child = [], $is_with_parent = true)
    {

        if ($element['block_type'] == 'SectionHeader')
        {
            // Не обрабатываем элементы которые пустые
            if (!strlen(trim($element['html']))) return false;

            [$element_tag, $element_text] = $this->parseElementsHtml($element['html']);

            if ($is_with_parent) $this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_child) . '">');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '<' . $element_tag . '>');
            $this->createStructureElement(self::CONTENT_TYPE_SECTION, '<span>' . $element_text . '</span>');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '</' . $element_tag . '>');
            if ($is_with_parent) $this->createStructureElement(self::CONTENT_TYPE_HTML, '</div>');
        }

        elseif ($element['block_type'] == 'Text')
        {
            // Не обрабатываем элементы которые пустые
            if (!strlen(trim($element['html']))) return false;

            [$element_tag, $element_text] = $this->parseElementsHtml($element['html']);

            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="col-12">');
            if ($is_with_parent) $this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="' . implode(' ', $classes_child) . '">');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '<' . $element_tag . '>');
            $this->createStructureElement(self::CONTENT_TYPE_TEXT, '<span>' . $element_text . '</span>');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '</' . $element_tag . '>');
            if ($is_with_parent) $this->createStructureElement(self::CONTENT_TYPE_HTML, '</div>');
        }

        elseif ($element['block_type'] == 'ListGroup')
        {
            // Не обрабатываем элементы которые пустые
            if (!strlen(trim($element['html']))) return false;
            // Не обрабатываем где нет дочерних элементов
            if (!count($element['children'])) return false;

            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<div class="col-12">');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '<ul class="col-12">');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '<ul class="' . implode(' ', $classes_child) . '">');

            foreach ($element['children'] as $child_index => $child_data)
            {
                $this->parseElements($element['bbox'], $child_data, $classes_parent, $classes_child);
            }

            $this->createStructureElement(self::CONTENT_TYPE_HTML, '</ul>');
            //$this->createStructureElement(self::CONTENT_TYPE_HTML, '</div>');

        }

        elseif ($element['block_type'] == 'ListItem')
        {
            $this->calcParentAndChildrenClasses($bbox, $element['bbox'], $classes_parent, $classes_child);
            [$element_tag, $element_text] = $this->parseElementsHtml($element['html']);

            $this->createStructureElement(self::CONTENT_TYPE_HTML, '<' . $element_tag . ' class="' . implode(' ', $classes_child) . '">');
            $this->createStructureElement(self::CONTENT_TYPE_TEXT, '<span>' . $element_text . '</span>');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '</' . $element_tag . '>');

            /*
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '<li class="' . $col_class_add . '">');
            $this->createStructureElement(self::CONTENT_TYPE_HTML, '</li>');
            */

        }

        elseif ($element['block_type'] == 'Picture')
        {
            if (empty($element['images'])) return  false;

            foreach ($element['images'] as $image_key => $image_path)
            {
                $this->createStructureElement(self::CONTENT_TYPE_IMAGE, '<img src="../images/' . $image_path . '">');
            }
        }
    }



    /**
     * Пытаемся найти максимальные размеры контента,
     * для этого достаточно родительских элементов.
     * Используем только определенный список блоков.
     */
    public function visionContentMaxWidthAndHeight ()
    {
        foreach (self::$fileContent['children'] as $page)
        {
            foreach ($page['children'] as $children)
            {
                if (collect([ 'SectionHeader', 'Text', 'ListGroup', 'Table'])->contains($children['block_type']))
                {
                    // Мы игнорирурем элементы без HTML
                    if (!$children['html']) {
                        continue;
                    }

                    @self::$dom->loadHTML('<?xml encoding="UTF-8">' . $children['html'], LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

                    // Мы игнорируем элементы без текста
                    if (!trim(self::$dom->getElementsByTagName('*')[0]->textContent)) {
                        continue;
                    }

                    $pageParity = $this->checkPageParity($this->getMetaData($children['id'])[self::META_PAGE]);

                    if (!self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_TOP] || $children['bbox'][self::BBOX_POSITION_TOP] < self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_TOP])
                    {
                        self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_TOP] = round($children['bbox'][self::BBOX_POSITION_TOP]);
                    }
                    if (!self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_LEFT] || $children['bbox'][self::BBOX_POSITION_LEFT] < self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_LEFT])
                    {
                        self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_LEFT] = round($children['bbox'][self::BBOX_POSITION_LEFT]);
                    }
                    if (!self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_RIGHT] || $children['bbox'][self::BBOX_POSITION_RIGHT] > self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_RIGHT])
                    {
                        self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_RIGHT] = round($children['bbox'][self::BBOX_POSITION_RIGHT]);
                    }
                    if (!self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_BOTTOM] || $children['bbox'][self::BBOX_POSITION_BOTTOM] > self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_BOTTOM])
                    {
                        self::$ocr_book_bbox[$pageParity][self::BBOX_POSITION_BOTTOM] = round($children['bbox'][self::BBOX_POSITION_BOTTOM]);
                    }
                }
            }
        }

        $this->info(print_r(self::$ocr_book_bbox, true));
    }

    // Новые функции для анализа стилей и классов
    public function visionChildIsFullWidth ($bbox_parent, $bbox_child, $tolerance = 15)
    {
        $parent_left = abs($bbox_parent[self::BBOX_POSITION_LEFT] + $tolerance);
        $parent_right = abs($bbox_parent[self::BBOX_POSITION_RIGHT] - $tolerance);
        $child_left = abs($bbox_child[self::BBOX_POSITION_LEFT]);
        $child_right = abs($bbox_child[self::BBOX_POSITION_RIGHT]);

        return ($parent_left >= $child_left && $parent_right <= $child_right);
    }

    public function visionChildIsCenterX ($bbox_parent, $bbox_child, $tolerance = 15)
    {
        $offset_left = abs($bbox_parent[self::BBOX_POSITION_LEFT] - $bbox_child[self::BBOX_POSITION_LEFT]);
        $offset_right = abs($bbox_parent[self::BBOX_POSITION_RIGHT] - $bbox_child[self::BBOX_POSITION_RIGHT]);

        return abs($offset_left - $offset_right) <= $tolerance;
    }

    public function visionChildIsLeftX ($bbox_parent, $bbox_child, $tolerance = 15)
    {
        $parent_x = abs($bbox_parent[self::BBOX_POSITION_RIGHT] - $bbox_parent[self::BBOX_POSITION_LEFT]) / 2;
        $child_x = abs($bbox_child[self::BBOX_POSITION_RIGHT] - $bbox_child[self::BBOX_POSITION_LEFT]) / 2;

        return (abs($parent_x - $child_x) <= $tolerance) < 0;
    }

    public function visionChildIsRightX ($bbox_parent, $bbox_child, $tolerance = 15)
    {
        //$parent_x = abs($bbox_parent[self::BBOX_POSITION_RIGHT] - $bbox_parent[self::BBOX_POSITION_LEFT]) / 2;
        //$child_x = abs($bbox_child[self::BBOX_POSITION_RIGHT] - $bbox_child[self::BBOX_POSITION_LEFT]) / 2;

        $parent_x = abs($bbox_parent[self::BBOX_WIDTH]) / 2;
        $child_x = abs($bbox_child[self::BBOX_WIDTH]) / 2;

        return (abs($parent_x - $child_x) <= $tolerance) > 0;
    }

    public function visionChildWidthClasses ($bbox_parent, $bbox_child, $columns_total = 12, $columns_used = 0)
    {
        //$parent_width = abs($bbox_parent[self::BBOX_POSITION_RIGHT] - $bbox_parent[self::BBOX_POSITION_LEFT]);
        //$child_width = abs($bbox_child[self::BBOX_POSITION_RIGHT] - $bbox_child[self::BBOX_POSITION_LEFT]);

        $parent_width = abs($bbox_parent[self::BBOX_WIDTH]);
        $child_width = abs($bbox_child[self::BBOX_WIDTH]);
        $percent = round((100 / $parent_width) * $child_width);
        $columns = round(($percent / 100) * $columns_total);
        $columns = max(1, min($columns_total, $columns));

         return ('col-md-' . (int) $columns);
    }

    public function visionChildsIsFullWidth ($parent, $tolerance = 10)
    {
        $childs_width = abs($parent['children'][array_key_last($parent['children'])]['bbox'][self::BBOX_POSITION_RIGHT]);

        return abs($parent['bbox'][self::BBOX_POSITION_RIGHT] - $childs_width) < $tolerance;
    }


    /**
     * Расчитываем, имеет ли значение отступ между блоками для принятия решения
     */
    public function calcIsMultipleSpaceBetween ($elements, $tolerance = 10)
    {
        $space_main = 0;
        for ($i = 0; $i < count($elements) - 1; $i++)
        {
            $index_1 = $i;
            $index_2 = $i + 1;
            $space_compare = $elements[$index_1]['bbox'][self::BBOX_POSITION_RIGHT] - $elements[$index_2]['bbox'][self::BBOX_POSITION_LEFT];

            $space = max($space_main, $space_compare);
        }

        $space = $space - $tolerance;

        return ($space < 1) ? false : $space;
    }



    /**
     * Разбираем HTML
     */
    public function parseElementsHtml ($html)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $elements = $dom->getElementsByTagName('*');

        foreach ($elements as $index => $element)
        {
            if ($index)
            {
                $text = $dom->saveHtml($element);
                $result = $this->parseElementsHtmlClean($text);
                $text = str_ireplace($text, $result, $text);
            }
        }

        $tag = $elements[0]->tagName;
        $text = $elements[0]->textContent;

        return [$tag, $text];
    }

    /**
     * Нам надо избавиться от ненужного форматирования, которое мы не используем
     */
    public function parseElementsHtmlClean ($html)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $elements = $dom->getElementsByTagName('*');

        foreach ($elements as $i => $element)
        {
            if ($i) break;

            if ($element->tagName == 'math')
            {
                if (preg_match('/mathbf/', $element->textContent))
                {
                    $result = str_replace($dom->saveHTML($element), '', $html);
                }
                elseif (!collect(self::APPROVAL_HTML_TAGS)->contains($element->tagName))
                {
                    $result = str_replace($dom->saveHTML($element), $element->textContent, $html);
                }
            }
        }

        return isset($result) ? $result : $element->textContent;
    }



/*
'id' => '',
        'element_type' => '',
        'page' => null,
        'parent_id' => null,
        'paragraph' => null,
        'sequence' => null,
        'text' => null,
        'synthesized_text' => null,
        'image_path' => null,*/

    public function createStructureElement ($type, $html) : array
    {
        self::$strukturesId += 1;

        $template = self::$template_element;

        $template['id'] = self::$strukturesId;
        $template['page'] = self::$strukturesPage;
        $template['element_type'] = $type;
        $template['text'] = $html;
        $template['synthesized_text'] = strip_tags($html);
        $template['sequence'] = $template['synthesized_text'] ? ++self::$strukturesSequence : null;

        self::$struktures[] = $template;

        return $template;
    }


    /**
     * Заменяет функцию сверху
     */
    public function structureAppend ($type, $html) : array
    {
        self::$strukturesId += 1;

        $template = self::$template_element;

        $template['id'] = self::$strukturesId;
        $template['page'] = self::$strukturesPage;
        $template['element_type'] = $type;
        $template['text'] = $html;
        $template['synthesized_text'] = strip_tags($html);
        $template['sequence'] = $template['synthesized_text'] ? ++self::$strukturesSequence : null;

        self::$struktures[] = $template;

        return $template;
    }

    /**
     * Удаляем ненужные данные и предобрабатываем
     * нужные для дальнейшей обработки и сохранения
     * Тут можно было бы сделать рекурсию по хорошему
     */
    public function cleanChildrenData ($data)
    {
        $this->cleanChildrenDataUseless($data);
        $this->cleanChildrenDataImages($data);

        return $data;
    }

    /**
     * Чистим данные, которые нам вообще не понадобятся
     */
    public function cleanChildrenDataUseless (&$data)
    {
        unset($data['polygon']);
    }

    /**
     * Сохраняем изображения
     */
    public function cleanChildrenDataImages (&$data)
    {
        if (!empty($data['images']))
        {
            foreach ($data['images'] as $key => $value)
            {
                $file_name = $this->cleanImageDataToFile($key, $value);
                $data['images'][$key] = $file_name;
            }
        }
    }

    /**
     * Сразу готовим все изображения,
     * возможно это даже поздно, ведь ранее нам надо
     * было выкинуть возможные изображения страниц
     */
    public function cleanImageDataToFile ($key, $data)
    {
        $file_name = str_replace('/', '_', $key) . '.png';
        $file_directory = public_path(self::$folderPath . '/images/');

        if (!is_dir($file_directory))
        {
            mkdir($file_directory, 0775, true);
        }

        $file_path = $file_directory . $file_name;
        $file_content = base64_decode($data);

        if (!file_exists($file_path))
        {
            file_put_contents($file_path, $file_content);
        }

        return $file_name;
    }

    /**
     * Поиск последнего ребенка в пересобранном массиве
     */
    public function getClusterLastChildrenByPage ($page)
    {
        if (!isset(self::$clusters[$page]) || !isset(self::$clusters[$page]['children']) || count(self::$clusters[$page]['children']))
        {
            return false;
        }

        return end(self::$clusters[$page]['children']);
    }

    /**
     * Проверяем и отсекаем элементы, которые похожи на нумерацию страниц,
     * чтобы они нам не мешали при разбеорке сетки
     */
    public function isPageNumbers ()
    {
        foreach (self::$fileContent['children'] as $json_page_key => $json_page_data)
        {
            [, $type_first, $real_page, $type_second, ] = explode('/', $json_page_data['id']);

            // Проверим на всякий пришли ли нам страница книги,
            // хотя тут по идее больше ничего не не должно быть
            if ($type_first != 'page') continue;

            foreach ($json_page_data['children'] as $json_page_children_key => $json_page_children_data)
            {
                // Выкидываем элементы, которые не содержат текст
                if (empty($json_page_children_data['html'])) continue;

                // Если похоже стринг совпадает с номером страницы
                if ($this->isTextPageNumber($real_page, $json_page_children_data['html']))
                {
                    if (empty(self::$pageNumbersRange[$this->checkPageParity($real_page)]))
                    {
                        self::$pageNumbersRange[$this->checkPageParity($real_page)] = $json_page_children_data['bbox'];
                    }
                    else
                    {
                        self::$pageNumbersRange[$this->checkPageParity($real_page)] = $this->mergeBboxesIsIntersects(self::$pageNumbersRange[$this->checkPageParity($real_page)], $json_page_children_data['bbox']);
                    }
                }
            }
        }
        if (!empty(self::$pageNumbersRange[self::PAGE_EVEN]))
        {
            $this->info('       -> Found coordinates for page number on even pages');
        }
        if (!empty(self::$pageNumbersRange[self::PAGE_ODD]))
        {
            $this->info('       -> Found coordinates for page number on odd pages');
        }
    }

    /**
     * Удаляем элементы похожие на номера страниц
     */
    public function cleanPageNumbers ()
    {
        foreach (self::$fileContent['children'] as $json_page_key => $json_page_data)
        {
            [, $type_first, $real_page, $type_second, ] = explode('/', $json_page_data['id']);

            foreach ($json_page_data['children'] as $json_page_children_key => $json_page_children_data)
            {
                // Выкидываем элементы, которые не содержат текст
                // Однако, тут стоило бы добавить проверку изображений
                // Если их BBOX пересекается и размеры изображения являются небольшини
                // похожими на номер страницы нераспознанный
                if (empty($json_page_children_data['html'])) continue;

                if ($this->isBboxesIntersects(self::$pageNumbersRange[$this->checkPageParity($real_page)], $json_page_children_data['bbox']))
                {
                    self::$deletedPageNumbersElements[$real_page][] = [
                        'bbox' => $json_page_children_data['bbox'],
                        'html' => $json_page_children_data['html'],
                    ];
                    unset(self::$fileContent['children'][$json_page_key]['children'][$json_page_children_key]);
                }
            }
        }
    }

    // ЭТО НАДО ДОБАВИТЬ ОБЯЗАТЕЛЬНО
    // Необходимо чистить пустые элементы
    public function cleanEmptyHtml ()
    {

    }

    /**
     * Проверим, может ли строка являться номером страницы,
     * проверяет только до сотен, так как врят ли будет книга
     * с тысячами в номере страницы
     */
    public function isTextPageNumber ($realPageNumber, $string)
    {
        $string = strip_tags($string);
        return (preg_match('/^\d{1,3}$/', $string)) && $realPageNumber == $string;
    }

    /**
     * Посчитать есть ли пересечение у BBOX
     */
    public function isBboxesIntersects ($bbox1, $bbox2)
    {
        $intersectsX = ($bbox1[0] <= $bbox2[2]) && ($bbox1[2] >= $bbox2[0]);
        $intersectsY = ($bbox1[1] <= $bbox2[3]) && ($bbox1[3] >= $bbox2[1]);

        if (!$intersectsX || !$intersectsY) {
            return false;
        }

        return true;
    }

    /**
     * Посчитать есть ли пересечение у BBOX
     */
    public function isBboxIntersectsByHeight ($bbox1, $bbox2, $expect = 0)
    {
        /*
        if ($bbox1[self::BBOX_POSITION_TOP] > $bbox2[self::BBOX_POSITION_TOP])
        {
            if (abs($bbox2[self::BBOX_POSITION_TOP] - $bbox1[self::BBOX_POSITION_BOTTOM]) > $expect)
            {
                return true;
            }
        }
        else
        {
            if (abs($bbox1[self::BBOX_POSITION_TOP] - $bbox2[self::BBOX_POSITION_BOTTOM]) > $expect)
            {
                return true;
            }
        }

        return false;
        */

        $intersectFirst = $bbox1[self::BBOX_POSITION_TOP] - $bbox2[self::BBOX_POSITION_BOTTOM];
        $intersectSecond = $bbox1[self::BBOX_POSITION_BOTTOM] - $bbox2[self::BBOX_POSITION_TOP];

        if (!$expect && ($intersectFirst > 0 || $intersectSecond > 0))
        {
            return true;
        }
        elseif ($expect)
        {
            if ($intersectFirst > 0 && abs($intersectFirst) > $expect)
            {
                return true;
            }
            if ($intersectSecond > 0 && abs($intersectSecond) > $expect)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Посчитать есть ли пересечение у BBOX
     */
    public function isBboxIntersectsByWidth ($bbox1, $bbox2)
    {
        $intersectsX = ($bbox1[self::BBOX_POSITION_LEFT] <= $bbox2[self::BBOX_POSITION_BOTTOM]) && ($bbox1[self::BBOX_POSITION_BOTTOM] >= $bbox2[self::BBOX_POSITION_LEFT]);

        if (!$intersectsX) {
            return false;
        }

        return true;
    }




    /**
     * Сравнение элементов с BBOX по всем возможным вариациям,
     * которые нам могут пригодиться
     */
    public function bboxesSortByPositions (&$elements, $positions)
    {
        $maps = [
            self::BBOX_POSITION_TOP => function ($a, $b) {
                return $a['bbox'][self::BBOX_POSITION_TOP] <=> $b['bbox'][self::BBOX_POSITION_TOP];
            },
            self::BBOX_POSITION_LEFT => function ($a, $b) {
                return $a['bbox'][self::BBOX_POSITION_LEFT] <=> $b['bbox'][self::BBOX_POSITION_LEFT];
            },
            self::BBOX_POSITION_RIGHT => function ($a, $b) {
                return $a['bbox'][self::BBOX_POSITION_RIGHT] <=> $b['bbox'][self::BBOX_POSITION_RIGHT];
            },
            self::BBOX_POSITION_BOTTOM => function ($a, $b) {
                return $a['bbox'][self::BBOX_POSITION_BOTTOM] <=> $b['bbox'][self::BBOX_POSITION_BOTTOM];
            },
            'center-x' => function($a, $b) {
                $aCenterX = ($a['bbox'][0] + $a['bbox'][2]) / 2;
                $bCenterX = ($b['bbox'][0] + $b['bbox'][2]) / 2;
                return $aCenterX <=> $bCenterX;
            },
            'center-y' => function($a, $b) {
                $aCenterY = ($a['bbox'][1] + $a['bbox'][3]) / 2;
                $bCenterY = ($b['bbox'][1] + $b['bbox'][3]) / 2;
                return $aCenterY <=> $bCenterY;
            },
            'width' => function($a, $b) {
                $aWidth = $a['bbox'][2] - $a['bbox'][0];
                $bWidth = $b['bbox'][2] - $b['bbox'][0];
                return $aWidth <=> $bWidth;
            },
            'height' => function($a, $b) {
                $aHeight = $a['bbox'][3] - $a['bbox'][1];
                $bHeight = $b['bbox'][3] - $b['bbox'][1];
                return $aHeight <=> $bHeight;
            },
            'area' => function($a, $b) {
                $aArea = ($a['bbox'][2] - $a['bbox'][0]) * ($a['bbox'][3] - $a['bbox'][1]);
                $bArea = ($b['bbox'][2] - $b['bbox'][0]) * ($b['bbox'][3] - $b['bbox'][1]);
                return $aArea <=> $bArea;
            }
        ];

        $functions = function($a, $b) use ($positions, $maps) {
            foreach ($positions as $key) {
                return $maps[$key]($a, $b);
            }
        };

        usort($elements, $functions);
    }

    public function bboxesSortByPositionsTopLeft (&$elements)
    {
        usort($elements, function ($a, $b) {
            return ($a['bbox'][1] <=> $b['bbox'][1]) ?: ($a['bbox'][0] <=> $b['bbox'][0]);
        });
    }

    public function bboxesSortByPositionsTopLeftNew (&$elements)
    {
        usort($elements, function($a, $b) {
    // Сначала сравниваем по координате Y (вертикальное положение)
    if ($a['bbox'][1] != $b['bbox'][1]) {
        return $a['bbox'][1] - $b['bbox'][1]; // по возрастанию Y (сверху вниз)
    }

    // Если Y одинаковы, сравниваем по координате X (горизонтальное положение)
    return $a['bbox'][0] - $b['bbox'][0]; // по возрастанию X (слева направо)
});
    }

    /*
    public function sortBboxesByCoordPosition (&$bboxes, $position)
    {
        usort($bboxes, function ($a, $b) use($position) {
            return $a['bbox'][$position] <=> $b['bbox'][$position];
        });
    }
        */

    /**
     * Расчитать общую область для BBOX
     */
    public function calcBboxesArea ($bboxes)
    {
        foreach ($bboxes as $bbox)
        {
            if (!isset($result))
            {
                $result = $bbox['bbox'];
                continue;
            }

            $result = $this->mergeBboxes($result, $bbox['bbox']);
        }

        return $result;
    }

    /**
     * Объеденить BBOX и создать общую область
     */
    public function mergeBboxes ($bbox1, $bbox2)
    {
        $mergedBbox = [
            min($bbox1[self::BBOX_POSITION_LEFT], $bbox2[self::BBOX_POSITION_LEFT]),
            min($bbox1[self::BBOX_POSITION_TOP], $bbox2[self::BBOX_POSITION_TOP]),
            max($bbox1[self::BBOX_POSITION_RIGHT], $bbox2[self::BBOX_POSITION_RIGHT]),
            max($bbox1[self::BBOX_POSITION_BOTTOM], $bbox2[self::BBOX_POSITION_BOTTOM]),
        ];

        return $mergedBbox;
    }


    /**
     * Объеденить два BBOX если они пересекаются,
     * сохранить максимальные и минимальыне координаты
     */
    public function mergeBboxesIsIntersects ($bbox1, $bbox2)
    {
        // Если BBOX не пересекаются, то не пытаемся их объеденить
        // и возвращаем родительский как основной
        if (!$this->isBboxesIntersects($bbox1, $bbox2))
        {
            return $bbox1;
        }

        return $this->mergeBboxes($bbox1, $bbox2);
    }


    /**
     * Получить метаданные элемента
     */
    public function getMetaData ($id)
    {
        list(, $meta[self::META_ENTITY], $meta[self::META_PAGE], $meta[self::META_TYPE], $meta[self::META_ID]) = explode('/', $id);

        return $meta;
    }


    /**
     * Узнаем четность страницы
     */
    public function checkPageParity ($number)
    {
        if ($number <= 0) {
            return self::PAGE_EVEN;
        }

        return ($number % 2 === 0 ? self::PAGE_EVEN : self::PAGE_ODD);
    }


    public function clusterCreateNewBlock ($page_number, $block_class, $bbox = [])
    {
        $row = [
            //'id' => '/page/' . $page_number . '/' . ucfirst($block_class) . '/0',
            'id' => '/page/' . $page_number . '/Div/-1',
            'block_type' => ucfirst($block_class),
            'block_classes' => [ $block_class ],
            'block_styles' => '',
            'bbox' => $bbox,
            'children' => [],
            'section_hierarchy' => [],
            'images' => [],
        ];

        return $row;
    }

    public function clusterAddChildrenToBlock (&$clusters, $claster_key, $children)
    {
        if (empty($clusters[$claster_key]['bbox']))
        {
            $clusters[$claster_key]['children'] = $children;
            $clusters[$claster_key]['bbox'] = $children['bbox'];

        }
        else
        {
            $clusters[$claster_key]['children'] = $children;
            $clusters[$claster_key]['bbox'] = $children['bbox'];
        }
    }

    /**
     * Найти кластер для пересечений по ширине
     */
    public function clusterFindKeyIsIntersectByWitdh ($clusters, $bbox)
    {
        foreach ($clusters as $cluster_key => $cluster)
        {
            if ($this->isBboxIntersectsByWidth($cluster['bbox'], $bbox))
            {
                return $cluster_key;
            }
        }

        return false;
    }

    /**
     * $container = [x, y, x2, y2]
     * Нам известно, что все BBOX в какой-то степени пересекаются по высоте
     * 1. Мы отсортировали из по позиции с лева на право
     * 2. Мы понимаем, что любой следующий элемент находится либо под, либо правее
     * 3. Если находится правее и не пересекается по ширине, значит строка, а если пересекается, тогда это колонка
     */
    public function buildRowColIntersects ($elements)
    {
        // Родительский элемент у нас всегда ROW
        $this->sortBboxesByCoordPosition($elements, self::BBOX_POSITION_LEFT);

        $clusters = [];

        foreach ($elements as $element)
        {
            if ($cluster_key = $this->clusterFindKeyIsIntersectByWitdh($clusters, $element['bbox']))
            {
                $clusters[$cluster_key]['children'][] = $element;
                $clusters[$cluster_key]['bbox'] = $this->mergeBbox($clusters[$cluster_key]['bbox'], $element['bbox']);
            }
            else
            {
                $starter = $this->clusterCreateNewBlock($this->getMetaData($elements[0]['id'])[self::META_PAGE], 'col');
                $starter['children'][] = $element;
                $starter['bbox'] = $element['bbox'];
                $clusters[] = $starter;
            }
        }

        return $clusters;

        /*
        $this->info('CLUSTERS');
        $this->info(print_r($clusters, true));
        exit();
        */
    }


    /******************************************** FUNCTIONS FOR DELETE - USED ONLY FOR TEST ********************************************/

    /**
     * Сохранить вывод для просмотра
     */
    public function saveOutData ($file_name, $data)
    {
        $file_directory = public_path(self::$folderPath . '/test_data/');
        $file_path = $file_directory . $file_name;

        if (!is_dir($file_directory))
        {
            mkdir($file_directory, 0775, true);
        }

        file_put_contents($file_path, print_r($data, true));
    }

    /**
     * Сохраним вывод HTML для теста
     */
    public function saveOutHtml ($file_name, $data)
    {
        $file_directory = public_path(self::$folderPath . '/test_data/');
        $file_path = $file_directory . $file_name;

        $content = ('
            <!doctype html>
                <html lang="ru">
                <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Книга</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body { background-color: #b3b3b3ff }
                    .page_content { background-color: #fff; border: 1px solid #424242ff; padding: 30px; border-radius: 10px }
                    .page_title { font-size: 2rem; color: #fff; text-align: center; margin: 50px 0 20px 0; font-weight: bold }
                    img { max-width: 100% }
                    ul { list-style: none; padding-left: 0 }
                </style>
                </head>
                <body>
                <div class="container m-4">
        ');

        $current_page = 0;

        foreach ($data as $element_id => $element_data)
        {
            if ($current_page && $current_page != $element_data['page'])
            {
                $content .= '</div>';
            }

            if ($current_page != $element_data['page'])
            {
                $content .= '<h2 class="page_title">Страница ' . $element_data['page'] . '</h2>';
                $content .= '<div class="page_content">';
                $current_page = $element_data['page'];
            }

            $content .= $element_data['text'];


        }

        $content .= ('
            </div></body></html>
        ');

        if (!is_dir($file_directory))
        {
            mkdir($file_directory, 0775, true);
        }

        file_put_contents($file_path, $content);
    }

}
