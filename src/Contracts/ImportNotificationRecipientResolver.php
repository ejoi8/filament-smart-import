<?php

namespace SmartImport\Contracts;

use SmartImport\Data\ImportNotificationRecipient;
use SmartImport\Models\ImportSession;

interface ImportNotificationRecipientResolver
{
    /** @return list<ImportNotificationRecipient> */
    public function recipients(ImportSession $session): array;
}
