<?php
$allRows = [
    ['Máy 1', 'Cấu hình A', ''],
    ['Thành Phần', 'Mã SP', '', 'SLƯỢNG'],
    ['CPU', 'Intel i5', 'SN-CPU-1', '1'],
    ['RAM', '8GB', 'SN-RAM-1', '1'],
    [],
    ['Máy 2', 'Cấu hình A', ''],
    ['Thành Phần', 'Mã SP', '', 'SLƯỢNG'],
    ['CPU', 'Intel i5', '', '1'],
    ['RAM', '8GB', '', '1']
];

$machineBlocks = [];
foreach ($allRows as $rIdx => $row) {
    foreach ($row as $cIdx => $cellVal) {
        if (preg_match('/^máy\s*(\d+)$/ui', trim((string)($cellVal ?? '')), $m)) {
            $machineBlocks[] = [
                'col'      => $cIdx,
                'row'      => $rIdx,
                'so_may'   => (int)$m[1],
                'items'    => [],
            ];
        }
    }
}

$blocksByCol = [];
foreach ($machineBlocks as $bIdx => $b) $blocksByCol[$b['col']][] = $bIdx;

foreach ($blocksByCol as $col => $bIdxList) {
    usort($bIdxList, fn($a, $b) => $machineBlocks[$a]['row'] <=> $machineBlocks[$b]['row']);
    for ($bi = 0; $bi < count($bIdxList); $bi++) {
        $bIdx  = $bIdxList[$bi];
        $block = &$machineBlocks[$bIdx];
        $startRow = $block['row'] + 2;
        $endRow   = ($bi + 1 < count($bIdxList)) ? $machineBlocks[$bIdxList[$bi + 1]]['row'] : count($allRows);

        $lastType = $lastModel = '';
        for ($r = $startRow; $r < $endRow; $r++) {
            $row = $allRows[$r] ?? [];
            $typeCell  = trim((string)($row[$col]     ?? ''));
            $modelCell = trim((string)($row[$col + 1] ?? ''));
            $serialCell= trim((string)($row[$col + 2] ?? ''));

            if ($typeCell  !== '') $lastType  = $typeCell;
            if ($modelCell !== '') $lastModel = $modelCell;

            $type = $lastType;
            if ($type === '' || mb_strtolower($type, 'UTF-8') === 'thành phần') continue;

            $hasData = false;
            foreach ($row as $v) { if (trim((string)($v ?? '')) !== '') { $hasData = true; break; } }
            if (!$hasData) { $lastType = $lastModel = ''; break; }

            $block['items'][] = ['type' => $type, 'model' => $lastModel, 'serial' => $serialCell];
        }
        unset($block);
    }
}

print_r($machineBlocks);
