<?php
namespace Concrete\Package\CommunityStoreImport;

use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Config\Repository\Repository as Config;
use Exception;

class Controller extends Package
{
    protected $pkgHandle = 'community_store_import';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '0.9.5';

    public function getPackageDescription()
    {
        return t("Product import for concrete5 Community Store.");
    }

    public function getPackageName()
    {
        return t("Community Store Import");
    }

    public function install()
    {
        $installed = Package::getInstalledHandles();

        if (!(is_array($installed) && in_array('community_store', $installed))) {
            throw new Exception(t('This package requires that Community Store is installed.'));
        }

        $pkg = parent::install();

        $this->installSinglePage('/dashboard/store/products/import', 'Import', $pkg);

        $this->setConfigValue('community_store_import.import_file', null);
        $this->setConfigValue('community_store_import.max_execution_time', '60');
        $this->setConfigValue('community_store_import.default_image', null);
        $this->setConfigValue('community_store_import.image_directory', '');
        $this->setConfigValue('community_store_import.csv.delimiter', ',');
        $this->setConfigValue('community_store_import.csv.enclosure', '"');
        $this->setConfigValue('community_store_import.csv.line_length', 1000);

        return $pkg;
    }

    protected function installSinglePage($path, $name, $pkg)
    {
        $page = Page::getByPath($path);
        if (!is_object($page) || $page->isError()) {
            SinglePage::add($path, $pkg);
            // Get the page again after creation
            $page = Page::getByPath($path);
            if (is_object($page) && !$page->isError()) {
                $page->update(['cName' => t($name)]);
            }
        }
    }

    private function setConfigValue($key, $value)
    {
        $config = $this->app->make(Config::class);
        if (!$config->has($key)) {
            $config->save($key, $value);
        }
    }

    public function upgrade()
    {
        parent::upgrade();
    }

    public function uninstall()
    {
        // Remove the single page
        $page = Page::getByPath('/dashboard/store/products/import');
        if (is_object($page) && !$page->isError()) {
            $page->delete();
        }

        // Remove configuration values
        $config = $this->app->make(Config::class);
        $configKeys = [
            'community_store_import.import_file',
            'community_store_import.max_execution_time',
            'community_store_import.default_image',
            'community_store_import.image_directory',
            'community_store_import.csv.delimiter',
            'community_store_import.csv.enclosure',
            'community_store_import.csv.line_length',
        ];
        
        foreach ($configKeys as $key) {
            if ($config->has($key)) {
                $config->save($key, null);
            }
        }

        parent::uninstall();
    }
}
