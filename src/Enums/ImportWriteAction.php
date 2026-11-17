<?php

namespace SmartImport\Enums;

enum ImportWriteAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Skip = 'skip';
    case Fail = 'fail';
}
