<?php

include __DIR__ . '/http-build-url.php';

include __DIR__ . '/Pushback.php';

$pushback = new Pantheon\QuicksilverPushback\Pushback();
$pushback->pushback('legacy');
