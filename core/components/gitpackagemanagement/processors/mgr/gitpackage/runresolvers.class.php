<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/gpc/gitpackageconfig.class.php';
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/builder/gitpackagebuilder.class.php';
/**
 * Clone git repository and install it
 *
 * @package gitpackagemanagement
 * @subpackage processors
 */
class GitPackageManagementRunResolversProcessor extends modObjectProcessor {
    /** @var GitPackage $object */
    public $object;
    /** @var GitPackageConfig $config */
    public $config;
    public $packagePath = null;
    /** @var GitPackageBuilder $builder */
    public $builder;
    private $corePath;
    private $assetsPath;
    /** @var modSmarty $smarty */
    private $smarty;
    private $tvMap = array();

    public function prepare(){
        $id = $this->getProperty('id');
        if ($id == null) return $this->failure();

        $this->object = $this->modx->getObject('GitPackage', array('id' => $id));
        if (!$this->object) return $this->failure();

        $this->packagePath = rtrim($this->modx->getOption('gitpackagemanagement.packages_dir', null, null), '/') . '/';
        if ($this->packagePath == null) {
            return $this->modx->lexicon('gitpackagemanagement.package_err_ns_packages_dir');
        }

        $packagePath = $this->packagePath . $this->object->dir_name;

        $configFile = $packagePath . $this->modx->gitpackagemanagement->configPath;
        if (!file_exists($configFile)) {
            return $this->modx->lexicon('gitpackagemanagement.package_err_url_config_nf');
        }

        $config = file_get_contents($configFile);

        $config = $this->modx->fromJSON($config);

        $this->config = new GitPackageConfig($this->modx, $packagePath);
        if ($this->config->parseConfig($config) == false) {
            return $this->modx->lexicon('gitpackagemanagement.package_err_url_config_nf');
        }

        return true;
    }

    public function process() {
        $prepare = $this->prepare();
        if ($prepare !== true) {
            return $prepare;
        }

        $resolver = $this->config->getBuild()->getResolver();
        $resolversDir = $resolver->getResolversDir();
        $resolversDir = trim($resolversDir, '/');
        $resolversDir = $this->config->getPackagePath() . '/_build/' . $resolversDir . '/';
        $resolversBefore = $resolver->getBefore();
        $resolversAfter = $resolver->getAfter();

        foreach ($resolversBefore as $resolver) {
            $result = include $resolversDir . $resolver;
            if (!$result) {
                return $this->failure('Before resolver ' . $resolver . ' failure!');
            }
        }
        foreach ($resolversAfter as $resolver) {
            $result = include $resolversDir . $resolver;
            if (!$result) {
                return $this->failure('After resolver ' . $resolver . ' failure!');
            }
        }
        return $this->success();
    }
}
return 'GitPackageManagementRunResolversProcessor';
