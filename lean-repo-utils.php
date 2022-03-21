<?php

function load_git_secrets($gitSecretsFile)
{
  if (!file_exists($gitSecretsFile)) {
    print "Could not find $gitSecretsFile\n";
    return [];
  }
  $gitSecretsContents = file_get_contents($gitSecretsFile);
  if (empty($gitSecretsContents)) {
    print "GitHub secrets file is empty\n";
    return [];
  }
  $gitSecrets = json_decode($gitSecretsContents, true);
  if (empty($gitSecrets)) {
    print "No data in Git secrets\n";
  }
  return $gitSecrets;
}

/**
 * Read the secrets.json file
 */
function pantheon_get_secrets($bindingDir, $requiredKeys, $defaultValues) {
  $secretsFile = "$bindingDir/files/private/.build-secrets/tokens.json";
  if (!file_exists($secretsFile)) {
    pantheon_raise_dashboard_error('Secrets file does not exist');
  }
  $secretsContents = file_get_contents($secretsFile);
  if (empty($secretsContents)) {
    pantheon_raise_dashboard_error('Could not read secrets file (or it is empty).');
  }
  $secrets = json_decode($secretsContents, 1);
  if (empty($secrets)) {
    pantheon_raise_dashboard_error('Could not parse json data in secrets file.');
  }
  $secrets += $defaultValues;
  $missing = array_diff($requiredKeys, array_keys($secrets));
  if (!empty($missing)) {
    die('Missing required keys in json secrets file: ' . implode(',', $missing) . '. Aborting!');
  }
  return $secrets;
}

/**
 * Function to report an error on the Pantheon dashboard
 *
 * Not supported; may stop working at any point in the future.
 */
function pantheon_raise_dashboard_error($reason = 'Uknown failure', $extended = FALSE) {
  // Make creative use of the error reporting API
  $data = array('file'=>'Quicksilver Pushback',
                'line'=>'Error',
                'type'=>'error',
                'message'=>$reason);
  $params = http_build_query($data);
  $result = pantheon_curl('https://api.live.getpantheon.com/sites/self/environments/self/events?'. $params, NULL, 8443, 'POST');
  error_log("Quicksilver Pushback Integration failed - $reason");
  // Dump additional debug info into the error log
  if ($extended) {
    error_log(print_r($extended, 1));
  }
  die("Quicksilver Pushback Integration failed - $reason");
}

/**
 * Read the Build Providers file
 */
function load_build_providers($fullRepository) {
    $buildProvidersFile = "build-providers.json";
    if (!file_exists("$fullRepository/$buildProvidersFile")) {
        pantheon_raise_dashboard_error("Could not find build metadata file, $buildProvidersFile\n");
    }
    $buildProvidersFileContents = file_get_contents("$fullRepository/$buildProvidersFile");
    $buildProviders = json_decode($buildProvidersFileContents, true);
    if (empty($buildProviders)) {
        pantheon_raise_dashboard_error("No data in build providers\n");
    }

    return $buildProviders;
}

/**
 * Read the Build Metadata file
 */
function load_build_metadata($fullRepository) {
    $buildMetadataFile = "build-metadata.json";
    if (!file_exists("$fullRepository/$buildMetadataFile")) {
      pantheon_raise_dashboard_error("Could not find build metadata file, $buildMetadataFile\n");
    }
    $buildMetadataFileContents = file_get_contents("$fullRepository/$buildMetadataFile");
    $buildMetadata = json_decode($buildMetadataFileContents, true);
    if (empty($buildMetadata)) {
      pantheon_raise_dashboard_error("No data in build providers\n");
    }

    return $buildMetadata;
}

/**
 * Push the code back to the external provider.
 */
function push_back($fullRepository, $workDir, $upstreamRepoWithCredentials, $buildMetadata, $buildMetadataFile)
{

    print "::::::::::::::::: Build Metadata :::::::::::::::::\n";
    var_export($buildMetadata);
    print "\n\n";

    // The last commit made on the lean repo prior to creating the build artifacts
    $fromSha = $buildMetadata['sha'];

    // The name of the PR branch
    $branch = $buildMetadata['ref'];
    // When working from HEAD, use branch master.
    if ($branch == 'HEAD') {
        $branch = 'master';
    }

    // The commit to cherry-pick
    $commitToSubmit = exec("git -C $fullRepository rev-parse HEAD");

    // Seatbelts: is build metadatafile modified in the HEAD commit?
    $commitWithBuildMetadataFile = exec("git -C $fullRepository log -n 1 --pretty=format:%H -- $buildMetadataFile");
    if ($commitWithBuildMetadataFile == $commitToSubmit) {
        print "Ignoring commit because it contains build assets.\n";
        return;
    }

    // A working branch to make changes on
    $targetBranch = $branch;

    print "::::::::::::::::: Info :::::::::::::::::\n";
    print "We are going to check out $branch from {$buildMetadata['url']}, branch from $fromSha and cherry-pick $commitToSubmit onto it\n";

    $canonicalRepository = "$workDir/scratchRepository";
    $workbranch = "recommit-work";

    // Make a working clone of the Git branch. Clone just the branch
    // and commit we need.
    passthru("git clone $upstreamRepoWithCredentials --depth=1 --branch $branch --single-branch $canonicalRepository 2>&1");

    // If there have been extra commits, then unshallow the repository so that
    // we can make a branch off of the commit this multidev was built from.
    print "git rev-parse HEAD\n";
    $remoteHead = exec("git -C $canonicalRepository rev-parse HEAD");
    if ($remoteHead != $fromSha) {
        // TODO: If we had git 2.11.0, we could use --shallow-since with the date
        // from $buildMetadata['commit-date'] to get exactly the commits we need.
        // Until then, though, we will just `unshallow` the whole branch if there
        // is a conflicting commit.
        print "git fetch --unshallow\n";
        passthru("git -C $canonicalRepository fetch --unshallow 2>&1");
    }

    // Get metadata from the commit at the HEAD of the full repository
    $comment = escapeshellarg(exec("git -C $fullRepository log -1 --pretty=\"%s\""));
    $commit_date = escapeshellarg(exec("git -C $fullRepository log -1 --pretty=\"%at\""));
    $author_name = exec("git -C $fullRepository log -1 --pretty=\"%an\"");
    $author_email = exec("git -C $fullRepository log -1 --pretty=\"%ae\"");
    $author = escapeshellarg("$author_name <$author_email>");

    print "Comment is $comment and author is $author and date is $commit_date\n";
    // Make a safe space to store stuff
    $safe_space = "$workDir/safe-space";
    mkdir($safe_space);

    // If there are conflicting commits, or if this new commit is on the master
    // branch, then we will work from and push to a branch with a different name.
    // The user should then create a new PR, and use the Git Provider UI to resolve
    // any conflicts (or clone the branch locally to do the same thing).
    $createNewBranchReason = '';
    if ($branch == 'master') {
        $createNewBranchReason = "the $branch branch cannot be pushed to directly";
    }
    elseif ($remoteHead != $fromSha) {
        $createNewBranchReason = "new conflicting commits (e.g. $remoteHead) were added to the upstream repository";
    }
    if (!empty($createNewBranchReason)) {
        // Warn that a new branch is being created.
        $targetBranch = substr($commitToSubmit, 0, 5) . $branch;
        print "Creating a new branch, '$targetBranch', because $createNewBranchReason.\n";
        print "git checkout -B $targetBranch $fromSha\n";
        passthru("git -C $canonicalRepository checkout -B $targetBranch $fromSha 2>&1");
    }

    // Now for some git magic.
    //
    // - $fullRepository contains all of the files we want to commit (and more).
    // - $canonicalRepository is where we want to commit them.
    //
    // The .gitignore file in the canonical repository is correctly configured
    // to ignore the build results that we do not want from the full repository.
    //
    // To affect the change, we will:
    //
    // - Copy the .gitignore file from the canonical repository to the full repo.
    // - Operate on the CONTENTS of the full repository with the .git directory
    //   of the canonical repository via the --git-dir and -C flags.
    // - We restore the .gitignore at the end via `git checkout -- .gitignore`.

    $gitignore_contents = file_get_contents("$canonicalRepository/.gitignore");
    file_put_contents("$fullRepository/.gitignore", $gitignore_contents);

    print "::::::::::::::::: .gitignore :::::::::::::::::\n$gitignore_contents\n";

    // Add our files and make our commit
    print "git add .\n";
    passthru("git --git-dir=$canonicalRepository/.git -C $fullRepository add .", $status);
    if ($status != 0) {
        print "FAILED with $status\n";
    }
    // We don't want to commit the build-metadata to the canonical repository.
    passthru("git --git-dir=$canonicalRepository/.git -C $fullRepository reset HEAD $buildMetadataFile");

    $userName = exec("git --git-dir=$canonicalRepository/.git -C $fullRepository config user.name");
    if (empty($userName)) {
        passthru("git --git-dir=$canonicalRepository/.git -C $fullRepository config user.name 'Pantheon'");
    }
    $userEmail = exec("git --git-dir=$canonicalRepository/.git -C $fullRepository config user.email");
    if (empty($userEmail)) {
        passthru("git --git-dir=$canonicalRepository/.git -C $fullRepository config user.email 'bot@getpantheon.com'");
    }

    // TODO: Copy author, message and perhaps other attributes from the commit at the head of the full repository
    passthru("git --git-dir=$canonicalRepository/.git -C $fullRepository commit -q --no-edit --message=$comment --author=$author --date=$commit_date 2>&1", $commitStatus);

    // Get our .gitignore back
    passthru("git -C $fullRepository checkout -- .gitignore");

    // Make sure that HEAD changed after 'git apply'
    $appliedCommit = exec("git -C $canonicalRepository rev-parse HEAD");

    // Seatbelts: this generally should not happen. If it does, we will presume
    // it is not an error; this situation might arise if someone commits only
    // changes to build result files from dashboard.
    if ($appliedCommit == $remoteHead) {
        print "'git commit' did not add a commits. Status code: $commitStatus\n";
        return;
    }

    exec("git -C $canonicalRepository diff-tree --no-commit-id --name-only -r HEAD", $committedFiles);
    $committedFiles = implode("\n", $committedFiles);
    if (empty($committedFiles)) {
        print "Commit $appliedCommit does not contain any files.\n";
        return;
    }
    // Even more seatbelts: ensure that there is nothing in the
    // commit that should not have been modified. Our .gitignore
    // file should ensure this never happens. For now, only test
    // 'vendor'.
    if (preg_match('#^vendor/#', $committedFiles)) {
        print "Aborting: commit $appliedCommit contains changes to the 'vendor' directory.\n";
        return 1;
    }

    // If the apply worked, then push the commit back to the light repository.
    if (($commitStatus == 0) && ($appliedCommit != $remoteHead)) {

        // Push the new branch back to Pantheon
        passthru("git -C $canonicalRepository push $upstreamRepoWithCredentials $targetBranch 2>&1");

        // TODO: If a new branch was created, it would be cool to use the Git API
        // to create a new PR. If there is an existing PR (i.e. branch not master),
        // it would also be cool to cross-reference the new PR to the old PR. The trouble
        // here is converting the branch name to a PR number.
    }

    return $commitStatus;
}

// From https://stackoverflow.com/a/49198715.
if(!function_exists('http_build_url'))
{
    // Define constants
    define('HTTP_URL_REPLACE',          0x0001);    // Replace every part of the first URL when there's one of the second URL
    define('HTTP_URL_JOIN_PATH',        0x0002);    // Join relative paths
    define('HTTP_URL_JOIN_QUERY',       0x0004);    // Join query strings
    define('HTTP_URL_STRIP_USER',       0x0008);    // Strip any user authentication information
    define('HTTP_URL_STRIP_PASS',       0x0010);    // Strip any password authentication information
    define('HTTP_URL_STRIP_PORT',       0x0020);    // Strip explicit port numbers
    define('HTTP_URL_STRIP_PATH',       0x0040);    // Strip complete path
    define('HTTP_URL_STRIP_QUERY',      0x0080);    // Strip query string
    define('HTTP_URL_STRIP_FRAGMENT',   0x0100);    // Strip any fragments (#identifier)

    // Combination constants
    define('HTTP_URL_STRIP_AUTH',       HTTP_URL_STRIP_USER | HTTP_URL_STRIP_PASS);
    define('HTTP_URL_STRIP_ALL',        HTTP_URL_STRIP_AUTH | HTTP_URL_STRIP_PORT | HTTP_URL_STRIP_QUERY | HTTP_URL_STRIP_FRAGMENT);

    /**
     * HTTP Build URL
     * Combines arrays in the form of parse_url() into a new string based on specific options
     * @name http_build_url
     * @param string|array $url     The existing URL as a string or result from parse_url
     * @param string|array $parts   Same as $url
     * @param int $flags            URLs are combined based on these
     * @param array &$new_url       If set, filled with array version of new url
     * @return string
     */
    function http_build_url(/*string|array*/ $url, /*string|array*/ $parts = array(), /*int*/ $flags = HTTP_URL_REPLACE, /*array*/ &$new_url = false)
    {
        // If the $url is a string
        if(is_string($url))
        {
            $url = parse_url($url);
        }

        // If the $parts is a string
        if(is_string($parts))
        {
            $parts  = parse_url($parts);
        }

        // Scheme and Host are always replaced
        if(isset($parts['scheme'])) $url['scheme']  = $parts['scheme'];
        if(isset($parts['host']))   $url['host']    = $parts['host'];

        // (If applicable) Replace the original URL with it's new parts
        if(HTTP_URL_REPLACE & $flags)
        {
            // Go through each possible key
            foreach(array('user','pass','port','path','query','fragment') as $key)
            {
                // If it's set in $parts, replace it in $url
                if(isset($parts[$key])) $url[$key]  = $parts[$key];
            }
        }
        else
        {
            // Join the original URL path with the new path
            if(isset($parts['path']) && (HTTP_URL_JOIN_PATH & $flags))
            {
                if(isset($url['path']) && $url['path'] != '')
                {
                    // If the URL doesn't start with a slash, we need to merge
                    if($url['path'][0] != '/')
                    {
                        // If the path ends with a slash, store as is
                        if('/' == $parts['path'][strlen($parts['path'])-1])
                        {
                            $sBasePath  = $parts['path'];
                        }
                        // Else trim off the file
                        else
                        {
                            // Get just the base directory
                            $sBasePath  = dirname($parts['path']);
                        }

                        // If it's empty
                        if('' == $sBasePath)    $sBasePath  = '/';

                        // Add the two together
                        $url['path']    = $sBasePath . $url['path'];

                        // Free memory
                        unset($sBasePath);
                    }

                    if(false !== strpos($url['path'], './'))
                    {
                        // Remove any '../' and their directories
                        while(preg_match('/\w+\/\.\.\//', $url['path'])){
                            $url['path']    = preg_replace('/\w+\/\.\.\//', '', $url['path']);
                        }

                        // Remove any './'
                        $url['path']    = str_replace('./', '', $url['path']);
                    }
                }
                else
                {
                    $url['path']    = $parts['path'];
                }
            }

            // Join the original query string with the new query string
            if(isset($parts['query']) && (HTTP_URL_JOIN_QUERY & $flags))
            {
                if (isset($url['query']))   $url['query']   .= '&' . $parts['query'];
                else                        $url['query']   = $parts['query'];
            }
        }

        // Strips all the applicable sections of the URL
        if(HTTP_URL_STRIP_USER & $flags)        unset($url['user']);
        if(HTTP_URL_STRIP_PASS & $flags)        unset($url['pass']);
        if(HTTP_URL_STRIP_PORT & $flags)        unset($url['port']);
        if(HTTP_URL_STRIP_PATH & $flags)        unset($url['path']);
        if(HTTP_URL_STRIP_QUERY & $flags)       unset($url['query']);
        if(HTTP_URL_STRIP_FRAGMENT & $flags)    unset($url['fragment']);

        // Store the new associative array in $new_url
        $new_url    = $url;

        // Combine the new elements into a string and return it
        return
            ((isset($url['scheme'])) ? $url['scheme'] . '://' : '')
            .((isset($url['user'])) ? $url['user'] . ((isset($url['pass'])) ? ':' . $url['pass'] : '') .'@' : '')
            .((isset($url['host'])) ? $url['host'] : '')
            .((isset($url['port'])) ? ':' . $url['port'] : '')
            .((isset($url['path'])) ? $url['path'] : '')
            .((isset($url['query'])) ? '?' . $url['query'] : '')
            .((isset($url['fragment'])) ? '#' . $url['fragment'] : '')
            ;
    }
}
