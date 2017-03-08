<?php

// Do nothing for test or live environments.
if (in_array($_ENV['PANTHEON_ENVIRONMENT'], ['test', 'live'])) {
  return;
}

include __DIR__ . '/lean-repo-utils.php';

/**
 * This script will separates changes from the most recent commit
 * and pushes any that affect the canonical sources back to the
 * master repository.
 */
$bindingDir = $_SERVER['HOME'];
$repositoryRoot = "$bindingDir/code";
// $docRoot = "$repositoryRoot/" . $_SERVER['DOCROOT'];

print "Enter push-back-to-github. Repository root is $repositoryRoot.\n";

$privateFiles = "$bindingDir/files/private";
$gitHubSecretsFile = "$privateFiles/github-secrets.json";
$gitHubSecrets = load_github_secrets($gitHubSecretsFile);
$github_token = $gitHubSecrets['token'];

$workDir = "$bindingDir/tmp";

// Temporary:
passthru("rm -rf $workDir");

$result = push_back_to_github($repositoryRoot, $workDir, $github_token);

// Throw out the working repository.
passthru("rm -rf $workDir");

// Post error to dashboard and exit if the merge fails.
if ($applyStatus != 0) {
  $message = "git apply failed with exit code $applyStatus.\n\n" . implode("\n", $output);
  pantheon_raise_dashboard_error($message, true);
}

function push_back_to_github($repositoryRoot, $workDir, $github_token)
{
  $buildMetadataFile = "$repositoryRoot/build-metadata.json";
  if (!file_exists($buildMetadataFile)) {
    print "Could not find build metadata file, $buildMetadataFile\n";
    return;
  }
  $buildMetadataFileContents = file_get_contents($buildMetadataFile);
  $buildMetadata = json_decode($buildMetadataFileContents, true);
  if (empty($buildMetadata)) {
    print "No data in build metadata\n";
    return;
  }

  print "::::::::::::::::: Build Metadata :::::::::::::::::\n";
  var_export($buildMetadata);
  print "\n\n";

  // The remote repo to push to
  $upstreamRepo = $buildMetadata['url'];
  $upstreamRepoWithCredentials = $upstreamRepo;
  if (!empty($github_token)) {
    $upstreamRepoWithCredentials = str_replace('git@github.com:', 'https://github.com/', $upstreamRepoWithCredentials);
    $upstreamRepoWithCredentials = str_replace('https://', "https://$github_token:x-oauth-basic@", $upstreamRepoWithCredentials);
  }

  // The last commit made on the lean repo prior to creating the build artifacts
  $fromSha = $buildMetadata['sha'];

  // The name of the PR branch
  $branch = $buildMetadata['ref'];

  // The commit to cherry-pick
  $commitToSubmit = exec('git rev-parse HEAD');

  // A working branch to make changes on
  $targetBranch = $branch;

  print "::::::::::::::::: Info :::::::::::::::::\n";
  print "We are going to check out $branch from {$buildMetadata['url']}, branch from $fromSha and cherry-pick $commitToSubmit onto it\n";

  $canonicalRepository = "$workDir/scratchRepository";
  $workbranch = "recommit-work";

  // Make a working clone of the GitHub branch. Clone just the branch
  // and commit we need.
  print "git clone $upstreamRepo --depth=1 --branch $branch --single-branch\n";
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

  // If there are conflicting commits, or if this new commit is on the master
  // branch, then we will work from and push to a branch with a different name.
  // The user should then create a new PR on GitHub, and use the GitHub UI
  // to resolve any conflicts (or clone the branch locally to do the same thing).
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

  // A bit of cleverness. (That means that it is dangerous to modify the code
  // that follows. Understand the implications first.)  ;)
  //
  // We cannot change branches on the source repository -- at least not permanently,
  // and never in a way that would cause any changes to any part of the filesystem
  // being served by the web server.  Bearing this in mind, we are going to very
  // carefully get rid of all of the files in the applied commit.
  //
  //  - First we remove all files with `git rm`, using `--cached` so they are not deleted.
  //  - Next, we replace the .gitignore file from the canonical repository.
  //  - Using the canonical .gitignore, we re-add the non-ignored files with `git add`.
  //  - Once the .gitignore file has done its job, we reset it, so it will not be part of the canonical commit.
  //  - The HEAD commit is then modified with `git commit --amend` in non-interactive mode.
  //
  // Note that we cheated a bit in the middle -- the .gitignore file is momentarily
  // modified as we run through these steps. The contents of this file are
  // immaterial to the web server, though, so this indiscression is harmless.
  print "git checkout -B $workbranch\n";
  passthru("git -C $repositoryRoot checkout -B $workbranch", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }
  print "Copy canonical .gitignore from $canonicalRepository to $repositoryRoot\n";
  $canonical_gitignore = file_get_contents("$canonicalRepository/.gitignore");
  file_put_contents("$repositoryRoot/.gitignore", $canonical_gitignore);
  print "git rm --cached -r .\n";
  passthru("git -C $repositoryRoot rm --cached -r -q .", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }
  print "git add .\n";
  passthru("git -C $repositoryRoot add .", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }

  print "reset .gitignore\n";
  passthru("git -C $repositoryRoot reset HEAD .gitignore");
  passthru("git -C $repositoryRoot checkout -- .gitignore");
  print "git commit --amend\n";
  passthru("git -C $repositoryRoot commit -q --amend --no-edit", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }

  // Get the sha of the modified (canonical-files-only) commit
  $canonicalCommitToSubmit = exec('git rev-parse HEAD');

  // Use `git format-patch | git am` to do the equivalent of a cherry-pick
  // between the two repositories. This should not fail, as we are applying
  // our changes on top of the commit this branch was built from.
  print "git -C $repositoryRoot format-patch --stdout {$canonicalCommitToSubmit}~ | git -C $canonicalRepository am\n";
  exec("git -C $repositoryRoot format-patch --stdout {$canonicalCommitToSubmit}~ | git -C $canonicalRepository am 2>&1", $output, $applyStatus);

  // Bring our primary repository back to the branch it started on.
  // The first thing we need to do is re-commit the files we removed from
  // the commit, because otherwise, git isn't going to want to overwrite
  // them now that they are "untracked" files.
  print "git add .\n";
  passthru("git -C $repositoryRoot add .", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }
  print "git commit --amend\n";
  passthru("git -C $repositoryRoot commit -q --amend --no-edit", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }

  print "git checkout -\n";
  passthru("git -C $repositoryRoot checkout -", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }
  print "git branch -D $workbranch\n";
  passthru("git -C $repositoryRoot branch -D $workbranch", $status);
  if ($status != 0) {
    print "FAILED with $status\n";
  }

  // Make sure that HEAD changed after 'git apply'
  $appliedCommit = exec("git -C $canonicalRepository rev-parse HEAD");

  // Seatbelts: we expect this should only happen if $applyStatus != 0
  if ($appliedCommit == $remoteHead) {
    print "'git apply' did not add any commits. Status code: $applyStatus\n";
    print "Output:\n";
    print implode("\n", $output) . "\n";
  }

  // If the apply worked, then push the commit back to the light repository.
  if (($applyStatus == 0) && ($appliedCommit != $remoteHead)) {

    // Push the new branch back to Pantheon
    print "git push $upstreamRepo $targetBranch\n";
    passthru("git -C $canonicalRepository push $upstreamRepoWithCredentials $targetBranch 2>&1");

    // TODO: If a new branch was created, it would be cool to use the GitHub API
    // to create a new PR. If there is an existing PR (i.e. branch not master),
    // it would also be cool to cross-reference the new PR to the old PR. The trouble
    // here is converting the branch name to a PR number.
  }

  return $applyStatus;
}
