<?php

/**
 * Build, upload and publish a package
 *
 * @package gitpackagemanagement
 * @subpackage processors
 */
class GitPackageManagementCLIBuildPackagePublishProcessor extends modObjectProcessor {
    /** @var GitPackage $object */
    public $object;

    public function process() {
        $key = $this->getProperty('2', 0);
        if (empty($key)) {
            return $this->failure();
        }

        $this->object = $this->modx->getObject('GitPackage', array('key' => $key));
        if (!$this->object) {
            return $this->failure();
        }

        $corePath = $this->modx->getOption('gitpackagemanagement.core_path',null,$this->modx->getOption('core_path').'components/gitpackagemanagement/');
        $path = $this->modx->getOption('processorsPath', $this->modx->gitpackagemanagement->config, $corePath . 'processors/');
        /** @var modProcessorResponse $response */
        $response = $this->modx->runProcessor('mgr/gitpackage/buildpackagepublish', array(
            'id' => $this->object->id,
        ), array(
            'processors_path' => $path,
            'location' => '',
        ));

        if (!$response || $response->isError()) {
            return $this->failure($response ? $response->getMessage() : '');
        }

        return $this->success($response->getMessage());
    }

}
return 'GitPackageManagementCLIBuildPackagePublishProcessor';
