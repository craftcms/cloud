<?php

namespace craft\cloud\cli\controllers;

use Craft;
use craft\console\Controller;
use Illuminate\Support\Collection;
use PHLAK\SemVer\Exceptions\InvalidVersionException;
use PHLAK\SemVer\Version;
use Symfony\Component\Yaml\Yaml;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

class SetupController extends Controller
{
    public function actionIndex(): int
    {
        $this->runAction('config');
        $this->stdout(PHP_EOL);
        $this->stdout("Your project is ready to deploy to Craft Cloud!\n", BaseConsole::FG_GREEN);
        $this->stdout("See https://craftcms.com/docs/cloud/config.html\n");

        return ExitCode::OK;
    }

    public function actionConfig(): int
    {
        $config = [];
        $filePath = Craft::getAlias('@root/craft-cloud.yaml');
        $fileName = basename($filePath);
        $defaultPhpVersion = Version::parse('8.2');
        $defaultNodeVersion = Version::parse('20.9');
        $defaultNpmScript = 'build';
        $ddevConfigFile = Craft::getAlias('@root/.ddev/config.yaml');
        $ddevConfig = file_exists($ddevConfigFile)
            ? Yaml::parseFile($ddevConfigFile)
            : null;
        $ddevPhpVersion = $ddevConfig['php_version'] ?? null;
        $ddevNodeVersion = $ddevConfig['nodejs_version'] ?? null;
        $composerJsonFile = Craft::getAlias('@root/composer.json');
        $composerJson = file_exists($composerJsonFile)
            ? json_decode(file_get_contents($composerJsonFile), true)
            : null;
        $composerJsonPhpVersion = $composerJson['config']['platform']['php'] ?? null;
        $packageJsonFile = Craft::getAlias('@root/package.json');
        $packageJson = file_exists($packageJsonFile)
            ? json_decode(file_get_contents($packageJsonFile), true)
            : null;
        $packageJsonNodeVersion = $packageJson['engines']['node'] ?? null;
        $packageJsonScripts = Collection::make($packageJson['scripts'] ?? null)->keys();
        $confirmMessage = file_exists($filePath)
            ? $this->markdownToAnsi("`{$fileName}` already exists. Overwrite?")
            : $this->markdownToAnsi("Create `{$fileName}`?");

        if (!$this->confirm($confirmMessage, true)) {
            return ExitCode::OK;
        }

        if ($ddevPhpVersion) {
            try {
                $this->do(
                    "Detected PHP version from DDEV config: `{$ddevPhpVersion}`",
                    function() use ($ddevPhpVersion, &$defaultPhpVersion) {
                        $defaultPhpVersion = Version::parse($ddevPhpVersion);
                    }
                );
            } catch (InvalidVersionException $e) {
            }
        }

        if ($composerJsonPhpVersion) {
            try {
                $this->do(
                    "Detected PHP version from composer.json (_config.platforms.php_): `{$composerJsonPhpVersion}`",
                    function() use ($composerJsonPhpVersion, &$defaultPhpVersion) {
                        $defaultPhpVersion = Version::parse($composerJsonPhpVersion);
                    }
                );
            } catch (InvalidVersionException $e) {
            }
        }

        $config['php-version'] = $this->prompt('PHP version:', array(
            'required' => true,
            'default' => "$defaultPhpVersion->major.$defaultPhpVersion->minor",
            'validator' => function(string $value, &$error) {
                if (!preg_match('/^[0-9]+\.[0-9]+$/', $value)) {
                    $error = $this->markdownToAnsi('PHP version must be specified as `major.minor`.');
                    return false;
                }
                return true;
            },
        ));

        if ($packageJsonScripts->isNotEmpty() && $this->confirm('Run npm script on deploy?', true)) {
            $config['npm-script'] = $this->prompt('npm script to run:', [
                'default' => $packageJsonScripts->contains($defaultNpmScript) ? $defaultNpmScript : null,
                'required' => true,
                'validator' => function(string $value, &$error) use ($packageJsonScripts) {
                    if (!$packageJsonScripts->contains($value)) {
                        $error = $this->markdownToAnsi("npm script not found in package.json: `{$value}`");
                        return false;
                    }

                    return true;
                },
            ]);

            if ($defaultNpmScript === $config['npm-script']) {
                unset($config['npm-script']);
            }

            if ($ddevNodeVersion) {
                try {
                    $this->do(
                        "Detected Node.js version from DDEV config: `{$ddevNodeVersion}`",
                        function() use ($ddevNodeVersion, &$defaultNodeVersion) {
                            $defaultNodeVersion = Version::parse($ddevNodeVersion);
                        }
                    );
                } catch (InvalidVersionException $e) {
                }
            }

            if ($packageJsonNodeVersion) {
                try {
                    $this->do(
                        "Detected Node.js version from package.json (_engines.node_): `{$packageJsonNodeVersion}`",
                        function() use ($packageJsonNodeVersion, &$defaultNodeVersion) {
                            $defaultNodeVersion = Version::parse($packageJsonNodeVersion);
                        }
                    );
                } catch (InvalidVersionException $e) {
                }
            }

            $config['node-version'] = $this->prompt('Node version:', [
                'required' => false,
                'default' => "$defaultNodeVersion->major.$defaultNodeVersion->minor",
                'validator' => function(string $input, ?string &$error = null) {
                    if (!preg_match('/^[0-9]+\.[0-9]+$/', $input)) {
                        $error = $this->markdownToAnsi('Node version must be specified as `major.minor`.');
                        return false;
                    }
                    return true;
                },
            ]);
        }

        $output = "# Craft Cloud configuration file\n";
        $output .= "# https://craftcms.com/knowledge-base/cloud-config\n";
        $output .= Yaml::dump($config, 20, 2);

        $this->writeToFile(
            $filePath,
            $output,
        );

        return ExitCode::OK;
    }
}
