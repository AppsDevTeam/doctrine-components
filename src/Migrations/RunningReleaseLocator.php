<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Migrations;

/**
 * Maps a path inside a release of the Deployer layout (`<deploy path>/releases/<release>/...`) to the same path
 * in the running release (`<deploy path>/current/...`).
 */
final class RunningReleaseLocator
{
	private const RELEASES_DIR = 'releases';
	private const CURRENT_LINK = 'current';

	/**
	 * @return string|null the counterpart of $path in the running release, null outside of the layout (local
	 *                     development, a path outside of the releases) or without a running release (first deploy)
	 */
	public function locate(string $path): ?string
	{
		$path = realpath($path);
		if ($path === false) {
			return null;
		}

		// The innermost `releases/<release>` ancestor with a `current` next to it: the deploy path itself may
		// contain a directory named `releases`, and so may the release
		$release = $path;
		while (($releases = dirname($release)) !== $release) {
			if (basename($releases) === self::RELEASES_DIR) {
				$running = dirname($releases) . '/' . self::CURRENT_LINK;
				if (is_dir($running)) {
					return $running . substr($path, strlen($release));
				}
			}
			$release = $releases;
		}

		return null;
	}
}
