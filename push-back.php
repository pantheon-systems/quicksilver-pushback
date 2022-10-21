<?php

include __DIR__ . '/http-build-url.php';

include __DIR__ . 'Pusback.php';

$pushback = new Pushback();
$pushback->pushback();
