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
    /** @var  Packeteer $packeteer */
    public $packeteer;

    public function process()
    {
        $corePath = $this->modx->getOption('packeteer.core_path', null, $this->modx->getOption('core_path') . 'components/packeteer/');
        $this->packeteer = $this->modx->getService('packeteer', 'Packeteer', $corePath . 'model/packeteer/', array(
            'core_path' => $corePath
        ));

        $process = parent::process();
        if ($process['success'] !== true) {
            return $process;
        };

        // @todo upload the package with sftp or similar
        $source = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
        $targetPath = realpath(MODX_BASE_PATH . $this->packeteer->getOption('site_extras_path'));
        $target = $targetPath . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
        copy($source, $target);
        chmod($targetPath . '/_packages/', 0777);
        chmod($target, 0666);

        $package_info = $targetPath . '/_packages/' . $this->builder->getTPBuilder()->package->name . '.info.php';
        if (!file_exists($package_info)) {
            $info_file = fopen($package_info, 'w');
            fwrite($info_file, "<?php\n" .
                "return array(\n" .
                "    'name' => '{$this->config->getLowCaseName()}',\n" .
                "    'displayname' => '{$this->config->getName()}',\n" .
                "    'description' => '{$this->config->getDescription()}',\n" .
                "    'author' => '{$this->config->getAuthor()}',\n" .
                "    'modx_version' => '2.3');\n"
            );
            fclose($info_file);
        }
        chmod($package_info, 0666);

        $packageName = $this->config->getLowCaseName();
        $beta = (bool)preg_match('/.*?-(dev|a|alpha|b|beta|rc)\\d*/i', $this->builder->getTPBuilder()->getSignature());

        $curl = curl_init();
        $url = $this->packeteer->getOption('site_url') . 'rest/packeteer/package/scan/' . $packageName . '?' . http_build_query(array(
                'beta' => (string)$beta,
                'hash' => hash('sha256', $this->packeteer->getOption('site_id') . $packageName . ((string)$beta))
            ));
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $url
        ));
        $result = json_decode(curl_exec($curl), true);
        $this->modx->log(xPDO::LOG_LEVEL_DEBUG, $result['message'], '', 'GitPackageManagementBuildPackagePublishProcessor');
        curl_close($curl);

        if (isset($result['success']) && $result['success'] == true) {
            return $this->success($result['message']);
        } else {
            return $this->failure($result['message']);
        }
    }

    protected function addCategory() {
        $category = parent::addCategory();

        $buildOptions = $this->config->getBuild()->getBuildOptions();

        if ($this->modx->getOption('encrypt', $buildOptions, false)){
            $this->modx->loadClass('packeteerVehicle', $this->packeteer->getOption('vehiclePath'), true, true);

            $categoryVehicle = $category->getVehicle();
            $categoryVehicle->attributes['vehicle_class'] = 'packeteerVehicle';
        }
        return $category;
    }

    protected function prependVehicles() {
        $resolversDir = $this->config->getBuild()->getResolver()->getResolversDir();
        $resolversDir = trim($resolversDir, '/');
        $resolversDir = $this->packagePath . '_build/' . $resolversDir . '/';

        $this->modx->loadClass('xPDOFileVehicle', MODX_CORE_PATH. 'xpdo/transport/', true,true);
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

        $this->modx->loadClass('xPDOScriptVehicle', MODX_CORE_PATH. 'xpdo/transport/', true,true);
        $fileObject = new xPDOScriptVehicle();
        $vehicle = $this->builder->createVehicle($fileObject, array(
            'vehicle_class' => 'xPDOScriptVehicle',
            'object' => array(
                'source' => $resolversDir. 'packeteer.vehicle.php'
            )
        ));

        $this->builder->putVehicle($vehicle);
    }
}

return 'GitPackageManagementBuildPackagePublishProcessor';
