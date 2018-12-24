<?php
/**
 * WIP: FeaturesConfigFilter writes configuration from features
 * to the features config/install dir instead of to default config
 * @todo:
 * get all features dynamically
 * configuration is deleted when doing cim, change the cim storage to the features directory
 * Currently uuid and other site-specific data is exported to features, try to remove it
 * refactor
 */

namespace Drupal\features_config_filter\Plugin\ConfigFilter;

use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\config_filter\Plugin\ConfigFilterBase;

/**
 * @ConfigFilter(
 *   id = "features_config_filter",
 *   label = @Translation("Features: Config filter"),
 *   status = TRUE,
 *   weight = 9000,
 *   storages = {"config.storage.sync"},
 * )
 */
class FeaturesConfigFilter extends ConfigFilterBase {

  private $FCFBlacklist = [];
  private $FCFBlacklistKeyedWithModule = [];

  private $blacklistedModules = [
    'calibr8_news',
  ];

  private $blacklistedModulesWithFileStorage = [];

  /**
   * Constructs a new SplitFilter.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigManagerInterface $manager
   *   The config manager for retrieving dependent config.
   * @param \Drupal\Core\Config\StorageInterface|null $secondary
   *   The config storage for the blacklisted config.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $manager, $secondary);

    $this->calculateBlacklist();

    foreach ($this->blacklistedModules as $blacklistedModule) {
      $path = drupal_get_path('module', $blacklistedModule) . '/config/install';

      if (file_exists($path) || strpos($path, 'vfs://') === 0) {
        // Allow virtual file systems even if file_exists is false.
        $this->blacklistedModulesWithFileStorage[$blacklistedModule] = new FileStorage($path);
      }
    }
  }

  /**
   * Calculate the blacklist by including dependents and resolving wild cards.
   */
  protected function calculateBlacklist() {

    foreach ($this->blacklistedModules as $module) {
      $path = drupal_get_path('module', $module);
      if ($path === NULL || $path === '') {
        continue;
      }

      $files = file_scan_directory($path . '/config/install', '/.yml/');
      $cleanFiles = [];
      foreach ($files as $file) {
        $cleanFileName = str_replace('.yml', '', $file->name);
        $this->FCFBlacklist[] = $cleanFileName;
        $cleanFiles[] = $cleanFileName;
      }

      $this->FCFBlacklistKeyedWithModule[$module] = $cleanFiles;
      unset($cleanFiles);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function filterWrite($name, array $data) {
    if ($this->checkIfInBlacklist($name)) {
      $moduleName = $this->getModuleNameForConfigFile($name);
      if (!empty($moduleName)) {
        $secondaryStorage = $this->blacklistedModulesWithFileStorage[$moduleName];
        $secondaryStorage->write($name, $data);
        $data =  [];
      }
    }

    // exclude our dev module from core.extension.yml
    if ($name != 'core.extension') {
      return $data;
    }
    unset($data['module']['features_config_filter']);

    return $data;
  }


  /**
   * {@inheritdoc}
   * The code works, but adds a collection for each language instead of the default config
   * We put this functionality on hold for now
   */
  /*public function filterListAll($prefix, array $data) {

    if ($this->pluginId == 'features_config_filter') {
      foreach ($this->FCFBlacklistKeyedWithModule as $module => $config) {
        $data = array_unique(array_merge($data, $config));
      }
    }
    return $data;
  }*/

  /**
   * {@inheritdoc}
   */
  public function filterReadMultiple(array $names, array $data) {

    // features_config_filter is excluded from core.extension, enable it when importing
    if (in_array('core.extension', $names)) {
      $data['core.extension']['module']['features_config_filter'] = 0;
    }

    return $data;
  }


  /**
   * Custom helper to function to check if it's in our custom blacklist or not.
   *
   * @param $name
   *
   * @return bool
   */
  private function checkIfInBlacklist($name) {
    if (in_array($name, $this->FCFBlacklist, TRUE)) {
      return TRUE;
    }
    return FALSE;
  }

  private function getModuleNameForConfigFile($name) {
    foreach ($this->FCFBlacklistKeyedWithModule as $moduleName => $fileList) {
      if (in_array($name, $fileList, TRUE)) {
        return $moduleName;
      }
    }

    return FALSE;
  }

}