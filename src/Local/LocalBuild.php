<?php
namespace Platformsh\Cli\Local;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class LocalBuild
{

    // Some changes may not be backwards-compatible with previous build
    // archives. Increment this number as breaking changes are released.
    const BUILD_VERSION = 2;

    protected $settings;
    protected $output;
    protected $fsHelper;
    protected $gitHelper;

    /**
     * @return ToolstackInterface[]
     */
    public function getToolstacks()
    {
        return array(
          new Toolstack\Drupal(),
          new Toolstack\Symfony(),
          new Toolstack\Composer(),
          new Toolstack\NoToolstack(),
        );
    }

    /**
     * @param array           $settings
     * @param OutputInterface $output
     * @param object          $fsHelper
     * @param object          $gitHelper
     */
    public function __construct(array $settings = array(), OutputInterface $output = null, $fsHelper = null, $gitHelper = null)
    {
        $this->settings = $settings;
        $this->output = $output ?: new NullOutput();
        $this->fsHelper = $fsHelper ?: new FilesystemHelper(new ShellHelper($output));
        $this->fsHelper->setRelativeLinks(empty($settings['absoluteLinks']));
        $this->gitHelper = $gitHelper ?: new GitHelper();
    }

    /**
     * Build a normal Platform.sh project.
     *
     * @param string $projectRoot The absolute path to the project root.
     * @param string $sourceDir   The absolute path to the source directory.
     * @param string $destination Where the web root(s) will be linked (absolute
     *                            path).
     *
     * @return bool
     */
    public function buildProject($projectRoot, $sourceDir = null, $destination = null)
    {
        $this->settings['projectRoot'] = $projectRoot;
        $sourceDir = $sourceDir ?: $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $destination = $destination ?: $projectRoot . '/' . LocalProject::WEB_ROOT;

        return $this->build($sourceDir, $destination);
    }

    /**
     * Build a project from any source directory, targeting any destination.
     *
     * @param string $sourceDir   The absolute path to the source directory.
     * @param string $destination Where the web root(s) will be linked (absolute
     *                            path).
     * @param array  $apps        An array of application names to build.
     *
     * @throws \Exception on failure
     *
     * @return bool
     */
    public function build($sourceDir, $destination, array $apps = array())
    {
        $success = true;
        $identifiers = array();
        foreach ($this->getApplications($sourceDir) as $identifier => $appRoot) {
            $appConfig = $this->getAppConfig($appRoot);
            $appIdentifier = isset($appConfig['name']) ? $appConfig['name'] : $identifier;
            $appConfig['_identifier'] = $appIdentifier;
            $identifiers[] = $appIdentifier;
            if ($apps && !in_array($appIdentifier, $apps)) {
                continue;
            }
            $success = $this->buildApp($appRoot, $sourceDir, $destination, $appConfig) && $success;
        }
        $notFounds = array_diff($apps, $identifiers);
        if ($notFounds) {
            foreach ($notFounds as $notFound) {
                $this->output->writeln("Application not found: <comment>$notFound</comment>");
            }
        }
        if (empty($this->settings['noClean'])) {
            if (!empty($this->settings['projectRoot'])) {
                $this->output->writeln("Cleaning up...");
                $this->cleanBuilds($this->settings['projectRoot']);
                $this->cleanArchives($this->settings['projectRoot']);
            }
            else {
                $buildsDir = $sourceDir . '/' . LocalProject::BUILD_DIR;
                if (is_dir($buildsDir)) {
                    $this->output->writeln("Cleaning up...");
                    $this->cleanDirectory($buildsDir);
                }
            }
        }

        return $success;
    }

    /**
     * Get a list of applications in the repository.
     *
     * @param string $repositoryRoot The absolute path to the repository.
     *
     * @return string[]    A list of directories containing applications.
     */
    public function getApplications($repositoryRoot)
    {
        $finder = new Finder();
        $finder->in($repositoryRoot)
               ->ignoreDotFiles(false)
               ->notPath('builds')
               ->name('.platform.app.yaml')
               ->depth('> 0')
               ->depth('< 5');
        if ($finder->count() == 0) {
            return array('default' => $repositoryRoot);
        }
        $applications = array();
        /** @var \Symfony\Component\Finder\SplFileInfo $file */
        foreach ($finder as $file) {
            $filename = $file->getRealPath();
            $appRoot = dirname($filename);
            $identifier = ltrim(str_replace($repositoryRoot, '', $appRoot), '/');
            $applications[$identifier] = $appRoot;
        }

        return array_unique($applications);
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @param string $appRoot The absolute path to the application.
     *
     * @return array
     */
    public function getAppConfig($appRoot)
    {
        $config = array();
        if (file_exists($appRoot . '/.platform.app.yaml')) {
            try {
                $parser = new Parser();
                $config = (array) $parser->parse(file_get_contents($appRoot . '/.platform.app.yaml'));
            }
            catch (ParseException $e) {
                throw new InvalidConfigException(
                  "Parse error in file '$appRoot/.platform.app.yaml'. \n" . $e->getMessage()
                );
            }
        }

        return $this->normalizeConfig($config);
    }

    /**
     * Normalize an application's configuration.
     *
     * @param array $config
     *
     * @return array
     */
    public function normalizeConfig(array $config)
    {
        // Backwards compatibility with old config format: toolstack is changed
        // to application type and build['flavor'].
        if (isset($config['toolstack'])) {
            if (!strpos($config['toolstack'], ':')) {
                throw new InvalidConfigException("Invalid value for 'toolstack'");
            }
            list($config['type'], $config['build']['flavor']) = explode(':', $config['toolstack'], 2);
        }

        return $config;
    }

    /**
     * Get the toolstack for a particular application.
     *
     * @param string $appRoot   The absolute path to the application.
     * @param mixed  $appConfig The application's configuration.
     *
     * @throws \Exception   If a specified toolstack is not found.
     *
     * @return ToolstackInterface|false
     */
    public function getToolstack($appRoot, array $appConfig = array())
    {
        $toolstackChoice = false;

        // For now, we reconstruct a toolstack string based on the 'type' and
        // 'build.flavor' config keys.
        if (isset($appConfig['type'])) {
            list($stack, ) = explode(':', $appConfig['type'], 2);
            $flavor = isset($appConfig['build']['flavor']) ? $appConfig['build']['flavor'] : 'default';

            // Toolstack classes for HHVM are the same as PHP.
            if ($stack === 'hhvm') {
                $stack = 'php';
            }

            $toolstackChoice = "$stack:$flavor";

            // Alias php:default to php:composer.
            if ($toolstackChoice === 'php:default') {
                $toolstackChoice = 'php:composer';
            }
        }

        foreach (self::getToolstacks() as $toolstack) {
            $key = $toolstack->getKey();
            if ((!$toolstackChoice && $toolstack->detect($appRoot))
              || ($key && $toolstackChoice === $key)
            ) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }

        return false;
    }

    /**
     * Get a hash of the application files.
     *
     * This should change if any of the application files or build settings
     * change.
     *
     * @param string $appRoot
     *
     * @return string|false
     */
    public function getTreeId($appRoot)
    {
        $hashes = array();

        // Get a hash representing all the files in the application, excluding
        // the .platform folder.
        $tree = $this->gitHelper->execute(array('ls-files', '-s'), $appRoot);
        if ($tree === false) {
            return false;
        }
        $tree = preg_replace('#^|\n[^\n]+?\.platform\n|$#', "\n", $tree);
        $hashes[] = sha1($tree);

        // Include the hashes of untracked and modified files.
        $others = $this->gitHelper->execute(
          array('ls-files', '--modified', '--others', '--exclude-standard', '-x .platform', '.'),
          $appRoot
        );
        if ($others === false) {
            return false;
        }
        $count = 0;
        foreach (explode("\n", $others) as $filename) {
            if ($count > 5000) {
                return false;
            }
            $filename = "$appRoot/$filename";
            if (is_file($filename)) {
                $hashes[] = sha1_file($filename);
                $count++;
            }
        }

        // Include relevant build settings.
        $irrelevant = array('environmentId', 'appName', 'multiApp', 'noClean', 'verbosity', 'drushConcurrency', 'projectRoot');
        $settings = array_filter(array_diff_key($this->settings, array_flip($irrelevant)));
        $hashes[] = serialize($settings);

        $hashes[] = self::BUILD_VERSION;

        // Combine them all.
        return sha1(implode(' ', $hashes));
    }

    /**
     * @param string $appRoot
     * @param string $sourceDir
     * @param string $destination
     * @param array  $appConfig
     *
     * @return bool
     */
    protected function buildApp($appRoot, $sourceDir, $destination, array $appConfig = array())
    {
        $verbose = $this->output->isVerbose();

        $multiApp = $appRoot != $sourceDir;
        $appName = isset($appConfig['name']) ? $appConfig['name'] : false;
        $appIdentifier = $appName ?: $appConfig['_identifier'];

        $buildName = date('Y-m-d--H-i-s');
        if (!empty($this->settings['environmentId'])) {
            $buildName .= '--' . $this->settings['environmentId'];
        }
        if ($multiApp) {
            $buildName .= '--' . str_replace('/', '-', $appIdentifier);
        }

        if (!empty($this->settings['projectRoot'])) {
            $buildDir = $this->settings['projectRoot'] . '/' . LocalProject::BUILD_DIR . '/' . $buildName;
        }
        else {
            $buildDir = $sourceDir . '/' . LocalProject::BUILD_DIR . '/' . $buildName;
        }

        // Get the configured document root.
        $documentRoot = $this->getDocumentRoot($appConfig);

        $toolstack = $this->getToolstack($appRoot, $appConfig);

        if (!$toolstack) {
            $this->output->writeln("Toolstack not found for application <error>$appIdentifier</error>");

            return false;
        }

        $toolstack->setOutput($this->output);

        $buildSettings = $this->settings + array(
            'multiApp' => $multiApp,
            'appName' => $appName,
          );
        $toolstack->prepare($buildDir, $documentRoot, $appRoot, $sourceDir, $buildSettings);

        $archive = false;
        if (empty($this->settings['noArchive']) && empty($this->settings['noCache']) && !empty($this->settings['projectRoot'])) {
            $treeId = $this->getTreeId($appRoot);
            if ($treeId) {
                if ($verbose) {
                    $this->output->writeln("Tree ID: $treeId");
                }
                $archive = $this->settings['projectRoot'] . '/' . LocalProject::ARCHIVE_DIR . '/' . $treeId . '.tar.gz';
            }
        }

        if ($archive && file_exists($archive)) {
            $message = "Extracting archive for application <info>$appIdentifier</info>";
            $this->output->writeln($message);
            $this->fsHelper->extractArchive($archive, $buildDir);
        } else {
            $message = "Building application <info>$appIdentifier</info>";
            if (isset($appConfig['type'])) {
                $message .= ' (runtime type: ' . $appConfig['type'] . ')';
            }
            $this->output->writeln($message);

            $toolstack->build();

            // We can only run post-build hooks for apps that actually have
            // a separate build directory.
            if (file_exists($buildDir)) {
                if ($this->runPostBuildHooks($appConfig, $buildDir) === false) {
                    // The user may not care if build hooks fail, but we should
                    // not archive the result.
                    $archive = false;
                }
            }
            else {
                $this->warnAboutHooks($appConfig, 'build');
            }

            if ($archive && $toolstack->canArchive()) {
                $this->output->writeln("Saving build archive");
                if (!is_dir(dirname($archive))) {
                    mkdir(dirname($archive));
                }
                $this->fsHelper->archiveDir($buildDir, $archive);
            }
        }

        $toolstack->install();

        $webRoot = $toolstack->getWebRoot();

        // Symlink the built web root ($webRoot) into www or www/appIdentifier.
        if (!is_dir($webRoot)) {
            $this->output->writeln("Web root not found: <error>$webRoot</error>");

            return false;
        }
        if ($multiApp) {
            $appDir = str_replace('/', '-', $appIdentifier);
            if (is_link($destination)) {
                $this->fsHelper->remove($destination);
            }
            $destination .= "/$appDir";
        }

        $this->fsHelper->symlink($webRoot, $destination);
        $this->output->writeln("Web root: $destination");

        $message = "Build complete for application <info>$appIdentifier</info>";
        $this->output->writeln($message);

        return true;
    }

    /**
     * Get the configured document root for the application.
     *
     * @link https://docs.platform.sh/reference/configuration-files
     *
     * @param array $appConfig
     *
     * @return string
     */
    protected function getDocumentRoot(array $appConfig)
    {
        // The default document root is '/public'. This is used if the root is
        // not set, if it is empty, or if it is set to '/'.
        $documentRoot = '/public';
        if (!empty($appConfig['web']['document_root']) && $appConfig['web']['document_root'] !== '/') {
            $documentRoot = $appConfig['web']['document_root'];
        }
        return ltrim($documentRoot, '/');
    }

    /**
     * Run post-build hooks.
     *
     * @param array  $appConfig
     * @param string $buildDir
     *
     * @return bool|null
     *   False if the build hooks fail, true if they succeed, null if not
     *   applicable.
     */
    protected function runPostBuildHooks(array $appConfig, $buildDir)
    {
        if (!isset($appConfig['hooks']['build'])) {
            return null;
        }
        if (!empty($this->settings['noBuildHooks'])) {
            $this->output->writeln("Skipping post-build hooks");
            return null;
        }
        $this->output->writeln("Running post-build hooks");
        $command = implode(';', (array) $appConfig['hooks']['build']);
        chdir($buildDir);
        exec($command, $output, $returnVar);
        foreach ($output as $line) {
            $this->output->writeln('  ' . $line);
        }
        if ($returnVar > 0) {
            $this->output->writeln('<error>The build hook failed</error>');
            return false;
        }

        return true;
    }

    /**
     * Warn the user that the CLI will not run hooks.
     *
     * @param array  $appConfig
     * @param string $hookType
     */
    protected function warnAboutHooks(array $appConfig, $hookType)
    {
        if (empty($appConfig['hooks'][$hookType])) {
            return;
        }
        $indent = '        ';
        $this->output->writeln(
          "<comment>You have defined the following $hookType hook(s). The CLI will not run them locally, unless you use the --copy option.</comment>"
        );
        $this->output->writeln("    $hookType: |");
        $hooks = (array) $appConfig['hooks'][$hookType];
        $asString = implode("\n", array_map('trim', $hooks));
        $withIndent = $indent . str_replace("\n", "\n$indent", $asString);
        $this->output->writeln($withIndent);
    }

    /**
     * Remove old builds.
     *
     * This preserves the currently active build.
     *
     * @param string $projectRoot
     * @param int    $maxAge
     * @param int    $keepMax
     * @param bool   $includeActive
     * @param bool   $quiet
     *
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanBuilds($projectRoot, $maxAge = null, $keepMax = 10, $includeActive = false, $quiet = true)
    {
        // Find all the potentially active symlinks, which might be www itself
        // or symlinks inside www. This is so we can avoid deleting the active
        // build(s).
        $blacklist = array();
        if (!$includeActive) {
            $blacklist = $this->getActiveBuilds($projectRoot);
        }

        return $this->cleanDirectory(
          $projectRoot . '/' . LocalProject::BUILD_DIR,
          $maxAge,
          $keepMax,
          $blacklist,
          $quiet
        );
    }

    /**
     * @param string $projectRoot
     *
     * @throws \Exception If it cannot be determined whether or not a symlink
     *                    points to a genuine active build.
     *
     * @return array The absolute paths to any active builds in the project.
     */
    protected function getActiveBuilds($projectRoot)
    {
        $www = $projectRoot . '/' . LocalProject::WEB_ROOT;
        if (!file_exists($www)) {
            return array();
        }
        $links = array($www);
        if (!is_link($www) && is_dir($www)) {
            $finder = new Finder();
            /** @var \Symfony\Component\Finder\SplFileInfo $file */
            foreach ($finder->in($www)
                            ->directories()
                            ->depth(0) as $file) {
                $links[] = $file->getPathname();
            }
        }
        $activeBuilds = array();
        $buildsDir = $projectRoot . '/' . LocalProject::BUILD_DIR;
        foreach ($links as $link) {
            if (is_link($link) && ($target = readlink($link))) {
                // Make the target into an absolute path.
                $target = $target[0] === DIRECTORY_SEPARATOR ? $target : realpath(dirname($link) . '/' . $target);
                if (!$target) {
                    continue;
                }
                // Ignore the target if it doesn't point to a build in 'builds'.
                if (strpos($target, $buildsDir) === false) {
                    continue;
                }
                // The target should just be one level below the 'builds'
                // directory, not more.
                while (dirname($target) != $buildsDir) {
                    $target = dirname($target);
                    if (strpos($target, $buildsDir) === false) {
                        throw new \Exception('Error resolving active build directory');
                    }
                }
                $activeBuilds[] = $target;
            }
        }

        return $activeBuilds;
    }

    /**
     * Remove old build archives.
     *
     * @param string $projectRoot
     * @param int    $maxAge
     * @param int    $keepMax
     * @param bool   $quiet
     *
     * @return int[]
     *   The numbers of deleted and kept builds.
     */
    public function cleanArchives($projectRoot, $maxAge = null, $keepMax = 10, $quiet = true)
    {
        return $this->cleanDirectory(
          $projectRoot . '/' . LocalProject::ARCHIVE_DIR,
          $maxAge,
          $keepMax,
          array(),
          $quiet
        );
    }

    /**
     * Remove old files from a directory.
     *
     * @param string $directory
     * @param int    $maxAge
     * @param int    $keepMax
     * @param array  $blacklist
     * @param bool   $quiet
     *
     * @return int[]
     */
    protected function cleanDirectory($directory, $maxAge = null, $keepMax = 5, array $blacklist = array(), $quiet = true)
    {
        if (!is_dir($directory)) {
            return array(0, 0);
        }
        $files = glob($directory . '/*');
        if (!$files) {
            return array(0, 0);
        }
        // Sort files by modified time (descending).
        usort(
          $files,
          function ($a, $b) {
              return filemtime($a) < filemtime($b);
          }
        );
        $now = time();
        $numDeleted = 0;
        $numKept = 0;
        foreach ($files as $filename) {
            if (in_array($filename, $blacklist)) {
                $numKept++;
                continue;
            }
            if ($keepMax !== null && ($numKept >= $keepMax) || ($maxAge !== null && $now - filemtime($filename) > $maxAge)) {
                if (!$quiet) {
                    $this->output->writeln("Deleting: " . basename($filename));
                }
                $this->fsHelper->remove($filename);
                $numDeleted++;
            } else {
                $numKept++;
            }
        }

        return array($numDeleted, $numKept);
    }

}
