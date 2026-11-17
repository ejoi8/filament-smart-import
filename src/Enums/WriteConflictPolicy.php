<?php

namespace SmartImport\Enums;

enum WriteConflictPolicy: string
{
    case Fail = 'fail';
    case Skip = 'skip';
}
