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

    public function process()
    {
        $process = parent::process();
        if ($process['success'] !== true) {
            return $process;
        };

        // @todo upload the package with sftp or similar
        $source = $this->config->getPackagePath() . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
        $targetPath = realpath(MODX_BASE_PATH . $this->modx->getOption('packeteer.site_extras_path'));
        $target = $targetPath . '/_packages/' . $this->builder->getTPBuilder()->getSignature() . '.transport.zip';
        copy($source, $target);
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
        $beta = (bool)preg_match('/.*?-(b|beta)\\d*/i', $this->builder->getTPBuilder()->getSignature());

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $this->modx->getOption('packeteer.site_assets_url') . 'components/packeteer/connector.php',
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => array(
                'action' => 'web/packages/scan',
                'package' => $packageName,
                'beta' => $beta,
                'hash' => hash('sha256', $this->modx->getOption('packeteer.site_id') . $packageName . ((string)$beta))
            )
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
}

return 'GitPackageManagementBuildPackagePublishProcessor';