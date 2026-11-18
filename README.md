# CalenAI

CalenAI is a Nextcloud app for creating and editing calendar events in natural language. It uses an AI provider configured by your Nextcloud administrator. The app interface is currently in Japanese.

Open CalenAI from the app menu or click **AI** in Nextcloud Calendar. Try these two messages in sequence:

| Message | What happens |
| --- | --- |
| Meet with Tanaka next Tuesday at 3 PM for one hour. | CalenAI saves the event immediately. |
| Actually, move it to 4 PM. | CalenAI updates the event you just created. You can undo the edit. |

You can edit or delete recent events created through CalenAI. Deletion requires confirmation. If you use several calendars, you can describe what belongs in each one and let CalenAI choose where to save new events.

## Install

Choose a formal release whose archived `calenai/appinfo/info.xml` includes your Nextcloud major between `min-version` and `max-version`. The commands below use v1.2.7 for Nextcloud 35 as an example. Installation requires administrator access. To create an event, the user needs a writable calendar.

1. Download the app archive and its checksum from the same [GitHub release](https://github.com/ajctrl/CalenAI/releases/tag/v1.2.7). For example, run the following in an empty working directory.

   ```sh
   release=https://github.com/ajctrl/CalenAI/releases/download/v1.2.7
   curl -fLO "$release/calenai-1.2.7.tar.gz" &&
     curl -fLO "$release/SHA256SUMS" &&
     sha256sum -c SHA256SUMS
   ```

2. For a first install, extract the archive into your Nextcloud custom apps directory. Adjust the path to match your installation.

   ```sh
   tar -xzf calenai-1.2.7.tar.gz -C /var/www/html/custom_apps
   ```

   The resulting path should be `custom_apps/calenai/appinfo/info.xml`. Ensure the Nextcloud web server user can read the files.
3. From the Nextcloud installation directory, enable the app as the web server user. On a typical Debian installation, run `sudo -u www-data php occ app:enable calenai`.

For Docker deployments, use the same versioned archive and checksum in your deployment repository. Place the extracted app in the persistent custom apps directory and run the enable command inside the Nextcloud container.

## Configure the AI provider

In Nextcloud administration settings, open the AI section and find **CalenAI**. Choose an OpenAI-compatible Chat Completions endpoint, Anthropic, or Azure OpenAI. Set the endpoint, model, and credentials for your provider, then save the settings and test the connection.

## Data sent to the AI provider

CalenAI sends the input text, current time, and time zone to the configured provider. By default, it also sends titles and times for up to 20 recent events created through CalenAI. Administrators can limit that context to edit and delete requests. When automatic calendar selection is enabled, the provider also receives calendar names and the routing descriptions supplied by the user.

## Update

Choose a compatible version from [GitHub Releases](https://github.com/ajctrl/CalenAI/releases) and verify its archive against the checksum from that release. During your maintenance window, replace the installed `calenai` directory with the newly extracted directory and run `php occ upgrade` as the web server user from the Nextcloud installation directory.

## Development

Install development dependencies with `composer install`. Run `composer lint` to check PHP syntax and `composer test` to run the unit tests.

## Nextcloud compatibility

Every day at 04:17 UTC, on manual dispatch, and on PRs and pushes to `main`, CI reads the [official maintenance schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule#currently-supported-versions). It tests the exact current stable release of each supported major and every major between those releases and the range in `appinfo/info.xml`. For an intervening EOL major it uses the final stable patch. A missing release or failed test blocks the compatibility proposal.

Each job installs the archive into a fresh Nextcloud instance, enables CalenAI, and runs PHPUnit and the calendar E2E suite. For a major outside the declared range, only the staged test copy receives the proposed metadata. After all jobs pass, the scheduled or manually dispatched workflow opens or updates a PR with the verified range and a patch version bump. This requires the repository Actions setting **Allow GitHub Actions to create and approve pull requests**. Fix any failing major and rerun before merging a range change.

## Releasing a version

Set the new version in `appinfo/info.xml` in the release PR. After the change reaches `main` and the PHP and Nextcloud tests pass, GitHub Actions publishes a GitHub Release tagged `v<version>` with the app archive and `SHA256SUMS`. Before publication, CI opens the draft archive and checks its app ID, version and tested range. Merges that keep an already published app version check the existing archive and skip publication. Published assets are immutable in this workflow.

CalenAI is licensed under AGPL-3.0-or-later. Report bugs in [GitHub Issues](https://github.com/ajctrl/CalenAI/issues).
