<?php
require_once dirname(dirname(dirname(dirname(__FILE__)))) . '/model/gitpackagemanagement/gpc/gitpackageconfig.class.php';

/**
 * Check lexicon in git repository and collect missing/superfluous entries
 *
 * @package gitpackagemanagement
 * @subpackage processors
 */
class GitPackageManagementCreateDocsProcessor extends modObjectProcessor
{
    /** @var GitPackage $object */
    public $object;
    /** @var GitPackageConfig $config */
    public $config;

    public $packagePath = null;
    public $docsPath = null;

    private $language = null;

    public function prepare()
    {
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

        $this->language = $this->modx->getOption('gitpackagemanagement.default_lexicon', null, 'en');

        return true;
    }

    public function process()
    {
        $prepare = $this->prepare();
        if ($prepare !== true) {
            return $prepare;
        }

        $this->setPaths();

        $doctypes = $this->createDocs();

        return $this->success('Documentation created for ' . implode(', ', $doctypes));
    }


    private function setPaths()
    {
        $packagesPath = rtrim($this->modx->getOption('gitpackagemanagement.packages_dir', null, null), '/') . '/';

        $this->packagePath = $packagesPath . $this->object->dir_name . "/";
        $this->packagePath = str_replace('\\', '/', $this->packagePath);

        $this->docsPath = $this->packagePath . '_docs/';
    }

    /**
     * Create docs
     *
     * @return array
     */
    private function createDocs()
    {
        $doctypes = [];
        if ($this->createSettingsDocs()) {
            $doctypes[] = $this->modx->lexicon('gitpackagemanagement.create_docs_settings');
        }
        if ($this->createPropertiesDocs()) {
            $doctypes[] = $this->modx->lexicon('gitpackagemanagement.create_docs_properties');
        }
        return $doctypes;
    }

    private function createSettingsDocs() {
        $values = [];
        $settings = $this->config->getSettings();
        ksort($settings);
        foreach ($settings as $setting) {
            $this->modx->lexicon->load($setting->getNamespace() . ':setting');
            switch ($setting->getType()) {
                case 'textfield':
                default:
                    $default = ($setting->getValue()) ?: '-';
                    break;
                case 'combo-boolean':
                    $default = ($setting->getValue() == '1') ? $this->modx->lexicon('yes') : $this->modx->lexicon('no');
                    break;
            }
            $values[] = [
                'key' => $setting->getNamespacedKey(),
                'name' => $this->modx->lexicon('setting_' . $setting->getNamespacedKey()),
                'description' => $this->convertLinks($this->escapeTable($this->modx->lexicon('setting_' . $setting->getNamespacedKey() . '_desc'))),
                'default' => $default,
            ];
        }
        if ($values) {
            $result = [
                '| Key | Name | Description | Default |',
                '|-----|------|-------------|---------|'
            ];
            foreach ($values as $value) {
                $result[] = '| ' . $value['key'] . ' | ' . $value['name'] . ' | ' . $value['description'] . ' | ' . $value['default'] . ' |';
            }

            if (!file_exists($this->docsPath)) {
                $this->modx->cacheManager->writeTree($this->docsPath . 'settings/');
            }
            $this->modx->cacheManager->writeFile($this->docsPath . 'settings/' . 'setting.md', implode("\n", $result));
        }
        return true;
    }

     private function createPropertiesDocs() {
         $snippets = $this->config->getElements('snippets');
         foreach ($snippets as $snippet) {
             $this->modx->lexicon->load($this->config->getLowCaseName() . ':properties');
             $values = [];
             $properties = $snippet->getProperties();
             foreach ($properties as $property) {
                 switch ($property['type']) {
                     case 'textfield':
                     default:
                         $default = ($property['value']) ?: '-';
                         break;
                     case 'combo-boolean':
                         $default = ($property['value'] == '1') ? '1 (' . $this->modx->lexicon('yes') . ')' : '0 (' . $this->modx->lexicon('no') . ')';
                         break;
                 }
                 $values[$property['name']] = [
                     'name' => $property['name'],
                     'description' => $this->convertLinks($this->escapeTable($this->modx->lexicon($this->config->getLowCaseName() . '.' . strtolower($snippet->getName()) . '.' . $property['name']))),
                     'default' => $default,
                 ];
             }

             ksort($values);
             $result = [
                 '## ' . $snippet->getName(),
                 '',
                 '| Property | Description | Default |',
                 '|----------|-------------|---------|'
             ];
             foreach ($values as $value) {
                 $result[] = '| ' . $value['name'] . ' | ' . $value['description'] . ' | ' . $value['default'] . ' |';
             }

             if (!file_exists($this->docsPath)) {
                 $this->modx->cacheManager->writeTree($this->docsPath . 'snippets/');
             }
             $this->modx->cacheManager->writeFile($this->docsPath . 'snippets/' . $snippet->getName() . '.md', implode("\n", $result));
         }
         return true;
    }

    private function convertLinks($string) {
        $search = '#(<a .*?href=")(.*?)(".*?>)(.*?)(</a>)#';
        $replace = '[$4]($2)';
        return preg_replace($search, $replace, $string);
    }

    private function escapeTable($string) {
        return str_replace('|', '&#124;', $string);
    }
}

return 'GitPackageManagementCreateDocsProcessor';
