<?php

namespace App\Support;

use App\Models\DocumentType;
use App\Models\Organization;

final class Catalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function documentTypes(): array
    {
        return [
            ['code' => 'med', 'name' => 'Медицинский осмотр (периодический)', 'short_name' => 'Медосмотр', 'category' => 'med', 'validity_months' => 12, 'is_basic' => true, 'description' => 'Заключение периодического медицинского осмотра, обычно раз в год.'],
            ['code' => 'psych', 'name' => 'Психиатрическое освидетельствование', 'short_name' => 'Психосвидетельствование', 'category' => 'med', 'validity_months' => 60, 'description' => 'Для работ повышенной опасности, обычно раз в 5 лет.'],
            ['code' => 'intro', 'name' => 'Вводный инструктаж по безопасности и охране труда', 'short_name' => 'Вводный инструктаж', 'category' => 'briefing', 'validity_months' => null, 'is_basic' => true, 'description' => 'Проводится при приёме на работу, бессрочно.'],
            ['code' => 'repeat', 'name' => 'Повторный инструктаж на рабочем месте', 'short_name' => 'Повторный инструктаж', 'category' => 'briefing', 'validity_months' => 6, 'description' => 'Обычно раз в 6 месяцев, для работ повышенной опасности чаще.'],
            ['code' => 'ot', 'name' => 'Удостоверение о проверке знаний по безопасности и охране труда', 'short_name' => 'ОТ', 'category' => 'ot', 'validity_months' => 12, 'is_basic' => true, 'description' => 'Проверка знаний по БиОТ, для рабочих обычно ежегодно.'],
            ['code' => 'pb', 'name' => 'Удостоверение по промышленной безопасности', 'short_name' => 'ПБ', 'category' => 'ot', 'validity_months' => 12, 'description' => 'Для работ на опасных производственных объектах.'],
            ['code' => 'eb', 'name' => 'Удостоверение по электробезопасности', 'short_name' => 'ЭБ', 'category' => 'ot', 'validity_months' => 12, 'has_group' => true, 'description' => 'С группой допуска I-V. Проверка знаний обычно ежегодно.'],
            ['code' => 'height', 'name' => 'Допуск к работам на высоте', 'short_name' => 'Высота', 'category' => 'special', 'validity_months' => 12, 'description' => 'Работы на высоте 1,8 м и более.'],
            ['code' => 'fire', 'name' => 'Допуск к огневым работам (пожарно-технический минимум)', 'short_name' => 'Огневые/ПТМ', 'category' => 'special', 'validity_months' => 12, 'description' => 'Обучение ПТМ и допуск к огневым работам.'],
            ['code' => 'gas', 'name' => 'Допуск к газоопасным работам', 'short_name' => 'Газоопасные', 'category' => 'special', 'validity_months' => 12, 'description' => 'Обучение и проверка знаний, включая работу в СИЗОД.'],
            ['code' => 'confined', 'name' => 'Работы в замкнутых пространствах', 'short_name' => 'Замкнутые пространства', 'category' => 'special', 'validity_months' => 12],
            ['code' => 'sling', 'name' => 'Удостоверение стропальщика', 'short_name' => 'Стропальщик', 'category' => 'profession', 'validity_months' => 12, 'description' => 'Периодическая проверка знаний обычно раз в год.'],
            ['code' => 'welder', 'name' => 'Удостоверение электрогазосварщика (аттестация)', 'short_name' => 'Сварщик', 'category' => 'profession', 'validity_months' => 24],
            ['code' => 'lift', 'name' => 'Удостоверение рабочего люльки подъёмника', 'short_name' => 'Люлька', 'category' => 'profession', 'validity_months' => 12],
            ['code' => 'first_aid', 'name' => 'Обучение оказанию первой помощи', 'short_name' => 'Первая помощь', 'category' => 'ot', 'validity_months' => 12],
            ['code' => 'ppe', 'name' => 'Личная карточка учёта выдачи СИЗ', 'short_name' => 'СИЗ', 'category' => 'ppe', 'validity_months' => 12, 'is_basic' => true, 'description' => 'Отметка о выдаче спецодежды и СИЗ по нормам, сверка раз в год.'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function profileTemplates(): array
    {
        $base = ['med', 'intro', 'repeat', 'ot', 'ppe'];

        return [
            [
                'customer' => 'ПНХЗ',
                'name' => 'ПНХЗ: огневые работы',
                'work_type' => 'Огневые работы',
                'description' => 'Сварка и резка на действующей установке. Пример набора, уточняется у заказчика.',
                'items' => array_merge($base, ['pb', 'fire', 'gas', 'first_aid', 'welder']),
            ],
            [
                'customer' => 'ПНХЗ',
                'name' => 'ПНХЗ: газоопасные работы',
                'work_type' => 'Газоопасные работы',
                'description' => 'Вскрытие аппаратов, работы в колодцах и ёмкостях. Пример набора.',
                'items' => array_merge($base, ['pb', 'gas', 'confined', 'psych', 'first_aid']),
            ],
            [
                'customer' => 'ГРЭС-1',
                'name' => 'ГРЭС: работы в электроустановках',
                'work_type' => 'Работы в электроустановках',
                'description' => 'Электромонтаж и наладка в действующих электроустановках. Пример набора.',
                'items' => array_merge($base, ['pb', ['eb', 4], 'height', 'first_aid']),
            ],
            [
                'customer' => 'КЭЗ',
                'name' => 'ERG: работы на высоте',
                'work_type' => 'Работы на высоте',
                'description' => 'Монтаж металлоконструкций, кровля, площадки обслуживания. Пример набора.',
                'items' => array_merge($base, ['pb', 'height', 'psych', 'first_aid', ['eb', 2]]),
            ],
            [
                'customer' => 'КЭЗ',
                'name' => 'ERG: стропальные и такелажные работы',
                'work_type' => 'Стропальные работы',
                'description' => 'Подъём и перемещение грузов кранами. Пример набора.',
                'items' => array_merge($base, ['pb', 'sling', 'height']),
            ],
        ];
    }

    public static function installDocumentTypes(Organization $org): void
    {
        foreach (self::documentTypes() as $i => $row) {
            DocumentType::updateOrCreate(
                ['organization_id' => $org->id, 'code' => $row['code']],
                array_merge(['has_group' => false, 'is_basic' => false, 'description' => null], $row, ['sort' => ($i + 1) * 10]),
            );
        }
    }
}
