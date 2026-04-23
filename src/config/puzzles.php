<?php

declare(strict_types=1);

// Каталог пазлів доступний адміну для запуску раундів.
function getPuzzleCatalog(): array
{
    return [
        'africa_intro' => [
            'title' => 'Африка',
            'type' => 'Материк',
            'level' => 'Рівень 1 · квадрати 4×3',
            'cols' => 4,
            'rows' => 3,
            'shapeMode' => 'square',
            'asset' => '/assets/img/africa.svg',
            'hint' => 'Знайдіть контури північної частини та поступово відновіть материк.',
        ],
        'greenland_mid' => [
            'title' => 'Гренландія',
            'type' => 'Острів',
            'level' => 'Рівень 2 · мікс фігур 4×4',
            'cols' => 4,
            'rows' => 4,
            'shapeMode' => 'mixed',
            'asset' => '/assets/img/greenland.svg',
            'hint' => 'Фігури мають різні краї, тому орієнтуйтеся на контур і колір.',
        ],
        'baikal_advanced' => [
            'title' => 'Байкал',
            'type' => 'Озеро',
            'level' => 'Рівень 3 · мікс фігур 5×4',
            'cols' => 5,
            'rows' => 4,
            'shapeMode' => 'mixed',
            'asset' => '/assets/img/baikal.svg',
            'hint' => 'Вузька форма озера допоможе знайти центральні фрагменти.',
        ],
        'pacific_expert' => [
            'title' => 'Тихий океан',
            'type' => 'Океан',
            'level' => 'Рівень 4 · складні фігури 6×4',
            'cols' => 6,
            'rows' => 4,
            'shapeMode' => 'irregular',
            'asset' => '/assets/img/pacific.svg',
            'hint' => 'Це найскладніший рівень: використовуйте форму хвиль та островів.',
        ],
    ];
}
