<?php

namespace SmartImport\Enums;

enum ImportMode: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Upsert = 'upsert';
    case Skip = 'skip';
}
