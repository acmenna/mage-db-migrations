<?php

/**
 * Db Migrations Resource Setup Model
 *
 * @category    Db
 * @package     Db_Migrations
 * @author      Adolfo Castro Menna
 */
class Db_Migrations_Model_Resource_Setup extends Mage_Core_Model_Resource_Setup
{

    const TYPE_DATA_DOWNGRADE       = 'data-downgrade';

    /**
     * Apply data updates to the system after upgrading.
     *
     * @return Db_Migrations_Model_Resource_Setup
     */
    public function applyDataUpdates()
    {
        $dataVer= $this->_getResource()->getDataVersion($this->_resourceName);
        $configVer = (string)$this->_moduleConfig->version;
        if ($dataVer !== false) {
             $status = version_compare($configVer, $dataVer);
             if ($status == self::VERSION_COMPARE_GREATER) {
                 $this->_upgradeData($dataVer, $configVer);
             } elseif ($status == self::VERSION_COMPARE_LOWER) {
                 $this->_downgradeData($dataVer, $configVer);
             }
        } elseif ($configVer) {
            $this->_installData($configVer);
        }
        return $this;
    }

    /**
     * Downgrade data
     *
     * @param string $oldVersion
     * @param string $newVersion
     * @return Db_Migrations_Model_Resource_Setup
     */
    
    protected function _downgradeData($oldVersion, $newVersion)
    {
        $this->_modifyResourceDb('data-downgrade', $oldVersion, $newVersion);
        $this->_getResource()->setDataVersion($this->_resourceName, $newVersion);
        
        return $this;

    }

    /**
     * Roll back resource
     *
     * @param string $newVersion
     * @param string $oldVersion
     * @return Mage_Core_Model_Resource_Setup
     */
    protected function _rollbackResourceDb($newVersion, $oldVersion)
    {
        $this->_modifyResourceDb(self::TYPE_DB_ROLLBACK, $oldVersion, $newVersion);
        return $this;
    }
    
    /**
     * Save resource version
     *
     * @param string $actionType
     * @param string $version
     * @return Mage_Core_Model_Resource_Setup
     */
    protected function _setResourceVersion($actionType, $version)
    {
        switch ($actionType) {
            case self::TYPE_DB_INSTALL:
            case self::TYPE_DB_UPGRADE:
            case self::TYPE_DB_ROLLBACK:
                $this->_getResource()->setDbVersion($this->_resourceName, $version);
                break;
            case self::TYPE_DATA_INSTALL:
            case self::TYPE_DATA_UPGRADE:
            case self::TYPE_DATA_DOWNGRADE:
                $this->_getResource()->setDataVersion($this->_resourceName, $version);
                break;

        }

        return $this;
    }

    /**
     * Run module modification files. Return version of last applied upgrade (false if no upgrades applied)
     *
     * @param string $actionType self::TYPE_*
     * @param string $fromVersion
     * @param string $toVersion
     * @return string|false
     * @throws Mage_Core_Exception
     */

    protected function _modifyResourceDb($actionType, $fromVersion, $toVersion)
    {
        switch ($actionType) {
            case self::TYPE_DB_INSTALL:
            case self::TYPE_DB_UPGRADE:
            case self::TYPE_DB_ROLLBACK:
                $files = $this->_getAvailableDbFiles($actionType, $fromVersion, $toVersion);
                break;
            case self::TYPE_DATA_INSTALL:
            case self::TYPE_DATA_UPGRADE:
                $files = $this->_getAvailableDataFiles($actionType, $fromVersion, $toVersion);
                break;
            default:
                $files = array();
                break;
        }
        if (empty($files) || !$this->getConnection()) {
            return false;
        }

        $version = false;

        foreach ($files as $file) {
            $fileName = $file['fileName'];
            $fileType = pathinfo($fileName, PATHINFO_EXTENSION);
            $this->getConnection()->disallowDdlCache();
            try {
                switch ($fileType) {
                    case 'php':
                        $conn   = $this->getConnection();
                        $result = include $fileName;
                        break;
                    case 'sql':
                        $sql = file_get_contents($fileName);
                        if (!empty($sql)) {

                            $result = $this->run($sql);
                        } else {
                            $result = true;
                        }
                        break;
                    default:
                        $result = false;
                        break;
                }

                if ($result) {
                    $this->_setResourceVersion($actionType, $file['toVersion']);
                }
            } catch (Exception $e) {
                printf('<pre>%s</pre>', print_r($e, true));
                throw Mage::exception('Mage_Core', Mage::helper('core')->__('Error in file: "%s" - %s', $fileName, $e->getMessage()));
            }
            $version = $file['toVersion'];
            $this->getConnection()->allowDdlCache();
        }
        self::$_hadUpdates = true;
        return $version;
    }

    /**
     * Get data files for modifications
     *
     * @param string $actionType
     * @param string $fromVersion
     * @param string $toVersion
     * @param array $arrFiles
     * @return array
     */
    protected function _getModifySqlFiles($actionType, $fromVersion, $toVersion, $arrFiles)
    {
        $arrRes = array();
        switch ($actionType) {
            case self::TYPE_DB_INSTALL:
            case self::TYPE_DATA_INSTALL:
                uksort($arrFiles, 'version_compare');
                foreach ($arrFiles as $version => $file) {
                    if (version_compare($version, $toVersion) !== self::VERSION_COMPARE_GREATER) {
                        $arrRes[0] = array(
                            'toVersion' => $version,
                            'fileName'  => $file
                        );
                    }
                }
                break;

            case self::TYPE_DB_UPGRADE:
            case self::TYPE_DATA_UPGRADE:
                uksort($arrFiles, 'version_compare');
              
                foreach ($arrFiles as $version => $file) {
                    $versionInfo = explode('-', $version);

                    // In array must be 2 elements: 0 => version from, 1 => version to
                    if (count($versionInfo)!=2) {
                        break;
                    }
                    $infoFrom = $versionInfo[0];
                    $infoTo   = $versionInfo[1];
                    if (version_compare($infoFrom, $fromVersion) !== self::VERSION_COMPARE_LOWER
                        && version_compare($infoTo, $toVersion) !== self::VERSION_COMPARE_GREATER) {
                        $arrRes[] = array(
                            'toVersion' => $infoTo,
                            'fileName'  => $file
                        );
                    }
                }
                break;
                
            case self::TYPE_DB_ROLLBACK:
                uksort($arrFiles, 'version_compare');
                
                arsort($arrFiles);
                
                foreach ($arrFiles as $version => $file) {
                    $versionInfo = explode('-', $version);

                    // In array must be 2 elements: 0 => version from, 1 => version to
                    if (count($versionInfo)!=2) {
                        break;
                    }
                    $infoFrom = $versionInfo[0];
                    $infoTo   = $versionInfo[1];
                    if (version_compare($infoFrom, $fromVersion) !== self::VERSION_COMPARE_GREATER
                        && version_compare($infoTo, $toVersion) !== self::VERSION_COMPARE_LOWER) {
                        $arrRes[] = array(
                            'toVersion' => $infoTo,
                            'fileName'  => $file
                        );
                    }
                }
                    
                break;
            
            case self::TYPE_DB_UNINSTALL: // Not implemented yet
                break;
        }
        return $arrRes;
    }

}
