<?php

namespace Jiannius\Audit\Tests\Fixtures;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
}
