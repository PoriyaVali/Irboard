<?php

namespace App\Protocols;

/** Explicit flag=mihomo and the Mihomo user agent use the Meta exporter. */
class Mihomo extends ClashMeta
{
    public $flag = 'mihomo';
}
