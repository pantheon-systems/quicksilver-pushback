<?php

include __DIR__ . '/lean-repo-utils.php';

// Do nothing if not on Pantheon or if on the test/live environments.
if (!isset($_ENV['PANTHEON_ENVIRONMENT']) || in_array($_ENV['PANTHEON_ENVIRONMENT'], ['test', 'live']) ) {
    return;
}

/**
 * This script will separates changes from the most recent commit
 * and pushes any that affect the canonical sources back to the
 * master repository.
 */
$bindingDir = $_SERVER['HOME'];
$fullRepository = "$bindingDir/code";
// $docRoot = "$fullRepository/" . $_SERVER['DOCROOT'];

print "Enter push-back. Repository root is $fullRepository.\n";

$privateFiles = "$bindingDir/files/private";
$gitSecretsFile = "$privateFiles/git-secrets.json";
$gitSecrets = load_github_secrets($gitSecretsFile);
$git_token = $gitSecrets['token'];

if (empty($git_token)) {
    $message = "Unable to load Git token from secrets file";
    pantheon_raise_dashboard_error($message, true);
}

$workDir = "$bindingDir/tmp/pushback-workdir";

// Temporary:
passthru("rm -rf $workDir");
mkdir($workDir);

$buildMetadata = load_build_metadata($fullRepository);
// The remote repo to push to
$upstreamRepo = $buildMetadata['url'];
$upstreamRepoWithCredentials = $upstreamRepo;
if (isset($buildMetadata['git-provider'])) {
    switch ($buildMetadata['git-provider']) {
        case 'github':
            $upstreamRepoWithCredentials = str_replace('git@github.com:', 'https://github.com/', $upstreamRepoWithCredentials);
            $upstreamRepoWithCredentials = str_replace('https://', "https://$git_token:x-oauth-basic@", $upstreamRepoWithCredentials);
            break;

        case 'gitlab':
            // While initial Git URLs from Build Tools are SSH based, they are immediately replaced
            // by the HTTP ones from GitLab CI. This runs at initial setup so the SSH one shouldn't
            // be there very long.
            if ((strpos($upstreamRepoWithCredentials, 'https://') !== false) || (strpos($upstreamRepoWithCredentials, 'http://') !== false)) {
                $parsed_url = parse_url($upstreamRepoWithCredentials);
                $parsed_url['user'] = 'oauth2';
                $parsed_url['pass'] = $git_token;
                $upstreamRepoWithCredentials = http_build_url($parsed_url);
            }
            else {
                pantheon_raise_dashboard_error("Error parsing GitLab URL from Build Metadata.", true);
            }
            break;

        case 'bitbucket':
            pantheon_raise_dashboard_error('Bitbucket is not currently supported with Quicksilver Pushback.');
            break;

        default:

    }
}

$status = push_back($fullRepository, $workDir, $upstreamRepoWithCredentials, $buildMetadata, "build-metadata.json");

// Throw out the working repository.
passthru("rm -rf $workDir");

// Post error to dashboard and exit if the merge fails.
if ($status != 0) {
    $message = "Commit back to canonical repository failed with exit code $status.";
    pantheon_raise_dashboard_error($message, true);
}