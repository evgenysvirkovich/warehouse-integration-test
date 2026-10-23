<?php

declare(strict_types=1);

class WarehouseTaskRunner
{
    private array $catalog;
    private array $stockCells;
    private array $tasks;
    private array $scans;
    private array $inventoryCount;

    public function __construct(string $dataDir)
    {
        $this->catalog = $this->loadCsv($dataDir . '/catalog.csv');
        $this->stockCells = $this->loadCsv($dataDir . '/stock_cells.csv');
        $this->tasks = $this->loadJson($dataDir . '/fbs_tasks.json');
        $this->scans = $this->loadCsv($dataDir . '/scans.csv');
        $this->inventoryCount = $this->loadCsv($dataDir . '/inventory_count.csv');
    }

    private function loadCsv(string $file): array
    {
        $data = [];
        if (($handle = fopen($file, "r")) !== false) {
            $headers = str_getcsv(fgets($handle), ";");
            while (($row = fgetcsv($handle, 1000, ";")) !== false) {
                $data[] = array_combine($headers, $row);
            }
            fclose($handle);
        }
        return $data;
    }

    private function loadJson(string $file): array
    {
        $raw = json_decode(file_get_contents($file), true);
        $tasks = [];
    
        // Ищем ключ "tasks" (с пробелом или без — защита от артефактов данных)
        $tasksKey = null;
        foreach (array_keys($raw) as $key) {
            if (trim($key) === 'tasks') {
                $tasksKey = $key;
                break;
            }
        }
    
        if (!$tasksKey) {
            throw new \RuntimeException("Ключ 'tasks' не найден в JSON");
        }
    
        foreach ($raw[$tasksKey] as $task) {
            // Ищем ключ items внутри task (тоже может быть с пробелом)
            $itemsKey = null;
            foreach (array_keys($task) as $key) {
                if (trim($key) === 'items') {
                    $itemsKey = $key;
                    break;
                }
            }
        
            $tasks[] = [
                'task_id' => trim($task['task_id '] ?? $task['task_id'] ?? ''),
                'marketplace' => trim($task['marketplace '] ?? $task['marketplace'] ?? ''),
                'ship_by' => trim($task['ship_by '] ?? $task['ship_by'] ?? ''),
                'items' => array_map(fn($i) => [
                    'sku' => trim($i['sku '] ?? $i['sku'] ?? ''),
                    'barcode' => trim($i['barcode '] ?? $i['barcode'] ?? ''),
                    'qty' => (int)($i['qty'] ?? 0)
                ], $task[$itemsKey] ?? [])
            ];
        }
        return $tasks;
    }

    public function run(): void
    {
        $this->solveTaskA();
        $this->solveTaskB();
        $this->solveTaskC();
        $this->printRecommendations();
    }

    private function solveTaskA(): void
    {
        echo "=== ЗАДАЧА A: ЛИСТ ПОДБОРА ===\n";
        
        // 1. Сортировка заданий по срочности (ship_by)
        usort($this->tasks, fn($a, $b) => strtotime($a['ship_by']) <=> strtotime($b['ship_by']));

        // 2. Агрегация остатков по SKU
        $stockBySku = [];
        foreach ($this->stockCells as $cell) {
            $stockBySku[$cell['sku']][] = ['cell' => $cell['cell'], 'qty' => (int)$cell['qty']];
        }

        $pickList = [];
        $impossibleTasks = [];
        $stockCopy = $stockBySku;

        foreach ($this->tasks as $task) {
            $canComplete = true;
            foreach ($task['items'] as $item) {
                $needed = $item['qty'];
                $picked = 0;
                
                if (isset($stockCopy[$item['sku']])) {
                    foreach ($stockCopy[$item['sku']] as &$cell) {
                        if ($picked >= $needed) break;
                        $take = min($cell['qty'], $needed - $picked);
                        $pickList[] = [
                            'task_id' => $task['task_id'],
                            'cell' => $cell['cell'],
                            'sku' => $item['sku'],
                            'barcode' => $item['barcode'],
                            'qty' => $take
                        ];
                        $cell['qty'] -= $take;
                        $picked += $take;
                    }
                }
                if ($picked < $needed) $canComplete = false;
            }
            if (!$canComplete) $impossibleTasks[] = $task['task_id'];
        }

        // 3. Сортировка листа подбора по ячейке для оптимального маршрута кладовщика
        usort($pickList, fn($a, $b) => strnatcmp($a['cell'], $b['cell']));

        echo str_pad("Ячейка", 10) . str_pad("SKU", 10) . str_pad("Штрихкод", 16) . str_pad("Кол-во", 7) . "Задание\n";
        echo str_repeat("-", 60) . "\n";
        foreach ($pickList as $p) {
            echo str_pad($p['cell'], 10) . str_pad($p['sku'], 10) . str_pad($p['barcode'], 16) . str_pad((string)$p['qty'], 7) . $p['task_id'] . "\n";
        }
        
        echo "\nНевозможно собрать (недостаток остатков): " . (empty($impossibleTasks) ? "Нет" : implode(", ", $impossibleTasks)) . "\n\n";
    }

    private function solveTaskB(): void
    {
        echo "=== ЗАДАЧА B: КОНТРОЛЬ СБОРКИ ===\n";
        
        $scansByTask = [];
        foreach ($this->scans as $scan) {
            $scansByTask[trim($scan['task_id'])][] = $scan;
        }

        $usedKiz = []; // Для отслеживания дублей КИЗ across all tasks

        foreach ($this->tasks as $task) {
            $taskId = $task['task_id'];
            $issues = [];
            
            $expected = [];
            foreach ($task['items'] as $item) {
                $expected[$item['barcode']] = ($expected[$item['barcode']] ?? 0) + $item['qty'];
            }

            $scannedQty = [];
            $scannedKiz = [];

            foreach ($scansByTask[$taskId] ?? [] as $scan) {
                $bc = trim($scan['barcode']);
                $kiz = trim($scan['marking_code']);
                $scannedQty[$bc] = ($scannedQty[$bc] ?? 0) + 1;

                if (!empty($kiz)) {
                    $scannedKiz[] = ['barcode' => $bc, 'kiz' => $kiz];
                    if (isset($usedKiz[$kiz])) {
                        $issues[] = "КРИТИЧНО: Дубль КИЗ '$kiz' (уже был в задании {$usedKiz[$kiz]})";
                    } else {
                        $usedKiz[$kiz] = $taskId;
                    }
                }
            }

            foreach ($expected as $bc => $qty) {
                $actual = $scannedQty[$bc] ?? 0;
                if ($actual < $qty) $issues[] = "Недостача: $bc (нужно $qty, есть $actual)";
                if ($actual > $qty) $issues[] = "Пересорт/Излишек: $bc (нужно $qty, есть $actual)";

                $catalogItem = array_first($this->catalog, fn($c) => $c['barcode'] === $bc);
                if ($catalogItem && $catalogItem['marked'] == '1') {
                    $kizForThisBc = array_filter($scannedKiz, fn($k) => $k['barcode'] === $bc);
                    if (count($kizForThisBc) < $qty) {
                        $issues[] = "Не хватает КИЗ для $bc (нужно $qty, отсканировано " . count($kizForThisBc) . ")";
                    }
                    foreach ($kizForThisBc as $k) {
                        // Проверка формата: 01 + 0 + EAN13 (14 цифр) + 21 + серийник
                        if (!preg_match('/^01(0\d{13})21(.+)$/', $k['kiz'], $matches)) {
                            $issues[] = "Неверный формат КИЗ: {$k['kiz']}";
                        } elseif ($matches[1] !== ('0' . $bc)) {
                            $issues[] = "КИЗ не соответствует товару: {$k['kiz']} (ожидается GTIN 0$bc)";
                        }
                    }
                }
            }

            foreach ($scannedQty as $bc => $qty) {
                if (!isset($expected[$bc])) {
                    $issues[] = "Лишний товар в сканах: $bc ($qty шт.)";
                }
            }

            echo "Задание $taskId: " . (empty($issues) ? "✅ OK" : "⚠️ ОШИБКИ") . "\n";
            foreach ($issues as $issue) echo "   - $issue\n";
        }
        echo "\n";
    }

    private function solveTaskC(): void
    {
        echo "=== ЗАДАЧА C: ИНВЕНТАРИЗАЦИЯ ===\n";
        
        // Считаем реально собранные (успешные) единицы по штрихкоду
        // Исключаем дубли КИЗ и ошибки из подсчета "ушедшего" товара
        $assembled = [];
        $validScans = []; 
        $usedKizCheck = [];
        
        foreach ($this->scans as $scan) {
            $bc = trim($scan['barcode']);
            $kiz = trim($scan['marking_code']);
            
            $isDuplicateKiz = !empty($kiz) && isset($usedKizCheck[$kiz]);
            if (!empty($kiz)) $usedKizCheck[$kiz] = true;

            // Упрощенно считаем, что скан засчитывается, если это не явный дубль КИЗ
            // В реальной системе статус скана определяется после валидации
            if (!$isDuplicateKiz) {
                $assembled[$bc] = ($assembled[$bc] ?? 0) + 1;
            }
        }

        $stockByBc = [];
        foreach ($this->stockCells as $cell) {
            $item = array_first($this->catalog, fn($c) => $c['sku'] === $cell['sku']);
            if ($item) {
                $stockByBc[$item['barcode']] = ($stockByBc[$item['barcode']] ?? 0) + (int)$cell['qty'];
            }
        }

        echo str_pad("Ячейка", 10) . str_pad("Штрихкод", 16) . str_pad("Учёт", 7) . str_pad("Собрано", 9) . str_pad("Ожид.факт", 11) . str_pad("Пересчёт", 10) . "Статус\n";
        echo str_repeat("-", 85) . "\n";

        foreach ($this->inventoryCount as $count) {
            $bc = trim($count['barcode']);
            $counted = (int)$count['counted_qty'];
            $stock = $stockByBc[$bc] ?? 0;
            $assembledQty = $assembled[$bc] ?? 0;
            $expectedFact = $stock - $assembledQty;
            $diff = $counted - $expectedFact;

            $status = "OK";
            if (!array_first($this->catalog, fn($c) => $c['barcode'] === $bc)) $status = "Неизвестный товар";
            elseif ($diff < 0) $status = "НЕДОСТАЧА";
            elseif ($diff > 0) $status = "ИЗЛИШЕК";

            if ($status !== "OK") {
                echo str_pad($count['cell'], 10) . str_pad($bc, 16) . str_pad((string)$stock, 7) . 
                     str_pad((string)$assembledQty, 9) . str_pad((string)$expectedFact, 11) . 
                     str_pad((string)$counted, 10) . "$status\n";
            }
        }
        echo "\n";
    }

    private function printRecommendations(): void
    {
        echo "=== РЕКОМЕНДАЦИИ ПО УЛУЧШЕНИЮ ПРОЦЕССА ===\n";
        echo "1. Валидация КИЗ на стороне ТСД в реальном времени: проверка формата и уникальности должна происходить\n";
        echo "   мгновенно при сканировании, с блокировкой повторного использования того же КИЗ в системе.\n";
        echo "2. Жёсткое резервирование остатков в WMS/1С в момент создания задания, а не в момент подбора.\n";
        echo "   Это исключит ситуацию, когда срочное задание не может быть собрано из-за того, что товар уже ушёл.\n";
        echo "3. Внедрение этапа «Контрольная сборка» (весовой контроль или сканирование собранной коробки целиком)\n";
        echo "   перед передачей в зону отгрузки, чтобы отсечь пересорт и недостачи до закрытия задания.\n";
        echo "4. Разделение логики «учётного остатка» и «доступного для сборки»: товар, находящийся в активных заданиях,\n";
        echo "   должен исключаться из доступного остатка для новых заданий.\n";
    }
}

// Хелпер для поиска первого элемента (аналог collect()->first())
function array_first(array $array, callable $callback) {
    foreach ($array as $key => $value) {
        if ($callback($value, $key)) return $value;
    }
    return null;
}

$runner = new WarehouseTaskRunner(__DIR__ . '/data');
$runner->run();
