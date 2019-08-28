# Quicksilver Pushback

![Quicksilver Pushback v2.x](https://img.shields.io/badge/Quicksilver_Pushback-v2.x-green.svg)  [![Terminus v2.x Compatible](https://img.shields.io/badge/terminus-v2.x-green.svg)](https://github.com/pantheon-systems/terminus-build-tools-plugin/tree/master)

This Quicksilver project is used in conjunction with the various suite of [Terminus Build Tools](https://github.com/pantheon-systems/terminus-build-tools-plugin)-based example repositories to push any commits made on the Pantheon dashboard back to the original Git repository for the site. This allows developers (or other users) to work on the Pantheon dashboard in SFTP mode and commit their code, through Pantheon, back to the canonical upstream repository via a PR. This is especially useful in scenarios where you want to export configuration (Drupal, WP-CFM).

## Requirements

It is recommended that you use one of the example PR workflow projects ([Drupal 8](https://www.github.com/pantheon-systems/example-drops-8-composer), [WordPress](https://www.github.com/pantheon-systems/example-wordpress-composer)) as a starting point for leveraging this tool.

- Support is only provided for the 2.x version of Terminus Build Tools.
- The project currently supports Github, GitLab (including self-hosted), and BitBucket providers.
- This Quicksilver script only works with Pantheon sites that have been configured to use a Git PR workflow.

### Installation

This project is designed to be included from a site's composer.json file, and placed in its appropriate installation directory by [Composer Installers](https://github.com/composer/installers).

In order for this to work, you should have the following in your composer.json file:

```json
{
  "require": {
    "composer/installers": "^1.0.20"
  },
  "extra": {
    "installer-paths": {
      "web/private/scripts/quicksilver": ["type:quicksilver-script"]
    }
  }
}
```

The project can be included by using the command:

`composer require pantheon-systems/quicksilver-pushback:^2`

If you are using one of the example PR workflow projects ([Drupal 8](https://www.github.com/pantheon-systems/example-drops-8-composer), [WordPress](https://www.github.com/pantheon-systems/example-wordpress-composer)) as a starting point for your site, these entries should already be present in your composer.json.

### Example `pantheon.yml`

Here's an example of what your `pantheon.yml` would look like if this were the only Quicksilver operation you wanted to use.

```yaml
api_version: 1

workflows:
  sync_code:
    after:
      - type: webphp
        description: Push changes back to Git repository if needed
        script: private/scripts/quicksilver/quicksilver-pushback/push-back.php
```
If you are using one of the example PR workflow projects as a starting point for your site, this entry should already be present in your pantheon.yml.
