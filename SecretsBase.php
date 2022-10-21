<?php

namespace Pantheon\QuicksilverPushback;

class SecretsBase implements SecretsInterface {
    
    /**
     * Get home directory.
     */
    protected function getHomeDir(): string {
        return $_SERVER['HOME'];
    }

}