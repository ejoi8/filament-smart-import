<?php

namespace SmartImport\Enums;

enum BlankCellPolicy: string
{
    case Preserve = 'preserve';
    case SetEmptyString = 'empty_string';
    case SetNull = 'null';
}
