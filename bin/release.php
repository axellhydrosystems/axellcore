<?php
/**
 * WP-CLI release command for axellcore.
 *
 * Usage:
 *   wp --require=bin/release.php axl release patch
 *   wp --require=bin/release.php axl release minor
 *   wp --require=bin/release.php axl release major
 *   wp --require=bin/release.php axl release 1.2.3
 *   wp --require=bin/release.php axl release patch --no-commit
 *   wp --require=bin/release.php axl release patch --no-tag
 *   wp --require=bin/release.php axl release patch --no-push
 *
 *   wp --require=bin/release.php axl language
 *   LANG_SRC=/path/to/languages/plugins wp --require=bin/release.php axl language
 *
 * Must be run from the plugin directory.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Run a shell command via proc_open. Prints output and dies on failure.
 *
 * @param string      $cmd    Shell command.
 * @param string|null $cwd    Working directory; null inherits current.
 * @param bool        $silent Suppress stdout printing.
 * @return string Captured stdout.
 */
function axellcore_run( string $cmd, ?string $cwd = null, bool $silent = false ): string {
	$descriptors = [
		0 => [ 'pipe', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];

	$process = proc_open( $cmd, $descriptors, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		WP_CLI::error( "Failed to start: $cmd" );
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );

	if ( ! $silent && $stdout !== '' ) {
		WP_CLI::line( rtrim( $stdout ) );
	}

	if ( $exit !== 0 ) {
		WP_CLI::error( $stderr !== '' ? rtrim( $stderr ) : "Command failed (exit $exit): $cmd" );
	}

	return $stdout;
}

/**
 * Like axellcore_run() but returns [ exit_code, stdout ] without dying.
 *
 * @param string      $cmd Shell command.
 * @param string|null $cwd Working directory.
 * @return array{ 0: int, 1: string }
 */
function axellcore_try_run( string $cmd, ?string $cwd = null ): array {
	$descriptors = [
		0 => [ 'pipe', 'r' ],
		1 => [ 'pipe', 'w' ],
		2 => [ 'pipe', 'w' ],
	];

	$process = proc_open( $cmd, $descriptors, $pipes, $cwd );
	if ( ! is_resource( $process ) ) {
		return [ 1, '' ];
	}

	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return [ proc_close( $process ), $stdout ];
}

/**
 * Assert that an external command exists on PATH.
 *
 * @param string $cmd Command name.
 */
function axellcore_require_cmd( string $cmd ): void {
	[ $exit ] = axellcore_try_run( "command -v $cmd" );
	if ( $exit !== 0 ) {
		WP_CLI::error( "$cmd is required but not found on PATH." );
	}
}

/**
 * Resolve the plugin root directory (bin/../).
 *
 * @return string Absolute path, no trailing slash.
 */
function axellcore_plugin_dir(): string {
	return dirname( __FILE__, 2 );
}

/**
 * Read the current version from the plugin header.
 *
 * @param string $plugin_file Absolute path to axellcore.php.
 * @return string Version string e.g. "0.2.10".
 */
function axellcore_current_version( string $plugin_file ): string {
	$contents = file_get_contents( $plugin_file );
	if ( $contents === false ) {
		WP_CLI::error( "Cannot read $plugin_file" );
	}
	if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $contents, $m ) ) {
		WP_CLI::error( "Cannot parse version from $plugin_file" );
	}
	return trim( $m[1] );
}

/**
 * Bump a semver string.
 *
 * @param string $current Current version.
 * @param string $bump    "patch", "minor", "major", or an explicit semver.
 * @return string New version.
 */
function axellcore_bump_version( string $current, string $bump ): string {
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $current, $m ) ) {
		WP_CLI::error( "Cannot parse current version: $current" );
	}

	$maj = (int) $m[1];
	$min = (int) $m[2];
	$pat = (int) $m[3];

	switch ( $bump ) {
		case 'major':
			return ( $maj + 1 ) . '.0.0';
		case 'minor':
			return "$maj." . ( $min + 1 ) . '.0';
		case 'patch':
			return "$maj.$min." . ( $pat + 1 );
		default:
			if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $bump ) ) {
				WP_CLI::error( "Invalid version: $bump" );
			}
			return $bump;
	}
}

/**
 * Parse a .po file and return msgids that are untranslated or fuzzy.
 *
 * Handles both singular msgstr and plural msgstr[N] forms.
 * Skips the file header (msgid "") and well-known plugin metadata strings.
 *
 * @param string $po_file Absolute path to .po file.
 * @return list<string> Untranslated msgid values.
 */
function axellcore_po_missing( string $po_file ): array {
	$skip_comments = [
		'Plugin Name of the plugin',
		'Description of the plugin',
		'Author of the plugin',
		'Author URI of the plugin',
		'Plugin URI of the plugin',
	];

	$contents = file_get_contents( $po_file );
	if ( $contents === false ) {
		return [];
	}

	$blocks  = preg_split( '/\n{2,}/', trim( $contents ) ) ?: [];
	$missing = [];

	foreach ( $blocks as $block ) {
		$lines = explode( "\n", trim( $block ) );

		if ( in_array( 'msgid ""', $lines, true ) ) {
			continue;
		}

		$dot_comments = array_map(
			fn( string $l ) => trim( ltrim( $l, '#.' ) ),
			array_filter( $lines, fn( string $l ) => str_starts_with( $l, '#.' ) )
		);
		if ( array_intersect( array_values( $dot_comments ), $skip_comments ) ) {
			continue;
		}

		$is_fuzzy = in_array( '#, fuzzy', $lines, true );

		preg_match_all( '/^msgid\s+"(.*)"$/m', $block, $id_m );
		$msgid_val = implode( '', $id_m[1] );

		preg_match_all( '/^msgstr(?:\[\d+\])?\s+"(.*)"$/m', $block, $str_m );
		$msgstr_val = implode( '', $str_m[1] );

		if ( $msgid_val !== '' && ( $is_fuzzy || trim( $msgstr_val ) === '' ) ) {
			$missing[] = $msgid_val;
		}
	}

	return $missing;
}

// ── WP-CLI command class ──────────────────────────────────────────────────────

/**
 * Manages axellcore plugin releases and language packs.
 */
class Axellcore_CLI_Command extends WP_CLI_Command {

	/**
	 * Bump the plugin version, update POT, commit, tag and push.
	 *
	 * ## OPTIONS
	 *
	 * <bump>
	 * : Version bump: patch, minor, major, or explicit semver (e.g. 1.2.3).
	 *
	 * [--no-commit]
	 * : Stop after bumping files; skip commit, tag and push.
	 *
	 * [--no-tag]
	 * : Commit but skip tag and push.
	 *
	 * [--no-push]
	 * : Commit and tag but skip push.
	 *
	 * ## EXAMPLES
	 *
	 *   wp --require=bin/release.php axl release patch
	 *   wp --require=bin/release.php axl release 1.3.0 --no-push
	 *
	 * @subcommand release
	 * @when before_wp_load
	 */
	public function release( array $args, array $assoc_args ): void {
		axellcore_require_cmd( 'git' );
		axellcore_require_cmd( 'node' );
		axellcore_require_cmd( 'wp' );

		// Dependency rules: --no-commit => --no-tag => --no-push.
		$no_commit = (bool) ( $assoc_args['no-commit'] ?? false );
		$no_tag    = $no_commit || (bool) ( $assoc_args['no-tag'] ?? false );
		$no_push   = $no_tag    || (bool) ( $assoc_args['no-push'] ?? false );

		$plugin_dir  = axellcore_plugin_dir();
		$plugin_file = $plugin_dir . '/axellcore.php';
		$readme_txt  = $plugin_dir . '/readme.txt';
		$pot_file    = $plugin_dir . '/languages/axellcore.pot';

		$current     = axellcore_current_version( $plugin_file );
		$new_version = axellcore_bump_version( $current, $args[0] );

		WP_CLI::line( '' );
		WP_CLI::line( "axellcore $current \u2192 $new_version" );
		WP_CLI::line( '' );

		axellcore_run( 'git fetch origin --quiet', $plugin_dir, true );

		[ $tag_exists ] = axellcore_try_run(
			"git ls-remote --exit-code origin refs/tags/$new_version",
			$plugin_dir
		);
		if ( $tag_exists === 0 ) {
			WP_CLI::error( "Tag $new_version already exists on remote." );
		}

		// ── Bump version ──────────────────────────────────────────────────────

		WP_CLI::log( "  \u2192 bumping version in axellcore.php and readme.txt" );

		$plugin_contents = file_get_contents( $plugin_file );
		$plugin_contents = preg_replace( '/^( \* Version:).*$/m', "$1           $new_version", $plugin_contents );
		$plugin_contents = preg_replace( "/define\( 'AXELLCORE_VERSION', '[^']*' \)/", "define( 'AXELLCORE_VERSION', '$new_version' )", $plugin_contents );
		file_put_contents( $plugin_file, $plugin_contents );

		$readme_contents = file_get_contents( $readme_txt );
		$readme_contents = preg_replace( '/^Stable tag:.*/m', "Stable tag: $new_version", $readme_contents );

		if ( ! str_contains( $readme_contents, "= $new_version =" ) ) {
			$today           = gmdate( 'Y-m-d' );
			$readme_contents = str_replace(
				'== Changelog ==',
				"== Changelog ==\n\n= $new_version =\n* Release $new_version ($today).",
				$readme_contents
			);
		}
		file_put_contents( $readme_txt, $readme_contents );

		// ── Validate changelog ────────────────────────────────────────────────

		preg_match(
			'/^= ' . preg_quote( $new_version, '/' ) . ' =\n(.*?)(?=^= |\z)/ms',
			$readme_contents,
			$cl_match
		);
		$changelog_entry = trim( $cl_match[1] ?? '' );

		if ( $changelog_entry === '' ) {
			WP_CLI::error( "Changelog for $new_version is empty. Add release notes to readme.txt before releasing." );
		}

		if ( $changelog_entry === "* Release $new_version (" . gmdate( 'Y-m-d' ) . ")." ) {
			WP_CLI::error( "No changes added in current changelog for $new_version.\nEdit readme.txt and replace the placeholder before releasing." );
		}

		// ── Regenerate README.md ──────────────────────────────────────────────

		WP_CLI::log( "  \u2192 regenerating README.md via grunt" );
		axellcore_run( 'npm run readme --silent', $plugin_dir, true );

		// ── Update POT ────────────────────────────────────────────────────────

		WP_CLI::log( "  \u2192 generating axellcore.pot via wp i18n make-pot" );
		WP_CLI::runcommand(
			"i18n make-pot $plugin_dir $pot_file --domain=axellcore --exclude=lib,vendor,node_modules,tests --quiet",
			[ 'launch' => true ]
		);

		// ── Commit, tag, push ─────────────────────────────────────────────────

		WP_CLI::log( "  \u2192 staging all changes" );
		axellcore_run( 'git add -A', $plugin_dir, true );

		if ( $no_commit ) {
			WP_CLI::line( '' );
			WP_CLI::success( "Files bumped to $new_version (--no-commit: skipping commit, tag and push)." );
			return;
		}

		WP_CLI::log( "  \u2192 committing version bump" );
		axellcore_run( "git commit --quiet -m \"chore: release $new_version\"", $plugin_dir, true );

		if ( $no_tag ) {
			WP_CLI::line( '' );
			WP_CLI::success( "Released $new_version (--no-tag: skipping tag and push)." );
			return;
		}

		WP_CLI::log( "  \u2192 tagging $new_version" );
		axellcore_run( "git tag $new_version", $plugin_dir, true );

		if ( $no_push ) {
			WP_CLI::line( '' );
			WP_CLI::success( "Released $new_version (--no-push: skipping push)." );
			return;
		}

		WP_CLI::log( "  \u2192 pushing main and tag" );
		$branch = trim( axellcore_run( 'git rev-parse --abbrev-ref HEAD', $plugin_dir, true ) );
		[ $push_exit ] = axellcore_try_run( "git push origin $branch --quiet", $plugin_dir );
		if ( $push_exit !== 0 ) {
			WP_CLI::log( "  \u2192 push rejected \u2014 retrying with --force" );
			axellcore_run( "git push origin $branch --force --quiet", $plugin_dir, true );
		}
		axellcore_run( "git push origin $new_version --quiet", $plugin_dir, true );

		$remote_url = trim( axellcore_run( 'git remote get-url origin', $plugin_dir, true ) );
		$repo       = preg_replace( [ '/.*github\.com[:\/]/', '/\.git$/' ], '', $remote_url );

		WP_CLI::line( '' );
		WP_CLI::success( "Released $new_version." );
		WP_CLI::line( "Run 'wp --require=bin/release.php axl language' after translating to publish language packs." );
		WP_CLI::line( "https://github.com/$repo/releases/tag/$new_version" );
	}

	/**
	 * Build and push a language/<version> orphan branch from .po files.
	 *
	 * ## OPTIONS
	 *
	 * [--lang-src=<path>]
	 * : Path to directory containing axellcore-*.po files.
	 *   Defaults to wp-content/languages/plugins/ two levels up from the plugin.
	 *   Also reads the LANG_SRC environment variable.
	 *
	 * ## EXAMPLES
	 *
	 *   wp --require=bin/release.php axl language
	 *   wp --require=bin/release.php axl language --lang-src=/path/to/languages/plugins
	 *
	 * @subcommand language
	 * @when before_wp_load
	 */
	public function language( array $args, array $assoc_args ): void {
		axellcore_require_cmd( 'git' );
		axellcore_require_cmd( 'msgfmt' );
		axellcore_require_cmd( 'msgmerge' );

		$plugin_dir = axellcore_plugin_dir();
		$pot_file   = $plugin_dir . '/languages/axellcore.pot';
		$current    = axellcore_current_version( $plugin_dir . '/axellcore.php' );

		$default_lang_src = realpath( $plugin_dir . '/../../languages/plugins' ) ?: '';
		$lang_src         = $assoc_args['lang-src']
			?? getenv( 'LANG_SRC' )
			?: $default_lang_src;

		$lang_branch = "language/$current";

		WP_CLI::line( '' );
		WP_CLI::line( "Building language branch $lang_branch" );
		WP_CLI::line( '' );

		if ( ! is_dir( $lang_src ) ) {
			WP_CLI::error( "LANG_SRC not found: $lang_src — use --lang-src= or set LANG_SRC env var." );
		}

		$po_files = glob( $lang_src . '/axellcore-*.po' ) ?: [];
		if ( empty( $po_files ) ) {
			WP_CLI::error( "No axellcore-*.po files found in $lang_src" );
		}

		// ── Merge POT + validate ───────────────────────────────────────────────

		$all_missing = [];

		foreach ( $po_files as $po_file ) {
			$locale = preg_replace( '/^axellcore-|\.po$/', '', basename( $po_file ) );
			WP_CLI::log( "  → merging pot into $locale" );
			axellcore_run( "msgmerge --update --backup=none --quiet $po_file $pot_file" );

			$missing = axellcore_po_missing( $po_file );
			if ( ! empty( $missing ) ) {
				$all_missing[ $locale ] = $missing;
			}
		}

		if ( ! empty( $all_missing ) ) {
			WP_CLI::line( '' );
			foreach ( $all_missing as $locale => $strings ) {
				WP_CLI::line( "[$locale]" );
				foreach ( $strings as $s ) {
					WP_CLI::line( "  $s" );
				}
			}
			WP_CLI::error( 'Untranslated strings found — translate before publishing.' );
		}

		// ── Prepare PO files for the branch ──────────────────────────────────────

		$lang_work = sys_get_temp_dir() . '/axellcore-lang-work-' . uniqid();
		$lang_repo = sys_get_temp_dir() . '/axellcore-lang-repo-' . uniqid();
		mkdir( $lang_work, 0755, true );
		mkdir( $lang_repo, 0755, true );

		$remote_url = trim( axellcore_run( 'git remote get-url origin', $plugin_dir, true ) );
		$today_iso  = gmdate( 'Y-m-d\TH:i:s+00:00' );
		$git_name   = trim( axellcore_run( 'git config user.name', $plugin_dir, true ) );
		$git_email  = trim( axellcore_run( 'git config user.email', $plugin_dir, true ) );

		foreach ( $po_files as $po_file ) {
			$locale  = preg_replace( '/^axellcore-|\.po$/', '', basename( $po_file ) );
			$dest_po = $lang_work . "/axellcore-$locale.po";
			copy( $po_file, $dest_po );

			$po_contents = file_get_contents( $dest_po );
			$po_contents = preg_replace( '/^"Project-Id-Version:.*$/m', "\"Project-Id-Version: axellcore $current\\\\n\"", $po_contents );
			$po_contents = preg_replace( '/^"PO-Revision-Date:.*$/m', "\"PO-Revision-Date: $today_iso\\\\n\"", $po_contents );
			file_put_contents( $dest_po, $po_contents );

			WP_CLI::log( "  → prepared $locale" );
		}

		// ── Build orphan branch ──────────────────────────────────────────────────

		axellcore_run( 'git init --quiet', $lang_repo, true );
		axellcore_run( "git remote add origin $remote_url", $lang_repo, true );
		axellcore_run( "git checkout --orphan $lang_branch --quiet", $lang_repo, true );
		axellcore_try_run( 'git rm -rf . --quiet', $lang_repo );

		foreach ( glob( $lang_work . '/axellcore-*.po' ) ?: [] as $prepared_po ) {
			copy( $prepared_po, $lang_repo . '/' . basename( $prepared_po ) );
		}

		axellcore_run( 'git add .', $lang_repo, true );
		axellcore_run(
			"git -c user.name=\"$git_name\" -c user.email=\"$git_email\" commit --quiet -m \"i18n: pt_BR language pack for $current\"",
			$lang_repo,
			true
		);

		WP_CLI::log( "  \u2192 pushing $lang_branch" );
		axellcore_run( "git push origin $lang_branch --force --quiet", $lang_repo, true );

		$lang_sha = trim( axellcore_run( 'git rev-parse HEAD', $lang_repo, true ) );

		// Clean up temp dirs.
		axellcore_run( "rm -rf $lang_work $lang_repo" );

		WP_CLI::line( '' );
		WP_CLI::success( "Language branch $lang_branch pushed ($lang_sha)." );

		// ── Dispatch GitHub Actions workflow ────────────────────────────────

		[ $gh_exit ] = axellcore_try_run( 'command -v gh' );
		if ( $gh_exit === 0 ) {
			$repo = preg_replace( [ '/.*github\.com[:\/]/', '/\.git$/' ], '', $remote_url );
			WP_CLI::log( "  \u2192 dispatching Language workflow for $current" );
			axellcore_run( "gh workflow run language.yml --repo $repo --field version=$current" );
			WP_CLI::success( 'Language workflow dispatched.' );
			WP_CLI::line( "https://github.com/$repo/actions/workflows/language.yml" );
		} else {
			WP_CLI::warning( 'gh CLI not found — trigger the Language workflow manually with version=' . $current );
		}
	}
}

WP_CLI::add_command( 'axl', 'Axellcore_CLI_Command' );
