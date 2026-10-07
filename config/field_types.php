<?php

use App\Modules\Configuration\Domain\FieldTypes\AutomaticFieldType;
use App\Modules\Configuration\Domain\FieldTypes\FixedFieldType;
use App\Modules\Configuration\Domain\FieldTypes\ProfileFieldType;
use App\Modules\Configuration\Domain\FieldTypes\TableFieldType;
use App\Modules\Configuration\Domain\FieldTypes\TextFieldType;
use App\Modules\Configuration\Domain\FieldTypes\UnitsFieldType;

return [
    'types' => [
        TextFieldType::class,
        ProfileFieldType::class,
        TableFieldType::class,
        AutomaticFieldType::class,
        FixedFieldType::class,
        UnitsFieldType::class,
    ],
];
