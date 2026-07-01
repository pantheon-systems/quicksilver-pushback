# quicksilver-pushback

quicksilver-pushback is a Quicksilver script for Pantheon that automatically pushes commits made on the Pantheon dashboard back to the canonical upstream Git repository. It is used in conjunction with the [Terminus Build Tools](https://github.com/pantheon-systems/terminus-build-tools-plugin) suite of PR-workflow example repositories.

## Role at Pantheon

When developers work in SFTP mode on the Pantheon dashboard and commit code, those commits land in Pantheon's internal Git repository rather than the upstream GitHub, GitLab, or Bitbucket repository. quicksilver-pushback bridges this gap by detecting such commits and opening a pull request against the upstream repository. This makes it possible to export configuration (for example, Drupal CMI or WP-CFM), review changes in a PR workflow, and keep the canonical repository in sync with dashboard activity.

## Supported Providers

The script supports GitHub, GitLab (including self-hosted instances), and Bitbucket. It is compatible with Terminus Build Tools v2.x and is intended for Pantheon sites configured with a Git PR workflow.

## Installation

The package is distributed via Composer and is designed to be installed by [Composer Installers](https://github.com/composer/installers) into the site's `web/private/scripts/quicksilver` directory. It is triggered through Pantheon's `pantheon.yml` `sync_code` workflow hook.

## Further Reading

- [Terminus Build Tools plugin](https://github.com/pantheon-systems/terminus-build-tools-plugin)
- [Example Drupal 8 PR workflow](https://github.com/pantheon-systems/example-drops-8-composer)
- [Example WordPress PR workflow](https://github.com/pantheon-systems/example-wordpress-composer)
