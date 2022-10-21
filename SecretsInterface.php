<?php

namespace Pantheon\QuicksilverPushback;

interface SecretsInterface {
    
    /**
     * Return secrets for current site.
     */
    public function getSecrets(): array;

    /**
     * Return named secret for current site.
     */
    public function getSecret(string $name): string;

}