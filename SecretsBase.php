<?php

namespace Pantheon\QuicksilverPushback;

abstract class SecretsBase implements SecretsInterface {
    
    /**
     * Get home directory.
     */
    protected function getHomeDir(): string {
        return $_SERVER['HOME'];
    }

    /**
     * Return secrets for current site.
     */
    abstract public function getSecrets(): array;

    /**
     * Return named secret for current site.
     */
    abstract public function getSecret(string $name): string;

}