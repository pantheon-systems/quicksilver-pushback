<?php

namespace Pantheon\QuicksilverPushback;

class Pushback {


    /**
     * Pushback.
     */
    public function pushback(string $secretsMechanism)
    {

        // Do nothing if not on Pantheon or if on the test/live environments.
        if (!isset($_ENV['PANTHEON_ENVIRONMENT']) || in_array($_ENV['PANTHEON_ENVIRONMENT'], ['test', 'live']) ) {
            return;
        }

        /**
         * This script will separates changes from the most recent commit
         * and pushes any that affect the canonical sources back to the
         * master repository.
         */
        $bindingDir = $this->getHomeDir();
        $fullRepository = realpath("$bindingDir/code");

        print "Enter push-back. Repository root is $fullRepository.\n";

        $secrets = $this->getSecretsObject('legacy');
        $git_token = $secrets->getSecret('token');

        if (empty($git_token)) {
            $message = "Unable to load Git token from secrets file";
            $this->raiseDashboardError($message);
        }

        // Create empty temp folder.
        $workDir = sys_get_temp_dir() . "/pushback-workdir";
        passthru("rm -rf $workDir");
        mkdir($workDir);

        $buildProviders = $this->loadBuildProviders($fullRepository);
        $buildMetadata = $this->loadBuildMetadata($fullRepository);

        // The remote repo to push to
        $upstreamRepo = $buildMetadata['url'];
        $upstreamRepoWithCredentials = $upstreamRepo;

        if (!isset($buildProviders['git'])) {
            throw new \Exception('No git provider found.');
        }
        switch ($buildProviders['git']) {
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
                    $this->raiseDashboardError("Error parsing GitLab URL from Build Metadata.");
                }
                break;

            case 'bitbucket':
                $upstreamRepoWithCredentials = str_replace('git@bitbucket.org:', 'https://bitbucket.org/', $upstreamRepoWithCredentials);
                if ((strpos($upstreamRepoWithCredentials, 'https://') !== false) || (strpos($upstreamRepoWithCredentials, 'http://') !== false)) {
                    $parsed_url = parse_url($upstreamRepoWithCredentials);
                    $parsed_url['user'] = $gitSecrets['user'];
                    $parsed_url['pass'] = $gitSecrets['pass'];
                    $upstreamRepoWithCredentials = http_build_url($parsed_url);
                }
                else {
                    $this->raiseDashboardError("Error parsing Bitbucket URL from Build Metadata.");
                }
                break;
        }

        $status = $this->doPushback($fullRepository, $workDir, $upstreamRepoWithCredentials, $buildMetadata, "build-metadata.json");

        // Throw out the working repository.
        passthru("rm -rf $workDir");

        // Post error to dashboard and exit if the merge fails.
        if ($status != 0) {
            $message = "Commit back to canonical repository failed with exit code $status.";
            $this->raiseDashboardError($message, true);
        }

    }

    /**
     * Get secrets object.
     */
    private function getSecretsObject(string $mechanism)
    {
        if ($mechanism == 'legacy') {
            return new SecretsLegacy();
        }
        throw new \Exception('Invalid secrets mechanism: ' . $mechanism);
    }

    /**
     * Get home directory.
     */
    protected function getHomeDir(): string {
        return $_SERVER['HOME'];
    }

    /**
     * Function to report an error on the Pantheon dashboard
     *
     * Not supported; may stop working at any point in the future.
     */
    private function raiseDashboardError($reason = 'Uknown failure', $extended = FALSE) {
        // Make creative use of the error reporting API
        $data = array(
            'file'=>'Quicksilver Pushback',
            'line'=>'Error',
            'type'=>'error',
            'message' => $reason
        );
        $params = http_build_query($data);
        $result = pantheon_curl('https://api.live.getpantheon.com/sites/self/environments/self/events?'. $params, NULL, 8443, 'POST');
        error_log("Quicksilver Pushback Integration failed - $reason");

        // Dump additional debug info into the error log
        if ($extended) {
            error_log(print_r($extended, 1));
        }

        // @todo Exception?
        die("Quicksilver Pushback Integration failed - $reason");
    }

    /**
     * Read the Build Providers file
     */
    private function loadBuildProviders($fullRepository) {
        $buildProvidersFile = "build-providers.json";
        if (!file_exists("$fullRepository/$buildProvidersFile")) {
            $this->raiseDashboardError("Could not find build metadata file, $buildProvidersFile\n");
        }
        $buildProvidersFileContents = file_get_contents("$fullRepository/$buildProvidersFile");
        $buildProviders = json_decode($buildProvidersFileContents, true);
        if (empty($buildProviders)) {
            $this->raiseDashboardError("No data in build providers\n");
        }

        return $buildProviders;
    }

    /**
     * Read the Build Metadata file
     */
    private function loadBuildMetadata($fullRepository) {
        $buildMetadataFile = "build-metadata.json";
        if (!file_exists("$fullRepository/$buildMetadataFile")) {
            $this->raiseDashboardError("Could not find build metadata file, $buildMetadataFile\n");
        }
        $buildMetadataFileContents = file_get_contents("$fullRepository/$buildMetadataFile");
        $buildMetadata = json_decode($buildMetadataFileContents, true);
        if (empty($buildMetadata)) {
            $this->raiseDashboardError("No data in build providers\n");
        }

        return $buildMetadata;
    }

    /**
     * Do the actual push back to the source repo.
     */
    private function doPushback($fullRepository, $workDir, $upstreamRepoWithCredentials, $buildMetadata, $buildMetadataFile)
    {
        print "::::::::::::::::: Build Metadata :::::::::::::::::\n";
        var_export($buildMetadata);
        print "\n\n";

        $fromSha = $buildMetadata['sha'];
    
        // The name of the PR branch
        $branch = $buildMetadata['ref'];
        // When working from HEAD, use branch master.
        if ($branch == 'HEAD') {
            $branch = 'master';
        }
    
        // The commit to cherry-pick
        $commitToSubmit = exec("git -C $fullRepository rev-parse HEAD");

        exec("git -C $fullRepository log $fromSha --pretty=format:%H", $output, $status);
        if ($status === 0) {
            // We will cherry-pick everything from $fromSha to $commitToSubmit excluding $commitWithBuildMetadataFile.
            exec("git -C $fullRepository rev-list --ancestry-path $fromSha..$commitToSubmit", $commits);
        } else {
            // fromSha does not exist here, use all of the available commits.
            exec("git -C $fullRepository log --pretty=format:%H", $commits);
        }
        $commits = array_reverse($commits);

        print("Commits to cherry-pick: " . print_r($commits, true) . "\n");

        $commitWithBuildMetadataFile = exec("git -C $fullRepository log -n 1 --pretty=format:%H -- $buildMetadataFile");
        print("Commit with build metadata file: $commitWithBuildMetadataFile\n");
        // A working branch to make changes on    
        print "::::::::::::::::: Info :::::::::::::::::\n";
        print "We are going to check out $branch from {$buildMetadata['url']}, branch from $fromSha and cherry-pick up to $commitToSubmit onto it\n";
    
        $canonicalRepository = "$workDir/scratchRepository";
        $workbranch = "recommit-work";
    
        // Make a working clone of the Git branch. Clone just the branch
        // and commit we need.
        passthru("git clone $upstreamRepoWithCredentials --branch $branch $canonicalRepository 2>&1");

        // If there have been extra commits, then unshallow the repository so that
        // we can make a branch off of the commit this multidev was built from.
        print "git rev-parse HEAD\n";
        $remoteHead = exec("git -C $canonicalRepository rev-parse HEAD");

        // A working branch to make changes on
        $targetBranch = $branch;

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
        passthru("git -C $canonicalRepository remote add pantheon $fullRepository 2>&1");
        passthru("git -C $canonicalRepository fetch pantheon 2>&1");
        if (!empty($createNewBranchReason)) {
            // Warn that a new branch is being created.
            $targetBranch = substr($commitToSubmit, 0, 5) . $branch;
            print "Creating a new branch, '$targetBranch', because $createNewBranchReason.\n";
        }
        $localBranchName = "canon-$targetBranch";
        passthru("git -C $canonicalRepository checkout -b $localBranchName 2>&1");

        foreach ($commits as $commit) {
            if ($commit == $commitWithBuildMetadataFile) {
                print("Ignoring commit $commit because it contains the build metadata file.\n");
                continue;
            }
            print("Cherry-picking commit $commit.\n");
            $cherryPickResult = exec("git -C $canonicalRepository cherry-pick -n $commit");
            if ($cherryPickResult != '') {
                $this->raiseDashboardError("Cherry-pick failed with message: $cherryPickResult");
            }

            // Get metadata from the commit at the commit of the full repository
            $comment = escapeshellarg(exec("git -C $fullRepository log -1 $commit --pretty=\"%s\""));
            $commit_date = escapeshellarg(exec("git -C $fullRepository log -1 $commit --pretty=\"%at\""));
            $author_name = escapeshellarg(exec("git -C $fullRepository log -1 $commit --pretty=\"%an\""));
            $author_email = escapeshellarg(exec("git -C $fullRepository log -1 $commit --pretty=\"%ae\""));
            passthru("git -C $canonicalRepository config user.email $author_email 2>&1");
            passthru("git -C $canonicalRepository config user.name $author_name 2>&1");
        
            print "Comment is $comment and date is $commit_date\n";
            passthru("git -C $canonicalRepository status");
            passthru("git -C $canonicalRepository commit --message=$comment --date=$commit_date 2>&1", $commitStatus);
            if ($commitStatus != 0) {
                break;
            }
        }
        // If the apply worked, then push the commit back to the light repository.
        if ($commitStatus == 0) {
    
            // Push the new branch back to Pantheon
            passthru("git -C $canonicalRepository push origin $localBranchName:$targetBranch 2>&1");
    
            // TODO: If a new branch was created, it would be cool to use the Git API
            // to create a new PR. If there is an existing PR (i.e. branch not master),
            // it would also be cool to cross-reference the new PR to the old PR. The trouble
            // here is converting the branch name to a PR number.
        }
    
        return $commitStatus;

    }

}