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

    public function process()
    {
        $corePath = $this->modx->getOption('packeteer.core_path', null, $this->modx->getOption('core_path') . 'components/packeteer/');
        $this->packeteer = $this->modx->getService('packeteer', 'Packeteer', $corePath . 'model/packeteer/', array(
            'core_path' => $corePath
        ));

        $this->prepare();

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/Gruntfile.js')) {
            exec('export PATH=$PATH:/usr/local/bin; /usr/local/bin/grunt --gruntfile=' . $this->config->getPackagePath() . '/Gruntfile.js default 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Grunt issue!' . "\n" . implode("\n", $execResult));
                return $this->failure('Grunt issue!' . '<br>' . implode('<br>', $execResult));
            }
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Grunt successful.');
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/gulpfile.js')) {
            exec('export PATH=$PATH:/usr/local/bin; /usr/local/bin/gulp --gulpfile=' . $this->config->getPackagePath() . '/gulpfile.js default 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Gulp issue!' . "\n" . implode("\n", $execResult));
                return $this->failure('Gulp issue!' . '<br>' . implode('<br>', $execResult));
            }
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Gulp successful.');
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/composer.json')) {
            exec('export PATH=$PATH:/usr/local/bin:/Applications/MAMP/bin/php/php7.4.26/bin; export COMPOSER_HOME=/Applications/MAMP/bin/php/composer; /Applications/MAMP/bin/php/composer install --prefer-dist --no-dev --no-progress --optimize-autoloader --working-dir=' . $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/' . ' 2>&1', $execResult, $execVal);
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Running composer for ' . $this->config->getName() . ' ' . $this->config->getVersion() . "\n" . implode("\n", $execResult));
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Composer issue!');
                return $this->failure('Composer issue!' . '<br>' . implode('<br>', $execResult));
            }
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Composer successful.');
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/test/phpunit.xml')) {
            exec('export PATH=$PATH:/usr/local/bin:/Applications/MAMP/bin/php/php7.4.26/bin; /usr/local/bin/phpunit --configuration ' . $this->config->getPackagePath() . '/test/phpunit.xml 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'phpUnit issue!' . "\n" . implode("\n", $execResult));
                return $this->failure('phpUnit issue!' . '<br>' . implode('<br>', $execResult));
            }
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'phpUnit successful.');
        }

        $lexiconPath = $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/lexicon/';
        if (file_exists($lexiconPath)) {
            $lexiconPathIterator = new RecursiveDirectoryIterator($lexiconPath, RecursiveDirectoryIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($lexiconPathIterator, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD) as $file => $info) {
                if (in_array($info->getFilename(), array('_variable.php', '_missing.php', '_superfluous.php'))) {
                    @unlink($info->getRealPath());
                }
            }
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Lexicon test files deleted.');
        }

        $useComposer = $this->modx->getOption('composer', $this->config->getBuild()->getBuildOptions(), false);
        if ($useComposer) {
            // Don't include the vendor folder in the package
            $vendorPath = $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/vendor/';
            $tempVendorPath = $this->config->getPackagePath() . '/temp_vendor/';
            rename($vendorPath, $tempVendorPath);
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Temporary move the vendor folder from the package.');
        }

        $process = parent::process();

        if ($useComposer) {
            // Move the vendor folder back
            rename($tempVendorPath, $vendorPath);
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Move the vendor folder back into the package.');
        }

        if ($process['success'] !== true) {
            return $process;
        }

        $source = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
        chmod($source, 0666);
        $packageAttributes = $this->builder->getTPBuilder()->package->attributes;
        $buildOptions = $this->config->getBuild()->getBuildOptions();

        $packageInfoArray = array(
            'name' => $this->config->getLowCaseName(),
            'displayname' => $this->config->getName(),
            'description' => $this->config->getDescription(),
            'author' => $this->config->getAuthor(),
            'instructions' => utf8_encode($packageAttributes['readme']),
            'changelog' => utf8_encode($packageAttributes['changelog']),
            'license' => utf8_encode($packageAttributes['license']),
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
                $file = fopen($source, 'r');
                $filesystem->writeStream(basename($source), $file);
            } catch (FilesystemException | UnableToWriteFile $exception) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'SFTP Error uploading package: ' . $exception->getMessage());
                return $this->failure('SFTP Error uploading package: ' . $exception->getMessage());
            }

            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Upload the package per FTP to the package provider.');

            $package_info = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->package->name . '.info.php';
            $info_file = fopen($package_info, 'w');
            fwrite($info_file, $packageInfo);
            fclose($info_file);
            chmod($package_info, 0666);

            try {
                $file = fopen($package_info, 'r');
                $filesystem->writeStream(basename($package_info), $file);
            } catch (FilesystemException | UnableToWriteFile $exception) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'SFTP Error uploading package info: ' . $exception->getMessage());
                return $this->failure('SFTP Error uploading package info: ' . $exception->getMessage());
            }

            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Upload the package info per FTP to the package provider.');
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
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Update the package info file.');
        }

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
        $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Before Scan package.');
        $result = curl_exec($ch);
        $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Scan package: ' . $result);
        $result = json_decode($result, true);
        if ($result == null) {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'cURL Error scan package: ' . curl_error($ch));
        } else {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'Scan for the package on the package provider.');
        }
        curl_close($ch);

        if (isset($result['success']) && $result['success'] == true) {
            return $this->success($result['message']);
        } else {
            return $this->failure($result['message']);
        }
    }
}

return 'GitPackageManagementBuildPackagePublishProcessor';