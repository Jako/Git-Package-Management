<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/gpc/gitpackageconfig.class.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/builder/gitpackagebuilder.class.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/processors/mgr/gitpackage/buildpackage.class.php';

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
                return $this->failure('Grunt issue!');
            }
        }

        $execVal = 0;
        $execResult = array();
        if (file_exists($this->config->getPackagePath() . '/test/phpunit.xml')) {
            exec('export PATH=$PATH:/usr/local/bin; /usr/local/bin/phpunit --configuration ' . $this->config->getPackagePath() . '/test/phpunit.xml 2>&1', $execResult, $execVal);
            if ($execVal != 0) {
                $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'phpUnit issue!' . "\n" . implode("\n", $execResult));
                return $this->failure('phpUnit issue!');
            }
        }

        $lexiconPath = $this->config->getPackagePath() . '/core/components/' . $this->config->getLowCaseName() . '/lexicon/';
        $lexiconPathIterator = new RecursiveDirectoryIterator($lexiconPath, RecursiveDirectoryIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($lexiconPathIterator, RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD) as $file => $info) {
            if (in_array($info->getFilename(), array('_variable.php', '_missing.php', '_superfluous.php'))) {
                @unlink($info->getRealPath());
            }
        }

        $process = parent::process();
        if ($process['success'] !== true) {
            return $process;
        };

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
            $user = $this->packeteer->getOption('sftp_user');
            $serverurl = $this->packeteer->getOption('sftp_serverurl');
            $serverpath = $this->packeteer->getOption('sftp_serverpath');
            $publickey = $this->packeteer->getOption('sftp_publickey');
            $privatekey = $this->packeteer->getOption('sftp_privatekey');
            $secret = $this->packeteer->getOption('sftp_secret');
            $filename = basename($source);

            $connection = ssh2_connect($serverurl, 22, array('hostkey' => 'ssh-rsa'));

            if (ssh2_auth_pubkey_file($connection, $user, $publickey, $privatekey, $secret)) {
                if (ssh2_scp_send($connection, $source, $serverpath . $filename, 0666) == false) {
                    return $this->failure('SFTP Error uploading package.');
                }
                $package_info = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->package->name . '.info.php';
                $info_file = fopen($package_info, 'w');
                fwrite($info_file, $packageInfo);
                fclose($info_file);
                chmod($package_info, 0666);

                if (ssh2_scp_send($connection, $package_info, $serverpath . $this->builder->getTPBuilder()->package->name . '.info.php', 0666) == false) {
                    return $this->failure('SFTP Error uploading package.');
                }
            } else {
                return $this->failure('SFTP Connection Error.');
            }
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
        }

        $packageName = $this->config->getLowCaseName();
        $beta = (bool)preg_match('/.*?-(dev|a|alpha|b|beta|rc)\\d*/i', $this->builder->getTPBuilder()->getSignature());

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $this->packeteer->getOption('site_url') . 'rest/packeteer/package/scan/' . $packageName . '?' . http_build_query(array(
                    'beta' => (string)$beta,
                    'hash' => hash('sha256', $this->packeteer->getOption('site_id') . $packageName . ((string)$beta))
                )),
            CURLOPT_RETURNTRANSFER => 1
        ));
        $result = curl_exec($ch);
        $result = json_decode($result, true);
        if ($result == null) {
            $this->modx->log(xPDO::LOG_LEVEL_ERROR, 'cURL Error scan package: ' . curl_error($ch));
        }
        curl_close($ch);

        if (isset($result['success']) && $result['success'] == true) {
            return $this->success($result['message']);
        } else {
            return $this->failure($result['message']);
        }
    }

    protected function addCategory()
    {
        $category = parent::addCategory();

        $buildOptions = $this->config->getBuild()->getBuildOptions();

        if ($this->modx->getOption('encrypt', $buildOptions, false)) {
            $this->modx->loadClass('packeteerVehicle', $this->packeteer->getOption('vehiclePath'), true, true);

            $categoryVehicle = $category->getVehicle();
            $categoryVehicle->attributes['vehicle_class'] = 'packeteerVehicle';
            $categoryVehicle->attributes[xPDOTransport::ABORT_INSTALL_ON_VEHICLE_FAIL] = true;
        }
        return $category;
    }

    protected function prependVehicles()
    {
        $buildOptions = $this->config->getBuild()->getBuildOptions();

        if ($this->modx->getOption('encrypt', $buildOptions, false)) {
            $resolversDir = $this->config->getBuild()->getResolver()->getResolversDir();
            $resolversDir = trim($resolversDir, '/');
            $resolversDir = $this->packagePath . '_build/' . $resolversDir . '/';

            $this->modx->loadClass('xPDOFileVehicle', MODX_CORE_PATH . 'xpdo/transport/', true, true);
            $fileObject = new xPDOFileVehicle();
            $vehicle = $this->builder->createVehicle($fileObject, array(
                'vehicle_class' => 'xPDOFileVehicle',
                'object' => array(
                    'source' => $this->packagePath . '../packeteer_vehicle/',
                    'target' => 'return MODX_CORE_PATH . "components/";',
                    'name' => $this->config->getLowCaseName() . '_vehicle'
                )
            ));

            $this->builder->putVehicle($vehicle);

            $this->modx->loadClass('xPDOScriptVehicle', MODX_CORE_PATH . 'xpdo/transport/', true, true);
            $fileObject = new xPDOScriptVehicle();
            $vehicle = $this->builder->createVehicle($fileObject, array(
                'vehicle_class' => 'xPDOScriptVehicle',
                'object' => array(
                    'source' => $resolversDir . 'packeteer.vehicle.php'
                )
            ));

            $this->builder->putVehicle($vehicle);
        }
    }

    protected function appendVehicles()
    {
        $buildOptions = $this->config->getBuild()->getBuildOptions();

        if ($this->modx->getOption('encrypt', $buildOptions, false)) {
            $resolversDir = $this->config->getBuild()->getResolver()->getResolversDir();
            $resolversDir = trim($resolversDir, '/');
            $resolversDir = $this->packagePath . '_build/' . $resolversDir . '/';

            $this->modx->loadClass('xPDOScriptVehicle', MODX_CORE_PATH . 'xpdo/transport/', true, true);
            $fileObject = new xPDOScriptVehicle();
            $vehicle = $this->builder->createVehicle($fileObject, array(
                'vehicle_class' => 'xPDOScriptVehicle',
                'object' => array(
                    'source' => $resolversDir . 'packeteer.vehicle.php'
                )
            ));

            $this->builder->putVehicle($vehicle);
        }
    }
}

return 'GitPackageManagementBuildPackagePublishProcessor';
