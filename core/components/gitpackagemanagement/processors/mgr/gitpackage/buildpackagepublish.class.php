<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/gpc/gitpackageconfig.class.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/builder/gitpackagebuilder.class.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/processors/mgr/gitpackage/buildpackage.class.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/autoload.php';

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\PhpseclibV2\SftpConnectionProvider;
use League\Flysystem\PhpseclibV2\SftpAdapter;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

/**
 * Clone git repository and install it
 *
 * @package gitpackagemanagement
 * @subpackage processors
 */
class GitPackageManagementBuildPackagePublishProcessor extends GitPackageManagementBuildPackageProcessor
{
    /** @var Packeteer $packeteer */
    public $packeteer;

    private $vendorPath;
    private $tempVendorPath;

    private $phpVersion = '7.4.33';

    public function process()
    {
        $corePath = $this->modx->getOption('packeteer.core_path', null, $this->modx->getOption('core_path') . 'components/packeteer/');
        $this->packeteer = $this->modx->getService('packeteer', 'Packeteer', $corePath . 'model/packeteer/', array(
            'core_path' => $corePath
        ));

        $this->prepare();

        $buildOptions = $this->config->getBuild()->getBuildOptions();

        try {
            $this->prepareExternalScripts($buildOptions);
        } catch (Exception $e) {
            return $this->failure($e->getMessage());
        }

        $this->cleanupLexicons();
        $this->moveTempComposer();

        $process = parent::process();

        $this->moveBackComposer();

        if ($process['success'] !== true) {
            return $process;
        }

        try {
            $this->createUpload();
        } catch (Exception $e) {
            return $this->failure($e->getMessage());
        }

        $result = $this->scanPacketeerPackages();

        if (isset($result['success']) && $result['success'] == true) {
            return $this->success($result['message']);
        } else {
            return $this->failure($result['message']);
        }
    }

    /**
     * @throws Exception
     */
    private function prepareExternalScripts($buildOptions)
    {
        $phpVersion = $this->modx->getOption('php_version', $buildOptions, $this->phpVersion);

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/Gruntfile.js')) {
            exec('export PATH=$PATH:/usr/local/bin; /usr/local/bin/grunt --gruntfile=' . $this->config->getPackagePath() . '/Gruntfile.js default 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Grunt issue!' . "\n" . implode("\n", $execResult));
                throw new Exception('Grunt issue!' . '<br>' . implode('<br>', $execResult));
            }
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Grunt successful.');
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/gulpfile.js')) {
            exec('export PATH=$PATH:/usr/local/bin; /usr/local/bin/gulp --gulpfile=' . $this->config->getPackagePath() . '/gulpfile.js default 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Gulp issue!' . "\n" . implode("\n", $execResult));
                throw new Exception('Gulp issue!' . '<br>' . implode('<br>', $execResult));
            }
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Gulp successful.');
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/composer.json')) {
            exec('export PATH=$PATH:/usr/local/bin:/Applications/MAMP/bin/php/php' . $phpVersion . '/bin; export COMPOSER_HOME=/Applications/MAMP/bin/php/composer; /Applications/MAMP/bin/php/composer licenses --format=json --working-dir=' . $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/' . ' 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Composer issue!');
                throw new Exception('Composer issue!' . '<br>' . implode('<br>', $execResult));
            } else {
                $result = json_decode(implode('', $execResult), true);
                $dependencies = $result['dependencies'] ?? [];
                $packages = [];
                foreach ($dependencies as $name => $info) {
                    $packages[] = '* ' . $name . '@' . ($info['version'] ?? 'unknown') . ' [' . ($info['license'][0] ?? 'unknown') . ']';
                }
            }
            $packages = implode("\n", $packages);

            if ($packages) {
                $packages = "## Third party licenses\n\nThis extra includes third party software, for which we are thankful.\n\n" . $packages;
                $filename = $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/docs/readme.md';
                $content = file_get_contents($filename);
                if ($content && strpos($content, '## Third party licenses')) {
                    $content = preg_replace('/## Third party licenses.*$/s', $packages, $content);
                } else {
                    $content = $content . "\n\n" . $packages;
                }
                file_put_contents($filename, $content);
            }

            exec('export PATH=$PATH:/usr/local/bin:/Applications/MAMP/bin/php/php' . $phpVersion . '/bin; export COMPOSER_HOME=/Applications/MAMP/bin/php/composer; /Applications/MAMP/bin/php/composer update --prefer-dist --no-dev --no-progress --optimize-autoloader --lock --working-dir=' . $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/' . ' 2>&1', $execResult, $execVal);
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Running composer for ' . $this->config->getName() . ' ' . $this->config->getVersion() . "\n" . implode("\n", $execResult));
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Composer issue!');
                throw new Exception('Composer issue!' . '<br>' . implode('<br>', $execResult));
            }
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Composer successful.');
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/test/phpunit.xml')) {
            exec('export PATH=$PATH:/usr/local/bin:/Applications/MAMP/bin/php/php' . $phpVersion . '/bin; /usr/local/bin/phpunit --configuration ' . $this->config->getPackagePath() . '/test/phpunit.xml 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'phpUnit issue!' . "\n" . implode("\n", $execResult));
                throw new Exception('phpUnit issue!' . '<br>' . implode('<br>', $execResult));
            }
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'phpUnit successful.');
        }
    }

    /**
     * @return void
     */
    private function moveTempComposer()
    {
        $useComposer = $this->modx->getOption('composer', $this->config->getBuild()->getBuildOptions(), false);
        if ($useComposer) {
            // Don't include the vendor folder in the package
            $this->vendorPath = $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/vendor/';
            $this->tempVendorPath = $this->config->getPackagePath() . '/temp_vendor/';
            rename($this->vendorPath, $this->tempVendorPath);
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Temporary move the vendor folder from the package.');
        }
    }

    /**
     * @return void
     */
    private function moveBackComposer(): void
    {
        $useComposer = $this->modx->getOption('composer', $this->config->getBuild()->getBuildOptions(), false);
        if ($useComposer) {
            // Move the vendor folder back
            rename($this->tempVendorPath, $this->vendorPath);
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Move the vendor folder back into the package.');
        }
    }

    /**
     * @return mixed
     */
    private function cleanupLexicons()
    {
        $lexiconPath = $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/lexicon/';
        if (file_exists($lexiconPath)) {
            $lexiconPathIterator = new RecursiveDirectoryIterator($lexiconPath, FilesystemIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($lexiconPathIterator, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD) as $file => $info) {
                if (in_array($info->getFilename(), array('_variable.php', '_missing.php', '_superfluous.php'))) {
                    @unlink($info->getRealPath());
                }
            }
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Lexicon test files deleted.');
        }
    }

    /**
     * @return void
     * @throws Exception
     */
    private function createUpload(): void
    {
        $source = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
        chmod($source, 0666);
        $packageAttributes = $this->builder->getTPBuilder()->package->attributes;

        // the build options can have changed in the external scripts
        $buildOptions = $this->config->getBuild()->getBuildOptions();

        $packageInfoArray = array(
            'name' => $this->config->getLowCaseName(),
            'displayname' => $this->config->getName(),
            'description' => $this->config->getDescription(),
            'author' => $this->config->getAuthor(),
            'instructions' => mb_convert_encoding($packageAttributes['readme'], 'UTF-8', 'ISO-8859-1'),
            'changelog' => mb_convert_encoding($packageAttributes['changelog'], 'UTF-8', 'ISO-8859-1'),
            'license' => mb_convert_encoding($packageAttributes['license'], 'UTF-8', 'ISO-8859-1'),
            'modx_version' => $this->modx->getOption('modx_version', $buildOptions, $this->packeteer->getOption('minimal_modx_version'))
        );
        $packageInfo = "<?php\n" .
            'return json_decode(\'' . json_encode($packageInfoArray, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '\', true);' . "\n";

        if ($this->packeteer->getOption('sftp_user')) {

            $filesystem = new Filesystem(new SftpAdapter(
                new SftpConnectionProvider(
                    $this->packeteer->getOption('sftp_serverurl'),
                    $this->packeteer->getOption('sftp_user'),
                    null,
                    $this->packeteer->getOption('sftp_privatekey'),
                    $this->packeteer->getOption('sftp_secret')
                ),
                $this->packeteer->getOption('sftp_serverpath'),
                PortableVisibilityConverter::fromArray([
                    'file' => [
                        'public' => 0664,
                        'private' => 0644,
                    ],
                    'dir' => [
                        'public' => 0775,
                        'private' => 0755,
                    ],
                ])
            ));

            try {
                sleep(2);
                $file = fopen($source, 'r');
                $filesystem->writeStream(basename($source), $file);
            } catch (FilesystemException|UnableToWriteFile $exception) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'SFTP Error uploading package: ' . $exception->getMessage());
                throw new Exception('SFTP Error uploading package: ' . $exception->getMessage());
            }

            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Upload the package per FTP to the package provider.');

            $package_info = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->package->name . '.info.php';
            $info_file = fopen($package_info, 'w');
            fwrite($info_file, $packageInfo);
            fclose($info_file);
            chmod($package_info, 0666);

            try {
                $file = fopen($package_info, 'r');
                $filesystem->writeStream(basename($package_info), $file);
            } catch (FilesystemException|UnableToWriteFile $exception) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'SFTP Error uploading package info: ' . $exception->getMessage());
                throw new Exception('SFTP Error uploading package info: ' . $exception->getMessage());
            }

            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Upload the package info per FTP to the package provider.');
        } else {
            $targetPath = realpath(MODX_BASE_PATH . $this->packeteer->getOption('site_extras_path'));
            $target = $targetPath . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
            copy($source, $target);
            chmod($targetPath . '/_packages/', 0777);
            chmod($target, 0666);

            $package_info = $targetPath . '/_packages/' . $this->builder->getTPBuilder()->package->name . '.info.php';
            $info_file = fopen($package_info, 'w');
            fwrite($info_file, $packageInfo);
            fclose($info_file);
            chmod($package_info, 0666);
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Update the package info file.');
        }
    }

    /**
     * @return mixed
     */
    private function scanPacketeerPackages()
    {
        $packageName = $this->config->getLowCaseName();
        $beta = (bool)preg_match('/.*?-(dev|a|alpha|b|beta|rc)\\d*/i', $this->builder->getTPBuilder()->getSignature());

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $this->packeteer->getOption('site_url') . 'rest/packeteer/package/scan/' . $packageName . '?' . http_build_query(array(
                    'beta' => (string)$beta,
                    'hash' => hash('sha256', $this->packeteer->getOption('site_id') . $packageName . ((string)$beta))
                )),
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Before Scan package.');
        $result = curl_exec($ch);
        // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Scan package: ' . $result);
        $result = json_decode($result, true);
        if ($result == null) {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'cURL Error scan package: ' . curl_error($ch));
        } else {
            // $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Scan for the package on the package provider.');
        }
        curl_close($ch);
        return $result;
    }
}

return 'GitPackageManagementBuildPackagePublishProcessor';
