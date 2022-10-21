<?php

namespace Pantheon\QuicksilverPushback;

class SecretsLegacy extends SecretsBase {

    /**
     * Secrets for current site.
     */
    protected array $secrets;
    
    /**
     * {@inheritdoc}
     */
    public function getSecrets(): array
    {
        if (!empty($this->secrets)) {
            return $this->secrets;
        }
        $bindingDir = $this->getHomeDir();
        $privateFiles = realpath("$bindingDir/files/private");
        $secretsFile = "$privateFiles/.build-secrets/tokens.json";
        $this->secrets = $this->loadSecretsFromFile($secretsFile);
        return $this->secrets;
    }

    /**
     * {@inheritdoc}
     */
    public function getSecret(string $name): string
    {
        $secrets = $this->getSecrets();
        if (empty($secrets[$name])) {
            return '';
        }
        return $secrets[$name];
    }
    
    /**
     * Load secrets from file.
     */
    private function loadSecretsFromFile($secretsFile)
    {
      if (!file_exists($secretsFile)) {
        print "Could not find $secretsFile\n";
        return [];
      }
      $secretsContents = file_get_contents($secretsFile);
      if (empty($secretsContents)) {
        print "Secrets file is empty\n";
        return [];
      }
      $secrets = json_decode($secretsContents, true);
      if (empty($secrets)) {
        print "No data in secrets\n";
      }
      return $secrets;
    }
}