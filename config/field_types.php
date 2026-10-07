<?php

use App\Modules\Configuration\Domain\FieldTypes\AutomaticFieldType;
use App\Modules\Configuration\Domain\FieldTypes\ProfileFieldType;
use App\Modules\Configuration\Domain\FieldTypes\TableFieldType;
use App\Modules\Configuration\Domain\FieldTypes\TextFieldType;

return [
    'types' => [
        TextFieldType::class,
        ProfileFieldType::class,
        TableFieldType::class,
        AutomaticFieldType::class,
    ],
];
